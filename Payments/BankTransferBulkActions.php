<?php
/**
 * Bulk Actions para Gestión de Estados de Pago
 * 
 * @package SchoolManagement\Payments
 * @since 1.0.0
 * @version 2.0.0
 * 
 * FUNCIONALIDADES PRINCIPALES:
 * 
 * 1. BULK ACTION UNIVERSAL PARA MARCAR COMO PAGADO/NO PAGADO:
 *    - Permite marcar CUALQUIER pedido como pagado/no pagado
 *    - NO tiene restricciones por método de pago
 *    - NO tiene restricciones por tipo de pedido
 *    - Funciona con transferencias, Redsys, PayPal, etc.
 *    - Funciona con pedidos normales y master orders
 * 
 * 2. GESTIÓN COMPLETA DE INDICADORES DE PAGO:
 *    - Añade/elimina fecha de pago (payment_date)
 *    - Añade/elimina transaction ID manual
 *    - Mantiene el estado actual del pedido
 *    - Actualiza valores de ordenación para columnas
 * 
 * 3. LOGGING Y NOTIFICACIONES:
 *    - Registra todas las acciones realizadas
 *    - Muestra mensajes de confirmación al admin
 *    - Maneja errores de forma elegante
 * 
 * 4. COMPATIBILIDAD HPOS:
 *    - Funciona tanto con sistema legacy como HPOS
 *    - Detección automática del sistema en uso
 *    - Manejo unificado de pedidos
 * 
 * FLUJO DE TRABAJO:
 * 1. Admin selecciona CUALQUIER pedido en la lista
 * 2. Elige "Marcar como pagado" o "Marcar como no pagado"
 * 3. Sistema procesa TODOS los pedidos seleccionados
 * 4. Añade/elimina indicadores de pago SIN cambiar estado del pedido
 * 5. Muestra resultado con número de pedidos procesados
 * 
 * CAMBIOS EN V2.0.0:
 * - Eliminadas TODAS las restricciones por método de pago
 * - Eliminadas TODAS las restricciones por tipo de pedido
 * - Ahora funciona universalmente con cualquier pedido
 */

namespace SchoolManagement\Payments;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manejador Universal de Bulk Actions para Estados de Pago
 * 
 * Esta clase permite a los administradores marcar múltiples pedidos
 * como pagados o no pagados de forma masiva, sin restricciones por
 * método de pago o tipo de pedido.
 * 
 * CARACTERÍSTICAS PRINCIPALES:
 * - Bulk actions universales para cualquier tipo de pedido
 * - Sin restricciones por método de pago (funciona con todos)
 * - Compatible con pedidos normales y master orders
 * - Compatible con sistemas legacy y HPOS
 * - Logging completo de acciones realizadas
 * 
 * USO:
 * La clase se auto-registra en WordPress hooks al instanciarse.
 * Aparecerá automáticamente en la lista de pedidos de WooCommerce
 * y permitirá marcar cualquier pedido como pagado/no pagado.
 * 
 * @since 1.0.0
 * @version 2.0.0
 */
class BankTransferBulkActions
{
    /**
     * Instancia de PaymentStatusColumn para verificaciones
     */
    private $paymentStatusColumn;

    /**
     * Constructor de la clase
     */
    public function __construct(?PaymentStatusColumn $paymentStatusColumn = null)
    {
        $this->paymentStatusColumn = $paymentStatusColumn;
        
        // Si no se proporciona, crear una instancia con PaymentHandler
        if (!$this->paymentStatusColumn) {
            // Intentar crear PaymentHandler primero
            try {
                $paymentHandler = new PaymentHandler();
                $this->paymentStatusColumn = new PaymentStatusColumn($paymentHandler);
            } catch (\Exception $e) {
                // Si falla, dejar como null y usar métodos básicos
                $this->paymentStatusColumn = null;
            }
        }
        
        $this->initHooks();
    }

    /**
     * Inicializar hooks de WordPress
     */
    private function initHooks(): void
    {
        // Hooks para sistema legacy (posts) - PRIORIDAD MÁXIMA para ejecutar antes que StatusManager
        add_filter('bulk_actions-edit-shop_order', [$this, 'addBulkActions']);
        add_filter('handle_bulk_actions-edit-shop_order', [$this, 'handleBulkActions'], 1, 3);
        
        // Hooks para sistema HPOS - PRIORIDAD MÁXIMA para ejecutar antes que StatusManager
        add_filter('bulk_actions-woocommerce_page_wc-orders', [$this, 'addBulkActions']);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [$this, 'handleBulkActions'], 1, 3);
        
        // Hook para mostrar mensajes de admin
        add_action('admin_notices', [$this, 'showAdminNotices']);
    }

    /**
     * Añadir bulk actions al listado de pedidos
     * 
     * @param array $actions Acciones existentes
     * @return array Acciones modificadas
     */
    public function addBulkActions(array $actions): array
    {
        $actions['mark_bank_transfers_paid'] = __('💰 Mark orders as paid', 'neve-child');
        $actions['mark_bank_transfers_unpaid'] = __('⏳ Mark orders as Pending Payment', 'neve-child');
        
        return $actions;
    }

    /**
     * Manejar ejecución de bulk actions
     * 
     * @param string $redirect_to URL de redirección
     * @param string $doaction Acción a realizar
     * @param array $post_ids IDs de pedidos seleccionados
     * @return string URL de redirección modificada
     */
    public function handleBulkActions(string $redirect_to, string $doaction, array $post_ids): string
    {
        // Solo procesar nuestras acciones
        if (!in_array($doaction, ['mark_bank_transfers_paid', 'mark_bank_transfers_unpaid'])) {
            return $redirect_to;
        }

        if (empty($post_ids)) {
            return $redirect_to;
        }

        $is_marking_paid = ($doaction === 'mark_bank_transfers_paid');
        $processed_count = 0;
        $skipped_count = 0;
        $error_count = 0;
        $skipped_reasons = [];

        foreach ($post_ids as $order_id) {
            try {
                $result = $this->processOrderPaymentStatus($order_id, $is_marking_paid);
                
                switch ($result['status']) {
                    case 'processed':
                        $processed_count++;
                        break;
                    case 'skipped':
                        $skipped_count++;
                        $skipped_reasons[] = $result['reason'];
                        break;
                    case 'error':
                        $error_count++;
                        break;
                }
                
            } catch (\Exception $e) {
                $error_count++;
            }
        }

        // Preparar mensaje de resultado
        $message_data = [
            'processed' => $processed_count,
            'skipped' => $skipped_count,
            'errors' => $error_count,
            'action' => $is_marking_paid ? 'paid' : 'unpaid',
            'skipped_reasons' => array_count_values($skipped_reasons)
        ];

        // Añadir parámetros a la URL de redirección
        $redirect_to = add_query_arg([
            'bulk_bank_transfer_result' => base64_encode(json_encode($message_data))
        ], $redirect_to);

        return $redirect_to;
    }

    /**
     * Procesar estado de pago de un pedido individual
     * 
     * @param int $order_id ID del pedido
     * @param bool $mark_as_paid Si marcar como pagado o no pagado
     * @return array Resultado del procesamiento
     */
    private function processOrderPaymentStatus(int $order_id, bool $mark_as_paid): array
    {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return [
                'status' => 'error',
                'reason' => __('Order not found', 'neve-child')
            ];
        }

        // Cualquier pedido puede ser marcado como pagado/no pagado
        // sin importar el método de pago o tipo de pedido
        

        $payment_date = $order->get_meta('payment_date');
        $transaction_id = $order->get_transaction_id();
        $deferred_payment = $order->get_meta('_dm_pay_later_card_payment_date');
        $order_status = $order->get_status();

        // Verificar estado actual usando nuestro sistema
        $is_currently_paid = $this->paymentStatusColumn ? 
            $this->isOrderReallyPaid($order) : 
            $this->basicPaymentCheck($order);

        // LÓGICA ESPECIAL PARA BULK ACTIONS:
        // Para pedidos 'completed' sin indicadores de pago manual, consideramos que NO están pagados
        // desde la perspectiva de nuestro sistema de gestión manual
        if ($order_status === 'completed') {
            // Si es completed pero no tiene indicadores manuales, se puede marcar como pagado/no pagado
            if (empty($payment_date) && empty($transaction_id)) {
                $is_currently_paid = false;
            }
        }
        
        // LÓGICA ESPECIAL PARA MASTER ORDERS COMPLETADAS:
        // Para master orders en cualquier estado completo
        if (in_array($order_status, ['mast-ordr-cpl', 'mast-complete'])) {
            // Si es master order completed pero no tiene indicadores manuales, se puede marcar como pagado/no pagado
            if (empty($payment_date) && empty($transaction_id)) {
                $is_currently_paid = false;
            }
        }

        // If already in the desired state, skip ONLY if marking as paid
        // For marking as unpaid, always process to ensure complete cleanup
        if ($mark_as_paid && $is_currently_paid) {
            return [
                'status' => 'skipped',
                'reason' => __('Already paid', 'neve-child')
            ];
        }
        
        if (!$mark_as_paid && !$is_currently_paid) {
            return [
                'status' => 'skipped',
                'reason' => __('Already Pending Payment', 'neve-child')
            ];
        }

        // Procesar cambio de estado
        if ($mark_as_paid) {
            return $this->markOrderAsPaid($order);
        } else {
            return $this->markOrderAsUnpaid($order);
        }
    }

    /**
     * Marcar pedido como pagado
     * 
     * @param \WC_Order $order Objeto de pedido
     * @return array Resultado del procesamiento
     */
    private function markOrderAsPaid(\WC_Order $order): array
    {
        try {
            $current_time = current_time('Y-m-d H:i:s');
            
            // Establecer fecha de pago
            $order->update_meta_data('payment_date', $current_time);
            
            // Establecer transaction ID para indicar pago confirmado
            if (empty($order->get_transaction_id())) {
                $transaction_id = 'manual_bank_' . $order->get_id() . '_' . time();
                $order->set_transaction_id($transaction_id);
            }
            
            // SOLO cambiar método de pago si es un pedido que PAGA EL CENTRO
            $current_payment_method = $order->get_payment_method();
            
            // PROTECCIÓN ADICIONAL: NUNCA cambiar métodos de pago automáticos
            $automatic_methods = ['redsys', 'dm_pay_later_card', 'bizum', 'paypal', 'stripe'];
            if (in_array($current_payment_method, $automatic_methods)) {
                // Guardar los cambios ANTES de salir
                $order->save();
                
                return [
                    'status' => 'processed',
                    'reason' => __('Order marked as paid but payment method preserved (automatic payment method detected)', 'neve-child')
                ];
            }
            
            if ($this->isSchoolPaymentOrder($order)) {
                // Guardar el método de pago original para referencia
                if (!$order->get_meta('_original_payment_method')) {
                    $order->update_meta_data('_original_payment_method', $current_payment_method);
                }
                
                // Cambiar a transferencia bancaria SOLO para pedidos que paga el centro
                $order->set_payment_method('bacs');
                $order->set_payment_method_title(__('Bank Transfer - Manual Payment', 'neve-child'));
                
                $order->add_order_note(sprintf(
                    __('Payment method automatically changed from "%s" to "Bank Transfer" due to manual payment confirmation.', 'neve-child'),
                    $this->getPaymentMethodName($current_payment_method)
                ));
            }
            
            // NO cambiar el estado del pedido - mantener el estado actual
            // Solo guardar los cambios de meta data y transaction ID
            $order->save();
            
            // FORZAR RECARGA del pedido para asegurar que los cambios se reflejen
            $order = wc_get_order($order->get_id());
            
            // Actualizar valor de ordenación - FORZAR ACTUALIZACIÓN
            if ($this->paymentStatusColumn && method_exists($this->paymentStatusColumn, 'updateOrderSortValue')) {
                $this->paymentStatusColumn->updateOrderSortValue($order->get_id());
            } else {
                // Fallback: actualizar manualmente el valor de ordenación
                $this->updateSortValueManually($order->get_id(), true);
            }
            
            // Log the action
            $order->add_order_note(sprintf(
                __('Marked as paid manually via bulk action on %s (status maintained: %s)', 'neve-child'),
                $current_time,
                $order->get_status()
            ));
            
            // Force complete display update
            $this->forceOrderDisplayUpdate($order->get_id());
            
            return [
                'status' => 'processed',
                'reason' => __('Marked as paid', 'neve-child')
            ];
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'reason' => __('Error marking as paid: ', 'neve-child') . $e->getMessage()
            ];
        }
    }

    /**
     * Marcar pedido como no pagado
     * 
     * @param \WC_Order $order Objeto de pedido
     * @return array Resultado del procesamiento
     */
    private function markOrderAsUnpaid(\WC_Order $order): array
    {
        try {
            $order_id = $order->get_id();
            
            // Limpiar TODOS los indicadores de pago
            $order->delete_meta_data('payment_date');
            $order->delete_meta_data('_dm_pay_later_card_payment_date');
            $order->delete_meta_data('_payment_complete_reduce_order_stock');
            
            // Limpiar transaction ID (tanto manuales como automáticos si es necesario)
            $transaction_id = $order->get_transaction_id();
            if (!empty($transaction_id)) {
                // Si es manual, limpiarlo completamente
                if (strpos($transaction_id, 'manual_bank_') === 0) {
                    $order->set_transaction_id('');
                }
                // Para otros transaction IDs, conservarlos para mantener historial
            }
            
            // NO cambiar el estado del pedido - mantener el estado actual
            $order->save();
            
            // FORZAR RECARGA del pedido para asegurar que los cambios se reflejen
            $order = wc_get_order($order->get_id());
            
            // Actualizar valor de ordenación
            if ($this->paymentStatusColumn && method_exists($this->paymentStatusColumn, 'updateOrderSortValue')) {
                $this->paymentStatusColumn->updateOrderSortValue($order->get_id());
            } else {
                // Fallback: actualizar manualmente el valor de ordenación
                $this->updateSortValueManually($order->get_id(), false);
            }
            
            // Log the action
            $order->add_order_note(sprintf(
                __('Marked as Pending Payment manually via bulk action (status maintained: %s) - Payment indicators cleared', 'neve-child'),
                $order->get_status()
            ));
            
            // Forzar actualización completa del display
            $this->forceOrderDisplayUpdate($order->get_id());
            
            
            return [
                'status' => 'processed',
                'reason' => __('Marked as Pending Payment', 'neve-child')
            ];
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'reason' => __('Error marking as Pending Payment: ', 'neve-child') . $e->getMessage()
            ];
        }
    }

    /**
     * Forzar actualización completa del estado visual del pedido
     * 
     * @param int $order_id ID del pedido
     * @return void
     */
    private function forceOrderDisplayUpdate(int $order_id): void
    {
        try {
            // Obtener el pedido
            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }
            
            // Forzar recalculación de todos los meta campos relacionados
            $current_time = time();
            
            // Actualizar timestamp de última modificación
            $order->update_meta_data('_payment_last_updated', $current_time);
            $order->save_meta_data();
            
            // Si tenemos PaymentStatusColumn, forzar recalculación
            if ($this->paymentStatusColumn) {
                // Intentar llamar método de recalculación si existe
                if (method_exists($this->paymentStatusColumn, 'recalculatePaymentStatus')) {
                    $this->paymentStatusColumn->recalculatePaymentStatus($order_id);
                }
                
                // Forzar actualización del valor de ordenación
                if (method_exists($this->paymentStatusColumn, 'updateOrderSortValue')) {
                    $this->paymentStatusColumn->updateOrderSortValue($order_id);
                }
            }
            
            // Limpiar cualquier cache relacionado con el pedido
            wp_cache_delete($order_id, 'posts');
            wp_cache_delete("order_$order_id", 'woocommerce');
            
        } catch (\Exception $e) {
            // Error silencioso
        }
    }

    /**
     * Verificar si un pedido está realmente pagado (usando la lógica del sistema)
     * 
     * @param \WC_Order $order Objeto de pedido
     * @return bool Si está pagado
     */
    private function isOrderReallyPaid(\WC_Order $order): bool
    {
        // Usar la lógica exacta del PaymentStatusColumn
        if ($this->paymentStatusColumn && method_exists($this->paymentStatusColumn, 'advancedPaymentCheck')) {
            // Usar reflection para acceder al método privado de PaymentStatusColumn
            $reflection = new \ReflectionClass($this->paymentStatusColumn);
            $method = $reflection->getMethod('advancedPaymentCheck');
            $method->setAccessible(true);
            return $method->invoke($this->paymentStatusColumn, $order);
        }
        
        // Fallback: usar nuestra lógica básica alineada con el sistema
        return $this->basicPaymentCheck($order);
    }



    /**
     * Verificación básica de pago (ALINEADA CON CHECKOUTBLOCKER)
     * Utiliza la misma lógica que orderNeedsPaymentAdvanced del CheckoutBlocker
     * 
     * @param \WC_Order $order Objeto de pedido
     * @return bool Si está pagado (inverso de needsPayment)
     */
    private function basicPaymentCheck(\WC_Order $order): bool
    {
        // Usar la lógica INVERSA del CheckoutBlocker
        // Si CheckoutBlocker dice que "necesita pago", entonces NO está pagado
        // Si CheckoutBlocker dice que "NO necesita pago", entonces SÍ está pagado
        
        return !$this->orderNeedsPaymentAdvanced($order);
    }

    /**
     * Verificación avanzada si un pedido necesita pago
     * COPIADA EXACTAMENTE del CheckoutBlocker para mantener consistencia
     * 
     * @param \WC_Order $order Objeto de pedido
     * @return bool True si necesita pago, false en caso contrario
     */
    private function orderNeedsPaymentAdvanced(\WC_Order $order): bool
    {
        $payment_method = $order->get_payment_method();
        
        // Usar lógica unificada: verificar solo indicadores confiables de pago
        $payment_date = $order->get_meta('payment_date');
        $deferred_payment_date = $order->get_meta('_dm_pay_later_card_payment_date');
        
        // Verificar transaction_id solo si no es auto-generado (no contiene "order")
        $transaction_id = $order->get_transaction_id();
        $reliable_transaction = !empty($transaction_id) && stripos($transaction_id, 'order') === false;
        
        // Si tiene indicadores confiables de pago, NO necesita pago
        if (!empty($payment_date) || !empty($deferred_payment_date) || $reliable_transaction) {
            return false;
        }
        
        // Verificación específica para transferencias bancarias
        if ($payment_method === 'bacs') {
            return $this->bankTransferNeedsPayment($order);
        }

        // Para otros métodos, sin indicadores confiables necesita pago
        return true;
    }

    /**
     * Verificación específica para transferencias bancarias
     * COPIADA EXACTAMENTE del CheckoutBlocker para mantener consistencia
     * 
     * @param \WC_Order $order Objeto de pedido de transferencia bancaria
     * @return bool True si necesita pago, false en caso contrario
     */
    private function bankTransferNeedsPayment(\WC_Order $order): bool
    {
        $order_status = $order->get_status();
        
        // Usar misma lógica unificada que otras funciones
        $payment_date = $order->get_meta('payment_date');
        $deferred_payment_date = $order->get_meta('_dm_pay_later_card_payment_date');
        
        // Verificar transaction_id solo si no es auto-generado
        $transaction_id = $order->get_transaction_id();
        $reliable_transaction = !empty($transaction_id) && stripos($transaction_id, 'order') === false;

        // Si tiene indicadores confiables de pago, NO necesita pago
        if (!empty($payment_date) || !empty($deferred_payment_date) || $reliable_transaction) {
            return false;
        }

        // Para transferencias completadas sin indicadores, SÍ necesita confirmación manual
        if ($order_status === 'completed') {
            return true;
        }

        // Para estados que claramente indican pago pendiente
        if (in_array($order_status, ['pending', 'on-hold', 'failed'])) {
            return true;
        }

        // Para processing, usar needs_payment estándar de WooCommerce
        return $order->needs_payment();
    }

    /**
     * Mostrar mensajes de admin después de bulk actions
     * 
     * @return void
     */
    public function showAdminNotices(): void
    {
        // Verificar que estamos en la pantalla correcta
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['edit-shop_order', 'woocommerce_page_wc-orders'])) {
            return;
        }

        // Obtener datos del resultado
        $result_data = $_GET['bulk_bank_transfer_result'] ?? '';
        if (empty($result_data)) {
            return;
        }

        try {
            $data = json_decode(base64_decode($result_data), true);
            if (!$data) {
                return;
            }

            $this->displayBulkActionResult($data);
            
        } catch (\Exception $e) {
            // Error silencioso
        }
    }

    /**
     * Mostrar resultado de bulk action
     * 
     * @param array $data Datos del resultado
     * @return void
     */
    private function displayBulkActionResult(array $data): void
    {
        $processed = $data['processed'] ?? 0;
        $skipped = $data['skipped'] ?? 0;
        $errors = $data['errors'] ?? 0;
        $action = $data['action'] ?? 'paid';
        $skipped_reasons = $data['skipped_reasons'] ?? [];

        $action_text = $action === 'paid' ? __('as paid', 'neve-child') : __('as Pending Payment', 'neve-child');
        
        // Main message
        if ($processed > 0) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>✅ ' . __('Bulk Action completed:', 'neve-child') . '</strong> ';
            echo sprintf(
                _n(
                    '%d order marked %s.',
                    '%d orders marked %s.',
                    $processed,
                    'neve-child'
                ),
                $processed,
                $action_text
            );
            echo '</p></div>';
        }

        // Skipped items messages
        if ($skipped > 0) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>⚠️ ' . __('Orders skipped:', 'neve-child') . '</strong> ';
            echo sprintf(
                _n(
                    '%d order skipped.',
                    '%d orders skipped.',
                    $skipped,
                    'neve-child'
                ),
                $skipped
            );

            if (!empty($skipped_reasons)) {
                echo '<br><small>' . __('Reasons: ', 'neve-child');
                $reasons_text = [];
                foreach ($skipped_reasons as $reason => $count) {
                    $reasons_text[] = "$reason ($count)";
                }
                echo implode(', ', $reasons_text);
                echo '</small>';
            }
            echo '</p></div>';
        }

        // Error messages
        if ($errors > 0) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>❌ ' . __('Errors:', 'neve-child') . '</strong> ';
            echo sprintf(
                _n(
                    '%d order could not be processed.',
                    '%d orders could not be processed.',
                    $errors,
                    'neve-child'
                ),
                $errors
            );
            echo '</p></div>';
        }
    }

    /**
     * Obtener estadísticas de transferencias bancarias
     * 
     * @return array Estadísticas
     */
    public function getBankTransferStatistics(): array
    {
        $args = [
            'limit' => -1,
            'status' => array_keys(wc_get_order_statuses()),
            'payment_method' => 'bacs'
        ];

        $orders = wc_get_orders($args);
        
        $stats = [
            'total' => count($orders),
            'paid' => 0,
            'unpaid' => 0,
            'total_paid_amount' => 0,
            'total_unpaid_amount' => 0
        ];

        foreach ($orders as $order) {
            if (!$order) continue;
            
            $is_paid = $this->isOrderReallyPaid($order);
            $order_total = floatval($order->get_total());
            
            if ($is_paid) {
                $stats['paid']++;
                $stats['total_paid_amount'] += $order_total;
            } else {
                $stats['unpaid']++;
                $stats['total_unpaid_amount'] += $order_total;
            }
        }

        return $stats;
    }

    /**
     * Verificar si un pedido específico puede ser procesado
     * 
     * @param int $order_id ID del pedido
     * @return array Resultado de la verificación
     */
    public function canProcessOrder(int $order_id): array
    {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return [
                'can_process' => false,
                'reason' => __('Order not found', 'neve-child')
            ];
        }

        // *** UNIVERSAL: Cualquier tipo de pedido puede ser procesado ***
        // Sin restricciones por método de pago

        return [
            'can_process' => true,
            'current_status' => $order->get_status(),
            'is_paid' => $this->isOrderReallyPaid($order),
            'payment_method' => $order->get_payment_method(),
            'order_total' => $order->get_total()
        ];
    }

    /**
     * Actualizar valor de ordenación manualmente cuando PaymentStatusColumn no está disponible
     * 
     * @param int $order_id ID del pedido
     * @param bool $is_paid Si está pagado o no
     * @return void
     */
    private function updateSortValueManually(int $order_id, bool $is_paid): void
    {
        $sort_value = $is_paid ? 1 : 0;
        $current_time = time();
        
        // Detectar si estamos usando HPOS o sistema legacy
        if ($this->isHPOSEnabled()) {
            // Sistema HPOS
            $order = wc_get_order($order_id);
            if ($order) {
                $order->update_meta_data('_payment_status_sort', $sort_value);
                $order->update_meta_data('_payment_sort_updated', $current_time);
                $order->save_meta_data();
            }
        } else {
            // Sistema legacy
            update_post_meta($order_id, '_payment_status_sort', $sort_value);
            update_post_meta($order_id, '_payment_sort_updated', $current_time);
        }
    }

    /**
     * Verificar si HPOS está habilitado
     * 
     * @return bool
     */
    private function isHPOSEnabled(): bool
    {
        if (!class_exists('Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')) {
            return false;
        }
        
        try {
            return wc_get_container()->get('Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')->custom_orders_table_usage_is_enabled();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Obtener nombre del método de pago
     * 
     * @param string $payment_method ID del método de pago
     * @return string Nombre legible del método de pago
     */
    private function getPaymentMethodName(string $payment_method): string
    {
        // Intentar obtener el nombre desde WooCommerce primero
        $gateways = WC()->payment_gateways->payment_gateways();
        if (isset($gateways[$payment_method])) {
            return $gateways[$payment_method]->get_title();
        }
        
        // Fallback a nombres personalizados conocidos
        $payment_methods = [
            'redsys' => __('Redsys (Card)', 'neve-child'),
            'dm_pay_later_card' => __('DM Pay Later Card', 'neve-child'),
            'bacs' => __('Bank Transfer', 'neve-child'),
            'cheque' => __('Check', 'neve-child'),
            'cod' => __('Cash on Delivery', 'neve-child'),
            'paypal' => 'PayPal',
            'student_payment' => __('Individual Student Payments', 'neve-child'),
            '' => __('Not specified', 'neve-child')
        ];
        
        return $payment_methods[$payment_method] ?? ucfirst(str_replace('_', ' ', $payment_method));
    }

    /**
     * Determinar el tipo de transaction ID
     * 
     * @param string $transaction_id
     * @return string
     */
    private function getTransactionIdType(string $transaction_id): string
    {
        if (empty($transaction_id)) {
            return __('none', 'neve-child');
        }
        
        if (strpos($transaction_id, 'manual_bank_') === 0) {
            return 'manual_bank';
        }
        
        if (strpos($transaction_id, 'auto_') === 0) {
            return __('auto_generated', 'neve-child');
        }
        
        return __('other', 'neve-child');
    }

    /**
     * Verificar si un pedido es pagado por el centro
     * 
     * @param \WC_Order $order Objeto de pedido
     * @return bool True si el centro paga este pedido
     */
    private function isSchoolPaymentOrder(\WC_Order $order): bool
    {
        // 1. Verificar si es una master order (las master orders SÍ las paga el centro)
        if ($order->get_meta('_is_master_order')) {
            $school_id = $order->get_meta('_school_id');
            if (!empty($school_id)) {
                $the_billing_by_the_school = get_field('the_billing_by_the_school', 'user_' . $school_id);
                return ($the_billing_by_the_school === '1' || $the_billing_by_the_school === 1 || $the_billing_by_the_school === true);
            }
        }
        
        // 2. Para pedidos individuales, NUNCA cambiar método de pago
        // Los pedidos individuales los pagan los estudiantes con su método elegido (Redsys, Bizum, etc.)
        return false;
    }
}
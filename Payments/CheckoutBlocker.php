<?php
/**
 * Bloqueador de Checkout para WooCommerce
 * 
 * @package SchoolManagement\Payments
 * @since 1.0.0
 * @version 2.0.0
 * 
 * FUNCIONALIDADES PRINCIPALES:
 * 
 * 1. BLOQUEO INTELIGENTE DE CHECKOUT:
 *    - Verifica TODOS los pedidos con estado 'completed' del cliente
 *    - Bloquea el checkout si CUALQUIER pedido completado está sin pagar
 *    - Ignora pedidos sin pagar que no sean 'completed'
 *    - Redirige automáticamente al carrito con aviso específico
 * 
 * 2. GESTIÓN DE AVISOS:
 *    - Muestra enlaces directos a TODOS los pedidos completados sin pagar
 *    - Aviso sobre vaciado automático del carrito tras pago
 *    - Mensajes claros sobre pedidos completados pendientes
 * 
 * 3. VACIADO AUTOMÁTICO DEL CARRITO:
 *    - Vacía el carrito automáticamente tras completar pago pendiente
 *    - Evita confusión con productos duplicados
 *    - Permite al usuario empezar limpio después de pagar
 * 
 * 4. DETECCIÓN EXHAUSTIVA DE PEDIDOS COMPLETADOS:
 *    - Verifica TODOS los pedidos completados, no solo el último
 *    - Detecta transferencias bancarias completadas sin indicadores de pago manual
 *    - Lógica optimizada para pedidos ya procesados
 * 
 * FLUJO DE TRABAJO:
 * 1. Usuario intenta acceder al checkout
 * 2. Sistema busca TODOS los pedidos con estado 'completed'
 * 3. Verifica si ALGÚN pedido completado está sin pagar realmente
 * 4. Si hay CUALQUIER pedido completado sin pagar → bloquea checkout
 * 5. Si todos los pedidos completados están pagados → permite checkout
 * 6. Usuario paga TODOS los pedidos completados pendientes
 * 7. Sistema vacía automáticamente el carrito
 * 8. Usuario puede proceder con nueva compra
 * 
 * COMPATIBILIDAD:
 * - WooCommerce 3.0+
 * - WordPress 5.0+
 * - Funciona con cualquier método de pago
 * - Lógica consistente con BankTransferBulkActions
 */

namespace SchoolManagement\Payments;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bloqueador de Checkout para evitar pedidos múltiples sin pagar
 * 
 * Esta clase previene que los usuarios accedan al checkout cuando tienen
 * CUALQUIER pedido con estado 'completed' que no esté realmente pagado,
 * verificando exhaustivamente todos los pedidos completados para asegurar
 * que no haya ninguno pendiente de confirmación de pago, especialmente
 * transferencias bancarias completadas sin indicadores manuales.
 * 
 * CARACTERÍSTICAS PRINCIPALES:
 * - Verificación exhaustiva de TODOS los pedidos completados
 * - Bloquea si CUALQUIER pedido completado no está pagado
 * - Ignora pedidos sin pagar que no sean 'completed'
 * - Detección precisa de transferencias bancarias completadas sin pagar
 * - Redirección automática con mensajes informativos específicos
 * - Vaciado automático del carrito tras completar pagos
 * - Compatible con todos los métodos de pago de WooCommerce
 * 
 * USO:
 * La clase se auto-registra en WordPress hooks al instanciarse.
 * No requiere configuración adicional una vez incluida en el tema.
 * 
 * @since 1.0.0
 * @version 2.0.0
 */
class CheckoutBlocker
{
    /**
     * Número máximo de pedidos recientes a verificar para métodos legacy
     */
    private const MAX_RECENT_ORDERS = 4;

    /**
     * Constructor de la clase
     */
    public function __construct() {
        // Verificar si está desactivado temporalmente para debugging
        // Hooks principales
        add_action('template_redirect', [$this, 'checkUnpaidOrders']);
        add_action('woocommerce_thankyou', [$this, 'clearCartAfterPayment']);
        add_action('woocommerce_payment_complete', [$this, 'clearCartAfterPayment']);
        
        // HOOKS PARA SHORTCODES (carrito clásico) - USANDO SISTEMA NATIVO DE WOOCOMMERCE
        add_action('woocommerce_before_cart', [$this, 'showUnpaidOrdersNoticeInCart'], 5);
        add_action('woocommerce_before_checkout_form', [$this, 'showUnpaidOrdersNoticeInCheckout'], 5);
        
        // Hook para limpiar noticias viejas
        add_action('init', [$this, 'cleanOldNotices']);
    }

    /**
     * Verificar pedidos sin pagar de un usuario
     * 
     * @return void
     */
    public function checkUnpaidOrders(): void
    {
        // DEBUGGING: Log básico con información del usuario
        $debug_log = ABSPATH . 'checkout-blocker-debug.log';
        $user_id = get_current_user_id();
        $current_url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'unknown';
        
        file_put_contents($debug_log, 
            date('Y-m-d H:i:s') . " - INICIO VERIFICACIÓN CHECKOUT\n" .
            "Usuario ID: {$user_id}\n" .
            "URL actual: {$current_url}\n" .
            "Es checkout: " . (is_checkout() ? 'SÍ' : 'NO') . "\n" .
            "Es admin: " . (is_admin() ? 'SÍ' : 'NO') . "\n" .
            "Es order-pay: " . (is_wc_endpoint_url('order-pay') ? 'SÍ' : 'NO') . "\n" .
            "Es order-received: " . (is_wc_endpoint_url('order-received') ? 'SÍ' : 'NO') . "\n" .
            "Usuario logueado: " . (is_user_logged_in() ? 'SÍ' : 'NO') . "\n\n", 
            FILE_APPEND
        );
        
        // Solo aplicar en la página de checkout, pero NO en order-received o order-pay
        if (!is_checkout() || is_admin() || is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received')) {
            file_put_contents($debug_log, date('Y-m-d H:i:s') . " - SALIDA TEMPRANA: No es página de checkout válida\n\n", FILE_APPEND);
            return;
        }

        // Solo para usuarios logueados
        if (!is_user_logged_in()) {
            file_put_contents($debug_log, date('Y-m-d H:i:s') . " - SALIDA TEMPRANA: Usuario no logueado\n\n", FILE_APPEND);
            return;
        }

        
        try {
            $unpaid_completed_orders = $this->getAllCompletedOrdersIfUnpaid($user_id);
            
            // DEBUGGING DETALLADO: Mostrar información de los pedidos completados sin pagar
            file_put_contents($debug_log, 
                date('Y-m-d H:i:s') . " - RESULTADOS DE VERIFICACIÓN:\n" .
                "Pedidos completados sin pagar encontrados: " . count($unpaid_completed_orders) . "\n", 
                FILE_APPEND
            );
            
            if (!empty($unpaid_completed_orders)) {
                foreach ($unpaid_completed_orders as $order) {
                    file_put_contents($debug_log, 
                        "  → Pedido #" . $order->get_id() . " (" . $order->get_order_number() . ")\n" .
                        "    Estado: " . $order->get_status() . "\n" .
                        "    Método pago: " . $order->get_payment_method() . "\n" .
                        "    Transaction ID: '" . $order->get_transaction_id() . "'\n" .
                        "    Meta payment_date: '" . $order->get_meta('payment_date') . "'\n" .
                        "    Meta _dm_pay_later_card_payment_date: '" . $order->get_meta('_dm_pay_later_card_payment_date') . "'\n" .
                        "    Meta _order_marked_as_paid: '" . $order->get_meta('_order_marked_as_paid') . "'\n" .
                        "    Meta _is_master_order: '" . $order->get_meta('_is_master_order') . "'\n" .
                        "    Fecha creación: " . $order->get_date_created()->format('Y-m-d H:i:s') . "\n" .
                        "    Total: " . $order->get_total() . "\n\n", 
                        FILE_APPEND
                    );
                }
            }

            // Si hay pedidos completados sin pagar, bloquear acceso al checkout
            if (!empty($unpaid_completed_orders)) {
                file_put_contents($debug_log, date('Y-m-d H:i:s') . " - BLOQUEANDO CHECKOUT: Redirigiendo al carrito\n\n", FILE_APPEND);
                $this->redirectToCartWithNotice($unpaid_completed_orders);
            } else {
                file_put_contents($debug_log, date('Y-m-d H:i:s') . " - CHECKOUT PERMITIDO: No hay pedidos completados sin pagar\n\n", FILE_APPEND);
            }
        } catch (Exception $e) {
            // En caso de error, permitir el checkout para no bloquear al usuario
            file_put_contents($debug_log, 
                date('Y-m-d H:i:s') . " - ERROR EN VERIFICACIÓN: " . $e->getMessage() . "\n" .
                "Archivo: " . $e->getFile() . "\n" .
                "Línea: " . $e->getLine() . "\n\n", 
                FILE_APPEND
            );
            return;
        }
    }

    /**
     * MÉTODO MEJORADO: Mostrar notice en el carrito usando WooCommerce nativo
     * 
     * @return void
     */
    public function showUnpaidOrdersNoticeInCart(): void
    {
        static $already_executed = false;
        
        // Evitar ejecución múltiple
        if ($already_executed) {
            return;
        }
        
        $already_executed = true;
        
        // Verificar que WooCommerce está disponible
        if (!function_exists('wc_add_notice')) {
            return;
        }
        
        if (!is_user_logged_in()) {
            return;
        }

        $user_id = get_current_user_id();
        $unpaid_completed_orders = $this->getAllCompletedOrdersIfUnpaid($user_id);
        
        if (!empty($unpaid_completed_orders)) {
            // Crear mensaje simple y limpio para pedidos completados sin pagar
            $message = sprintf(
                __('You must complete the payment of %s before making a new purchase. After paying, your cart will be automatically emptied.', 'neve-child'),
                count($unpaid_completed_orders) === 1 ? __('your completed order', 'neve-child') : __('your completed orders', 'neve-child')
            );

            // Usar wc_add_notice() para que el tema maneje el styling nativo
            wc_add_notice($message, 'notice');
        }
    }

    /**
     * MÉTODO MEJORADO: Mostrar notice en el checkout usando WooCommerce nativo
     * 
     * @return void
     */
    public function showUnpaidOrdersNoticeInCheckout(): void
    {
        // Verificar que WooCommerce está disponible
        if (!function_exists('wc_add_notice')) {
            return;
        }
        
        if (!is_user_logged_in()) {
            return;
        }

        $user_id = get_current_user_id();
        $unpaid_completed_orders = $this->getAllCompletedOrdersIfUnpaid($user_id);
        
        if (!empty($unpaid_completed_orders)) {
            // Crear mensaje estructurado usando sistema nativo de WooCommerce para pedidos completados
            $order_links = [];
            foreach ($unpaid_completed_orders as $order) {
                $pay_url = $order->get_checkout_payment_url();
                $order_number = $order->get_order_number();
                $order_total = $order->get_formatted_order_total();
                $payment_method = $order->get_payment_method_title();
                
                $order_links[] = sprintf(
                    '<a href="%s" class="button wc-forward" target="_blank" style="margin: 0 8px 8px 0; display: inline-block; white-space: nowrap;">%s</a>',
                    esc_url($pay_url),
                    sprintf(
                        __('Pay Completed Order #%s (%s) - %s', 'neve-child'),
                        esc_html($order_number),
                        wp_strip_all_tags($order_total),
                        esc_html($payment_method)
                    )
                );
            }

            // Crear mensaje utilizando el sistema nativo de WooCommerce - ESTRUCTURA MEJORADA
            $message = sprintf(
                '<strong>⚠️ %s</strong><br>' .
                __('You have %s with Pending Payment. Please complete the payment to proceed:', 'neve-child') . '<br><br>' .
                '<div style="margin: 15px 0; line-height: 1.5;">%s</div>' .
                '<small><em>%s</em></small>',
                __('You cannot proceed to checkout', 'neve-child'),
                count($unpaid_completed_orders) === 1 ? __('one completed order', 'neve-child') : sprintf(__('%d completed orders', 'neve-child'), count($unpaid_completed_orders)),
                implode(' ', $order_links),
                __('Note: After completing the payment, your current cart will be automatically emptied.', 'neve-child')
            );

            // Usar wc_add_notice() como error para destacar más en checkout
            wc_add_notice($message, 'error');
        }
    }

    /**
     * MÉTODO CORREGIDO: Obtener TODOS los pedidos completados sin pagar
     * Verifica que TODOS los pedidos completados estén pagados, no solo el último
     * 
     * @param int $user_id ID del usuario
     * @return array Array de pedidos completados sin pagar
     */
    private function getAllCompletedOrdersIfUnpaid(int $user_id): array
    {
        $debug_log = ABSPATH . 'checkout-blocker-debug.log';
        
        // Obtener TODOS los pedidos completados del usuario (sin límite)
        $completed_orders = wc_get_orders([
            'customer_id' => $user_id,
            'limit' => -1, // Sin límite - todos los pedidos completados
            'orderby' => 'date',
            'order' => 'DESC',
            'status' => ['completed'],
        ]);

        file_put_contents($debug_log, 
            date('Y-m-d H:i:s') . " - BÚSQUEDA DE PEDIDOS COMPLETADOS:\n" .
            "Usuario ID: {$user_id}\n" .
            "Pedidos completados encontrados: " . count($completed_orders) . "\n", 
            FILE_APPEND
        );

        // Si no hay pedidos completados, no hay nada que verificar
        if (empty($completed_orders)) {
            file_put_contents($debug_log, date('Y-m-d H:i:s') . " - No hay pedidos completados\n\n", FILE_APPEND);
            return [];
        }

        $unpaid_completed_orders = [];

        // Verificar CADA pedido completado para ver si necesita pago
        foreach ($completed_orders as $order) {
            $is_master_order = $order->get_meta('_is_master_order') === 'yes';
            $is_child_order = !empty($order->get_meta('_master_order_id'));
            $order_school_id = $order->get_meta(\SchoolManagement\Shared\Constants::ORDER_META_SCHOOL_ID);
            
            // Determinar si el centro paga
            $school_pays = false;
            if ($order_school_id) {
                $billing_field = get_field(\SchoolManagement\Shared\Constants::ACF_FIELD_SCHOOL_BILLING, $order_school_id);
                // Lógica invertida: si the_billing_by_the_school = true, el colegio NO paga (padres pagan)
                $school_pays = ($billing_field === false || $billing_field === '0' || $billing_field === 0);
            }
            
            file_put_contents($debug_log, 
                "  → Analizando pedido #" . $order->get_id() . " (" . $order->get_order_number() . "):\n" .
                "    Estado: " . $order->get_status() . "\n" .
                "    Método pago: " . $order->get_payment_method() . "\n" .
                "    Es pedido maestro: " . ($is_master_order ? 'SÍ' : 'NO') . "\n" .
                "    Es pedido hijo: " . ($is_child_order ? 'SÍ' : 'NO') . "\n" .
                "    ID del colegio: $order_school_id\n" .
                "    ¿El centro paga?: " . ($school_pays ? 'SÍ' : 'NO') . "\n",
                FILE_APPEND
            );
            
            // NUEVA LÓGICA: Filtrar según quién paga
            $should_check_order = false;
            
            if ($school_pays) {
                // Si el centro paga, solo considerar pedidos maestros
                if ($is_master_order) {
                    $should_check_order = true;
                    file_put_contents($debug_log, "    → VERIFICANDO: Centro paga y es pedido maestro\n", FILE_APPEND);
                } else {
                    file_put_contents($debug_log, "    → SALTANDO: Centro paga pero es pedido individual (se maneja via maestro)\n", FILE_APPEND);
                }
            } else {
                // Si los padres pagan, considerar pedidos individuales (no maestros)
                if (!$is_master_order) {
                    $should_check_order = true;
                    file_put_contents($debug_log, "    → VERIFICANDO: Padres pagan y es pedido individual\n", FILE_APPEND);
                } else {
                    file_put_contents($debug_log, "    → SALTANDO: Padres pagan pero es pedido maestro\n", FILE_APPEND);
                }
            }
            
            if (!$should_check_order) {
                continue; // Saltar este pedido
            }
            
            $needs_payment = $this->orderNeedsPaymentAdvanced($order);
            
            file_put_contents($debug_log, 
                "    ¿Necesita pago?: " . ($needs_payment ? 'SÍ' : 'NO') . "\n" .
                "    Transaction ID: '" . $order->get_transaction_id() . "'\n" .
                "    Meta payment_date: '" . $order->get_meta('payment_date') . "'\n" .
                "    Meta _dm_pay_later_card_payment_date: '" . $order->get_meta('_dm_pay_later_card_payment_date') . "'\n" .
                "    Meta _order_marked_as_paid: '" . $order->get_meta('_order_marked_as_paid') . "'\n",
                FILE_APPEND
            );
            
            if ($needs_payment) {
                $unpaid_completed_orders[] = $order;
                file_put_contents($debug_log, "    → AGREGADO a lista de pedidos sin pagar\n", FILE_APPEND);
            } else {
                file_put_contents($debug_log, "    → NO agregado: está pagado\n", FILE_APPEND);
            }
        }

        file_put_contents($debug_log, 
            "\n" . date('Y-m-d H:i:s') . " - RESUMEN FINAL:\n" .
            "Total pedidos completados sin pagar: " . count($unpaid_completed_orders) . "\n\n",
            FILE_APPEND
        );

        return $unpaid_completed_orders;
    }

    /**
     * MÉTODO LEGACY: Obtener pedidos sin pagar de los últimos pedidos del usuario
     * Mantenido para compatibilidad con otros métodos de la clase
     * 
     * @param int $user_id ID del usuario
     * @return array Array de pedidos sin pagar
     */
    private function getUnpaidOrdersFromRecentOrders(int $user_id): array
    {
        // Obtener los últimos pedidos del usuario - AMPLIAMOS EL RANGO DE ESTADOS
        $recent_orders = wc_get_orders([
            'customer_id' => $user_id,
            'limit' => self::MAX_RECENT_ORDERS,
            'orderby' => 'date',
            'order' => 'DESC',
            'status' => ['pending', 'on-hold', 'processing', 'failed', 'cancelled', 'completed'],
        ]);

        $unpaid_orders = [];

        foreach ($recent_orders as $order) {
            // NUEVA LÓGICA: Verificar si el pedido necesita pago usando múltiples métodos
            if ($this->orderNeedsPaymentAdvanced($order)) {
                $unpaid_orders[] = $order;
            }
        }

        return $unpaid_orders;
    }

    /**
     * NUEVO MÉTODO: Verificación avanzada si un pedido necesita pago
     * Utiliza lógica simplificada sin dependencias circulares
     * 
     * @param \WC_Order $order Objeto de pedido
     * @return bool True si necesita pago, false en caso contrario
     */
    private function orderNeedsPaymentAdvanced(\WC_Order $order): bool
    {
        $debug_log = ABSPATH . 'checkout-blocker-debug.log';
        $order_id = $order->get_id();
        $payment_method = $order->get_payment_method();
        
        file_put_contents($debug_log, 
            "    ANÁLISIS DETALLADO DEL PEDIDO #$order_id:\n" .
            "    Método de pago: $payment_method\n",
            FILE_APPEND
        );
        
        // Usar lógica unificada: verificar solo indicadores confiables de pago
        $payment_date = $order->get_meta('payment_date');
        $deferred_payment_date = $order->get_meta('_dm_pay_later_card_payment_date');
        $marked_as_paid = $order->get_meta('_order_marked_as_paid');
        
        file_put_contents($debug_log, 
            "    payment_date: '$payment_date'\n" .
            "    _dm_pay_later_card_payment_date: '$deferred_payment_date'\n" .
            "    _order_marked_as_paid: '$marked_as_paid'\n",
            FILE_APPEND
        );
        
        // Verificar transaction_id solo si no es auto-generado (no contiene "order")
        $transaction_id = $order->get_transaction_id();
        $reliable_transaction = !empty($transaction_id) && stripos($transaction_id, 'order') === false;
        
        file_put_contents($debug_log, 
            "    transaction_id: '$transaction_id'\n" .
            "    ¿Transaction ID confiable?: " . ($reliable_transaction ? 'SÍ' : 'NO') . "\n",
            FILE_APPEND
        );
        
        // NUEVA LÓGICA: Verificar el meta _order_marked_as_paid
        if ($marked_as_paid === 'yes' || $marked_as_paid === '1' || $marked_as_paid === 1) {
            file_put_contents($debug_log, 
                "    → RESULTADO: NO necesita pago (marcado como pagado manualmente)\n",
                FILE_APPEND
            );
            return false;
        }
        
        // Si tiene indicadores confiables de pago, NO necesita pago
        if (!empty($payment_date) || !empty($deferred_payment_date) || $reliable_transaction) {
            file_put_contents($debug_log, 
                "    → RESULTADO: NO necesita pago (tiene indicadores confiables)\n",
                FILE_APPEND
            );
            return false;
        }
        
        // Verificación específica para transferencias bancarias
        if ($payment_method === 'bacs') {
            $needs_payment = $this->bankTransferNeedsPayment($order);
            file_put_contents($debug_log, 
                "    → RESULTADO: " . ($needs_payment ? 'SÍ' : 'NO') . " necesita pago (transferencia bancaria)\n",
                FILE_APPEND
            );
            return $needs_payment;
        }

        // Para otros métodos, sin indicadores confiables necesita pago
        file_put_contents($debug_log, 
            "    → RESULTADO: SÍ necesita pago (sin indicadores confiables de pago)\n",
            FILE_APPEND
        );
        return true;
    }

    /**
     * NUEVO MÉTODO: Verificación específica para transferencias bancarias
     * Replica la lógica de BankTransferBulkActions para detectar transferencias sin pagar
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
     * Redirigir al carrito con aviso sobre pedidos completados sin pagar
     * 
     * @param array $unpaid_completed_orders Array con pedidos completados sin pagar
     * @return void
     */
    private function redirectToCartWithNotice(array $unpaid_completed_orders): void
    {
        // DEBUGGING: Log para diagnosticar problemas
        
        // Verificar que WooCommerce esté disponible
        if (!function_exists('wc_get_cart_url')) {
            return;
        }
        
        // Prevenir redirecciones infinitas
        $current_url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $cart_url = wc_get_cart_url();
        $cart_path = parse_url($cart_url, PHP_URL_PATH);
        
        if (strpos($current_url, $cart_path) !== false) {
            return;
        }
        
        // Crear mensaje para el usuario - adaptado para múltiples pedidos completados
        $order_links = [];
        foreach ($unpaid_completed_orders as $order) {
            try {
                $pay_url = $order->get_checkout_payment_url();
                $order_number = $order->get_order_number();
                $order_total = $order->get_formatted_order_total();
                $payment_method = $order->get_payment_method_title();
                
                $order_links[] = sprintf(
                    '<a href="%s" class="button wc-forward" target="_blank">%s</a>',
                    esc_url($pay_url),
                    sprintf(
                        __('Pay Completed Order #%s (%s) - %s', 'neve-child'),
                        esc_html($order_number),
                        $order_total,
                        esc_html($payment_method)
                    )
                );
            } catch (Exception $e) {
            }
        }

        $message = sprintf(
            '<strong>%s</strong><br><br>' .
            __('You have %s with Pending Payment. Please complete the payment before making a new purchase:', 'neve-child') . '<br><br>%s<br><br>' .
            '<em>%s</em>',
            __('You cannot proceed to checkout.', 'neve-child'),
            count($unpaid_completed_orders) === 1 ? __('one completed order', 'neve-child') : sprintf(__('%d completed orders', 'neve-child'), count($unpaid_completed_orders)),
            implode('<br>', $order_links),
            __('Note: After completing the payment, your current cart will be automatically emptied to avoid confusion.', 'neve-child')
        );

        // Guardar el mensaje en la sesión con timestamp
        $this->setSessionNotice($message, 'error');

        // Verificar que la URL del carrito sea válida y segura
        $cart_url = wc_get_cart_url();
        if (empty($cart_url) || !wp_http_validate_url($cart_url)) {
            wp_die('Error interno: No se puede acceder al carrito. Contacta al administrador.', 'Error de Redirección');
            return;
        }
        
        
        // Usar wp_safe_redirect con verificación adicional
        $redirect_result = wp_safe_redirect($cart_url);
        
        if (!$redirect_result) {
            // Fallback: mostrar mensaje y enlace manual
            wp_die(
                $message . '<br><br><a href="' . esc_url($cart_url) . '">Ir al carrito manualmente</a>',
                'Redirección al Carrito',
                ['back_link' => true]
            );
        }
        
        exit;
    }

    /**
     * Establecer un notice en la sesión de WooCommerce
     * 
     * @param string $message Mensaje del notice
     * @param string $type Tipo de notice ('error', 'success', 'notice')
     * @return void
     */
    private function setSessionNotice(string $message, string $type = 'notice'): void
    {
        if (!function_exists('WC') || !WC() || !WC()->session) {
            return;
        }

        $notices = WC()->session->get('checkout_blocker_notices', []);
        $notice_data = [
            'message' => $message,
            'type' => $type,
            'timestamp' => time()
        ];
        
        $notices[] = $notice_data;

        WC()->session->set('checkout_blocker_notices', $notices);
    }

    /**
     * Vaciar carrito después de completar un pago
     * 
     * @param int $order_id ID del pedido que se pagó
     * @return void
     */
    public function clearCartAfterPayment(int $order_id): void
    {
        $this->clearCartIfUserHasSession($order_id);
    }

    /**
     * Vaciar carrito después de que un pedido se marque como completado
     * 
     * @param int $order_id ID del pedido completado
     * @return void
     */
    public function clearCartAfterOrderCompleted(int $order_id): void
    {
        $this->clearCartIfUserHasSession($order_id);
    }

    /**
     * Vaciar carrito después de que un pedido se marque como processing
     * 
     * @param int $order_id ID del pedido en processing
     * @return void
     */
    public function clearCartAfterOrderProcessing(int $order_id): void
    {
        $this->clearCartIfUserHasSession($order_id);
    }

    /**
     * Vaciar carrito si el usuario tiene sesión activa
     * 
     * @param int $order_id ID del pedido
     * @return void
     */
    private function clearCartIfUserHasSession(int $order_id): void
    {
        // Verificar que WooCommerce esté disponible y hay una sesión
        if (!WC() || !WC()->cart || !WC()->session) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Solo vaciar si el usuario del pedido coincide con el usuario de la sesión actual
        $current_user_id = get_current_user_id();
        $order_user_id = $order->get_user_id();

        // Para usuarios invitados, verificar por email si está disponible
        if ($current_user_id === 0 && $order_user_id === 0) {
            // Ambos son invitados, no podemos verificar de forma segura
            return;
        }

        if ($current_user_id !== $order_user_id) {
            return;
        }

        // Verificar que el carrito no esté vacío
        if (WC()->cart->is_empty()) {
            return;
        }

        // Vaciar el carrito
        WC()->cart->empty_cart();

        // Establecer un mensaje de confirmación usando nuestro sistema de notices
        $success_message = __('Your cart has been automatically emptied after completing the payment of your previous order. You can proceed with a new purchase.', 'neve-child');
        $this->setSessionNotice($success_message, 'success');
    }

    /**
     * MÉTODO ACTUALIZADO: Verificar si un pedido específico necesita pago
     * Ahora utiliza la lógica avanzada
     * 
     * @param int $order_id ID del pedido
     * @return bool True si necesita pago, false en caso contrario
     */
    public function orderNeedsPayment(int $order_id): bool
    {
        $order = wc_get_order($order_id);
        return $order ? $this->orderNeedsPaymentAdvanced($order) : false;
    }

    /**
     * MÉTODO ACTUALIZADO: Obtener todos los pedidos sin pagar de un usuario
     * Ahora utiliza la lógica avanzada
     * 
     * @param int $user_id ID del usuario
     * @return array Array de pedidos sin pagar
     */
    public function getAllUnpaidOrders(int $user_id): array
    {
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'status' => ['pending', 'on-hold', 'failed', 'processing', 'completed'],
            'limit' => -1,
        ]);

        $unpaid_orders = [];
        foreach ($orders as $order) {
            if ($this->orderNeedsPaymentAdvanced($order)) {
                $unpaid_orders[] = $order;
            }
        }

        return $unpaid_orders;
    }

    /**
     * Verificar si el usuario puede acceder al checkout
     * Ahora verifica únicamente si el último pedido completado está sin pagar
     * 
     * @param int $user_id ID del usuario (opcional, usa el usuario actual si no se proporciona)
     * @return bool True si puede acceder, false en caso contrario
     */
    public function canUserAccessCheckout(int $user_id = 0): bool
    {
        if ($user_id === 0) {
            if (!is_user_logged_in()) {
                return true; // Los usuarios invitados pueden acceder
            }
            $user_id = get_current_user_id();
        }

        $unpaid_completed_orders = $this->getAllCompletedOrdersIfUnpaid($user_id);
        return empty($unpaid_completed_orders); // Puede acceder si no hay pedidos completados sin pagar
    }

    /**
     * Limpiar notices antiguos de la sesión
     * 
     * @return void
     */
    public function cleanOldNotices(): void
    {
        if (!function_exists('WC') || !WC() || !WC()->session) {
            return;
        }

        // Limpiar notices de checkout blocker que tengan más de 5 minutos
        $notices = WC()->session->get('checkout_blocker_notices', []);
        $cleaned_notices = [];
        
        foreach ($notices as $notice) {
            if (time() - $notice['timestamp'] < 300) { // 5 minutos
                $cleaned_notices[] = $notice;
            }
        }
        
        if (count($cleaned_notices) !== count($notices)) {
            WC()->session->set('checkout_blocker_notices', $cleaned_notices);
        }

        // También limpiar el array de notices mostrados si la sesión es muy antigua
        $shown_notices = WC()->session->get('shown_unpaid_notices', []);
        if (!empty($shown_notices)) {
            // Resetear cada hora
            $last_reset = WC()->session->get('unpaid_notices_last_reset', 0);
            if (time() - $last_reset > 3600) {
                WC()->session->set('shown_unpaid_notices', []);
                WC()->session->set('unpaid_notices_last_reset', time());
            }
        }
    }

}

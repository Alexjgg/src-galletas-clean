<?php
/**
 * Master Order Manager - SOLUCIÓN DEFINITIVA Y ATÓMICA
 * 
 * GARANTÍA ABSOLUTA: Solo un pedido maestro por escuela, sin condiciones de carrera
 * 
 * ESTRATEGIA:
 * 1. Tabla única por escuela con UNIQUE KEY
 * 2. INSERT ... ON DUPLICATE KEY UPDATE para atomicidad total
 * 3. Búsqueda directa sin ventanas de tiempo
 * 4. Locks solo para operaciones críticas
 * 
 * @package SchoolManagement\Orders
 * @since 4.0.0 - Solución Definitiva
 */

namespace SchoolManagement\Orders;

if (!defined('ABSPATH')) {
    exit;
}

class MasterOrderManager
{
    private const MASTER_ORDER_STATUS = 'master-order';
    private const MASTER_ORDER_COMPLETE_STATUS = 'mast-ordr-cpl';
    
    /**
     * ORDEN DE PROGRESIÓN DE ESTADOS DE PEDIDOS MAESTROS
     * 
     * REGLAS DE TRANSICIÓN:
     * - master-order: Solo puede ir a mast-warehs
     * - mast-warehs ↔ mast-prepared: Movimiento libre entre almacén y preparado
     * - mast-complete: Estado final, no puede cambiar
     */
    private const MASTER_ORDER_STATUS_PROGRESSION = [
        'master-order'   => 0,   // Estado inicial - validado (solo avanza)
        'mast-warehs'    => 1,   // Almacén (↔ preparado)
        'mast-prepared'  => 2,   // Preparado (↔ almacén)  
        'mast-complete'  => 3    // Completo (final, inmutable)
    ];
    
    // Variables estáticas para rastrear timing de bulk actions
    private static $bulk_action_start_time = null;
    private static $bulk_action_order_count = 0;
    
    // Variable para prevenir doble procesamiento en la misma request
    private static $processed_orders_in_request = [];
    
    // Variable para rastrear las master orders tocadas en esta bulk action
    private static $touched_master_orders = [];
    
    public function __construct()
    {
        $this->initHooks();
        $this->createTables();
    }

    /**
     * Crear tablas necesarias para atomicidad
     * MEJORADO: Constraints adicionales para prevenir duplicados
     */
    private function createTables(): void
    {
        global $wpdb;
        
        // Tabla para garantizar un solo pedido maestro por escuela
        $table_name = $wpdb->prefix . 'school_master_orders';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            school_id bigint(20) unsigned NOT NULL,
            master_order_id bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            is_active tinyint(1) DEFAULT 1,
            process_lock varchar(32) DEFAULT NULL COMMENT 'Lock temporal para prevenir duplicados',
            PRIMARY KEY (school_id),
            UNIQUE KEY unique_school (school_id),
            UNIQUE KEY unique_active_school (school_id, is_active) COMMENT 'Solo una master activa por escuela',
            KEY master_order_id (master_order_id),
            KEY active_orders (is_active, created_at),
            KEY process_locks (process_lock, created_at)
        ) {$wpdb->get_charset_collate()};";
        
        $wpdb->query($sql);
        
        // NOTA: La restricción de "solo una master activa por escuela" se maneja con:
        // UNIQUE KEY unique_active_school (school_id, is_active) en la definición de tabla
        // Esto es compatible con todas las versiones de MySQL
        
    }

    /**
     * Initialize WordPress hooks
     */
    public function initHooks(): void
    {
        add_action('init', [$this, 'registerMasterOrderStatuses']);
        add_filter('wc_order_statuses', [$this, 'addMasterOrderStatuses']);
        add_action('admin_notices', [$this, 'showNotices']);
        add_action('add_meta_boxes', [$this, 'addOrderRelationshipsMetabox']);
        
        // Hook principal para procesar pedidos revisados
        add_action('woocommerce_order_status_changed', [$this, 'handleOrderStatusChange'], 10, 4);
        add_action('woocommerce_order_status_master-order', [$this, 'handleMasterOrderStatusChange'], 10, 2);
        add_action('woocommerce_order_status_master-order-complete', [$this, 'handleMasterOrderCompleteStatusChange'], 10, 2);
        
        // Hooks para bulk actions (legacy y HPOS)
        add_filter('bulk_actions-edit-shop_order', [$this, 'addBulkActions']);
        add_filter('bulk_actions-woocommerce_page_wc-orders', [$this, 'addBulkActions']);
        add_filter('handle_bulk_actions-edit-shop_order', [$this, 'handleBulkActions'], 10, 3);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [$this, 'handleBulkActions'], 10, 3);
        add_action('admin_notices', [$this, 'showBulkActionNotices']);
        
        // Hook para interceptar completed en pedidos maestros (prioridad muy alta)
        add_action('woocommerce_order_status_completed', [$this, 'interceptMasterOrderCompleted'], 1, 2);
        
        // Hook adicional para interceptar cambios de estado ANTES de que se guarden
        add_filter('woocommerce_order_get_status', [$this, 'filterMasterOrderStatus'], 10, 2);
        add_action('woocommerce_order_status_changed', [$this, 'interceptStatusChange'], 1, 4);
        
        // Hooks adicionales para asegurar visibilidad de estados
        add_filter('woocommerce_reports_order_statuses', [$this, 'addMasterOrderStatuses']);
        // REMOVIDO: add_filter('woocommerce_order_is_paid_statuses', [$this, 'addPaidStatuses']);
        // El estado de pago se maneja ahora a través del sistema PaymentStatusColumn y OrderManager
        
        // CRÍTICO: Interceptar el filtro directo de is_paid() para master orders
        add_filter('woocommerce_order_is_paid', [$this, 'filterMasterOrderIsPaid'], 10, 2);
        

    }

    /**
     * Maneja cambios de estado de pedidos - VERSIÓN DEFINITIVA
     */
    public function handleOrderStatusChange(int $order_id, string $old_status, string $new_status, $order): void
    {
        // PROTECCIÓN: Evitar procesar el mismo pedido múltiples veces en la misma request
        if (isset(self::$processed_orders_in_request[$order_id])) {
            return;
        }
        
        // Iniciar timing de bulk action en el primer pedido que cambia a reviewed
        if ($new_status === 'reviewed' && self::$bulk_action_start_time === null) {
            self::$bulk_action_start_time = microtime(true);
            self::$bulk_action_order_count = 0;
            self::$processed_orders_in_request = []; // Reset del array de procesados
        }
        
        // NUEVA LÓGICA: Manejar cuando un pedido sale del estado "reviewed"
        if ($old_status === 'reviewed' && $new_status !== 'reviewed') {
            $this->handleOrderRemovedFromReviewed($order_id, $order);
            return;
        }

        // Solo procesar cuando cambia a "revisado"
        if ($new_status !== 'reviewed') {
            return;
        }

        // PROTECCIÓN CRÍTICA: Solo procesar cambios autorizados a 'reviewed'
        // Solo permitir processing → reviewed para crear master orders
        $allowed_previous_statuses = ['processing'];
        if (!in_array($old_status, $allowed_previous_statuses)) {
            return;
        }

        // No procesar pedidos maestros
        if ($this->isMasterOrder($order_id)) {
            return;
        }

        // Verificar que tenga school_id
        $school_id = $order->get_meta('_school_id');
        if (empty($school_id)) {
            return;
        }

        // Verificar que no esté ya procesado - PROTECCIÓN MEJORADA
        $existing_master = $order->get_meta('_master_order_id');
        if ($existing_master) {
            // Verificar si el pedido maestro sigue siendo válido
            $master_order_check = wc_get_order($existing_master);
            if ($master_order_check && $master_order_check->get_status() === 'master-order') {
                return;
            } else {
                $order->delete_meta_data('_master_order_id');
                $order->delete_meta_data('_added_to_master_at');
                $order->save();
            }
        }

        // Marcar como procesado ANTES de procesar
        self::$processed_orders_in_request[$order_id] = true;
        
        // Incrementar contador
        self::$bulk_action_order_count++;

        // Procesar pedido de forma completamente atómica
        $this->processOrderAtomic($order_id, $school_id);
        
        // Validación final en shutdown hook
        if (self::$bulk_action_order_count === 1) {
            add_action('shutdown', function() {
                if (self::$bulk_action_start_time !== null) {
                    // VALIDACIÓN FINAL: Verificar SOLO las master orders tocadas
                    $this->finalValidationAndCleanup();
                    
                    // Reset para la próxima bulk action
                    self::$bulk_action_start_time = null;
                    self::$bulk_action_order_count = 0;
                    self::$processed_orders_in_request = [];
                    self::$touched_master_orders = [];
                }
            }, 999);
        }
    }

    /**
     * Procesa pedido de forma completamente atómica - MÉTODO PRINCIPAL
     * MEJORADO: Con lock de aplicación para prevenir procesamiento simultáneo
     */
    private function processOrderAtomic(int $order_id, int $school_id): void
    {
        global $wpdb;
        
        // NUEVA PROTECCIÓN: Verificar estado del pedido una vez más antes de procesar
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        $current_status = $order->get_status();
        $allowed_statuses = ['processing', 'reviewed'];
        
        if (!in_array($current_status, $allowed_statuses)) {
            return;
        }
        
        // PROTECCIÓN CRÍTICA: Lock de aplicación por escuela
        $lock_name = "master_order_school_{$school_id}";
        $lock_timeout = 10; // 10 segundos máximo
        
        // Intentar obtener el lock
        $lock_acquired = $wpdb->get_var($wpdb->prepare(
            "SELECT GET_LOCK(%s, %d)",
            $lock_name, $lock_timeout
        ));
        
        if ($lock_acquired != 1) {
            return;
        }
        
        try {
            // PASO 1: Obtener pedido maestro de forma atómica
            $master_order_id = $this->getOrCreateMasterOrderAtomic($school_id);
            
            if (!$master_order_id) {
                return;
            }
            
            // Registrar esta master order como "tocada" en esta bulk action
            if (!in_array($master_order_id, self::$touched_master_orders)) {
                self::$touched_master_orders[] = $master_order_id;
            }

            // PASO 2: Agregar pedido al pedido maestro
            $success = $this->addOrderToMasterOrder($order_id, $master_order_id);
            
            if ($success) {
                $this->createNotification($order_id, $master_order_id, $school_id);
            }
            
        } finally {
            // CRÍTICO: Siempre liberar el lock
            $wpdb->query($wpdb->prepare(
                "SELECT RELEASE_LOCK(%s)",
                $lock_name
            ));
        }
    }

    /**
     * Obtener o crear pedido maestro de forma completamente atómica
     * GARANTÍA: Solo un pedido maestro por escuela, sin condiciones de carrera
     * MEJORADO: Uso de transacciones DB y locks para prevenir duplicados
     */
    private function getOrCreateMasterOrderAtomic(int $school_id): ?int
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'school_master_orders';
        
        // PROTECCIÓN CRÍTICA: Usar transacción con lock para evitar condiciones de carrera
        $wpdb->query('START TRANSACTION');
        
        // PASO 1: Lock de la fila específica para esta escuela (previene duplicados)
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT master_order_id FROM $table_name WHERE school_id = %d AND is_active = 1 FOR UPDATE",
            $school_id
        ));
        
        if ($existing) {
            $master_order_id = (int) $existing->master_order_id;
            
            // Verificar que el pedido maestro sigue siendo válido Y no esté completo
            $order = wc_get_order($master_order_id);
            if ($order && $order->get_status() === 'master-order') {
                $wpdb->query('COMMIT'); // Liberar el lock
                return $master_order_id;
            } elseif ($order && $order->get_status() === 'master-order-complete') {
                // Marcar como inactivo porque está completo
                $wpdb->update(
                    $table_name,
                    ['is_active' => 0],
                    ['school_id' => $school_id, 'master_order_id' => $master_order_id],
                    ['%d'],
                    ['%d', '%d']
                );
            } else {
                // Marcar como inactivo (pedido no válido)
                $wpdb->update(
                    $table_name,
                    ['is_active' => 0],
                    ['school_id' => $school_id, 'master_order_id' => $master_order_id],
                    ['%d'],
                    ['%d', '%d']
                );
            }
        }
        
        // PASO 2: Crear nuevo pedido maestro DENTRO de la transacción
        
        // Reservar el slot ANTES de crear el pedido WooCommerce
        $reservation_result = $wpdb->query($wpdb->prepare(
            "INSERT INTO $table_name (school_id, master_order_id, is_active, created_at, updated_at) 
             VALUES (%d, %d, 1, NOW(), NOW()) 
             ON DUPLICATE KEY UPDATE 
                master_order_id = IF(is_active = 0, %d, master_order_id),
                is_active = IF(is_active = 0, 1, is_active),
                updated_at = NOW()",
            $school_id, 0, 0  // Usar 0 temporalmente como placeholder
        ));
        
        if ($reservation_result === 0 || $wpdb->rows_affected === 0) {
            // Ya existe una master order activa para esta escuela
            $wpdb->query('ROLLBACK');
            
            // Obtener el pedido maestro existente (fuera de la transacción)
            $winner = $wpdb->get_row($wpdb->prepare(
                "SELECT master_order_id FROM $table_name WHERE school_id = %d AND is_active = 1",
                $school_id
            ));
            
            return $winner ? (int) $winner->master_order_id : null;
        }
        
        // PASO 3: Crear el pedido WooCommerce DESPUÉS de reservar el slot
        $new_master_order_id = $this->createNewMasterOrder($school_id);
        if (!$new_master_order_id) {
            // Si falla la creación, liberar la reserva
            $wpdb->query('ROLLBACK');
            return null;
        }
        
        // PASO 4: Actualizar la reserva con el ID real del pedido
        $update_result = $wpdb->update(
            $table_name,
            ['master_order_id' => $new_master_order_id, 'updated_at' => current_time('mysql')],
            ['school_id' => $school_id, 'master_order_id' => 0, 'is_active' => 1],
            ['%d', '%s'],
            ['%d', '%d', '%d']
        );
        
        if ($update_result === false || $update_result === 0) {
            // Algo salió mal, limpiar
            wp_delete_post($new_master_order_id, true);
            $wpdb->query('ROLLBACK');
            return null;
        }
        
        // ÉXITO: Confirmar transacción
        $wpdb->query('COMMIT');
        
        return $new_master_order_id;
    }

    /**
     * Crear nuevo pedido maestro WooCommerce
     */
    private function createNewMasterOrder(int $school_id): ?int
    {
        $school = get_post($school_id);
        if (!$school) {
            return null;
        }

        // TODAS las master orders se crean con el mismo estado inicial
        $initial_status = self::MASTER_ORDER_STATUS;

        // Crear pedido maestro
        $master_order = wc_create_order([
            'status' => $initial_status,
            'customer_id' => 0,
            'created_via' => 'auto-master-order-atomic'
        ]);

        if (is_wp_error($master_order)) {
            return null;
        }

        $master_order_id = $master_order->get_id();

        // Configurar metadatos
        $master_order->update_meta_data('_is_master_order', 'yes');
        $master_order->update_meta_data('_school_id', $school_id);
        $master_order->update_meta_data('_school_name', $school->post_title);
        $master_order->update_meta_data('_master_order_created', current_time('mysql'));
        
        // Configurar dirección de facturación con datos de la escuela
        $this->setMasterOrderBillingAddress($master_order, $school);
        
        // NUEVO: Configurar método de pago según si el centro paga directamente
        $school_pays = $this->schoolPaysDirectly($school_id);
        if ($school_pays) {
            // El centro paga: configurar como transferencia bancaria para requerir confirmación manual
            $master_order->set_payment_method('bacs');
            $master_order->set_payment_method_title(__('Bank Transfer - School Payment', 'neve-child'));
            
            // CRÍTICO: Asegurarse de que aparezca como NO pagada por defecto
            // No establecer payment_date ni transaction_id para que requiera confirmación manual
            $master_order->delete_meta_data('payment_date');
            $master_order->delete_meta_data('_dm_pay_later_card_payment_date');
            $master_order->set_transaction_id(''); // Sin transaction ID = no pagado
            
            // Agregar nota explicativa (mantener estado master-order)
            $master_order->add_order_note(
                __('Master validated order created for school direct payment. Requires manual payment confirmation like bank transfers. Status: NOT PAID by default.', 'neve-child')
            );
        } else {
            // Escuelas que NO pagan directamente (pagos individuales de estudiantes)
            // Configurar como pagada automáticamente porque los estudiantes pagan individualmente
            $master_order->set_payment_method('student_payment');
            $master_order->set_payment_method_title(__('Individual Student Payments', 'neve-child'));
            
            // Establecer indicadores de pago para que aparezca como pagada
            $current_time = current_time('Y-m-d H:i:s');
            $master_order->update_meta_data('payment_date', $current_time);
            $master_order->set_transaction_id('auto_student_payment_' . $master_order_id);
            
            // Agregar nota explicativa
            $master_order->add_order_note(
                __('Master validated order created for individual student payments. Automatically marked as paid since students handle their own payments.', 'neve-child')
            );
        }
        
        $master_order->save();

        // Asignar vendor automáticamente si el centro paga
        if (class_exists('SchoolManagement\Orders\OrderManager')) {
            \SchoolManagement\Orders\OrderManager::assignSchoolAndVendorData($master_order_id, $master_order);
        }

        return $master_order_id;
    }

    /**
     * Configurar dirección de facturación del pedido maestro
     */
    private function setMasterOrderBillingAddress($master_order, $school): void
    {
        $school_id = $school->ID;
        $school_meta = get_post_meta($school_id);
        
        // Verificar si el centro paga por sí mismo usando el método utilitario
        $school_pays = $this->schoolPaysDirectly($school_id);
        
        if ($school_pays) {
            // El centro paga: usar datos del centro para facturación completa
            $company_name = get_field('company', $school_id) ?: $school->post_title;
            
            $billing_data = [
                'first_name' => $company_name,
                'last_name' => '',
                'company' => $company_name,
                'address_1' => get_field('address', $school_id) ?: '',
                'address_2' => '',
                'city' => get_field('city', $school_id) ?: '',
                'state' => get_field('state', $school_id) ?: '',
                'postcode' => get_field('postcode', $school_id) ?: '',
                'country' => 'ES',
                'email' => get_field('email', $school_id) ?: '',
                'phone' => get_field('phone', $school_id) ?: ''
            ];
            
            $master_order->set_billing_address($billing_data);
            
            // Configurar metas específicos de Facturae según Helper.php
            $nif_value = get_field('cif', $school_id) ?: '';
            
            if (!empty($nif_value)) {
                // Meta key para NIF según Helper.php: '_wc_other/billing/nif'
                $master_order->update_meta_data('_wc_other/billing/nif', $nif_value);
                
                // Tipo de documento: '02' = NIF/NIE/VAT Number (según Helper.php)
                $master_order->update_meta_data('_wc_other/billing/document_type', '02');
                
                // Tipo de cliente: '01' = Business (empresa, según Helper.php)
                $master_order->update_meta_data('_wc_other/billing/customer_type', '01');
                
                // Checkbox factura siempre activo para centros que pagan
                $master_order->update_meta_data('_wc_other/billing/factura', '1');
                
                // Agregar nota informativa al pedido maestro
                $master_order->add_order_note(
                    sprintf(
                        __('Billing configured for school payment. NIF: %s, Customer type: Business (01)', 'neve-child'),
                        $nif_value
                    )
                );
            }
            
            // Agregar segundo apellido si existe (campo adicional del Helper)
            $second_surname = get_field('second_surname', $school_id);
            if (!empty($second_surname)) {
                $master_order->update_meta_data('_wc_billing/billing/second_surname', $second_surname);
            }
            
        } else {
            // El centro NO paga: usar datos básicos como antes (sin metas Facturae)
            $billing_data = [
                'first_name' => $school->post_title,
                'last_name' => '',
                'company' => $school->post_title,
                'address_1' => $school_meta['direccion'][0] ?? '',
                'address_2' => '',
                'city' => $school_meta['ciudad'][0] ?? '',
                'state' => $school_meta['provincia'][0] ?? '',
                'postcode' => $school_meta['codigo_postal'][0] ?? '',
                'country' => 'ES',
                'email' => $school_meta['email'][0] ?? '',
                'phone' => $school_meta['telefono'][0] ?? ''
            ];
            
            $master_order->set_billing_address($billing_data);
            
            // Agregar nota informativa
            $master_order->add_order_note(
                __('Billing configured for individual customer payments (school does not pay directly)', 'neve-child')
            );
        }
    }

    /**
     * Agregar pedido al pedido maestro - CON PROTECCIÓN CONTRA DUPLICADOS
     */
    private function addOrderToMasterOrder(int $order_id, int $master_order_id): bool
    {
        // ⏱️ TIMING DEBUG - INICIO
        $start_time = microtime(true);
        $step_times = [];
        
        // OPTIMIZACIÓN: Cargar órdenes UNA SOLA VEZ
        $step_start = microtime(true);
        $order = wc_get_order($order_id);
        $master_order = wc_get_order($master_order_id);
        $step_times['load_orders'] = (microtime(true) - $step_start) * 1000;
        
        if (!$order || !$master_order) {
            return false;
        }

        // VERIFICACIÓN RÁPIDA: Si ya está procesado, salir inmediatamente
        $step_start = microtime(true);
        $existing_master = $order->get_meta('_master_order_id');
        $step_times['check_duplicate'] = (microtime(true) - $step_start) * 1000;
        
        if ($existing_master == $master_order_id) {
            return true; // Ya procesado
        }

        // Verificar estado
        $current_status = $order->get_status();
        if (!in_array($current_status, ['processing', 'reviewed'])) {
            return false;
        }

        // LOCK SIMPLIFICADO: Solo para escritura atómica
        $lock_name = "add_order_{$order_id}_to_master_{$master_order_id}";
        
        $step_start = microtime(true);
        global $wpdb;
        $lock_result = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, %d)", $lock_name, 10));
        $step_times['acquire_lock'] = (microtime(true) - $step_start) * 1000;
        
        if ($lock_result != 1) {
            return false;
        }

        try {
            // Re-verificar duplicados SOLO dentro del lock
            $step_start = microtime(true);
            $included_orders = $master_order->get_meta('_included_orders') ?: [];
            $step_times['check_included'] = (microtime(true) - $step_start) * 1000;
            
            if (in_array($order_id, $included_orders)) {
                return true; // Añadido por otro proceso
            }

            // Marcar pedido como procesado
            $step_start = microtime(true);
            $order->update_meta_data('_master_order_id', $master_order_id);
            $order->update_meta_data('_added_to_master_at', current_time('mysql'));
            $order->save();
            $step_times['save_child_order'] = (microtime(true) - $step_start) * 1000;

            // OPTIMIZACIÓN: Cachear productos para evitar múltiples consultas
            $product_cache = [];
            
            // OPTIMIZACIÓN: Agregar items en batch
            $step_start = microtime(true);
            $items_count = 0;
            
            foreach ($order->get_items() as $item) {
                if (!is_a($item, 'WC_Order_Item_Product')) {
                    continue;
                }
                
                $items_count++;
                $product_id = $item->get_product_id();
                $variation_id = $item->get_variation_id();
                $quantity = $item->get_quantity();
                $subtotal = $item->get_subtotal();
                $total = $item->get_total();
                
                // Buscar si el producto ya existe
                $existing_item = $this->findExistingItem($master_order, $product_id, $variation_id);
                
                if ($existing_item) {
                    // Combinar cantidades (sin save individual, se guardará al final)
                    $existing_item->set_quantity($existing_item->get_quantity() + $quantity);
                    $existing_item->set_subtotal($existing_item->get_subtotal() + $subtotal);
                    $existing_item->set_total($existing_item->get_total() + $total);
                    $existing_item->save();
                } else {
                    // OPTIMIZACIÓN: Usar caché de productos
                    $cache_key = $variation_id ?: $product_id;
                    if (!isset($product_cache[$cache_key])) {
                        $product_cache[$cache_key] = wc_get_product($cache_key);
                    }
                    
                    // Añadir nuevo producto
                    $master_order->add_product(
                        $product_cache[$cache_key],
                        $quantity,
                        [
                            'variation' => $variation_id ? $product_cache[$variation_id] : null,
                            'totals' => ['subtotal' => $subtotal, 'total' => $total]
                        ]
                    );
                }
            }
            $step_times['add_items'] = (microtime(true) - $step_start) * 1000;
            $step_times['items_count'] = $items_count;

            // Recalcular totales UNA SOLA VEZ al final
            $step_start = microtime(true);
            $master_order->calculate_totals();
            $step_times['calculate_totals'] = (microtime(true) - $step_start) * 1000;
            
            // OPTIMIZACIÓN: Ordenamiento desactivado por rendimiento
            // El orden de productos no es crítico para la funcionalidad
            // $this->sortMasterOrderItemsByProductId($master_order);

            // Actualizar lista de pedidos incluidos
            $step_start = microtime(true);
            $included_orders[] = $order_id;
            $master_order->update_meta_data('_included_orders', $included_orders);
            
            // OPTIMIZACIÓN: Nota simple sin sprintf
            $master_order->add_order_note("Child order #{$order_id} added to master order");
            $master_order->save();
            $step_times['save_master_order'] = (microtime(true) - $step_start) * 1000;

            return true;

        } finally {
            $wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
        }
    }

    /**
     * Buscar un item existente en el pedido maestro por product_id y variation_id
     */
    private function findExistingItem($master_order, int $product_id, int $variation_id = 0)
    {
        foreach ($master_order->get_items() as $item) {
            // Verificar que es un item de producto
            if (!is_a($item, 'WC_Order_Item_Product')) {
                continue;
            }
            
            if ($item->get_product_id() == $product_id && $item->get_variation_id() == $variation_id) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Verificar si un pedido es un pedido maestro
     */
    private function isMasterOrder(int $order_id): bool
    {
        $order = wc_get_order($order_id);
        return $order && $order->get_meta('_is_master_order') === 'yes';
    }

    /**
     * Verificar si un cambio de estado representa un retroceso en la progresión
     * 
     * REGLAS ACTUALIZADAS:
     * - master-order: Solo puede avanzar a mast-warehs
     * - mast-warehs ↔ mast-prepared: Pueden moverse entre ellos libremente  
     * - mast-complete: Estado final, no puede cambiar
     * 
     * @param string $old_status Estado actual
     * @param string $new_status Estado destino
     * @return bool True si es un retroceso, false si es válido
     */
    private function isMasterOrderStatusRegression(string $old_status, string $new_status): bool
    {
        // Si alguno de los estados no está en la progresión, permitir (será manejado por otra validación)
        if (!isset(self::MASTER_ORDER_STATUS_PROGRESSION[$old_status]) || 
            !isset(self::MASTER_ORDER_STATUS_PROGRESSION[$new_status])) {
            return false;
        }

        // REGLA 1: master-order solo puede avanzar, no retroceder
        if ($old_status === 'master-order' && $new_status !== 'mast-warehs') {
            return true; // Bloquear: master-order solo puede ir a mast-warehs
        }

        // REGLA 2: mast-warehs ↔ mast-prepared pueden moverse libremente entre ellos
        if (($old_status === 'mast-warehs' && $new_status === 'mast-prepared') ||
            ($old_status === 'mast-prepared' && $new_status === 'mast-warehs')) {
            return false; // Permitir: movimiento libre entre almacén y preparado
        }

        // REGLA 3: mast-complete es estado final, no puede cambiar
        if ($old_status === 'mast-complete') {
            return true; // Bloquear: mast-complete no puede cambiar a nada
        }

        // REGLA 4: Prohibir saltar desde master-order directamente a mast-prepared o mast-complete
        if ($old_status === 'master-order' && 
            ($new_status === 'mast-prepared' || $new_status === 'mast-complete')) {
            return true; // Bloquear: no saltar estados desde master-order
        }

        // REGLA 5: Cualquier estado puede avanzar a mast-complete (excepto master-order directo)
        if ($new_status === 'mast-complete' && $old_status !== 'master-order') {
            return false; // Permitir: avance a completado desde warehouse o prepared
        }

        // Por defecto, usar la lógica de progresión numérica para otros casos
        return self::MASTER_ORDER_STATUS_PROGRESSION[$new_status] < self::MASTER_ORDER_STATUS_PROGRESSION[$old_status];
    }

    /**
     * Obtener la etiqueta amigable de un estado de pedido maestro
     * 
     * @param string $status Estado del pedido
     * @return string Etiqueta amigable
     */
    private function getMasterOrderStatusLabel(string $status): string
    {
        $labels = [
            'master-order'   => __('Master Validated', 'neve-child'),
            'mast-warehs'    => __('Master Warehouse', 'neve-child'), 
            'mast-prepared'  => __('Master Prepared', 'neve-child'),
            'mast-complete'  => __('Master Complete', 'neve-child')
        ];

        return $labels[$status] ?? $status;
    }

    /**
     * Verificar si una escuela es la que paga directamente
     * 
     * @param int $school_id ID de la escuela
     * @return bool True si la escuela paga directamente
     */
    private function schoolPaysDirectly(int $school_id): bool
    {
        $the_billing_by_the_school = get_field('the_billing_by_the_school', $school_id);
        return ($the_billing_by_the_school === '1' || $the_billing_by_the_school === 1 || $the_billing_by_the_school === true);
    }

    /**
     * Crear notificación de éxito
     */
    private function createNotification(int $order_id, int $master_order_id, int $school_id): void
    {
        $school = get_post($school_id);
        $message = sprintf(
            __('Order #%d added to master validated order #%d for school "%s"', 'neve-child'),
            $order_id,
            $master_order_id,
            $school ? $school->post_title : "ID: {$school_id}"
        );
        
        update_option('master_order_last_notification', $message);
    }

    /**
     * Registrar estados de pedido maestro
     */
    public function registerMasterOrderStatuses(): void
    {
        register_post_status('wc-' . self::MASTER_ORDER_STATUS, [
            'label' => __('Master Validated', 'neve-child'),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop(
                'Master Validated <span class="count">(%s)</span>',
                'Master Validated <span class="count">(%s)</span>',
                'neve-child'
            )
        ]);

        register_post_status('wc-' . self::MASTER_ORDER_COMPLETE_STATUS, [
            'label' => __('Master Complete', 'neve-child'),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop(
                'Master Complete <span class="count">(%s)</span>',
                'Master Complete <span class="count">(%s)</span>',
                'neve-child'
            )
        ]);
    }

    /**
     * Agregar estados a la lista de estados de WooCommerce
     */
    public function addMasterOrderStatuses($order_statuses): array
    {
        // Si no es array (puede venir false desde reportes), inicializar como array vacío
        if (!is_array($order_statuses)) {
            $order_statuses = [];
        }
        
        $order_statuses['wc-' . self::MASTER_ORDER_STATUS] = __('Master Validated', 'neve-child');
        $order_statuses['wc-' . self::MASTER_ORDER_COMPLETE_STATUS] = __('Master Complete', 'neve-child');
        return $order_statuses;
    }

    /**
     * Agregar estados maestros a la lista de estados "pagados"
     * MODIFICADO: Solo considerar pagados automáticamente cuando NO sea pago del centro
     */
    public function addPaidStatuses(array $statuses): array
    {
        // NOTA: No agregamos automáticamente los estados maestros aquí
        // porque queremos que las master orders que paga el centro requieran confirmación manual
        // Los estados se considerarán pagados a través del sistema de PaymentStatusColumn
        
        // Solo agregar si es para suscripciones u otros casos especiales donde se necesite
        // $statuses[] = self::MASTER_ORDER_STATUS;
        // $statuses[] = self::MASTER_ORDER_COMPLETE_STATUS;
        
        return $statuses;
    }

    /**
     * Interceptar cuando un pedido maestro se marca como "completed" 
     * y convertirlo al estado "master-order-complete"
     */
    public function interceptMasterOrderCompleted(int $order_id, $order): void
    {
        // Verificar si es un pedido maestro
        if (!$this->isMasterOrder($order_id)) {
            return;
        }
        
        // CRÍTICO: Si el centro paga directamente, limpiar payment_date antes de cambiar estado
        $school_id = $order->get_meta('_school_id');
        if ($school_id) {
            $school_pays = $this->schoolPaysDirectly($school_id);
            if ($school_pays) {
                // Limpiar payment_date que pudo haber sido establecida por otros hooks
                $order->delete_meta_data('payment_date');
                $order->set_transaction_id('');
                $order->delete_meta_data('_dm_pay_later_card_payment_date');
                $order->save();
            }
        }
        
        // PROTECCIÓN CRÍTICA: Evitar loops infinitos con variable global
        global $master_order_processing;
        if (!is_array($master_order_processing)) {
            $master_order_processing = [];
        }
        
        if (isset($master_order_processing[$order_id])) {
            return;
        }
        
        $master_order_processing[$order_id] = true;
        
        // SOLUCIÓN RADICAL: Usar una transacción y prevenir cualquier otro hook
        global $wpdb;
        
        // Remover TODOS los hooks que podrían interferir
        remove_all_actions('woocommerce_order_status_changed');
        remove_all_actions('woocommerce_order_status_completed');
        remove_all_actions('woocommerce_order_status_master-order-complete');
        remove_all_filters('woocommerce_order_get_status');
        
        // Iniciar transacción
        $wpdb->query('START TRANSACTION');
        
        // Cambiar el estado directamente en la base de datos
        $result = $wpdb->update(
            $wpdb->posts,
            ['post_status' => 'wc-' . self::MASTER_ORDER_COMPLETE_STATUS],
            ['ID' => $order_id],
            ['%s'],
            ['%d']
        );
        
        if ($result === false) {
            $wpdb->query('ROLLBACK');
            unset($master_order_processing[$order_id]);
            return;
        }
        
        // Limpiar TODOS los caches relacionados
        wp_cache_delete($order_id, 'posts');
        wp_cache_delete($order_id, 'post_meta');
        clean_post_cache($order_id);
        
        // Limpiar cache específico de WooCommerce
        if (function_exists('wc_delete_shop_order_transients')) {
            wc_delete_shop_order_transients($order_id);
        }
        
        // Commit de la transacción
        $wpdb->query('COMMIT');
        
        // Ejecutar manualmente la lógica de master-order-complete SIN recargar el objeto order
        $this->handleMasterOrderCompleteStatusChangeDirectly($order_id);
        
        // Agregar nota al pedido
        $order->add_order_note(__('Automatically converted from completed to master-order-complete', 'neve-child'));
        
        // Restaurar hooks DESPUÉS de todo el procesamiento
        add_action('woocommerce_order_status_changed', [$this, 'handleOrderStatusChange'], 10, 4);
        add_action('woocommerce_order_status_master-order', [$this, 'handleMasterOrderStatusChange'], 10, 2);
        add_action('woocommerce_order_status_master-order-complete', [$this, 'handleMasterOrderCompleteStatusChange'], 10, 2);
        add_action('woocommerce_order_status_completed', [$this, 'interceptMasterOrderCompleted'], 1, 2);
        add_filter('woocommerce_order_get_status', [$this, 'filterMasterOrderStatus'], 10, 2);
        add_action('woocommerce_order_status_changed', [$this, 'interceptStatusChange'], 1, 4);
        
        unset($master_order_processing[$order_id]);
    }

    /**
     * Interceptar cambios de estado ANTES de que se procesen completamente
     */
    public function interceptStatusChange(int $order_id, string $old_status, string $new_status, $order): void
    {
        // Solo interceptar si es un pedido maestro cambiando a completed
        if ($new_status !== 'completed' || !$this->isMasterOrder($order_id)) {
            return;
        }
        
        // Prevenir el procesamiento inmediatamente y cambiar a nuestro estado personalizado
        $this->forceStatusChange($order_id, self::MASTER_ORDER_COMPLETE_STATUS);
    }

    /**
     * Filtrar el estado de pedidos maestros que están marcados como completed
     */
    public function filterMasterOrderStatus(string $status, $order): string
    {
        if (!$order) {
            return $status;
        }
        
        $order_id = $order->get_id();
        
        // Si es un pedido maestro y el estado es completed, convertirlo
        if ($status === 'completed' && $this->isMasterOrder($order_id)) {
            return self::MASTER_ORDER_COMPLETE_STATUS;
        }
        
        return $status;
    }

    /**
     * Forzar cambio de estado de manera más directa
     */
    private function forceStatusChange(int $order_id, string $new_status): void
    {
        global $wpdb;
        
        // Cambio directo en base de datos
        $result = $wpdb->update(
            $wpdb->posts,
            ['post_status' => 'wc-' . $new_status],
            ['ID' => $order_id],
            ['%s'],
            ['%d']
        );
        
        if ($result) {
            // Limpiar caches
            wp_cache_delete($order_id, 'posts');
            wp_cache_delete($order_id, 'post_meta');
            clean_post_cache($order_id);
            
            if (function_exists('wc_delete_shop_order_transients')) {
                wc_delete_shop_order_transients($order_id);
            }
            
            // Ejecutar la lógica de master-order-complete
            $this->handleMasterOrderCompleteStatusChangeDirectly($order_id);
        }
    }

    /**
     * Agregar bulk actions personalizadas
     */
    public function addBulkActions(array $actions): array
    {
        // Master order bulk actions
        $actions['mark_master_order'] = __('Change to Master Validated', 'neve-child');
        $actions['mark_master_warehouse'] = __('Change to Master Warehouse', 'neve-child');
        $actions['mark_master_prepared'] = __('Change to Master Prepared', 'neve-child');
        $actions['mark_master_complete'] = __('Change to Master Complete', 'neve-child');
        
        return $actions;
    }

    /**
     * Manejar bulk actions personalizadas
     */
    public function handleBulkActions(string $redirect_to, string $action, array $post_ids): string
    {
        // Master order actions mapping
        $master_actions = [
            'mark_master_order' => 'master-order',
            'mark_master_warehouse' => 'mast-warehs', 
            'mark_master_prepared' => 'mast-prepared',
            'mark_master_complete' => 'mast-complete'
        ];

        // Handle new master order actions
        if (array_key_exists($action, $master_actions)) {
            $new_status = $master_actions[$action];
            $changed = 0;
            $protected = 0;
            $regressed = 0;

            foreach ($post_ids as $post_id) {
                if ($this->isMasterOrder($post_id)) {
                    $order = wc_get_order($post_id);
                    if ($order && $order->get_status() !== $new_status) {
                        $current_status = $order->get_status();
                        
                        // Verificar si es un retroceso en la progresión
                        if ($this->isMasterOrderStatusRegression($current_status, $new_status)) {
                            // No permitir el cambio - es un retroceso
                            $regressed++;
                            $order->add_order_note(sprintf(
                                __('Bulk action blocked: Cannot change from "%s" to "%s". Master validated orders can only advance in the workflow.', 'neve-child'),
                                $this->getMasterOrderStatusLabel($current_status),
                                $this->getMasterOrderStatusLabel($new_status)
                            ));
                        } else {
                            // Permitir el cambio - es avance o mismo estado
                            $order->update_status($new_status, sprintf(
                                __('Status changed to %s via bulk action', 'neve-child'),
                                $new_status
                            ));
                            $changed++;
                        }
                    }
                } else {
                    $protected++;
                }
            }

            // VALIDACIÓN FINAL: Después de procesar todos los pedidos
            // Recopilar IDs únicos de master orders afectadas
            $affected_master_orders = [];
            foreach ($post_ids as $post_id) {
                if ($this->isMasterOrder($post_id)) {
                    $affected_master_orders[$post_id] = true;
                }
            }

            // Validar cada master order afectada
            $validation_failures = [];
            foreach (array_keys($affected_master_orders) as $master_order_id) {
                $validation_result = $this->validateMasterOrderProductTotals($master_order_id);
                
                if (!$validation_result['valid']) {
                    $validation_failures[] = $validation_result;
                    
                    // Añadir nota al pedido maestro sobre el fallo de validación
                    $master_order = wc_get_order($master_order_id);
                    if ($master_order) {
                        $discrepancies_summary = [];
                        foreach ($validation_result['discrepancies'] as $disc) {
                            $discrepancies_summary[] = sprintf(
                                '%s: esperado %d, actual %d (diferencia: %+d)',
                                $disc['name'],
                                $disc['expected_qty'],
                                $disc['actual_qty'],
                                $disc['difference']
                            );
                        }
                        
                        $master_order->add_order_note(sprintf(
                            __('⚠️ VALIDATION FAILED after bulk action: %s. Discrepancies: %s', 'neve-child'),
                            $validation_result['summary'],
                            implode('; ', $discrepancies_summary)
                        ));
                    }
                }
            }

            // Mostrar notificación si hubo fallos de validación
            if (!empty($validation_failures)) {
                set_transient('master_order_validation_error', [
                    'type' => 'error',
                    'message' => sprintf(
                        _n(
                            '%d master order failed product validation. Check order notes for details.',
                            '%d master orders failed product validation. Check order notes for details.',
                            count($validation_failures),
                            'neve-child'
                        ),
                        count($validation_failures)
                    )
                ], 30);
            }

            // Add admin notices
            if ($changed > 0) {
                set_transient('master_order_bulk_notice', [
                    'type' => 'success',
                    'message' => sprintf(
                        _n('%d master validated order status changed.', '%d master validated orders status changed.', $changed, 'neve-child'),
                        $changed
                    )
                ], 30);
            }
            if ($protected > 0) {
                set_transient('master_order_bulk_warning', [
                    'type' => 'warning',
                    'message' => sprintf(
                        _n('%d regular order was protected (master actions only apply to master validated orders).', '%d regular orders were protected (master actions only apply to master validated orders).', $protected, 'neve-child'),
                        $protected
                    )
                ], 30);
            }
            // Regression blocking logic removed per user request

            return $redirect_to;
        }

        return $redirect_to;
    }

    /**
     * Mostrar notificaciones de bulk actions
     */
    public function showBulkActionNotices(): void
    {
        // Notificación de éxito
        $success_notice = get_transient('master_order_bulk_notice');
        if ($success_notice) {
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($success_notice['type']),
                esc_html($success_notice['message'])
            );
            delete_transient('master_order_bulk_notice');
        }

        // Notificación de advertencia
        $warning_notice = get_transient('master_order_bulk_warning');
        if ($warning_notice) {
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($warning_notice['type']),
                esc_html($warning_notice['message'])
            );
            delete_transient('master_order_bulk_warning');
        }

        // Notificación de error de validación
        $validation_error = get_transient('master_order_validation_error');
        if ($validation_error) {
            printf(
                '<div class="notice notice-%s is-dismissible"><p><strong>⚠️ Validation Error:</strong> %s</p></div>',
                esc_attr($validation_error['type']),
                esc_html($validation_error['message'])
            );
            delete_transient('master_order_validation_error');
        }

        if (!empty($_REQUEST['bulk_master_orders_completed'])) {
            $completed = intval($_REQUEST['bulk_master_orders_completed']);
            $errors = intval($_REQUEST['bulk_master_orders_errors'] ?? 0);
            
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>Master Validated Manager:</strong> ';
            printf(
                _n(
                    '%d master validated marked as complete.',
                    '%d master validated orders marked as complete.',
                    $completed,
                    'neve-child'
                ),
                $completed
            );
            
            if ($errors > 0) {
                printf(' ' . __('%d errors found.', 'neve-child'), $errors);
            }
            
            echo '</p></div>';
        }
        
        // Mostrar notificación de regresión bloqueada
        // Regression notice functionality removed per user request
    }

    /**
     * Manejar cambio de estado a pedido maestro
     */
    public function handleMasterOrderStatusChange(int $order_id, $order): void
    {
        // Cambio de estado a pedido maestro manejado silenciosamente
    }

    /**
     * Manejar cambio de estado a pedido maestro completo
     */
    public function handleMasterOrderCompleteStatusChange(int $order_id, $order): void
    {
        global $wpdb;
        
        // Evitar ejecuciones múltiples
        static $processing = [];
        if (isset($processing[$order_id])) {
            return;
        }
        $processing[$order_id] = true;
        
        // Obtener school_id del pedido maestro
        $school_id = $order->get_meta('_school_id');
        if (!$school_id) {
            unset($processing[$order_id]);
            return;
        }
        
        // Marcar como inactivo en la tabla de control
        $table_name = $wpdb->prefix . 'school_master_orders';
        
        // Verificar que la tabla existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        if (!$table_exists) {
            unset($processing[$order_id]);
            return;
        }
        
        // Verificar que el registro existe antes de actualizarlo
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE school_id = %d AND master_order_id = %d",
            $school_id, $order_id
        ));
        
        if (!$existing) {
            // Crear el registro si no existe
            $wpdb->insert(
                $table_name,
                [
                    'school_id' => $school_id,
                    'master_order_id' => $order_id,
                    'is_active' => 0
                ],
                ['%d', '%d', '%d']
            );
            $updated = $wpdb->rows_affected;
        } else {
            // Actualizar el registro existente
            $updated = $wpdb->update(
                $table_name,
                ['is_active' => 0],
                ['school_id' => $school_id, 'master_order_id' => $order_id],
                ['%d'],
                ['%d', '%d']
            );
        }
        
        if ($updated) {
            // Crear notificación
            $school = get_post($school_id);
            $message = sprintf(
                __('Master validated order #%d completed for school "%s". The next reviewed order will create a new master validated order.', 'neve-child'),
                $order_id,
                $school ? $school->post_title : "ID: {$school_id}"
            );
            update_option('master_order_last_notification', $message);
        }
        
        unset($processing[$order_id]);
    }

    /**
     * Versión directa del handler de estado completo que no recarga el objeto order
     */
    private function handleMasterOrderCompleteStatusChangeDirectly(int $order_id): void
    {
        global $wpdb;
        
        // Obtener school_id directamente de la base de datos
        $school_id = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_school_id'",
            $order_id
        ));
        
        if (!$school_id) {
            return;
        }
        
        // Marcar como inactivo en la tabla de control
        $table_name = $wpdb->prefix . 'school_master_orders';
        
        // Verificar que la tabla existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        if (!$table_exists) {
            return;
        }
        
        // Verificar que el registro existe antes de actualizarlo
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE school_id = %d AND master_order_id = %d",
            $school_id, $order_id
        ));
        
        if (!$existing) {
            // Crear el registro si no existe
            $wpdb->insert(
                $table_name,
                [
                    'school_id' => $school_id,
                    'master_order_id' => $order_id,
                    'is_active' => 0
                ],
                ['%d', '%d', '%d']
            );
            $updated = $wpdb->rows_affected;
        } else {
            // Actualizar el registro existente
            $updated = $wpdb->update(
                $table_name,
                ['is_active' => 0],
                ['school_id' => $school_id, 'master_order_id' => $order_id],
                ['%d'],
                ['%d', '%d']
            );
        }
        
        if ($updated) {
            // Crear notificación
            $school_title = $wpdb->get_var($wpdb->prepare(
                "SELECT post_title FROM {$wpdb->posts} WHERE ID = %d",
                $school_id
            ));
            
            $message = sprintf(
                __('Master validated order #%d completed for school "%s". The next reviewed order will create a new master validated order.', 'neve-child'),
                $order_id,
                $school_title ?: "ID: {$school_id}"
            );
            update_option('master_order_last_notification', $message);
        }
    }

    /**
     * Mostrar notificaciones en admin
     */
    public function showNotices(): void
    {
        $notification = get_option('master_order_last_notification');
        if ($notification) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>Master Validated Manager:</strong> ' . esc_html($notification) . '</p>';
            echo '</div>';
            delete_option('master_order_last_notification');
        }
    }

    /**
     * Agregar metabox de relaciones de pedidos
     */
    public function addOrderRelationshipsMetabox(): void
    {
        add_meta_box(
            'order-relationships',
            __('Order Relationships', 'neve-child'),
            [$this, 'renderOrderRelationshipsMetabox'],
            'shop_order',
            'side',
            'default'
        );
    }

    /**
     * Renderizar metabox de relaciones
     */
    public function renderOrderRelationshipsMetabox($post): void
    {
        $order = wc_get_order($post->ID);
        if (!$order) return;

        if ($order->get_meta('_is_master_order') === 'yes') {
            // Es un pedido maestro - mostrar pedidos incluidos
            $included_orders = $order->get_meta('_included_orders') ?: [];
            echo '<h4>' . __('Included Orders:', 'neve-child') . '</h4>';
            if (empty($included_orders)) {
                echo '<p>' . __('No orders included yet.', 'neve-child') . '</p>';
            } else {
                echo '<ul>';
                foreach ($included_orders as $included_id) {
                    $included_order = wc_get_order($included_id);
                    if ($included_order) {
                        $edit_url = admin_url("post.php?post={$included_id}&action=edit");
                        echo "<li><a href='{$edit_url}'>" . sprintf(__('Order #%d', 'neve-child'), $included_id) . "</a> - " . 
                             $included_order->get_formatted_order_total() . "</li>";
                    }
                }
                echo '</ul>';
            }
        } else {
            // Es un pedido normal - mostrar pedido maestro si existe
            $master_order_id = $order->get_meta('_master_order_id');
            if ($master_order_id) {
                $master_order = wc_get_order($master_order_id);
                if ($master_order) {
                    $edit_url = admin_url("post.php?post={$master_order_id}&action=edit");
                    echo "<h4>" . __('Master Validated Order:', 'neve-child') . "</h4>";
                    echo "<p><a href='{$edit_url}'>" . sprintf(__('Master Validated Order #%d', 'neve-child'), $master_order_id) . "</a></p>";
                    echo "<p>" . __('Added on:', 'neve-child') . " " . ($order->get_meta('_added_to_master_at') ?: 'N/A') . "</p>";
                }
            } else {
                echo '<p>' . __('Not assigned to master validated order.', 'neve-child') . '</p>';
            }
        }
    }

    /**
     * Filtrar el estado de pago de master orders
     * CRÍTICO: Este filtro se aplica al método is_paid() de WooCommerce
     */
    public function filterMasterOrderIsPaid(bool $is_paid, \WC_Order $order): bool
    {
        // Solo interceptar master orders
        if ($order->get_meta('_is_master_order') !== 'yes') {
            return $is_paid;
        }
        
        // Para master orders, verificar si el centro paga directamente
        $school_id = $order->get_meta('_school_id');
        if (!$school_id) {
            return $is_paid;
        }
        
        $school_pays = $this->schoolPaysDirectly($school_id);
        if (!$school_pays) {
            // Si el centro NO paga directamente, usar la lógica estándar
            return $is_paid;
        }
        
        // Si el centro paga directamente, verificar indicadores de pago manual
        $payment_date = $order->get_meta('payment_date');
        $transaction_id = $order->get_transaction_id();
        $deferred_payment_date = $order->get_meta('_dm_pay_later_card_payment_date');
        
        // Solo considerar pagado si tiene indicadores de confirmación manual
        if (!empty($payment_date) || !empty($transaction_id) || !empty($deferred_payment_date)) {
            return true;
        }
        
        // Master orders pagadas por el centro sin confirmación manual = NO PAGADAS
        return false;
    }

    /**
     * Manejar cuando un pedido sale del estado "reviewed"
     * Si la master order está aún en estado inicial, remover el pedido
     */
    private function handleOrderRemovedFromReviewed(int $order_id, $order): void
    {
        // PROTECCIÓN: Evitar procesamiento concurrente
        $lock_key = 'master_order_removal_' . $order_id;
        if (get_transient($lock_key)) {
            return;
        }
        set_transient($lock_key, true, 30); // Lock por 30 segundos

        try {
            // Verificar que tenga una master order asociada
            $master_order_id = $order->get_meta('_master_order_id');
            if (!$master_order_id) {
                return;
            }

            $master_order = wc_get_order($master_order_id);
            if (!$master_order) {
                // Limpiar referencia inválida
                $order->delete_meta_data('_master_order_id');
                $order->delete_meta_data('_added_to_master_at');
                $order->save();
                return;
            }

            // Solo permitir remoción si la master order está en estado inicial (aún puede crecer/decrecer)
            $master_status = $master_order->get_status();
            if ($master_status !== 'master-order') {
                // Si la master order ya avanzó de estado, no permitir remoción
                $order->add_order_note(
                    sprintf(
                        __('Cannot be removed from master order #%d because it has advanced beyond initial state (current status: %s)', 'neve-child'),
                        $master_order_id,
                        $master_status
                    )
                );
                return;
            }

            // Proceder con la remoción
            $this->removeOrderFromMasterOrder($order_id, $master_order_id);
            
        } finally {
            // Liberar el lock
            delete_transient($lock_key);
        }
    }

    /**
     * Remover un pedido de una master order - VERSIÓN MEJORADA
     */
    private function removeOrderFromMasterOrder(int $order_id, int $master_order_id): bool
    {
        $order = wc_get_order($order_id);
        $master_order = wc_get_order($master_order_id);
        
        if (!$order || !$master_order) {
            return false;
        }

        // Obtener lista de pedidos incluidos
        $included_orders = $master_order->get_meta('_included_orders') ?: [];
        
        // Verificar que el pedido esté en la lista
        if (!in_array($order_id, $included_orders)) {
            return false;
        }

        // PASO 1: Limpiar metadatos del pedido individual PRIMERO
        $order->delete_meta_data('_master_order_id');
        $order->delete_meta_data('_added_to_master_at');
        $order->save();

        // PASO 2: Actualizar lista de pedidos incluidos
        $included_orders = array_filter($included_orders, function($id) use ($order_id) {
            return $id != $order_id;
        });
        
        $master_order->update_meta_data('_included_orders', array_values($included_orders));
        $master_order->save();

        // PASO 3: Verificar si la master order quedó vacía
        if (empty($included_orders)) {
            // Si no quedan pedidos, eliminar la master order
            $this->deleteMasterOrder($master_order_id);
            
            $order->add_order_note(
                sprintf(
                    __('Removed from master order #%d. Master order was deleted as it became empty.', 'neve-child'),
                    $master_order_id
                )
            );
            return true;
        }

        // PASO 4: RECONSTRUIR COMPLETAMENTE la master order con los pedidos restantes
        $reconstruction_result = $this->reconstructMasterOrder($master_order_id, $included_orders);
        
        if ($reconstruction_result['success']) {
            $order->add_order_note(
                sprintf(
                    __('Removed from master order #%d. Master order reconstructed with %d remaining orders. %d products, total items: %d', 'neve-child'),
                    $master_order_id,
                    count($included_orders),
                    $reconstruction_result['unique_products'],
                    $reconstruction_result['total_items']
                )
            );
            
            $master_order->add_order_note(
                sprintf(
                    __('Order #%d was removed. Master order reconstructed with remaining orders: %s. Total products: %d, Total items: %d', 'neve-child'),
                    $order_id,
                    implode(', ', $included_orders),
                    $reconstruction_result['unique_products'],
                    $reconstruction_result['total_items']
                )
            );
        } else {
            $order->add_order_note(
                sprintf(
                    __('Removed from master order #%d but reconstruction failed: %s', 'neve-child'),
                    $master_order_id,
                    $reconstruction_result['error']
                )
            );
        }

        return $reconstruction_result['success'];
    }

    /**
     * Reconstruir completamente una master order con los pedidos restantes
     */
    private function reconstructMasterOrder(int $master_order_id, array $remaining_order_ids): array
    {
        $master_order = wc_get_order($master_order_id);
        if (!$master_order) {
            return ['success' => false, 'error' => 'Master order not found'];
        }

        try {
            // PASO 1: Limpiar TODOS los items existentes de la master order
            foreach ($master_order->get_items() as $item_id => $item) {
                $master_order->remove_item($item_id);
            }

            // PASO 2: Reconstruir desde cero con los pedidos restantes
            $product_totals = [];
            $total_items = 0;
            $processed_orders = 0;

            foreach ($remaining_order_ids as $remaining_order_id) {
                $remaining_order = wc_get_order($remaining_order_id);
                if (!$remaining_order) {
                    continue;
                }

                $processed_orders++;

                // Procesar cada item del pedido restante
                foreach ($remaining_order->get_items() as $item) {
                    if (!is_a($item, 'WC_Order_Item_Product')) {
                        continue;
                    }

                    $product_id = $item->get_product_id();
                    $variation_id = $item->get_variation_id();
                    $quantity = $item->get_quantity();
                    
                    // Crear clave única para el producto
                    $product_key = $product_id . '_' . $variation_id;
                    
                    // Acumular cantidades y datos
                    if (!isset($product_totals[$product_key])) {
                        $product_totals[$product_key] = [
                            'product_id' => $product_id,
                            'variation_id' => $variation_id,
                            'quantity' => 0,
                            'subtotal' => 0,
                            'total' => 0,
                            'product_data' => $item->get_data() // Conservar metadatos del producto
                        ];
                    }
                    
                    $product_totals[$product_key]['quantity'] += $quantity;
                    $product_totals[$product_key]['subtotal'] += $item->get_subtotal();
                    $product_totals[$product_key]['total'] += $item->get_total();
                    $total_items += $quantity;
                }
            }

            // PASO 3: Agregar los productos consolidados a la master order
            foreach ($product_totals as $product_key => $product_data) {
                $product = wc_get_product($product_data['product_id']);
                if (!$product) {
                    continue;
                }

                // Agregar el producto con la cantidad total
                $item_id = $master_order->add_product(
                    $product,
                    $product_data['quantity'],
                    [
                        'variation' => $product_data['variation_id'] ? wc_get_product($product_data['variation_id']) : null,
                        'totals' => [
                            'subtotal' => $product_data['subtotal'],
                            'total' => $product_data['total'],
                        ]
                    ]
                );

                if (!$item_id) {
                    return [
                        'success' => false, 
                        'error' => sprintf('Failed to add product %d to master order', $product_data['product_id'])
                    ];
                }
            }

            // PASO 4: Recalcular totales y guardar
            $master_order->calculate_totals();
            
            // OPTIMIZACIÓN: Ordenamiento desactivado por rendimiento
            // $this->sortMasterOrderItemsByProductId($master_order);

            return [
                'success' => true,
                'unique_products' => count($product_totals),
                'total_items' => $total_items,
                'processed_orders' => $processed_orders
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Exception during reconstruction: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Eliminar una master order vacía
     */
    private function deleteMasterOrder(int $master_order_id): bool
    {
        global $wpdb;
        
        $master_order = wc_get_order($master_order_id);
        if (!$master_order) {
            return false;
        }
        
        $school_id = $master_order->get_meta('_school_id');
        
        // Remover de la tabla de control
        if ($school_id) {
            $table_name = $wpdb->prefix . 'school_master_orders';
            $wpdb->delete(
                $table_name,
                [
                    'school_id' => $school_id,
                    'master_order_id' => $master_order_id,
                    'is_active' => 1
                ],
                ['%d', '%d', '%d']
            );
        }
        
        // Agregar nota final antes de eliminar
        $master_order->add_order_note(
            __('Master order deleted because all child orders were removed', 'neve-child')
        );
        
        // Eliminar la order de WooCommerce
        wp_delete_post($master_order_id, true);
        
        return true;
    }

    /**
     * Validación FINAL después de bulk action: verificar SOLO las master orders procesadas
     * Si la validación falla, DESHACE TODO: elimina master, restaura hijos a processing
     */
    private function finalValidationAndCleanup(): void
    {
        global $wpdb;
        
        // Obtener SOLO las master orders que se tocaron en esta bulk action
        $master_order_ids = self::$touched_master_orders;
        
        if (empty($master_order_ids)) {
            return;
        }
        
        $validated_ok = 0;
        $deleted = 0;
        
        foreach ($master_order_ids as $master_order_id) {
            // 1. Obtener pedidos hijos desde DB (source of truth) - wp_wc_orders_meta
            $child_order_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT order_id FROM {$wpdb->prefix}wc_orders_meta 
                 WHERE meta_key = '_master_order_id' AND meta_value = %d",
                $master_order_id
            ));
            
            if (empty($child_order_ids)) {
                // Obtener school_id de la tabla antes de eliminar
                $table_name = $wpdb->prefix . 'school_master_orders';
                $school_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT school_id FROM {$table_name} WHERE master_order_id = %d",
                    $master_order_id
                ));
                
                $this->deleteMasterOrderAndResetChildren($master_order_id, [], (int)$school_id);
                $deleted++;
                continue;
            }
            
            // 2. Consolidar productos ESPERADOS de los hijos (según DB)
            $expected_products = [];
            foreach ($child_order_ids as $child_id) {
                $child_order = wc_get_order($child_id);
                if (!$child_order) {
                    continue;
                }
                
                foreach ($child_order->get_items() as $item) {
                    if (!is_a($item, 'WC_Order_Item_Product')) continue;
                    
                    $product_id = $item->get_product_id();
                    $variation_id = $item->get_variation_id();
                    $key = $product_id . '_' . $variation_id;
                    
                    if (!isset($expected_products[$key])) {
                        $expected_products[$key] = [
                            'product_id' => $product_id,
                            'quantity' => 0,
                            'name' => $item->get_name()
                        ];
                    }
                    
                    $expected_products[$key]['quantity'] += $item->get_quantity();
                }
            }
            
            // 3. Obtener productos ACTUALES de la master
            $master_order = wc_get_order($master_order_id);
            if (!$master_order) {
                // Obtener school_id de la tabla
                $table_name = $wpdb->prefix . 'school_master_orders';
                $school_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT school_id FROM {$table_name} WHERE master_order_id = %d",
                    $master_order_id
                ));
                
                $this->deleteMasterOrderAndResetChildren($master_order_id, $child_order_ids, (int)$school_id);
                $deleted++;
                continue;
            }
            
            $actual_products = [];
            foreach ($master_order->get_items() as $item) {
                if (!is_a($item, 'WC_Order_Item_Product')) continue;
                
                $product_id = $item->get_product_id();
                $variation_id = $item->get_variation_id();
                $key = $product_id . '_' . $variation_id;
                
                $actual_products[$key] = [
                    'product_id' => $product_id,
                    'quantity' => $item->get_quantity(),
                    'name' => $item->get_name()
                ];
            }
            
            // 4. Comparar: ¿coinciden TODOS los productos?
            $has_discrepancies = false;
            $all_keys = array_unique(array_merge(array_keys($expected_products), array_keys($actual_products)));
            
            foreach ($all_keys as $key) {
                $expected_qty = $expected_products[$key]['quantity'] ?? 0;
                $actual_qty = $actual_products[$key]['quantity'] ?? 0;
                
                if ($expected_qty !== $actual_qty) {
                    $has_discrepancies = true;
                }
            }
            
            // 5. DECISIÓN: ¿Validar o ELIMINAR?
            if ($has_discrepancies) {
                // Obtener school_id de la tabla
                $table_name = $wpdb->prefix . 'school_master_orders';
                $school_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT school_id FROM {$table_name} WHERE master_order_id = %d",
                    $master_order_id
                ));
                
                $this->deleteMasterOrderAndResetChildren($master_order_id, $child_order_ids, (int)$school_id);
                $deleted++;
            } else {
                $validated_ok++;
            }
        }
        
        // Mostrar mensaje de administración si hubo eliminaciones
        if ($deleted > 0) {
            set_transient('master_order_validation_failed', [
                'deleted' => $deleted,
                'validated_ok' => $validated_ok
            ], 30); // 30 segundos
        }
    }
    
    /**
     * Eliminar master order y resetear pedidos hijos a processing
     */
    private function deleteMasterOrderAndResetChildren(int $master_order_id, array $child_order_ids, int $school_id): void
    {
        global $wpdb;
        
        // 1. Resetear todos los pedidos hijos a 'processing' y quitar master_order_id
        $children_reset = 0;
        $children_errors = 0;
        
        foreach ($child_order_ids as $child_id) {
            $child_order = wc_get_order($child_id);
            if (!$child_order) {
                $children_errors++;
                continue;
            }
            
            $old_status = $child_order->get_status();
            
            // Quitar metadata de master order
            $child_order->delete_meta_data('_master_order_id');
            $child_order->delete_meta_data('_added_to_master_at');
            
            // Volver a processing si estaba en reviewed
            if ($old_status === 'reviewed') {
                $child_order->set_status('processing', __('Master order validation failed - order restored to processing', 'text-domain'));
            }
            
            // Añadir nota administrativa explicando qué pasó
            $child_order->add_order_note(
                sprintf(
                    __('Validation error: Master order #%d failed validation and was deleted. This order has been restored to processing status. Product quantities did not match between child orders and master order.', 'text-domain'),
                    $master_order_id
                ),
                false, // is_customer_note = false (solo admin)
                true   // added_by_user = true
            );
            
            $child_order->save();
            
            $children_reset++;
        }
        
        // 2. Cambiar estado de la master order a 'trash' y luego eliminarla
        $master_order = wc_get_order($master_order_id);
        
        if ($master_order) {
            // Añadir nota explicativa antes de eliminar
            $master_order->add_order_note(
                sprintf(
                    __('Validation failed: Product quantities in master order did not match child orders. Master order deleted and %d child orders restored to processing status.', 'text-domain'),
                    count($child_order_ids)
                ),
                false,
                true
            );
            
            // Cambiar a trash con mensaje traducible
            $master_order->set_status('trash', __('Validation failed - master order deleted', 'text-domain'));
            $master_order->save();
            
            // Luego eliminar permanentemente (true = force delete)
            $master_order->delete(true);
        }
        
        // 3. Eliminar registro de la tabla de control
        $table_name = $wpdb->prefix . 'school_master_orders';
        
        $wpdb->delete(
            $table_name,
            ['master_order_id' => $master_order_id, 'school_id' => $school_id],
            ['%d', '%d']
        );
    }

    /**
     * Validar que los productos totales de una master order coincidan con sus hijos
     * 
     * Esta función compara los productos y cantidades de una master order con la suma
     * de todos sus pedidos hijos (_included_orders). Detecta discrepancias que pueden
     * indicar duplicados o problemas en la agregación.
     * 
     * PROTECCIÓN: Usa MySQL lock para garantizar lectura consistente de datos
     * 
     * @param int $master_order_id ID de la master order a validar
     * @return array Resultado de validación con estado y detalles
     */
    private function validateMasterOrderProductTotals(int $master_order_id): array
    {
        // PROTECCIÓN: Lock MySQL para lectura consistente
        $lock_name = "validate_master_order_{$master_order_id}";
        $lock_timeout = 10; // 10 segundos - solo lectura, debería ser rápido
        
        global $wpdb;
        $lock_result = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, %d)", $lock_name, $lock_timeout));
        
        if ($lock_result != 1) {
            return [
                'valid' => false,
                'error' => 'Could not acquire validation lock',
                'master_order_id' => $master_order_id
            ];
        }

        try {
            $master_order = wc_get_order($master_order_id);
            
            if (!$master_order) {
                return [
                    'valid' => false,
                    'error' => 'Master order not found',
                    'master_order_id' => $master_order_id
                ];
            }

            // Obtener lista de pedidos incluidos
            $included_orders = $master_order->get_meta('_included_orders') ?: [];
            
            if (empty($included_orders)) {
                return [
                    'valid' => true,
                    'message' => 'No child orders yet',
                    'master_order_id' => $master_order_id
                ];
            }

        // Calcular suma de productos de todos los pedidos hijos
        $child_products_sum = [];
        
        foreach ($included_orders as $child_order_id) {
            $child_order = wc_get_order($child_order_id);
            if (!$child_order) {
                continue;
            }

            foreach ($child_order->get_items() as $item) {
                if (!is_a($item, 'WC_Order_Item_Product')) {
                    continue;
                }

                $product_id = $item->get_product_id();
                $variation_id = $item->get_variation_id();
                $key = $variation_id ? "product_{$product_id}_var_{$variation_id}" : "product_{$product_id}";

                if (!isset($child_products_sum[$key])) {
                    $child_products_sum[$key] = [
                        'product_id' => $product_id,
                        'variation_id' => $variation_id,
                        'quantity' => 0,
                        'name' => $item->get_name()
                    ];
                }

                $child_products_sum[$key]['quantity'] += $item->get_quantity();
            }
        }

        // Obtener productos de la master order
        $master_products = [];
        foreach ($master_order->get_items() as $item) {
            if (!is_a($item, 'WC_Order_Item_Product')) {
                continue;
            }

            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $key = $variation_id ? "product_{$product_id}_var_{$variation_id}" : "product_{$product_id}";

            $master_products[$key] = [
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'quantity' => $item->get_quantity(),
                'name' => $item->get_name()
            ];
        }

        // Comparar productos
        $discrepancies = [];
        $all_product_keys = array_unique(array_merge(
            array_keys($child_products_sum),
            array_keys($master_products)
        ));

        foreach ($all_product_keys as $key) {
            $child_qty = $child_products_sum[$key]['quantity'] ?? 0;
            $master_qty = $master_products[$key]['quantity'] ?? 0;

            if ($child_qty != $master_qty) {
                $discrepancies[] = [
                    'product_key' => $key,
                    'product_id' => $child_products_sum[$key]['product_id'] ?? $master_products[$key]['product_id'],
                    'variation_id' => $child_products_sum[$key]['variation_id'] ?? $master_products[$key]['variation_id'],
                    'name' => $child_products_sum[$key]['name'] ?? $master_products[$key]['name'],
                    'expected_qty' => $child_qty,
                    'actual_qty' => $master_qty,
                    'difference' => $master_qty - $child_qty
                ];
            }
        }

        if (!empty($discrepancies)) {
            return [
                'valid' => false,
                'master_order_id' => $master_order_id,
                'child_orders_count' => count($included_orders),
                'discrepancies' => $discrepancies,
                'summary' => sprintf(
                    '%d product(s) with quantity mismatches',
                    count($discrepancies)
                )
            ];
        }

        return [
            'valid' => true,
            'master_order_id' => $master_order_id,
            'child_orders_count' => count($included_orders),
            'products_validated' => count($all_product_keys)
        ];

        } finally {
            // SIEMPRE liberar el lock
            $wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
        }
    }
}

// Inicializar automáticamente si no existe instancia
if (!isset($GLOBALS['master_order_manager'])) {
    $GLOBALS['master_order_manager'] = new MasterOrderManager();
}

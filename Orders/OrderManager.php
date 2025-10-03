<?php
/**
 * Order Manager for handling order operations
 * 
 * @package SchoolManagement\Orders
 * @since 1.0.0
 */

namespace SchoolManagement\Orders;
use SchoolManagement\Shared\Constants;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for managing orders and their school/vendor data
 */
class OrderManager
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->initHooks();
    }

    /**
     * Initialize WordPress hooks
     * 
     * @return void
     */
    public function initHooks(): void
    {
        add_action('woocommerce_checkout_create_order', [$this, 'saveOrderSchoolVendorData'], 10, 2);
        
        // Hook adicional para capturar master orders y otras órdenes creadas programáticamente
        add_action('woocommerce_new_order', [$this, 'addSchoolAndVendorData'], 10, 2);
        
        // Hook adicional para cuando se actualiza una orden (para capturar master orders después del save)
        add_action('woocommerce_update_order', [$this, 'addSchoolAndVendorData'], 10, 2);
        
        // Hooks for old system (post-based)
        add_filter('manage_edit-shop_order_columns', [$this, 'addCustomColumns'], 710, 1);
        add_action('manage_shop_order_posts_custom_column', [$this, 'displayCustomColumnOld'], 710, 2);
        
        // Hooks for new system (HPOS)
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'addCustomColumns'], 710);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'displayCustomColumnNew'], 710, 2);
        
        // Make columns sortable
        add_filter('manage_edit-shop_order_sortable_columns', [$this, 'makeColumnsSortable'], 710);
        add_filter('woocommerce_shop_order_list_table_sortable_columns', [$this, 'makeColumnsSortable'], 710);
        
        // Custom sorting
        add_action('pre_get_posts', [$this, 'customOrderBy'], 15);
        
        // Payment status filters (only if PaymentStatusColumn is not active)
        add_action('admin_init', [$this, 'maybeAddPaymentFilters']);
        
        // Make master orders editable when in master-warehouse state
        add_filter('wc_order_is_editable', [$this, 'makeMasterOrdersEditableInWarehouse'], 10, 2);
    }

    /**
     * Save school and vendor information in the order
     * 
     * @param \WC_Order $order Order object
     * @param array $data Checkout data
     * @return void
     */
    public function saveOrderSchoolVendorData(\WC_Order $order, array $data): void
    {
        $user_id = $order->get_user_id();
        if (!$user_id) {
            return;
        }

        $school_id = get_user_meta($user_id, 'school_id', true);

        if (!empty($school_id)) {
            $order->update_meta_data(\SchoolManagement\Shared\Constants::ORDER_META_SCHOOL_ID, $school_id);
            $school_name = get_the_title($school_id);
            $order->update_meta_data('_school_name', $school_name);

            // Get vendor information
            $vendor_id = get_field(\SchoolManagement\Shared\Constants::ACF_FIELD_VENDOR, $school_id);
            if ($vendor_id) {
                $vendor_name = get_the_title($vendor_id);
                $order->update_meta_data(\SchoolManagement\Shared\Constants::ORDER_META_VENDOR_ID, $vendor_id);
                $order->update_meta_data('_vendor_name', $vendor_name);
            }
        }
    }

    /**
     * Add school and vendor data to any order (including master orders)
     * 
     * @param int $order_id Order ID
     * @param \WC_Order $order Order object
     * @return void
     */
    public function addSchoolAndVendorData(int $order_id, \WC_Order $order): void
    {
        self::assignSchoolAndVendorData($order_id, $order);
    }

    /**
     * Static method to assign school and vendor data (for external use like MasterOrderManager)
     * 
     * @param int $order_id Order ID
     * @param \WC_Order $order Order object
     * @return void
     */
    public static function assignSchoolAndVendorData(int $order_id, \WC_Order $order): void
    {
        // Si ya tiene vendor_id asignado, no hacer nada
        if ($order->get_meta(\SchoolManagement\Shared\Constants::ORDER_META_VENDOR_ID)) {
            return;
        }
        
        // Obtener school_id - para master orders está en _school_id, para órdenes normales en user meta
        $school_id = null;
        
        // Verificar si es master order
        $is_master_order = $order->get_meta('_is_master_order') === 'yes';
        
        if ($is_master_order) {
            // Para master orders, obtener school_id del meta directo
            $school_id = $order->get_meta('_school_id');
        } else {
            // Para órdenes normales, obtener del user
            $user_id = $order->get_user_id();
            if ($user_id) {
                $school_id = get_user_meta($user_id, 'school_id', true);
            }
        }
        
        if (empty($school_id)) {
            return;
        }

        // Solo actualizar si no existe ya
        if (!$order->get_meta(\SchoolManagement\Shared\Constants::ORDER_META_SCHOOL_ID)) {
            $order->update_meta_data(\SchoolManagement\Shared\Constants::ORDER_META_SCHOOL_ID, $school_id);
            $school_name = get_the_title($school_id);
            $order->update_meta_data('_school_name', $school_name);
        }

        // Para master orders, solo asignar vendor si el centro paga
        if ($is_master_order) {
            // the_billing_by_the_school = true significa que el centro SÍ paga
            $the_billing_by_the_school = get_field('the_billing_by_the_school', $school_id);
            $school_pays = ($the_billing_by_the_school === '1' || $the_billing_by_the_school === 1 || $the_billing_by_the_school === true);
            
            if (!$school_pays) {
                // Si el centro NO paga (padres pagan individualmente), no asignar vendor
                return;
            }
        }

        // Get vendor information
        $vendor_id = get_field(\SchoolManagement\Shared\Constants::ACF_FIELD_VENDOR, $school_id);
        if ($vendor_id) {
            $vendor_name = get_the_title($vendor_id);
            $order->update_meta_data(\SchoolManagement\Shared\Constants::ORDER_META_VENDOR_ID, $vendor_id);
            $order->update_meta_data('_vendor_name', $vendor_name);
            $order->save();
        }
    }

    /**
     * Add custom columns to orders list
     * 
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public function addCustomColumns(array $columns): array
    {
        if (!is_admin()) {
            return $columns;
        }

        $new_columns = [];

        foreach ($columns as $column_name => $column_info) {
            $new_columns[$column_name] = $column_info;
            
            if ($column_name === 'order_status' || $column_name === 'status') {
                $new_columns['school'] = __('School', 'neve-child');
                $new_columns['vendor'] = __('Vendor', 'neve-child');
            }
        }

        if (!isset($columns['order_status']) && !isset($columns['status'])) {
            $new_columns['school'] = __('School', 'neve-child');
            $new_columns['vendor'] = __('Vendor', 'neve-child');
        }

        return $new_columns;
    }

    /**
     * Display custom columns in old system (CPT)
     * 
     * @param string $column Column name
     * @param int $post_id Post ID
     * @return void
     */
    public function displayCustomColumnOld(string $column, int $post_id): void
    {
        if ($column !== 'school' && $column !== 'vendor') {
            return;
        }

        $order = wc_get_order($post_id);
        if (!$order) {
            echo '—';
            return;
        }

        $this->displayColumnContent($column, $order);
    }

    /**
     * Display custom columns in new system (HPOS)
     * 
     * @param string $column Column name
     * @param \WC_Order $order Order object
     * @return void
     */
    public function displayCustomColumnNew(string $column, \WC_Order $order): void
    {
        if ($column !== 'school' && $column !== 'vendor') {
            return;
        }

        if (!$order) {
            echo '—';
            return;
        }

        $this->displayColumnContent($column, $order);
    }

    /**
     * Display column content for both systems
     * 
     * @param string $column Column name
     * @param \WC_Order $order Order object
     * @return void
     */
    private function displayColumnContent(string $column, \WC_Order $order): void
    {
        if ($column === 'school') {
            $value = $order->get_meta('_school_name');
            echo !empty($value) ? esc_html($value) : '—';
        } elseif ($column === 'vendor') {
            $value = $order->get_meta('_vendor_name');
            echo !empty($value) ? esc_html($value) : '—';
        }
    }

    /**
     * Make columns sortable
     * 
     * @param array $columns Sortable columns
     * @return array Modified columns
     */
    public function makeColumnsSortable(array $columns): array
    {
        $columns['school'] = '_school_name';
        $columns['vendor'] = '_vendor_name';
        return $columns;
    }

    /**
     * Handle custom sorting
     * 
     * @param \WP_Query $query Query object
     * @return void
     */
    public function customOrderBy(\WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || ($screen->id !== 'edit-shop_order' && $screen->id !== 'woocommerce_page_wc-orders')) {
            return;
        }

        $orderby = $query->get('orderby');

        if ($orderby === '_school_name') {
            $query->set('meta_key', '_school_name');
            $query->set('orderby', 'meta_value');
        } elseif ($orderby === '_vendor_name') {
            $query->set('meta_key', '_vendor_name');
            $query->set('orderby', 'meta_value');
        }
    }

    /**
     * Get order school ID
     * 
     * @param \WC_Order $order Order object
     * @return int|null School ID
     */
    public function getOrderSchoolId(\WC_Order $order): ?int
    {
    $school_id = $order->get_meta(\SchoolManagement\Shared\Constants::ORDER_META_SCHOOL_ID);
        return $school_id ? (int) $school_id : null;
    }

    /**
     * Get order vendor ID
     * 
     * @param \WC_Order $order Order object
     * @return int|null Vendor ID
     */
    public function getOrderVendorId(\WC_Order $order): ?int
    {
    $vendor_id = $order->get_meta(\SchoolManagement\Shared\Constants::ORDER_META_VENDOR_ID);
        return $vendor_id ? (int) $vendor_id : null;
    }

    /**
     * Check if we should add payment filters (if PaymentStatusColumn is not active)
     * 
     * @return void
     */
    public function maybeAddPaymentFilters(): void
    {
        // Si PaymentStatusColumn ya está activo, no añadir filtros duplicados
        if (class_exists('SchoolManagement\Payments\PaymentStatusColumn')) {
            // PaymentStatusColumn maneja los filtros, pero nosotros podemos añadir funcionalidad adicional
        }
        
        // Reactivar filtros de pago con prioridad diferente para evitar conflictos
        add_action('restrict_manage_posts', [$this, 'addPaymentStatusFilter'], 15);
        add_action('woocommerce_order_list_table_restrict_manage_orders', [$this, 'addPaymentStatusFilterHPOS'], 15);
        add_filter('parse_query', [$this, 'handlePaymentStatusFiltering'], 15);
        add_filter('woocommerce_order_list_table_prepare_items_query_args', [$this, 'handlePaymentStatusFilteringHPOS'], 15);
    }

    /**
     * Add payment status filter for old system (CPT)
     * 
     * Creates three distinct filter categories:
     * 1. Paid orders (all payment methods confirmed)
     * 2. Unpaid bank transfers (require manual confirmation)  
     * 3. Unpaid other methods (other payment methods pending)
     * 
     * @return void
     */
    public function addPaymentStatusFilter(): void
    {
        global $typenow;
        
        if ($typenow !== 'shop_order') {
            return;
        }

        $current_filter = $_GET['om_payment_filter'] ?? '';
        
        echo '<select name="om_payment_filter" style="float: none; margin-left: 10px;">';
        echo '<option value="">' . esc_html__('All Orders', 'neve-child') . '</option>';
        echo '<option value="paid"' . selected($current_filter, 'paid', false) . '>✅ ' . esc_html__('Paid (all methods)', 'neve-child') . '</option>';
        echo '<option value="unpaid_transfer"' . selected($current_filter, 'unpaid_transfer', false) . '>🏦 ' . esc_html__('Pending Payment Bank Transfers', 'neve-child') . '</option>';
        echo '<option value="unpaid_other"' . selected($current_filter, 'unpaid_other', false) . '>⏳ ' . esc_html__('Pending Payment', 'neve-child') . '</option>';
        echo '</select>';
    }

    /**
     * Add payment status filter for new system (HPOS)
     * 
     * Creates three distinct filter categories:
     * 1. Paid orders (all payment methods confirmed)
     * 2. Unpaid bank transfers (require manual confirmation)  
     * 3. Unpaid other methods (other payment methods pending)
     * 
     * @return void
     */
    public function addPaymentStatusFilterHPOS(): void
    {
        $current_filter = $_GET['om_payment_filter'] ?? '';
        
        echo '<select name="om_payment_filter" style="float: none; margin-left: 10px;">';
        echo '<option value="">' . esc_html__('All Orders', 'neve-child') . '</option>';
        echo '<option value="paid"' . selected($current_filter, 'paid', false) . '>✅ ' . esc_html__('Paid (all methods)', 'neve-child') . '</option>';
        echo '<option value="unpaid_transfer"' . selected($current_filter, 'unpaid_transfer', false) . '>🏦 ' . esc_html__('Pending Payment Bank Transfers', 'neve-child') . '</option>';
        echo '<option value="unpaid_other"' . selected($current_filter, 'unpaid_other', false) . '>⏳ ' . esc_html__('Pending Payment', 'neve-child') . '</option>';
        echo '</select>';
    }

    /**
     * Handle payment status filtering for old system (CPT)
     * 
     * @param \WP_Query $query Query object
     * @return void
     */
    public function handlePaymentStatusFiltering(\WP_Query $query): void
    {
        global $pagenow, $typenow;
        
        if ($pagenow !== 'edit.php' || $typenow !== 'shop_order' || !is_admin()) {
            return;
        }

        $payment_filter = $_GET['om_payment_filter'] ?? '';
        
        if (empty($payment_filter)) {
            return;
        }

        // Para filtros de pago, necesitamos usar un enfoque post-query porque la lógica es compleja
        add_filter('posts_results', [$this, 'filterOrdersByPaymentStatus'], 10, 2);
    }

    /**
     * Handle payment status filtering for new system (HPOS)
     * 
     * @param array $query_args Query arguments
     * @return array Modified query arguments
     */
    public function handlePaymentStatusFilteringHPOS(array $query_args): array
    {
        $payment_filter = $_GET['om_payment_filter'] ?? '';
        
        if (empty($payment_filter)) {
            return $query_args;
        }

        // Para HPOS con lógica compleja, necesitamos obtener todos los pedidos
        try {
            $all_orders = wc_get_orders([
                'limit' => -1,
                'return' => 'ids',
                'status' => 'any',
                'type' => 'shop_order' // Excluir reembolsos desde la consulta
            ]);

            $filtered_order_ids = [];

            foreach ($all_orders as $order_id) {
                $order = wc_get_order($order_id);
                if (!$order) continue;

                // Saltar reembolsos - no son pedidos normales
                if (is_a($order, 'WC_Order_Refund') || is_a($order, 'Automattic\WooCommerce\Admin\Overrides\OrderRefund')) {
                    continue;
                }

                $should_include = $this->shouldIncludeOrderInFilter($order, $payment_filter);
                
                if ($should_include) {
                    $filtered_order_ids[] = $order_id;
                }
            }

            // Si no hay resultados, añadir un ID inválido para mostrar cero resultados
            if (empty($filtered_order_ids)) {
                $filtered_order_ids = [0];
            }

            // Para HPOS, usar 'id' en lugar de 'post__in'
            $query_args['id'] = $filtered_order_ids;

        } catch (\Exception $e) {
            // En caso de error, no modificar la query
        }
        
        return $query_args;
    }

    /**
     * Filter orders by payment status (post-query for CPT system)
     * 
     * @param array $posts Posts array
     * @param \WP_Query $query Query object
     * @return array Filtered posts
     */
    public function filterOrdersByPaymentStatus(array $posts, \WP_Query $query): array
    {
        // Solo aplicar en páginas de administración de pedidos
        if (!is_admin() || !$query->is_main_query()) {
            return $posts;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'edit-shop_order') {
            return $posts;
        }

        $payment_filter = $_GET['om_payment_filter'] ?? '';
        if (empty($payment_filter)) {
            return $posts;
        }

        $filtered_posts = [];

        foreach ($posts as $post) {
            if (!$post || $post->post_type !== 'shop_order') {
                continue;
            }

            $order = wc_get_order($post->ID);
            if (!$order) {
                continue;
            }

            if ($this->shouldIncludeOrderInFilter($order, $payment_filter)) {
                $filtered_posts[] = $post;
            }
        }

        return $filtered_posts;
    }

    /**
     * Determine if an order should be included in the filter
     * 
     * @param \WC_Order|\WC_Abstract_Order $order Order object (can be refund)
     * @param string $filter Filter type
     * @return bool Should include order
     */
    private function shouldIncludeOrderInFilter($order, string $filter): bool
    {
        // Si es un reembolso, nunca incluir en filtros de pago
        if (is_a($order, 'WC_Order_Refund') || is_a($order, 'Automattic\WooCommerce\Admin\Overrides\OrderRefund')) {
            return false;
        }

        $order_id = $order->get_id();
        $is_paid = $this->isOrderReallyPaid($order);
        $payment_method = $order->get_payment_method();
        $order_status = $order->get_status();

        $result = false;

        switch ($filter) {
            case 'paid':
                // Todos los pedidos pagados
                $result = $is_paid;
                break;
                
            case 'unpaid_transfer':
                // Sin pagar Y método de pago que requiere confirmación manual
                $is_master_order = $order->get_meta('_is_master_order') === 'yes';
                
                if ($is_master_order) {
                    // Para master orders: bacs/transferencia requieren confirmación manual
                    $is_transfer = in_array($payment_method, ['bacs', 'transferencia']);
                } else {
                    // Para pedidos individuales: bacs/transferencia requieren confirmación, student_payment NO
                    $is_transfer = in_array($payment_method, ['bacs', 'transferencia']);
                }
                
                $result = !$is_paid && $is_transfer;
                break;
                
            case 'unpaid_other':
                // Sin pagar Y método de pago NO de confirmación manual
                $is_master_order = $order->get_meta('_is_master_order') === 'yes';
                
                if ($is_master_order) {
                    // Para master orders: todo excepto bacs/transferencia
                    $is_transfer = in_array($payment_method, ['bacs', 'transferencia']);
                } else {
                    // Para pedidos individuales: todo excepto bacs/transferencia (student_payment va aquí)
                    $is_transfer = in_array($payment_method, ['bacs', 'transferencia']);
                }
                
                $result = !$is_paid && !$is_transfer;
                break;
                
            default:
                // Todos los pedidos (sin filtro)
                $result = true;
                break;
        }

        return $result;
    }

    /**
     * Verify if an order is really paid using the same logic as PaymentStatusColumn
     * 
     * @param \WC_Order $order Order object
     * @return bool If the order is paid
     */
    public function isOrderReallyPaid(\WC_Order $order): bool
    {
        $order_id = $order->get_id();
        
        // Verificar que es un objeto válido
        if (!$order || !is_object($order)) {
            return false;
        }
        
        // Si es un reembolso, no está "pagado" en el sentido de estado de pago
        if (is_a($order, 'WC_Order_Refund') || is_a($order, 'Automattic\WooCommerce\Admin\Overrides\OrderRefund')) {
            return false;
        }
        
        // Verificar que tiene los métodos básicos de WC_Order
        if (!method_exists($order, 'get_status') || !method_exists($order, 'get_id')) {
            return false;
        }
        
        $order_status = $order->get_status();
        
        // VERIFICACIÓN ESPECIAL PARA MASTER ORDERS QUE PAGA EL CENTRO
        $is_master_order = $order->get_meta('_is_master_order') === 'yes';
        if ($is_master_order) {
            $school_id = $order->get_meta('_school_id');
            if ($school_id) {
                $the_billing_by_the_school = get_field('the_billing_by_the_school', $school_id);
                $school_pays = ($the_billing_by_the_school === '1' || $the_billing_by_the_school === 1 || $the_billing_by_the_school === true);
                
                if ($school_pays) {
                    // PARA MASTER ORDERS QUE PAGA EL CENTRO: Solo considerar pagado con indicadores MANUALES específicos
                    $transaction_id = $order->get_transaction_id();
                    $payment_date = $order->get_meta('payment_date');
                    $deferred_payment_date = $order->get_meta('_dm_pay_later_card_payment_date');
                    
                    // Solo considerar pagado si:
                    // 1. Tiene transaction_id que comience con "manual_bank_" (confirmación manual)
                    // 2. O tiene deferred_payment_date (confirmación Redsys)
                    // 3. PERO NO solo payment_date (puede ser automático)
                    
                    $has_manual_bank_confirmation = !empty($transaction_id) && strpos($transaction_id, 'manual_bank_') === 0;
                    $has_redsys_confirmation = !empty($deferred_payment_date);
                    
                    if ($has_manual_bank_confirmation || $has_redsys_confirmation) {
                        return true;
                    }
                    
                    // Si NO tiene confirmaciones manuales específicas, NO está pagado
                    // independientemente del estado (completed, processing, etc.)
                    return false;
                }
            }
        }
        
        // VERIFICAR INDICADORES DE PAGO MANUAL PARA PEDIDOS NORMALES (PRIORIDAD MÁXIMA)
        $payment_date = $order->get_meta('payment_date');
        $transaction_id = $order->get_transaction_id();
        $deferred_payment_date = $order->get_meta('_dm_pay_later_card_payment_date');
        
        // Si tiene indicadores de pago manual, está pagado independientemente del estado
        if (!empty($payment_date) || !empty($transaction_id) || !empty($deferred_payment_date)) {
            return true;
        }
        
        // Estados que definitivamente indican que está pagado SOLO si no hemos encontrado indicadores manuales
        if ($order_status === 'completed') {
            $payment_method = $order->get_payment_method();
            
            // LÓGICA ESPECIAL SEGÚN TIPO DE PEDIDO:
            $is_master_order = $order->get_meta('_is_master_order') === 'yes';
            
            if ($is_master_order) {
                // MASTER ORDERS: Requieren confirmación manual para métodos específicos
                if ($payment_method === 'bacs' || $payment_method === 'transferencia') {
                    // Master orders con transferencia bancaria completed sin indicadores manuales = NO PAGADO
                    return false;
                }
            } else {
                // PEDIDOS INDIVIDUALES: Solo transferencias bancarias directas requieren confirmación
                if ($payment_method === 'bacs' || $payment_method === 'transferencia') {
                    // Pedidos individuales con transferencia completed sin indicadores manuales = NO PAGADO
                    return false;
                }
                // IMPORTANTE: student_payment en pedidos individuales SÍ se considera pagado automáticamente
                if ($payment_method === 'student_payment') {
                    // Los pedidos individuales que paga el centro se consideran pagados al completar
                    return true;
                }
            }
            
            // Para otros métodos de pago completed sin indicadores manuales, verificar needs_payment
            $needs_payment = $order->needs_payment();
            if (!$needs_payment) {
                return true;
            } else {
                // Completed pero needs_payment=true y sin indicadores manuales = no pagado realmente
                return false;
            }
        }
        
        // Para estado 'processing' verificar múltiples indicadores
        if ($order_status === 'processing') {
            // LÓGICA ESPECIAL PARA MASTER ORDERS EN PROCESSING
            $is_master_order = $order->get_meta('_is_master_order') === 'yes';
            if ($is_master_order) {
                // Verificar si el centro paga directamente
                $school_id = $order->get_meta('_school_id');
                $school_pays = false;
                if ($school_id) {
                    $the_billing_by_the_school = get_field('the_billing_by_the_school', $school_id);
                    $school_pays = ($the_billing_by_the_school === '1' || $the_billing_by_the_school === 1 || $the_billing_by_the_school === true);
                }
                
                if ($school_pays) {
                    // Solo las master orders que paga el centro requieren indicadores de pago manual
                    if (!empty($payment_date) || !empty($transaction_id) || !empty($deferred_payment_date)) {
                        return true;
                    } else {
                        // Master order que paga el centro sin indicadores de pago manual = NO PAGADA
                        return false;
                    }
                }
                // Si NO paga el centro (pagos individuales), usar lógica estándar
            }
            
            // 1. Verificar transaction ID REAL (no auto-generado)
            if (!empty($transaction_id) && !str_starts_with($transaction_id, 'auto_')) {
                return true;
            }
        }
        
        // NUEVO: Para estado 'master-order' (master orders que paga el centro directamente)
        if ($order_status === 'master-order') {
            $is_master_order = $order->get_meta('_is_master_order') === 'yes';
            if ($is_master_order) {
                // Verificar si el centro paga directamente
                $school_id = $order->get_meta('_school_id');
                $school_pays = false;
                if ($school_id) {
                    $the_billing_by_the_school = get_field('the_billing_by_the_school', $school_id);
                    $school_pays = ($the_billing_by_the_school === '1' || $the_billing_by_the_school === 1 || $the_billing_by_the_school === true);
                }
                
                if ($school_pays) {
                    // Solo las master orders que paga el centro requieren indicadores de pago manual
                    if (!empty($payment_date) || !empty($transaction_id) || !empty($deferred_payment_date)) {
                        return true;
                    } else {
                        // Master order que paga el centro sin indicadores de pago manual = NO PAGADA
                        return false;
                    }
                }
                // Si NO paga el centro (pagos individuales), usar lógica estándar de needs_payment
                return !$order->needs_payment();
            }
        }
        
        // Para estado 'processing' verificar múltiples indicadores  
        if ($order_status === 'processing') {
            // 1. Verificar transaction ID REAL (no auto-generado)
            if (!empty($transaction_id) && !str_starts_with($transaction_id, 'auto_')) {
                return true;
            }
            
            // 2. Verificar indicadores específicos de pago CONFIRMADO por Redsys
            $redsys_confirmations = [
                '_redsys_order_number',
                '_redsys_auth_code', 
                '_redsys_response',
                '_redsys_authorization',
                '_redsys_authorisation_code',
                '_payment_complete_reduce_order_stock'
            ];
            
            foreach ($redsys_confirmations as $meta_key) {
                $meta_value = $order->get_meta($meta_key);
                if (!empty($meta_value)) {
                    return true;
                }
            }
            
            // 3. Si tiene payment_date Y no necesita pago, probablemente está pagado
            if ((!empty($payment_date) || !empty($deferred_payment_date)) && !$order->needs_payment()) {
                return true;
            }
            
            // Si no tiene confirmaciones reales, probablemente no está pagado
            return false;
        }
        
        // Estados que indican no pagado
        if (in_array($order_status, ['pending', 'on-hold', 'failed', 'cancelled'])) {
            return false;
        }
        
        // Para otros estados, verificar si necesita pago
        $needs_payment = $order->needs_payment();
        $result = !$needs_payment;
        return $result;
    }

    /**
     * Make master orders editable when in master-warehouse state
     * 
     * @param bool $is_editable Current editable state
     * @param \WC_Order $order Order object
     * @return bool Modified editable state
     */
    public function makeMasterOrdersEditableInWarehouse(bool $is_editable, \WC_Order $order): bool
    {
        // Si ya es editable, no cambiar nada
        if ($is_editable) {
            return $is_editable;
        }
        
        // Verificar que sea una master order
        $is_master_order = $order->get_meta('_is_master_order') === 'yes';
        if (!$is_master_order) {
            return $is_editable;
        }
        // VERIFICAR PRIMERO SI TIENE FACTURA - Si tiene factura, NO puede editarse
        if ($this->orderHasInvoice($order)) {
            return false;
        }
        
        // Verificar que esté en estado master-warehouse (mast-warehs)
        $order_status = $order->get_status();
        if ($order_status === 'mast-warehs') {
            return true;
        }
        
        return $is_editable;
    }

    /**
     * Verificar si un pedido tiene factura generada
     * 
     * @param \WC_Order $order Objeto del pedido
     * @return bool True si tiene factura, false si no
     */
    private function orderHasInvoice(\WC_Order $order): bool
    {
        // Verificar si existe documento de factura (WC PDF Invoices & Packing Slips)
        if (function_exists('wcpdf_get_document')) {
            $invoice = wcpdf_get_document('invoice', $order);
            if ($invoice && $invoice->exists()) {
                return true;
            }
            
            // También verificar factura simplificada
            $simplified_invoice = wcpdf_get_document('simplified-invoice', $order);
            if ($simplified_invoice && $simplified_invoice->exists()) {
                return true;
            }
        }
        
        // Verificar meta fields de facturación
        $invoice_number = $order->get_meta('_wcpdf_invoice_number');
        $invoice_date = $order->get_meta('_wcpdf_invoice_date');
        
        if (!empty($invoice_number) || !empty($invoice_date)) {
            return true;
        }
        
        // Verificar otros posibles meta fields de facturación
        $other_invoice_fields = [
            '_invoice_number',
            '_factura_numero', 
            '_invoice_generated',
            '_wcpdf_invoice_exists',
            '_wcpdf_invoice_number_data',
            '_wcpdf_simplified_invoice_number'
        ];
        
        foreach ($other_invoice_fields as $field) {
            if (!empty($order->get_meta($field))) {
                return true;
            }
        }
        
        return false;
    }
}
 
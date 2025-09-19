<?php
/**
 * Columna de Estado de Pago para WooCommerce Admin
 * 
 * @package SchoolManagement\Payments
 * @since 1.0.0
 * @version 1.0.0
 * 
 * FUNCIONALIDADES PRINCIPALES:
 * 
 * 1. COLUMNA VISUAL DE ESTADO DE PAGO:
 *    - Añade columna "Estado de Pago" en la lista de pedidos del admin
 *    - Indicadores visuales claros: PAGADO (verde) / SIN PAGAR (rojo)
 *    - Iconos distintivos para identificación rápida
 * 
 * 2. INTEGRACIÓN CON PAYMENTHANDLER:
 *    - Usa la lógica de verificación de pagos reales del PaymentHandler
 *    - Compatible con pagos diferidos de Redsys
 *    - Detecta pagos cancelados y estados incorrectos
 * 
 * 3. FILTRADO POR ESTADO DE PAGO:
 *    - Filtros rápidos: "Solo pagados" / "Solo sin pagar"
 *    - Búsqueda y gestión eficiente de pedidos
 *    - Integración con filtros existentes de WooCommerce
 * 
 * 4. COMPATIBILIDAD TOTAL:
 *    - Funciona con todos los métodos de pago
 *    - Compatible con estados personalizados
 *    - No interfiere con otras columnas o funcionalidades
 * 
 * COMPATIBILIDAD:
 * - WooCommerce 3.0+
 * - WordPress 5.0+
 * - Integración con PaymentHandler personalizado
 */

namespace SchoolManagement\Payments;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gestión de columna de estado de pago en admin de WooCommerce
 * 
 * Esta clase añade una columna visual en la lista de pedidos del admin
 * para identificar rápidamente qué pedidos están pagados y cuáles no,
 * usando la lógica avanzada de verificación de pagos del PaymentHandler.
 * 
 * CARACTERÍSTICAS PRINCIPALES:
 * - Columna visual con iconos y colores distintivos
 * - Verificación inteligente usando PaymentHandler
 * - Filtros rápidos para gestión eficiente
 * - Compatible con pagos diferidos y Redsys
 * 
 * USO:
 * La clase se auto-registra en WordPress hooks al instanciarse.
 * No requiere configuración adicional una vez incluida en el tema.
 * 
 * @since 1.0.0
 * @version 1.0.0
 */
class PaymentStatusColumn
{
    /**
     * Instancia del PaymentHandler para verificaciones de pago
     */
    private $paymentHandler;

        /**
     * Constructor
     * 
     * @param PaymentHandler $paymentHandler Instancia de PaymentHandler
     */
    public function __construct(PaymentHandler $paymentHandler)
    {
        $this->paymentHandler = $paymentHandler;
        $this->initHooks();
    }

    /**
     * Inicializar hooks de WordPress
     * 
     * @return void
     */
    private function initHooks(): void
    {
        // Hooks para AMBOS sistemas: Legacy posts y nuevo HPOS de WooCommerce
        
        // Sistema legacy (posts)
        add_filter('manage_shop_order_posts_columns', [$this, 'addPaymentStatusColumn'], 15);
        add_action('manage_shop_order_posts_custom_column', [$this, 'displayPaymentStatusColumn'], 10, 2);
        add_filter('manage_edit-shop_order_sortable_columns', [$this, 'makePaymentStatusColumnSortable']);
        
        // Sistema nuevo HPOS (WooCommerce 3.3+)
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'addPaymentStatusColumn'], 15);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'displayPaymentStatusColumnHPOS'], 10, 2);
        add_filter('manage_woocommerce_page_wc-orders_sortable_columns', [$this, 'makePaymentStatusColumnSortable']);
        
        // Ordenación para ambos sistemas
        add_action('pre_get_posts', [$this, 'handlePaymentStatusSorting'], 20);
        add_filter('woocommerce_order_list_table_prepare_items_query_args', [$this, 'handlePaymentStatusSortingHPOS'], 20);
        add_filter('woocommerce_orders_table_query_clauses', [$this, 'handlePaymentStatusSortingHPOSAlternative'], 20, 2);
        
        // Hooks para actualizar valores de ordenación
        add_action('woocommerce_order_status_changed', [$this, 'updateOrderSortValue'], 10, 1);
        add_action('woocommerce_payment_complete', [$this, 'updateOrderSortValue'], 10, 1);
        add_action('save_post_shop_order', [$this, 'updateOrderSortValue'], 10, 1);
        
        // Filtros habilitados para incluir refunds
        add_action('restrict_manage_posts', [$this, 'addPaymentStatusFilters']);
        add_action('woocommerce_order_list_table_restrict_manage_orders', [$this, 'addPaymentStatusFiltersHPOS']);
        add_filter('parse_query', [$this, 'handlePaymentStatusFiltering']);
        add_filter('woocommerce_order_list_table_prepare_items_query_args', [$this, 'handlePaymentStatusFilteringHPOS']);
        
        // CSS para ambos sistemas
        add_action('admin_head', [$this, 'addPaymentStatusColumnCSS']);
    }

    /**
     * Añadir columna de estado de pago a la tabla de pedidos
     * 
     * @param array $columns Columnas existentes
     * @return array Columnas modificadas
     */
    public function addPaymentStatusColumn(array $columns): array
    {
        // Insertar la columna después de la columna de estado del pedido
        $new_columns = [];
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            // Añadir después de la columna de estado
            if ($key === 'order_status') {
                $new_columns['payment_status'] = '<span title="' . __('Payment Status', 'neve-child') . '">💳 ' . __('Payment', 'neve-child') . '</span>';
            }
        }
        
        // Si no encontramos order_status, añadir al final
        if (!isset($new_columns['payment_status'])) {
            $new_columns['payment_status'] = '<span title="' . __('Payment Status', 'neve-child') . '">💳 ' . __('Payment', 'neve-child') . '</span>';
        }
        
        return $new_columns;
    }

    /**
     * Mostrar contenido de la columna de estado de pago (Sistema Legacy)
     * 
     * @param string $column Nombre de la columna
     * @param int $post_id ID del post (pedido)
     * @return void
     */
    public function displayPaymentStatusColumn(string $column, int $post_id): void
    {
        if ($column !== 'payment_status') {
            return;
        }

        $order = wc_get_order($post_id);
        if (!$order) {
            echo '<span class="payment-status-unknown">❓ ' . __('Unknown', 'neve-child') . '</span>';
            return;
        }

        $this->renderPaymentStatus($order);
    }

    /**
     * Mostrar contenido de la columna de estado de pago (Sistema HPOS)
     * 
     * @param string $column Nombre de la columna
     * @param \WC_Order $order Objeto de pedido
     * @return void
     */
    public function displayPaymentStatusColumnHPOS(string $column, \WC_Order $order): void
    {
        if ($column !== 'payment_status') {
            return;
        }

        if (!$order) {
            echo '<span class="payment-status-unknown">❓ ' . __('Unknown', 'neve-child') . '</span>';
            return;
        }

        $this->renderPaymentStatus($order);
    }

    /**
     * Renderizar el estado de pago (método común para ambos sistemas)
     * 
     * @param \WC_Order $order Objeto de pedido
     * @return void
     */
    private function renderPaymentStatus(\WC_Order $order): void
    {
        // Verificar si se debe actualizar el método de pago por indicadores manuales
        $this->checkAndUpdatePaymentMethodForManualPayment($order);
        
        // PRIMERO: Verificar si hay reembolsos
        $refund_status = $this->getRefundStatus($order);
        if ($refund_status !== null) {
            echo $refund_status;
            return;
        }
        
        $is_paid = $this->isOrderReallyPaid($order);
        $payment_method = $order->get_payment_method();
        $order_total = $order->get_formatted_order_total();
        
        if ($is_paid) {
            $payment_date = $this->getOrderPaymentDate($order);
            $tooltip = __('Order paid', 'neve-child');
            if ($payment_date) {
                $tooltip .= " " . __('on', 'neve-child') . " " . date('d/m/Y H:i', strtotime($payment_date));
            }
            $tooltip .= " - " . __('Method', 'neve-child') . ": " . $this->getPaymentMethodName($payment_method);
            $tooltip .= " - " . __('Total', 'neve-child') . ": " . wp_strip_all_tags($order_total);
            
            echo '<span class="payment-status-paid" title="' . esc_attr($tooltip) . '">✅ ' . __('PAID', 'neve-child') . '</span>';
        } else {
            $needs_payment = $order->needs_payment();
            $order_status = $order->get_status();
            
            // Verificar si es transferencia bancaria
            if ($payment_method === 'bacs' || $payment_method === 'transferencia') {
                $tooltip = __('Order pending bank transfer', 'neve-child');
                $tooltip .= " - " . __('Status', 'neve-child') . ": " . wc_get_order_status_name($order_status);
                $tooltip .= " - " . __('Method', 'neve-child') . ": " . $this->getPaymentMethodName($payment_method);
                $tooltip .= " - " . __('Total', 'neve-child') . ": " . wp_strip_all_tags($order_total);
                
                echo '<span class="payment-status-transfer" title="' . esc_attr($tooltip) . '">🏦 ' . __('TRANSFER', 'neve-child') . '</span>';
                return;
            }
            
            $tooltip = __('Pending payment order', 'neve-child');
            $tooltip .= " - " . __('Status', 'neve-child') . ": " . wc_get_order_status_name($order_status);
            $tooltip .= " - " . __('Method', 'neve-child') . ": " . $this->getPaymentMethodName($payment_method);
            $tooltip .= " - " . __('Total', 'neve-child') . ": " . wp_strip_all_tags($order_total);
            
            if ($needs_payment) {
                $pay_url = $order->get_checkout_payment_url();
                echo '<span class="payment-status-unpaid" title="' . esc_attr($tooltip) . '">';
                echo '<a href="' . esc_url($pay_url) . '" target="_blank" class="payment-link">⏳ ' . __('Pending Payment', 'neve-child') . '</a>';
                echo '</span>';
            } else {
                echo '<span class="payment-status-unpaid" title="' . esc_attr($tooltip) . '">⏳ ' . __('PENDING', 'neve-child') . '</span>';
            }
        }
    }

    /**
     * Hacer la columna de estado de pago ordenable
     * 
     * @param array $columns Columnas ordenables
     * @return array Columnas modificadas
     */
    public function makePaymentStatusColumnSortable(array $columns): array
    {
        $columns['payment_status'] = 'payment_status';
        return $columns;
    }

    /**
     * Manejar la ordenación por estado de pago (Sistema Legacy)
     * 
     * @param \WP_Query $query Objeto de consulta
     * @return void
     */
    public function handlePaymentStatusSorting(\WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        $orderby = $query->get('orderby');
        
        if ($orderby === 'payment_status') {
            $order_direction = $_GET['order'] ?? 'asc';
            
            // Ordenar usando meta_query para capturar estados de pago
            $query->set('orderby', 'meta_value_num');
            $query->set('meta_key', '_payment_status_sort');
            $query->set('order', $order_direction);
            
            // Asignar valores de ordenación antes de la consulta
            add_action('pre_get_posts', [$this, 'assignPaymentSortValues'], 5);
        }
    }

    /**
     * Manejar la ordenación por estado de pago (Sistema HPOS)
     * 
     * @param array $query_args Argumentos de consulta
     * @return array Argumentos modificados
     */
    public function handlePaymentStatusSortingHPOS(array $query_args): array
    {
        if (!isset($_GET['orderby']) || $_GET['orderby'] !== 'payment_status') {
            return $query_args;
        }

        $order_direction = $_GET['order'] ?? 'asc';
        
        // Ordenar por nuestro meta_key personalizado
        $query_args['orderby'] = 'meta_value_num';
        $query_args['meta_key'] = '_payment_status_sort';
        $query_args['order'] = $order_direction;
        
        // Asignar valores de ordenación para los pedidos
        add_action('woocommerce_before_order_list_table', [$this, 'assignPaymentSortValuesHPOS']);
        
        return $query_args;
    }

    /**
     * Método alternativo para manejar ordenación HPOS
     * 
     * @param array $clauses
     * @param array $query_vars
     * @return array
     */
    public function handlePaymentStatusSortingHPOSAlternative($clauses, $query_vars): array
    {
        if (!isset($_GET['orderby']) || $_GET['orderby'] !== 'payment_status') {
            return $clauses;
        }

        $order_direction = $_GET['order'] ?? 'asc';
        
        // Asignar valores de ordenación primero
        $this->assignPaymentSortValuesHPOS();
        
        // Modificar las cláusulas de la consulta
        if (isset($clauses['orderby'])) {
            $clauses['orderby'] = "CAST(meta_payment_status_sort.meta_value AS UNSIGNED) $order_direction";
        }
        
        if (isset($clauses['join'])) {
            $clauses['join'] .= " LEFT JOIN lnu_wc_orders_meta AS meta_payment_status_sort ON lnu_wc_orders.id = meta_payment_status_sort.order_id AND meta_payment_status_sort.meta_key = '_payment_status_sort'";
        }
        
        return $clauses;
    }

    /**
     * Añadir filtros rápidos para estado de pago (Sistema HPOS)
     * 
     * @return void
     */
    public function addPaymentStatusFiltersHPOS(): void
    {
        $current_filter = $_GET['payment_filter'] ?? '';
        
        echo '<select name="payment_filter" style="float: none; margin-left: 10px;">';
        echo '<option value="">' . __('All payment statuses', 'neve-child') . '</option>';
        echo '<option value="paid"' . selected($current_filter, 'paid', false) . '>✅ ' . __('Only paid', 'neve-child') . '</option>';
        echo '<option value="unpaid"' . selected($current_filter, 'unpaid', false) . '>⏳ ' . __('Pending payment', 'neve-child') . '</option>';
        echo '<option value="transfer"' . selected($current_filter, 'transfer', false) . '>🏦 ' . __('Pending Payment transfers', 'neve-child') . '</option>';
        echo '<option value="refund"' . selected($current_filter, 'refund', false) . '>💰 ' . __('With refunds', 'neve-child') . '</option>';
        echo '<option value="full_refund"' . selected($current_filter, 'full_refund', false) . '>🔴 ' . __('Full refunds', 'neve-child') . '</option>';
        echo '<option value="partial_refund"' . selected($current_filter, 'partial_refund', false) . '>🟠 ' . __('Partial refunds', 'neve-child') . '</option>';
        echo '</select>';
    }

    /**
     * Manejar filtrado por estado de pago (Sistema HPOS)
     * 
     * @param array $query_args Argumentos de consulta
     * @return array Argumentos modificados
     */
    public function handlePaymentStatusFilteringHPOS(array $query_args): array
    {
        $payment_filter = $_GET['payment_filter'] ?? '';
        
        if (empty($payment_filter)) {
            return $query_args;
        }

        // Para filtros de pago, necesitamos usar la misma lógica que la columna
        // Obtener todos los pedidos y filtrar por ID usando la lógica real de pago
        try {
            $all_orders = wc_get_orders([
                'limit' => -1,
                'return' => 'ids',
                'status' => 'any',
                'type' => 'shop_order'
            ]);

            $filtered_order_ids = [];

            foreach ($all_orders as $order_id) {
                $order = wc_get_order($order_id);
                if (!$order) continue;

                // Saltar reembolsos
                if (is_a($order, 'WC_Order_Refund') || is_a($order, 'Automattic\WooCommerce\Admin\Overrides\OrderRefund')) {
                    continue;
                }

                $should_include = $this->shouldIncludeOrderInPaymentFilter($order, $payment_filter);
                
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
     * Añadir filtros rápidos para estado de pago
     * 
     * @return void
     */
    public function addPaymentStatusFilters(): void
    {
        global $typenow;
        
        if ($typenow !== 'shop_order') {
            return;
        }

        $current_filter = $_GET['payment_filter'] ?? '';
        
        echo '<select name="payment_filter" style="float: none;">';
        echo '<option value="">' . __('All payment statuses', 'neve-child') . '</option>';
        echo '<option value="paid"' . selected($current_filter, 'paid', false) . '>✅ ' . __('Only paid', 'neve-child') . '</option>';
        echo '<option value="unpaid"' . selected($current_filter, 'unpaid', false) . '>⏳ ' . __('Pending payment', 'neve-child') . '</option>';
        echo '<option value="transfer"' . selected($current_filter, 'transfer', false) . '>🏦 ' . __('Pending Payment transfers', 'neve-child') . '</option>';
        echo '<option value="refund"' . selected($current_filter, 'refund', false) . '>💰 ' . __('With refunds', 'neve-child') . '</option>';
        echo '<option value="full_refund"' . selected($current_filter, 'full_refund', false) . '>🔴 ' . __('Full refunds', 'neve-child') . '</option>';
        echo '<option value="partial_refund"' . selected($current_filter, 'partial_refund', false) . '>🟠 ' . __('Partial refunds', 'neve-child') . '</option>';
        echo '</select>';
    }

    /**
     * Manejar filtrado por estado de pago
     * 
     * @param \WP_Query $query Objeto de consulta
     * @return void
     */
    public function handlePaymentStatusFiltering(\WP_Query $query): void
    {
        global $pagenow, $typenow;
        
        if ($pagenow !== 'edit.php' || $typenow !== 'shop_order' || !is_admin()) {
            return;
        }

        $payment_filter = $_GET['payment_filter'] ?? '';
        
        if (empty($payment_filter)) {
            return;
        }

        // Para filtros de pago, necesitamos usar un enfoque post-query porque la lógica es compleja
        // Almacenar el filtro para usarlo en un hook posterior
        add_filter('posts_results', [$this, 'filterOrdersByPaymentStatus'], 10, 2);
    }

    /**
     * Añadir CSS para la columna de estado de pago
     * 
     * @return void
     */
    public function addPaymentStatusColumnCSS(): void
    {
        $screen = get_current_screen();
        
        // Aplicar CSS tanto para sistema legacy como HPOS
        if (!$screen || !in_array($screen->id, ['edit-shop_order', 'woocommerce_page_wc-orders'])) {
            return;
        }

        ?>
        <style type="text/css">
            /* Columna de estado de pago */
            .column-payment_status,
            .manage-column.column-payment_status {
                width: 120px;
                text-align: center;
            }
            
            /* Estados de pago base */
            .payment-status-paid,
            .payment-status-unpaid,
            .payment-status-transfer,
            .payment-status-unknown {
                font-weight: bold;
                font-size: 11px;
                padding: 4px 8px;
                border-radius: 4px;
                border: 1px solid;
                display: inline-block;
                cursor: help;
                white-space: nowrap;
            }
            
            /* Estado pagado */
            .payment-status-paid {
                color: #00a32a;
                background: #f0f9ff;
                border-color: #00a32a;
            }
            
            /* Estado sin pagar */
            .payment-status-unpaid {
                color: #d63638;
                background: #fef7f7;
                border-color: #d63638;
            }
            
            /* Estado transferencia bancaria */
            .payment-status-transfer {
                color: #0073aa;
                background: #f0f8ff;
                border-color: #0073aa;
            }
            
            /* Estado desconocido */
            .payment-status-unknown {
                color: #646970;
                background: #f6f7f7;
                border-color: #c3c4c7;
            }
            
            /* Estados de reembolso */
            .payment-status-refund-full {
                color: #d63638;
                background: #fef2f2;
                border-color: #dc2626;
                font-weight: bold;
                font-size: 11px;
                padding: 4px 8px;
                border-radius: 4px;
                border: 1px solid;
                display: inline-block;
                cursor: help;
                white-space: nowrap;
            }
            
            .payment-status-refund-partial {
                color: #d97706;
                background: #fefbf3;
                border-color: #f59e0b;
                font-weight: bold;
                font-size: 11px;
                padding: 4px 8px;
                border-radius: 4px;
                border: 1px solid;
                display: inline-block;
                cursor: help;
                white-space: nowrap;
            }
            
            /* Enlaces de pago */
            .payment-link {
                color: inherit !important;
                text-decoration: none !important;
            }
            
            .payment-link:hover {
                text-decoration: underline !important;
            }
            
            /* Filtros */
            select[name="payment_filter"] {
                margin-left: 10px;
            }
            
            /* Responsivo */
            @media screen and (max-width: 782px) {
                .column-payment_status,
                .manage-column.column-payment_status {
                    display: none;
                }
            }
        </style>
        <?php
    }

    /**
     * Verificar si un pedido está realmente pagado
     * Usa la lógica del PaymentHandler si está disponible, sino usa lógica básica
     * 
     * @param \WC_Order|\WC_Abstract_Order $order Objeto de pedido o reembolso
     * @return bool Si está pagado o no
     */
    private function isOrderReallyPaid($order): bool
    {
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
        
        // Si tenemos PaymentHandler disponible, intentar usar su lógica
        if ($this->paymentHandler) {
            try {
                // Como isOrderTrulyPaid es privado, usar la lógica avanzada similar
                return $this->advancedPaymentCheck($order);
            } catch (\Exception $e) {
                // Fallback a lógica básica
            }
        }
        
        // Lógica básica de fallback (similar a CheckoutBlocker)
        return $this->basicPaymentCheck($order);
    }

    /**
     * Verificación avanzada de pago (compatible con plugin estándar de Redsys)
     * 
     * @param \WC_Order|\WC_Abstract_Order $order Objeto de pedido
     * @return bool Si está pagado
     */
    private function advancedPaymentCheck($order): bool
    {
        $order_status = $order->get_status();
        $payment_method = $order->get_payment_method();
        
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
        
        // Estados que definitivamente indican que está pagado SOLO si tienen indicadores manuales
        if ($order_status === 'completed') {
            $payment_method = $order->get_payment_method();
            
            // CRÍTICO: Para órdenes 'completed', SIEMPRE verificar indicadores manuales primero
            $has_manual_indicators = !empty($payment_date) || !empty($transaction_id) || !empty($deferred_payment_date);
            
            // LÓGICA ESPECIAL SEGÚN TIPO DE PEDIDO:
            $is_master_order = $order->get_meta('_is_master_order') === 'yes';
            
            if ($is_master_order) {
                // MASTER ORDERS: SIEMPRE requieren confirmación manual
                if (!$has_manual_indicators) {
                    return false; // Sin indicadores = NO PAGADO
                }
            } else {
                // PEDIDOS INDIVIDUALES: Depende del método de pago
                if ($payment_method === 'bacs' || $payment_method === 'transferencia') {
                    // Transferencias SIEMPRE requieren confirmación manual
                    if (!$has_manual_indicators) {
                        return false; // Sin indicadores = NO PAGADO
                    }
                } elseif ($payment_method === 'student_payment') {
                    // student_payment: completed = pagado automáticamente
                    return true;
                } else {
                    // Otros métodos: verificar needs_payment pero también requerir indicadores
                    if (!$has_manual_indicators && $order->needs_payment()) {
                        return false; // Sin indicadores Y needs_payment = NO PAGADO
                    }
                }
            }
            
            // Si llegamos aquí con indicadores manuales, verificar needs_payment
            return !$order->needs_payment();
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
        
        // Para estado 'processing' verificar múltiples indicadores como en PaymentHandler
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
                if (!empty($order->get_meta($meta_key))) {
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
        
        // Para otros estados, verificar si necesita pago (como en CheckoutBlocker)
        return !$order->needs_payment();
    }

    /**
     * Verificación básica de pago (similar a CheckoutBlocker)
     * 
     * @param \WC_Order|\WC_Abstract_Order $order Objeto de pedido
     * @return bool Si está pagado
     */
    private function basicPaymentCheck($order): bool
    {
        // Usar lógica unificada simple: verificar solo indicadores confiables de pago
        $payment_date = $order->get_meta('payment_date');
        $deferred_payment_date = $order->get_meta('_dm_pay_later_card_payment_date');
        
        // Verificar transaction_id solo si no es auto-generado (no contiene "order")
        $transaction_id = $order->get_transaction_id();
        $reliable_transaction = !empty($transaction_id) && stripos($transaction_id, 'order') === false;
        
        // Si tiene indicadores confiables de pago, está pagado
        if (!empty($payment_date) || !empty($deferred_payment_date) || $reliable_transaction) {
            return true;
        }

        // Sin indicadores confiables, usar estado WooCommerce estándar
        return !$order->needs_payment();
    }

    /**
     * Obtener fecha de pago del pedido
     * 
     * @param \WC_Order $order Objeto de pedido
     * @return string|null Fecha de pago
     */
    private function getOrderPaymentDate(\WC_Order $order): ?string
    {
        // Priorizar fecha de pago diferido
        $deferred_date = $order->get_meta('_dm_pay_later_card_payment_date');
        if (!empty($deferred_date)) {
            return $deferred_date;
        }
        
        // Fecha de pago general
        $payment_date = $order->get_meta('payment_date');
        if (!empty($payment_date)) {
            return $payment_date;
        }
        
        // Fecha de completado del pedido
        $completed_date = $order->get_date_completed();
        if ($completed_date) {
            return $completed_date->format('Y-m-d H:i:s');
        }
        
        return null;
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
            '' => __('Not specified', 'neve-child')
        ];
        
        return $payment_methods[$payment_method] ?? ucfirst(str_replace('_', ' ', $payment_method));
    }

    /**
     * Obtener el estado de reembolso del pedido
     * 
     * @param \WC_Order $order Objeto de pedido
     * @return string|null HTML del estado de reembolso o null si no hay reembolsos
     */
    private function getRefundStatus(\WC_Order $order): ?string
    {
        // Obtener todos los reembolsos del pedido
        $refunds = $order->get_refunds();
        
        if (empty($refunds)) {
            return null;
        }
        
        // Calcular totales
        $order_total = (float) $order->get_total();
        $total_refunded = (float) $order->get_total_refunded();
        
        // Determinar si es reembolso completo o parcial
        $is_full_refund = ($total_refunded >= $order_total);
        
        // Obtener información de los reembolsos
        $refund_dates = array_map(function($refund) {
            return $refund->get_date_created()->date('d/m/Y H:i');
        }, $refunds);
        
        // Crear tooltip informativo
        $tooltip = $is_full_refund ? __('Full refund', 'neve-child') : __('Partial refund', 'neve-child');
        $tooltip .= " - " . __('Total refunded', 'neve-child') . ": " . wc_price($total_refunded);
        $tooltip .= " " . __('of', 'neve-child') . " " . $order->get_formatted_order_total();
        $tooltip .= " - " . __('Refunds', 'neve-child') . ": " . count($refunds);
        $tooltip .= " - " . __('Last refund', 'neve-child') . ": " . end($refund_dates);
        
        // Determinar clase CSS y texto
        if ($is_full_refund) {
            $css_class = 'payment-status-refund-full';
            $icon = '↩️';
            $text = __('FULL REFUND', 'neve-child');
        } else {
            $css_class = 'payment-status-refund-partial';
            $icon = '↩️';
            $text = __('PARTIAL REFUND', 'neve-child');
        }
        
        return '<span class="' . $css_class . '" title="' . esc_attr($tooltip) . '">' . $icon . ' ' . $text . '</span>';
    }

    /**
     * Asignar valores de ordenación para sistema Legacy
     * 
     * @param \WP_Query $query Objeto de consulta
     * @return void
     */
    public function assignPaymentSortValues(\WP_Query $query): void
    {
        // Solo ejecutar en admin y para shop_order
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'shop_order') {
            return;
        }

        // Solo si estamos ordenando por payment_status
        if ($query->get('orderby') !== 'meta_value_num' || $query->get('meta_key') !== '_payment_status_sort') {
            return;
        }

        // Verificar si ya se ejecutó en esta petición
        static $already_assigned = false;
        if ($already_assigned) {
            return;
        }
        $already_assigned = true;

        // Obtener solo los pedidos de la página actual para optimizar
        $posts_per_page = $query->get('posts_per_page') ?: 20;
        $paged = $query->get('paged') ?: 1;
        $offset = ($paged - 1) * $posts_per_page;

        $orders = get_posts([
            'post_type' => 'shop_order',
            'post_status' => array_keys(wc_get_order_statuses()),
            'posts_per_page' => $posts_per_page * 2,
            'offset' => max(0, $offset - 10),
            'fields' => 'ids'
        ]);

        foreach ($orders as $order_id) {
            // Solo actualizar si no tiene valor o es antiguo
            $existing_value = get_post_meta($order_id, '_payment_status_sort', true);
            $last_modified = get_post_meta($order_id, '_payment_sort_updated', true);
            $order_modified = get_post_time('U', false, $order_id, true);
            
            // Actualizar si no tiene valor o si el pedido se modificó después de la última actualización
            if ($existing_value === '' || empty($last_modified) || $order_modified > $last_modified) {
                $order = wc_get_order($order_id);
                if (!$order) continue;

                $is_paid = $this->isOrderReallyPaid($order);
                $sort_value = $is_paid ? 1 : 0;
                
                update_post_meta($order_id, '_payment_status_sort', $sort_value);
                update_post_meta($order_id, '_payment_sort_updated', time());
            }
        }
    }

    /**
     * Asignar valores de ordenación para sistema HPOS
     * 
     * @return void
     */
    public function assignPaymentSortValuesHPOS(): void
    {
        // Verificar si tenemos acceso a HPOS
        if (!function_exists('wc_get_orders')) {
            return;
        }

        // Verificar si ya se ejecutó en esta petición
        static $already_assigned = false;
        if ($already_assigned) {
            return;
        }
        $already_assigned = true;

        try {
            // Obtener pedidos de forma eficiente - SOLO PEDIDOS, NO REEMBOLSOS
            $orders = wc_get_orders([
                'limit' => 100,
                'status' => array_keys(wc_get_order_statuses()),
                'orderby' => 'date',
                'order' => 'DESC',
                'type' => 'shop_order' // Excluir reembolsos explícitamente
            ]);

            foreach ($orders as $order) {
                if (!$order) continue;
                
                // Verificar que es realmente un pedido, no un reembolso
                if (is_a($order, 'WC_Order_Refund') || is_a($order, 'Automattic\WooCommerce\Admin\Overrides\OrderRefund')) {
                    continue;
                }

                $order_id = $order->get_id();
                
                // Solo actualizar si no tiene valor o es necesario
                $existing_value = $order->get_meta('_payment_status_sort');
                $last_updated = $order->get_meta('_payment_sort_updated');
                $order_modified = $order->get_date_modified() ? $order->get_date_modified()->getTimestamp() : 0;
                
                // Actualizar si no tiene valor o si el pedido se modificó después
                if ($existing_value === '' || empty($last_updated) || $order_modified > intval($last_updated)) {
                    $is_paid = $this->isOrderReallyPaid($order);
                    $sort_value = $is_paid ? 1 : 0;
                    
                    $order->update_meta_data('_payment_status_sort', $sort_value);
                    $order->update_meta_data('_payment_sort_updated', time());
                    $order->save_meta_data();
                }
            }
            
        } catch (\Exception $e) {
            // Error silencioso en producción
        }
    }

    /**
     * Actualizar valor de ordenación cuando cambia un pedido
     * 
     * @param int $order_id ID del pedido
     * @return void
     */
    public function updateOrderSortValue($order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $is_paid = $this->isOrderReallyPaid($order);
        
        // Asignar valor numérico: 1 = pagado, 0 = no pagado
        $sort_value = $is_paid ? 1 : 0;
        $current_time = time();
        
        // Actualizar para ambos sistemas
        if ($this->isHPOSEnabled()) {
            // Sistema HPOS
            $order->update_meta_data('_payment_status_sort', $sort_value);
            $order->update_meta_data('_payment_sort_updated', $current_time);
            $order->save_meta_data();
        } else {
            // Sistema legacy
            update_post_meta($order_id, '_payment_status_sort', $sort_value);
            update_post_meta($order_id, '_payment_sort_updated', $current_time);
        }
    }

    /**
     * Obtener estadísticas de pagos (método público para usar en dashboard)
     * 
     * @return array Estadísticas de pagos
     */
    public function getPaymentStatistics(): array
    {
        $args = [
            'post_type' => 'shop_order',
            'post_status' => array_keys(wc_get_order_statuses()),
            'posts_per_page' => -1,
            'fields' => 'ids'
        ];
        
        $order_ids = get_posts($args);
        
        $stats = [
            'total' => count($order_ids),
            'paid' => 0,
            'unpaid' => 0,
            'pending' => 0,
            'total_paid_amount' => 0,
            'total_unpaid_amount' => 0
        ];
        
        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) continue;
            
            $is_paid = $this->isOrderReallyPaid($order);
            $order_total = floatval($order->get_total());
            
            if ($is_paid) {
                $stats['paid']++;
                $stats['total_paid_amount'] += $order_total;
            } else {
                if ($order->needs_payment()) {
                    $stats['unpaid']++;
                    $stats['total_unpaid_amount'] += $order_total;
                } else {
                    $stats['pending']++;
                }
            }
        }
        
        return $stats;
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

        $payment_filter = $_GET['payment_filter'] ?? '';
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

            if ($this->shouldIncludeOrderInPaymentFilter($order, $payment_filter)) {
                $filtered_posts[] = $post;
            }
        }

        return $filtered_posts;
    }

    /**
     * Determine if an order should be included in the payment filter
     * 
     * @param \WC_Order|\WC_Abstract_Order $order Order object (can be refund)
     * @param string $filter Filter type
     * @return bool Should include order
     */
    private function shouldIncludeOrderInPaymentFilter($order, string $filter): bool
    {
        // Si es un reembolso, nunca incluir en filtros de pago
        if (is_a($order, 'WC_Order_Refund') || is_a($order, 'Automattic\WooCommerce\Admin\Overrides\OrderRefund')) {
            return false;
        }

        $is_paid = $this->isOrderReallyPaid($order);
        $payment_method = $order->get_payment_method();
        $order_status = $order->get_status();

        switch ($filter) {
            case 'paid':
                // Todos los pedidos pagados usando la misma lógica que la columna
                return $is_paid;
                
            case 'unpaid':
                // Todos los pedidos sin pagar (excluyendo transferencias)
                $is_transfer = in_array($payment_method, ['bacs', 'transferencia']);
                return !$is_paid && !$is_transfer;
                
            case 'transfer':
                // Solo transferencias bancarias SIN pagar
                $is_transfer = in_array($payment_method, ['bacs', 'transferencia']);
                return $is_transfer && !$is_paid;
                
            case 'refund':
                // Pedidos que tienen cualquier tipo de reembolso
                $refund_status = $this->getRefundStatus($order);
                return !empty($refund_status);
                
            case 'full_refund':
                // Solo pedidos con reembolso completo
                $refund_status = $this->getRefundStatus($order);
                return $refund_status === 'full';
                
            case 'partial_refund':
                // Solo pedidos con reembolso parcial
                $refund_status = $this->getRefundStatus($order);
                return $refund_status === 'partial';
                
            default:
                // Todos los pedidos (sin filtro)
                return true;
        }
    }

    /**
     * Verificar y cambiar método de pago a transferencia bancaria cuando se establece pago manual
     * 
     * @param \WC_Order $order Objeto de pedido
     * @return void
     */
    private function checkAndUpdatePaymentMethodForManualPayment(\WC_Order $order): void
    {
        // Solo ejecutar en admin para evitar cambios no deseados en frontend
        if (!is_admin()) {
            return;
        }
        
        $current_payment_method = $order->get_payment_method();
        
        // PROTECCIÓN PRINCIPAL: NUNCA cambiar métodos de pago automáticos
        $automatic_methods = ['redsys', 'dm_pay_later_card', 'bizum', 'paypal', 'stripe'];
        if (in_array($current_payment_method, $automatic_methods)) {
            return; // Salir inmediatamente sin hacer NADA
        }
        
        // SIMPLIFICADO: Solo cambiar método de pago si es un pedido que PAGA EL CENTRO
        if (!$this->isSchoolPaymentOrder($order)) {
            return; // Si no es pedido del centro, NO cambiar método de pago
        }

        $transaction_id = $order->get_transaction_id();
        
        // Solo cambiar si no es ya una transferencia bancaria y tiene indicadores de pago manual
        if ($current_payment_method !== 'bacs' && $current_payment_method !== 'transferencia') {
            $is_manual_payment = false;
            
            // DETECCIÓN ESTRICTA: Solo considerar manual si tiene transaction_id específico de pago manual
            if (!empty($transaction_id) && strpos($transaction_id, 'manual_bank_') === 0) {
                $is_manual_payment = true;
            }
            
            if ($is_manual_payment) {
                // Guardar el método de pago original
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
                
                $order->save();
            }
        }
    }

    /**
     * Verificar si un pago es automático (no manual)
     * 
     * @param \WC_Order $order Objeto de pedido
     * @return bool Si el pago fue automático
     */
    private function isAutomaticPayment(\WC_Order $order): bool
    {
        $payment_method = $order->get_payment_method();
        
        // Métodos que siempre son automáticos
        $automatic_methods = ['redsys', 'dm_pay_later_card', 'bizum', 'paypal', 'stripe'];
        if (in_array($payment_method, $automatic_methods)) {
            // Para Redsys y DM Pay Later Card, verificar si hay confirmaciones reales de pago automático
            if (in_array($payment_method, ['redsys', 'dm_pay_later_card'])) {
                $payment_confirmations = [
                    '_redsys_order_number',
                    '_redsys_auth_code', 
                    '_redsys_response',
                    '_redsys_authorization',
                    '_redsys_authorisation_code',
                    '_dm_pay_later_card_payment_date' // También cuenta como automático
                ];
                
                foreach ($payment_confirmations as $meta_key) {
                    if (!empty($order->get_meta($meta_key))) {
                        return true; // Es automático con confirmación real
                    }
                }
                
                // Si es Redsys o DM Pay Later Card pero sin confirmaciones, aún lo consideramos automático
                // para evitar cambios no deseados del método de pago
                return true;
            }
            
            // Otros métodos automáticos
            return true;
        }
        
        return false; // No se puede confirmar que sea automático
    }

    /**
     * Verificar si HPOS está habilitado
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
 
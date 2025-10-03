<?php
/**
 * Status Manager for handling order statuses
 * 
 * FUNCIONALIDADES:
 * 1. Estados personalizados para pedidos normales 
 * 2. Estados exclusivos para pedidos maestros
 * 3. Protección contra bulk actions en pedidos maestros
 * 4. Control total de transiciones de estado
 * 
 * @package SchoolManagement\Orders
 * @since 1.0.0
 */

namespace SchoolManagement\Orders;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for managing custom order statuses
 */
class StatusManager
{
    /**
     * ESTADOS EXCLUSIVOS PARA PEDIDOS MAESTROS
     * Limitados a 12 caracteres máximo
     */
    private const MASTER_ORDER_STATUSES = [
        'master-order',      // Estado inicial pedido maestro
        'mast-warehs',       // Master warehouse (almacén)  
        'mast-prepared',     // Master preparado
        'mast-complete'      // Estado final pedido maestro
    ];

    /**
     * ORDEN DE PROGRESIÓN DE ESTADOS DE PEDIDOS MAESTROS
     * Los pedidos maestros solo pueden avanzar, nunca retroceder
     */
    private const MASTER_ORDER_STATUS_PROGRESSION = [
        'master-order'   => 0,   // Estado inicial - validado
        'mast-warehs'    => 1,   // Almacén
        'mast-prepared'  => 2,   // Preparado  
        'mast-complete'  => 3    // Completo (final)
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->initHooks();
    }

    /**
     * Check if current user is a teacher
     */
    private function isTeacher(): bool
    {
        $user = wp_get_current_user();
        return in_array('teacher', (array) $user->roles);
    }

    /**
     * Teacher allowed statuses - ACTUALIZADO: Permitir TODOS los estados de WooCommerce
     * 
     * @var array
     */
    private const TEACHER_ALLOWED_STATUSES = [
        // Estados principales de WooCommerce
        'wc-pending',
        'wc-processing', 
        'wc-on-hold',
        'wc-completed',
        'wc-cancelled',
        'wc-refunded',
        'wc-failed',
        
        // Estados personalizados del sistema
        'wc-' . \SchoolManagement\Shared\Constants::STATUS_PAY_LATER,
        'wc-' . \SchoolManagement\Shared\Constants::STATUS_REVIEWED,
        'wc-warehouse',
        'wc-prepared',
        'wc-master-order',
        'wc-mast-warehs', 
        'wc-mast-prepared',
        'wc-mast-complete'
    ];

    /**
     * State transitions
     * 
     * @var array
     */
    private const STATE_TRANSITIONS = [
        'wc-pay-later' => 'wc-reviewed',
        'wc-on-hold' => 'wc-reviewed',
        'wc-processing' => 'wc-reviewed'
    ];

    /**
     * Initialize WordPress hooks
     * 
     * @return void
     */
    public function initHooks(): void
    {
        add_action('init', [$this, 'registerCustomStatuses']);
        add_filter('wc_order_statuses', [$this, 'addToWooCommerceStatuses']);
        add_filter('wc_order_statuses', [$this, 'filterAvailableOrderStatusesForTeacher']);
        add_filter('woocommerce_order_list_table_prepare_items_query_args', [$this, 'filterOrdersByTeacherStatus'], 20);
        add_filter('woocommerce_reports_get_order_report_query', [$this, 'filterOrderReportsByTeacherStatus'], 20);
        add_action('admin_notices', [$this, 'bulkStatusChangeNotice']);
        add_action('admin_head', [$this, 'customOrderStatusStyle']);
        add_filter('bulk_actions-woocommerce_page_wc-orders', [$this, 'addMarkAsReviewedAction']);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [$this, 'handleMarkAsReviewed'], 10, 3);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [$this, 'handleMarkAsWarehouse'], 10, 3);
        
        // // === FORZAR QUE LOS ESTADOS APAREZCAN EN FILTROS ===
        // add_action('restrict_manage_posts', [$this, 'ensureStatusesInFilters']);
        // add_action('woocommerce_order_list_table_restrict_manage_orders', [$this, 'ensureStatusesInFilters']);
        // add_filter('views_edit-shop_order', [$this, 'addStatusViews']);
        // add_filter('views_woocommerce_page_wc-orders', [$this, 'addStatusViews']);
        
        // === PROTECCIÓN PARA PEDIDOS MAESTROS ===
        
        // Interceptar TODOS los bulk actions para proteger master orders
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [$this, 'protectMasterOrdersFromBulkActions'], 5, 3);
        add_filter('handle_bulk_actions-edit-shop_order', [$this, 'protectMasterOrdersFromBulkActions'], 5, 3);
        
        // Interceptar cambios de estado individuales en master orders
        add_action('woocommerce_order_status_changed', [$this, 'protectMasterOrderStatusChanges'], 1, 4);
        
        // Manejar transiciones automáticas de estados en pedidos hijos
        add_action('woocommerce_order_status_changed', [$this, 'handleMasterOrderStateTransitions'], 10, 4);
        
        // PROTECCIÓN CRÍTICA: Interceptar TODOS los cambios de estado no autorizados
        // PRIORIDAD 1: Debe ejecutarse ANTES que MasterOrderManager (prioridad 10)
        add_action('woocommerce_order_status_changed', [$this, 'protectUnauthorizedStatusChanges'], 1, 4);
        
        // Ya no necesitamos el filtro complejo - la protección en MasterOrderManager es suficiente
        
        // Master Order bulk actions - DISABLED to avoid duplicates with MasterOrderManager
        // add_filter('bulk_actions-woocommerce_page_wc-orders', [$this, 'addMasterOrderBulkActions']);
        // add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [$this, 'handleMasterOrderBulkActions'], 10, 3);
        
        // Filtrar estados disponibles en el dropdown según el tipo de pedido
        add_filter('woocommerce_admin_order_actions', [$this, 'filterOrderActionsForMasterOrders'], 10, 2);
    }

    /**
     * Register custom WooCommerce statuses
     * 
     * @return void
     */
    public function registerCustomStatuses(): void
    {
        // Registrar estados normales
        foreach ($this->getCustomStatuses() as $status => $data) {
            register_post_status('wc-' . $status, [
                'label' => $data['label'],
                'public' => $data['public'],
                'exclude_from_search' => $data['exclude_from_search'],
                'show_in_admin_all_list' => $data['show_in_admin_all_list'],
                'show_in_admin_status_list' => $data['show_in_admin_status_list'],
                'label_count' => _n_noop($data['label_count'], $data['label_count'])
            ]);
        }
        
        // Registrar estados de master orders
        foreach ($this->getMasterOrderStatuses() as $status => $data) {
            register_post_status('wc-' . $status, [
                'label' => $data['label'],
                'public' => $data['public'],
                'exclude_from_search' => $data['exclude_from_search'],
                'show_in_admin_all_list' => $data['show_in_admin_all_list'],
                'show_in_admin_status_list' => $data['show_in_admin_status_list'],
                'label_count' => _n_noop($data['label_count'], $data['label_count'])
            ]);
        }
    }

    /**
     * Add custom statuses to WooCommerce
     * 
     * @param array $order_statuses Current order statuses
     * @return array Modified order statuses
     */
    public function addToWooCommerceStatuses(array $order_statuses): array
    {
        // Agregar estados normales
        $new_order_statuses = [];
        foreach ($this->getCustomStatuses() as $status => $data) {
            $new_order_statuses['wc-' . $status] = $data['label'];
        }

        // Agregar estados de master orders
        foreach ($this->getMasterOrderStatuses() as $status => $data) {
            $new_order_statuses['wc-' . $status] = $data['label'];
        }

        // Insert after processing
        $position = array_search('wc-processing', array_keys($order_statuses)) + 1;

        return array_slice($order_statuses, 0, $position, true) +
               $new_order_statuses +
               array_slice($order_statuses, $position, null, true);
    }

    /**
     * Filter available order statuses for teachers
     * ACTUALIZADO: Los profesores pueden ver TODOS los estados disponibles
     * 
     * @param array $order_statuses Order statuses
     * @return array Filtered statuses
     */
    public function filterAvailableOrderStatusesForTeacher(array $order_statuses): array
    {
        if (!$this->isTeacher()) {
            return $order_statuses;
        }

        // CAMBIO IMPORTANTE: Los profesores pueden ver TODOS los estados disponibles
        // No aplicamos filtro restrictivo para profesores en los dropdowns
        // Esto permite que los filtros de estado funcionen completamente
        
        return $order_statuses;
    }

    /**
     * Filter orders by teacher status
     * ACTUALIZADO: Los profesores NO tienen restricción de estados, solo de school_id
     * 
     * @param array $query_args Query arguments
     * @return array Modified query arguments
     */
    public function filterOrdersByTeacherStatus(array $query_args): array
    {
        if (!$this->isTeacher()) {
            return $query_args;
        }

        // CAMBIO IMPORTANTE: NO filtrar estados para profesores
        // El TeacherRoleManager ya maneja el filtro por school_id correctamente
        // Los profesores deben poder ver TODOS los estados de su colegio
        // 
        // REMOVIDO: $query_args['post_status'] = self::TEACHER_ALLOWED_STATUSES;

        return $query_args;
    }

    /**
     * Filter order reports by teacher status
     * 
     * @param array $query Query arguments
     * @return array Modified query arguments
     */
    public function filterOrderReportsByTeacherStatus(array $query): array
    {
        if (!$this->isTeacher()) {
            return $query;
        }

        global $wpdb;

        $allowed_statuses = "'" . implode("','", self::TEACHER_ALLOWED_STATUSES) . "'";
        
        if (isset($query['where']) && is_string($query['where'])) {
            $query['where'] .= " AND posts.post_status IN ({$allowed_statuses})";
        }

        return $query;
    }

    
    /**
     * Add mark as reviewed bulk action
     * 
     * @param array $bulk_actions Existing bulk actions
     * @return array Modified bulk actions
     */
    public function addMarkAsReviewedAction(array $bulk_actions): array
    {
        // Solo añadir nuestras acciones personalizadas sin tocar la opción por defecto
        if (current_user_can('edit_shop_orders') || current_user_can('manage_woocommerce')) {
            // Solo añadir si no existe ya para evitar duplicados
            if (!isset($bulk_actions['mark_reviewed'])) {
                $bulk_actions['mark_reviewed'] = __('Mark as reviewed', 'neve-child');
            }
            
            // Agregar acción para cambiar a warehouse
            if (!isset($bulk_actions['mark_warehouse'])) {
                $bulk_actions['mark_warehouse'] = __('Change status to Warehouse', 'neve-child');
            }
        }

        return $bulk_actions;
    }

    /**
     * Handle mark as reviewed bulk action
     * 
     * @param string $redirect_to Redirect URL
     * @param string $action Action name
     * @param array $post_ids Post IDs
     * @return string Modified redirect URL
     */
    public function handleMarkAsReviewed(string $redirect_to, string $action, array $post_ids): string
    {
        // Verificar permisos generales en lugar de solo teacher
        if ($action !== 'mark_reviewed' || !(current_user_can('edit_shop_orders') || current_user_can('manage_woocommerce'))) {
            return $redirect_to;
        }

        $changed = 0;

        foreach ($post_ids as $post_id) {
            $order = wc_get_order($post_id);
            
            if ($order && $order->get_status() !== \SchoolManagement\Shared\Constants::STATUS_REVIEWED) {
                // REGLA ESTRICTA: Solo pedidos en estado 'processing' pueden pasar a 'reviewed'
                // NO permitir 'completed' → 'reviewed' (es un retroceso no deseado)
                if ($order->get_status() !== 'processing') {
                    continue;
                }
                
                $order->update_status(\SchoolManagement\Shared\Constants::STATUS_REVIEWED, __('Status changed to reviewed', 'neve-child'));
                $changed++;
            }
        }

        $redirect_to = add_query_arg(
            ['marked_reviewed' => $changed],
            $redirect_to
        );

        return $redirect_to;
    }

    /**
     * Handle mark as warehouse bulk action
     * 
     * @param string $redirect_to Redirect URL
     * @param string $action Action name
     * @param array $post_ids Post IDs
     * @return string Modified redirect URL
     */
    public function handleMarkAsWarehouse(string $redirect_to, string $action, array $post_ids): string
    {
        // Verificar permisos generales
        if ($action !== 'mark_warehouse' || !(current_user_can('edit_shop_orders') || current_user_can('manage_woocommerce'))) {
            return $redirect_to;
        }

        $changed = 0;

        foreach ($post_ids as $post_id) {
            $order = wc_get_order($post_id);
            
            if ($order && $order->get_status() !== 'warehouse') {
                $order->update_status('warehouse', __('Status changed to warehouse', 'neve-child'));
                $changed++;
            }
        }

        $redirect_to = add_query_arg(
            ['marked_warehouse' => $changed],
            $redirect_to
        );

        return $redirect_to;
    }

    /**
     * Show bulk status change notice
     * 
     * @return void
     */
    public function bulkStatusChangeNotice(): void
    {
        // Notificación original para teachers
        if (isset($_REQUEST['marked_reviewed'])) {
            $changed = intval($_REQUEST['marked_reviewed']);
            
            if ($changed > 0) {
                printf(
                    '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                    sprintf(
                        _n(
                            '%s order marked as reviewed.',
                            '%s orders marked as reviewed.',
                            $changed,
                            'neve-child'
                        ),
                        $changed
                    )
                );
            }
        }

        // Notificación para warehouse
        if (isset($_REQUEST['marked_warehouse'])) {
            $changed = intval($_REQUEST['marked_warehouse']);
            
            if ($changed > 0) {
                printf(
                    '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                    sprintf(
                        _n(
                            '%s order marked as warehouse.',
                            '%s orders marked as warehouse.',
                            $changed,
                            'neve-child'
                        ),
                        $changed
                    )
                );
            }
        }

        // Notificaciones de protección de master orders
        $protection_notice = get_transient('master_order_bulk_protection_notice');
        if ($protection_notice) {
            printf(
                '<div class="notice notice-warning is-dismissible"><p><strong>Master Validated Orders Protected:</strong> %s</p></div>',
                esc_html($protection_notice)
            );
            delete_transient('master_order_bulk_protection_notice');
        }

        $status_notice = get_transient('master_order_status_protection_notice');
        if ($status_notice) {
            printf(
                '<div class="notice notice-error is-dismissible"><p><strong>Status Change Blocked:</strong> %s</p></div>',
                esc_html($status_notice)
            );
            delete_transient('master_order_status_protection_notice');
        }

        $bulk_action_notice = get_transient('master_order_bulk_action_notice');
        if ($bulk_action_notice) {
            printf(
                '<div class="notice notice-success is-dismissible"><p><strong>Master Validated Order Bulk Action:</strong> %s</p></div>',
                esc_html($bulk_action_notice)
            );
            delete_transient('master_order_bulk_action_notice');
        }

        // Notificación de bloqueo de cambio de estado no autorizado
        $status_blocked_notice = get_transient('status_change_blocked_notice');
        if ($status_blocked_notice) {
            printf(
                '<div class="notice notice-error is-dismissible"><p><strong>Status Change Blocked:</strong> %s</p></div>',
                esc_html($status_blocked_notice)
            );
            delete_transient('status_change_blocked_notice');
        }
    }

    /**
     * Add custom styles for order statuses
     * 
     * @return void
     */
    public function customOrderStatusStyle(): void
    {
        ?>
        <style>
        /* Estilos para estados normales */
        .order-status.status-pay-later {
            background: #ffba00;
            color: #000;
        }
        .order-status.status-reviewed {
            background: #c8d7e1;
            color: #2e4453;
        }
        .order-status.status-warehouse {
            background: #f8d9a7ff;
            color: #945e0cff;
        }
        .order-status.status-prepared {
            background: #44ad9b;
            color: white;
        }
        mark.pay-later {
            background: #ffba00;
            color: #000;
        }
        mark.reviewed {
            background: #c8d7e1;
            color: #2e4453;
        }
        mark.warehouse {
            background: #f8d9a7ff;
            color: #945e0cff;
        }
        mark.prepared {
            background: #44ad9b;
            color: white;
        }
        
        /* Estilos para estados de Master Orders */
        .order-status.status-master-order {
            background: #007cba;
            color: white;
            font-weight: bold;
        }
        .order-status.status-mast-warehs {
            background: #ff8c00;
            color: white;
            font-weight: bold;
        }
        .order-status.status-mast-prepared {
            background: #44ad9bff;
            color: white;
            font-weight: bold;
        }
        .order-status.status-mast-complete {
            background: #46b450;
            color: white;
            font-weight: bold;
        }
        
        mark.master-order {
            background: #007cba;
            color: white;
            font-weight: bold;
        }
        mark.mast-warehs {
            background: #ff8c00;
            color: white;
            font-weight: bold;
        }
        mark.mast-prepared {
            background: #8e44ad;
            color: white;
            font-weight: bold;
        }
        mark.mast-complete {
            background: #46b450;
            color: white;
            font-weight: bold;
        }
        
        /* Indicador visual para pedidos maestros en la lista */
        .post-type-shop_order .column-order_number .master-order-indicator {
            display: inline-block;
            background: #007cba;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
            margin-left: 5px;
            font-weight: bold;
        }
        </style>
        <?php
    }

    /**
     * Get teacher allowed statuses
     * 
     * @return array
     */
    public function getTeacherAllowedStatuses(): array
    {
        return self::TEACHER_ALLOWED_STATUSES;
    }

    /**
     * Get custom statuses
     * 
     * @return array
     */
    public function getCustomStatuses(): array
    {
        return [
            'pay-later' => [
                'label' => __('Pay later', 'neve-child'),
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                'label_count' => __('Pay later <span class="count">(%s)</span>', 'neve-child')
            ],
            'reviewed' => [
                'label' => __('Validated', 'neve-child'),
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                'label_count' => __('Validated <span class="count">(%s)</span>', 'neve-child')
            ],
            'warehouse' => [
                'label' => __('Warehouse', 'neve-child'),
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                'label_count' => __('Warehouse <span class="count">(%s)</span>', 'neve-child')
            ],
            'prepared' => [
                'label' => __('Prepared', 'neve-child'),
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                'label_count' => __('Prepared <span class="count">(%s)</span>', 'neve-child')
            ]
        ];
    }

    /**
     * Check if status is allowed for teachers
     * 
     * @param string $status Status to check
     * @return bool
     */
    public function isStatusAllowedForTeacher(string $status): bool
    {
        return in_array($status, self::TEACHER_ALLOWED_STATUSES);
    }

    // ================================
    // MÉTODOS PARA PEDIDOS MAESTROS
    // ================================

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

        // Es regresión si el nuevo estado tiene un número menor que el actual
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
     * Obtener configuración de estados para pedidos maestros
     */
    public function getMasterOrderStatuses(): array
    {
        return [
            'master-order' => [
                'label' => __('Master Validated', 'neve-child'),
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                'label_count' => __('Master Validated <span class="count">(%s)</span>', 'neve-child')
            ],
            'mast-warehs' => [
                'label' => __('Master Warehouse', 'neve-child'),
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                'label_count' => __('Master Warehouse <span class="count">(%s)</span>', 'neve-child')
            ],
            'mast-prepared' => [
                'label' => __('Master Prepared', 'neve-child'),
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                'label_count' => __('Master Prepared <span class="count">(%s)</span>', 'neve-child')
            ],
            'mast-complete' => [
                'label' => __('Master delivered', 'neve-child'),
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                'label_count' => __('Master delivered <span class="count">(%s)</span>', 'neve-child')
            ]
        ];
    }    /**
     * Proteger pedidos maestros de bulk actions no autorizadas
     */
    public function protectMasterOrdersFromBulkActions(string $redirect_to, string $action, array $post_ids): string
    {
        // Acciones permitidas para master orders
        $allowed_master_actions = [
            'mark_master_warehouse', 
            'mark_master_prepared',
            'mark_master_complete', 
            'mark_master_order',
            // Acciones de pago permitidas para master orders
            'mark_bank_transfers_paid',
            'mark_bank_transfers_unpaid'
        ];

        // Si la acción está permitida para master orders, continuar normal
        if (in_array($action, $allowed_master_actions)) {
            return $redirect_to;
        }

        // Filtrar master orders de acciones no permitidas
        $protected_orders = [];
        $remaining_orders = [];

        foreach ($post_ids as $order_id) {
            if ($this->isMasterOrder($order_id)) {
                $protected_orders[] = $order_id;
            } else {
                $remaining_orders[] = $order_id;
            }
        }

        // Si hay master orders, mostrar aviso y procesar solo los normales
        if (!empty($protected_orders)) {
            $message = sprintf(
                __('%d master validated orders were protected from bulk action "%s". Only regular orders were processed.', 'neve-child'),
                count($protected_orders),
                $action
            );
            
            set_transient('master_order_bulk_protection_notice', $message, 30);
            
            // Procesar solo pedidos normales si los hay
            if (!empty($remaining_orders)) {
                // Re-ejecutar la acción solo con pedidos normales
                $this->processBulkActionForRegularOrders($action, $remaining_orders);
            }
        }

        return $redirect_to;
    }

    /**
     * Proteger cambios de estado individuales en master orders
     */
    public function protectMasterOrderStatusChanges(int $order_id, string $old_status, string $new_status, $order): void
    {
        // Solo proteger si es master order
        if (!$this->isMasterOrder($order_id)) {
            return;
        }

        $revert_change = false;
        $error_message = '';

        // 1. Verificar que sea un estado válido para master orders
        if (!in_array($new_status, self::MASTER_ORDER_STATUSES)) {
            $revert_change = true;
            $error_message = __('Status change blocked: Master validated orders can only use master-order statuses (master-order, mast-warehs, mast-prepared, mast-complete).', 'neve-child');
        }
        
        // 2. ÚNICA RESTRICCIÓN: No permitir volver a 'master-order' una vez que se ha salido de él
        if (!$revert_change && $new_status === 'master-order' && $old_status !== 'master-order') {
            $revert_change = true;
            $error_message = __('Status change blocked: Master validated orders cannot return to "Master Validated" status once they have progressed beyond it.', 'neve-child');
        }

        // Si hay que revertir el cambio
        if ($revert_change) {
            // Revertir al estado anterior
            remove_action('woocommerce_order_status_changed', [$this, 'protectMasterOrderStatusChanges'], 1);
            
            $order->update_status($old_status, $error_message);
            
            add_action('woocommerce_order_status_changed', [$this, 'protectMasterOrderStatusChanges'], 1, 4);
            
            // Mostrar aviso al usuario
            set_transient('master_order_status_protection_notice', $error_message, 30);
        }
    }

    /**
     * Agregar bulk actions específicas para master orders
     */
    public function addMasterOrderBulkActions(array $bulk_actions): array
    {
        $bulk_actions['mark_master_order'] = __('Change to Master Validated', 'neve-child');
        $bulk_actions['mark_master_warehouse'] = __('Change to Master Warehouse', 'neve-child');
        $bulk_actions['mark_master_prepared'] = __('Change to Master Prepared', 'neve-child');
        $bulk_actions['mark_master_complete'] = __('Change to Master Complete', 'neve-child');
        
        return $bulk_actions;
    }

    /**
     * Manejar bulk actions específicas de master orders
     */
    public function handleMasterOrderBulkActions(string $redirect_to, string $action, array $post_ids): string
    {
        $master_actions = [
            'mark_master_order' => 'master-order',
            'mark_master_warehouse' => 'mast-warehs', 
            'mark_master_prepared' => 'mast-prepared',
            'mark_master_complete' => 'mast-complete'
        ];

        if (!array_key_exists($action, $master_actions)) {
            return $redirect_to;
        }

        $new_status = $master_actions[$action];
        $changed = 0;
        $protected = 0;
        $regressed = 0;

        foreach ($post_ids as $post_id) {
            if ($this->isMasterOrder($post_id)) {
                $order = wc_get_order($post_id);
                if ($order && $order->get_status() !== $new_status) {
                    $current_status = $order->get_status();
                    
                    // ÚNICA RESTRICCIÓN: No permitir volver a 'master-order' una vez que se ha salido de él
                    if ($new_status === 'master-order' && $current_status !== 'master-order') {
                        // No permitir volver a master-order
                        $regressed++;
                        $order->add_order_note(sprintf(
                            __('Bulk action blocked: Cannot return to "Master Validated" status once progressed beyond it. Current status: "%s"', 'neve-child'),
                            $this->getMasterOrderStatusLabel($current_status)
                        ));
                    } else {
                        // Permitir todos los demás cambios
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

        $messages = [];
        if ($changed > 0) {
            $messages[] = sprintf(
                _n('%d master validated order status changed.', '%d master validated orders status changed.', $changed, 'neve-child'),
                $changed
            );
        }
        if ($protected > 0) {
            $messages[] = sprintf(
                _n('%d regular order was protected (master actions only apply to master validated orders).', '%d regular orders were protected (master actions only apply to master validated orders).', $protected, 'neve-child'),
                $protected
            );
        }
        if ($regressed > 0) {
            $messages[] = sprintf(
                _n('%d master validated order was blocked from returning to "Master Validated" status.', '%d master validated orders were blocked from returning to "Master Validated" status.', $regressed, 'neve-child'),
                $regressed
            );
        }

        if (!empty($messages)) {
            set_transient('master_order_bulk_action_notice', implode(' ', $messages), 30);
        }

        return $redirect_to;
    }

    /**
     * Filtrar acciones disponibles para master orders
     */
    public function filterOrderActionsForMasterOrders(array $actions, \WC_Order $order): array
    {
        if ($this->isMasterOrder($order->get_id())) {
            // Para master orders, solo mostrar acciones relevantes
            $master_actions = [];
            
            // Mantener solo acciones seguras
            $safe_actions = ['view', 'email', 'regenerate_download_permissions'];
            foreach ($safe_actions as $safe_action) {
                if (isset($actions[$safe_action])) {
                    $master_actions[$safe_action] = $actions[$safe_action];
                }
            }
            
            return $master_actions;
        }

        return $actions;
    }

    /**
     * Procesar bulk action para pedidos regulares (helper)
     */
    private function processBulkActionForRegularOrders(string $action, array $order_ids): void
    {
        // Esto es un helper para re-procesar acciones en pedidos regulares
        // Delegar al sistema normal de WooCommerce
    }

    /**
     * Obtener los estados válidos para pedidos maestros
     */
    public function getValidMasterOrderStatuses(): array
    {
        return self::MASTER_ORDER_STATUSES;
    }

    /**
     * Verificar si un estado es válido para master orders
     */
    public function isValidMasterOrderStatus(string $status): bool
    {
        return in_array($status, self::MASTER_ORDER_STATUSES);
    }

    /**
     * Verificar si una transición de estado en pedidos hijos es hacia atrás
     * 
     * @param string $current_status Estado actual del hijo
     * @param string $target_status Estado objetivo del hijo  
     * @return bool True si es retroceso, false si es avance
     */
    private function isBackwardsTransitionForChildren(string $current_status, string $target_status): bool
    {
        // Orden de progresión típico para pedidos hijos
        $child_progression = [
            'pending'     => 0,
            'processing'  => 1,
            'reviewed'    => 2,
            'warehouse'   => 3,
            'prepared'    => 4,
            'completed'   => 5,
        ];
        
        $current_level = $child_progression[$current_status] ?? -1;
        $target_level = $child_progression[$target_status] ?? -1;
        
        // Si no conocemos el nivel, asumir que no es retroceso
        if ($current_level === -1 || $target_level === -1) {
            return false;
        }
        
        return $target_level < $current_level;
    }

    /**
     * Proteger cambios de estado no autorizados
     * CRÍTICO: 
     * 1. Solo permitir processing → reviewed, bloquear completed → reviewed
     * 2. No permitir cambios desde master-complete hacia estados anteriores
     * PRIORIDAD 1: SIMPLE - Solo revertir y mostrar mensaje
     */
    public function protectUnauthorizedStatusChanges(int $order_id, string $old_status, string $new_status, $order): void
    {
        // No interceptar master orders (tienen sus propias reglas de transición)
        if ($order->get_meta('_is_master_order') === 'yes') {
            return;
        }

        $error_message = '';
        $should_block = false;

        // REGLA 1: Solo permitir processing → reviewed
        if ($new_status === \SchoolManagement\Shared\Constants::STATUS_REVIEWED) {
            $allowed_previous_statuses = ['processing'];
            
            if (!in_array($old_status, $allowed_previous_statuses)) {
                $should_block = true;
                $error_message = sprintf(
                    __('Status change blocked: Orders can only change to "Validated" from "Processing" status. Current status "%s" is not allowed.', 'neve-child'),
                    $old_status
                );
            }
        }

        // REGLA 2: No permitir cambios desde master-complete hacia estados anteriores
        if ($old_status === 'mast-complete') {
            $should_block = true;
            $error_message = sprintf(
                __('Status change blocked: Orders in "Master Complete" status cannot be changed to previous states. Cannot change to "%s".', 'neve-child'),
                $new_status
            );
        }

        // Si se debe bloquear el cambio, revertir
        if ($should_block) {
            // Evitar loops infinitos
            static $reverting = [];
            if (isset($reverting[$order_id])) {
                return;
            }
            $reverting[$order_id] = true;
            
            // Revertir al estado anterior
            remove_action('woocommerce_order_status_changed', [$this, 'protectUnauthorizedStatusChanges'], 1);
            
            $order->update_status($old_status);
            
            add_action('woocommerce_order_status_changed', [$this, 'protectUnauthorizedStatusChanges'], 1, 4);
            
            unset($reverting[$order_id]);
        }
    }

    /**
     * Manejar transiciones automáticas de estados en pedidos hijos cuando cambia el Master Order
     * - Master pasa a mast-warehs → hijos pasan a warehouse
     * - Master pasa a mast-prepared → hijos pasan a prepared 
     * - Master pasa a mast-complete → hijos pasan a completed
     * - Maneja tanto avances como retrocesos del master
     */
    public function handleMasterOrderStateTransitions(int $order_id, string $old_status, string $new_status, $order): void
    {
        // Solo procesar Master Orders
        if ($order->get_meta('_is_master_order') !== 'yes') {
            return;
        }

        try {
            // Obtener pedidos hijos
            $included_orders = $order->get_meta('_included_orders') ?: [];
            if (empty($included_orders)) {
                return;
            }

            $changed_children = 0;
            $skipped_children = 0;
            $target_status = '';
            $transition_description = '';

            // Determinar la transición según el nuevo estado del master
            // NUEVO: Manejar tanto avances como retrocesos
            if ($new_status === 'mast-warehs') {
                $target_status = 'warehouse';
                $transition_description = 'Warehouse';
            } elseif ($new_status === 'mast-prepared') {
                $target_status = 'prepared';
                $transition_description = 'Prepared';
            } elseif ($new_status === 'mast-complete') {
                $target_status = 'completed';
                $transition_description = 'Completed';
            } else {
                // No es una transición que requiera cambios automáticos
                // (master-order no cambia automáticamente los hijos)
                return;
            }

            foreach ($included_orders as $child_order_id) {
                $child_order = wc_get_order($child_order_id);
                if (!$child_order) {
                    continue;
                }

                $current_child_status = $child_order->get_status();
                
                // Lógica mejorada para manejar avances y retrocesos
                if ($current_child_status !== $target_status) {
                    // Verificar si es un avance o retroceso
                    $is_backwards_transition = $this->isBackwardsTransitionForChildren($current_child_status, $target_status);
                    
                    // Permitir el cambio salvo en casos específicos
                    $should_skip = false;
                    
                    // No retroceder desde 'completed' a menos que sea explícitamente necesario
                    if ($current_child_status === 'completed' && $target_status !== 'completed') {
                        $should_skip = true;
                    }
                    
                    if (!$should_skip) {
                        $change_reason = $is_backwards_transition ? 'reverted' : 'automatically changed';
                        $child_order->update_status($target_status, 
                            sprintf(__('Status %s to %s after master validated order #%d changed to %s', 'neve-child'), 
                                $change_reason, $transition_description, $order_id, $new_status)
                        );
                        $changed_children++;
                    } else {
                        $skipped_children++;
                    }
                } else {
                    $skipped_children++;
                }
            }

            // Agregar nota al pedido maestro
            if ($changed_children > 0 || $skipped_children > 0) {
                $note_parts = [];
                
                if ($changed_children > 0) {
                    $note_parts[] = sprintf(__('%d child orders automatically changed to "%s"', 'neve-child'), 
                        $changed_children, $transition_description);
                }
                
                if ($skipped_children > 0) {
                    $note_parts[] = sprintf(__('%d child orders skipped (already in target status or completed)', 'neve-child'), $skipped_children);
                }
                
                $final_note = sprintf(__('Master validated order changed to %s. %s', 'neve-child'), 
                    $new_status, implode('. ', $note_parts));
                $order->add_order_note($final_note);
            }

        } catch (\Exception $e) {
            // Error silencioso
        }
    }

}

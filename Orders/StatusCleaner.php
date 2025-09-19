<?php
/**
 * Status Cleaner - Limpia estados de WooCommerce no deseados
 * 
 * Este archivo elimina todos los estados personalizados de WooCommerce
 * que no estén en nuestra lista permitida, manteniendo solo:
 * - Estados comunes de WooCommerce
 * - Estados personalizados definidos en StatusManager
 * 
 * @package SchoolManagement\Orders
 * @since 1.0.0
 */

namespace SchoolManagement\Orders;

if (!defined('ABSPATH')) {
    exit;
}

class StatusCleaner
{
    /**
     * Estados comunes de WooCommerce que SIEMPRE se mantienen
     */
    private const CORE_WOOCOMMERCE_STATUSES = [
        'wc-pending',
        'wc-processing',
        'wc-on-hold',
        'wc-completed',
        'wc-cancelled',
        'wc-refunded',
        'wc-failed',
    ];

    /**
     * Estados personalizados permitidos (del StatusManager)
     */
    private const ALLOWED_CUSTOM_STATUSES = [
        // Estados normales personalizados
        'wc-pay-later',
        'wc-reviewed',
        'wc-warehouse',
        'wc-prepared',
        
        // Estados de Master Orders
        'wc-master-order',
        'wc-mast-warehs',
        'wc-mast-prepared',
        'wc-mast-complete',
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->initHooks();
    }

    /**
     * Inicializar hooks
     */
    private function initHooks(): void
    {
        // Filtrar estados disponibles
        // add_filter('wc_order_statuses', [$this, 'cleanOrderStatuses'], 999);
        
        // // Limpiar estados registrados
        // add_action('init', [$this, 'unregisterUnwantedStatuses'], 999);
        
        // Filtrar bulk actions para remover estados no deseados - ACTIVADO
        // add_filter('bulk_actions-edit-shop_order', [$this, 'cleanBulkActions'], 999);
        add_filter('bulk_actions-woocommerce_page_wc-orders', [$this, 'cleanBulkActions'], 999);
        
        // // Agregar acción de limpieza manual en admin
        // add_action('admin_menu', [$this, 'addCleanupAdminMenu']);
        // add_action('admin_post_cleanup_order_statuses', [$this, 'handleManualCleanup']);
        
        // // === ELIMINAR ESTADOS DUPLICADOS/NO DESEADOS ===
        // // Eliminar estados legacy que causan duplicados
        // add_action('init', [$this, 'removeLegacyDuplicateStatuses'], 25);
        // add_filter('wc_order_statuses', [$this, 'removeLegacyDuplicateFromList'], 25);
    }

    /**
     * Limpiar estados de pedidos, manteniendo solo los permitidos
     * 
     * @param array $order_statuses Estados actuales
     * @return array Estados filtrados
     */
    public function cleanOrderStatuses(array $order_statuses): array
    {
        $allowed_statuses = array_merge(
            self::CORE_WOOCOMMERCE_STATUSES,
            self::ALLOWED_CUSTOM_STATUSES
        );

        // Filtrar solo estados permitidos
        $cleaned_statuses = [];
        foreach ($order_statuses as $status_key => $status_label) {
            if (in_array($status_key, $allowed_statuses)) {
                $cleaned_statuses[$status_key] = $status_label;
            }
        }

        return $cleaned_statuses;
    }

    /**
     * Limpiar bulk actions, removiendo acciones para estados no permitidos
     * 
     * @param array $bulk_actions Bulk actions actuales
     * @return array Bulk actions filtradas
     */
    public function cleanBulkActions(array $bulk_actions): array
    {
        // NOTA: Las bulk actions legacy han sido comentadas en functions/woocommerce-order-status.php
        // por lo que ya no deberían aparecer, pero mantenemos este filtro como seguridad adicional
        
        // Solo eliminar bulk actions DUPLICADAS o legacy que pudieran aparecer por otros plugins
        $unwanted_duplicate_actions = [
            'mark_in-progress',     // Duplicado con mark_warehouse (comentado en functions.php)
            'mark_prepared',        // Si aparece duplicado legacy (comentado en functions.php) 
            'mark_customized',      // Estado desactivado (comentado en functions.php)
            'mark_pickup',          // Estado desactivado (comentado en functions.php)
            'mark_estimate',        // Estado desactivado (comentado en functions.php)
        ];

        // Remover solo las bulk actions duplicadas/legacy si aparecieran
        foreach ($unwanted_duplicate_actions as $unwanted_action) {
            if (isset($bulk_actions[$unwanted_action])) {
                unset($bulk_actions[$unwanted_action]);
            }
        }

        // MANTENER todas las demás acciones:
        // - Bulk actions básicas de WooCommerce: mark_processing, mark_on-hold, mark_completed, mark_cancelled
        // - Nuestras acciones personalizadas: mark_reviewed, mark_warehouse
        // - Master orders: mark_master_*
        // - Payment actions: mark_bank_transfers_*
        // - PDF actions: invoice, packing-slip
        // - Acciones del sistema: trash

        return $bulk_actions;
    }

    /**
     * Desregistrar estados no deseados
     */
    public function unregisterUnwantedStatuses(): void
    {
        global $wp_post_statuses;

        if (!isset($wp_post_statuses) || !is_array($wp_post_statuses)) {
            return;
        }

        $allowed_statuses = array_merge(
            self::CORE_WOOCOMMERCE_STATUSES,
            self::ALLOWED_CUSTOM_STATUSES
        );

        // Buscar y desregistrar estados no permitidos
        foreach ($wp_post_statuses as $status_name => $status_object) {
            // Solo procesar estados que empiecen con 'wc-'
            if (strpos($status_name, 'wc-') === 0) {
                if (!in_array($status_name, $allowed_statuses)) {
                    // Desregistrar estado no permitido
                    unset($wp_post_statuses[$status_name]);
                }
            }
        }
    }

    /**
     * Agregar menú de limpieza en admin (solo para administradores)
     */
    public function addCleanupAdminMenu(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        add_submenu_page(
            'woocommerce',
            __('Status Cleanup', 'neve-child'),
            __('Status Cleanup', 'neve-child'),
            'manage_options',
            'order-status-cleanup',
            [$this, 'renderCleanupPage']
        );
    }

    /**
     * Renderizar página de limpieza
     */
    public function renderCleanupPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'neve-child'));
        }

        $all_statuses = $this->getAllRegisteredStatuses();
        $unwanted_statuses = $this->getUnwantedStatuses($all_statuses);

        ?>
        <div class="wrap">
            <h1><?php echo __('Order Status Cleanup', 'neve-child'); ?></h1>
            
            <div class="notice notice-info">
                <p><strong><?php echo __('This tool helps clean up unwanted order statuses.', 'neve-child'); ?></strong></p>
                <p><?php echo __('Only core WooCommerce statuses and your custom statuses will be kept.', 'neve-child'); ?></p>
            </div>

            <h2><?php echo __('Current Status Overview', 'neve-child'); ?></h2>
            
            <h3><?php echo __('✅ Allowed Statuses (Will be kept)', 'neve-child'); ?></h3>
            <ul>
                <li><strong><?php echo __('Core WooCommerce:', 'neve-child'); ?></strong>
                    <ul>
                        <?php foreach (self::CORE_WOOCOMMERCE_STATUSES as $status): ?>
                            <li><code><?php echo esc_html($status); ?></code> - <?php echo esc_html($all_statuses[$status] ?? 'Unknown'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </li>
                <li><strong><?php echo __('Custom Statuses:', 'neve-child'); ?></strong>
                    <ul>
                        <?php foreach (self::ALLOWED_CUSTOM_STATUSES as $status): ?>
                            <li><code><?php echo esc_html($status); ?></code> - <?php echo esc_html($all_statuses[$status] ?? 'Not registered yet'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </li>
            </ul>

            <?php if (!empty($unwanted_statuses)): ?>
                <h3><?php echo __('❌ Unwanted Statuses (Will be removed)', 'neve-child'); ?></h3>
                <ul>
                    <?php foreach ($unwanted_statuses as $status => $label): ?>
                        <li><code><?php echo esc_html($status); ?></code> - <?php echo esc_html($label); ?></li>
                    <?php endforeach; ?>
                </ul>

                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <?php wp_nonce_field('cleanup_order_statuses', 'cleanup_nonce'); ?>
                    <input type="hidden" name="action" value="cleanup_order_statuses">
                    
                    <p class="submit">
                        <input type="submit" 
                               name="submit" 
                               id="submit" 
                               class="button button-primary" 
                               value="<?php echo esc_attr(__('Clean Up Unwanted Statuses', 'neve-child')); ?>"
                               onclick="return confirm('<?php echo esc_js(__('Are you sure you want to clean up these statuses? This action cannot be undone.', 'neve-child')); ?>')">
                    </p>
                </form>
            <?php else: ?>
                <div class="notice notice-success">
                    <p><strong><?php echo __('✅ No unwanted statuses found!', 'neve-child'); ?></strong></p>
                    <p><?php echo __('Your order statuses are already clean.', 'neve-child'); ?></p>
                </div>
            <?php endif; ?>

            <h2><?php echo __('Status Usage in Orders', 'neve-child'); ?></h2>
            <?php $this->displayStatusUsage(); ?>
        </div>
        <?php
    }

    /**
     * Manejar limpieza manual
     */
    public function handleManualCleanup(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to perform this action.', 'neve-child'));
        }

        if (!wp_verify_nonce($_POST['cleanup_nonce'] ?? '', 'cleanup_order_statuses')) {
            wp_die(__('Security check failed.', 'neve-child'));
        }

        $cleaned_count = $this->performStatusCleanup();

        $redirect_url = add_query_arg([
            'page' => 'order-status-cleanup',
            'cleaned' => $cleaned_count,
        ], admin_url('admin.php'));

        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Realizar limpieza de estados
     * 
     * @return int Número de estados eliminados
     */
    private function performStatusCleanup(): int
    {
        global $wp_post_statuses;

        $cleaned_count = 0;
        $allowed_statuses = array_merge(
            self::CORE_WOOCOMMERCE_STATUSES,
            self::ALLOWED_CUSTOM_STATUSES
        );

        foreach ($wp_post_statuses as $status_name => $status_object) {
            if (strpos($status_name, 'wc-') === 0) {
                if (!in_array($status_name, $allowed_statuses)) {
                    unset($wp_post_statuses[$status_name]);
                    $cleaned_count++;
                }
            }
        }

        // Limpiar cache
        wp_cache_flush();

        return $cleaned_count;
    }

    /**
     * Obtener todos los estados registrados
     * 
     * @return array
     */
    private function getAllRegisteredStatuses(): array
    {
        return wc_get_order_statuses();
    }

    /**
     * Obtener estados no deseados
     * 
     * @param array $all_statuses
     * @return array
     */
    private function getUnwantedStatuses(array $all_statuses): array
    {
        $allowed_statuses = array_merge(
            self::CORE_WOOCOMMERCE_STATUSES,
            self::ALLOWED_CUSTOM_STATUSES
        );

        $unwanted = [];
        foreach ($all_statuses as $status => $label) {
            if (strpos($status, 'wc-') === 0 && !in_array($status, $allowed_statuses)) {
                $unwanted[$status] = $label;
            }
        }

        return $unwanted;
    }

    /**
     * Mostrar uso de estados en pedidos
     */
    private function displayStatusUsage(): void
    {
        global $wpdb;

        $query = "
            SELECT post_status, COUNT(*) as count 
            FROM {$wpdb->posts} 
            WHERE post_type = 'shop_order' 
            AND post_status LIKE 'wc-%'
            GROUP BY post_status 
            ORDER BY count DESC
        ";

        $results = $wpdb->get_results($query);
        $all_statuses = $this->getAllRegisteredStatuses();
        $allowed_statuses = array_merge(
            self::CORE_WOOCOMMERCE_STATUSES,
            self::ALLOWED_CUSTOM_STATUSES
        );

        if ($results) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>' . __('Status', 'neve-child') . '</th>';
            echo '<th>' . __('Label', 'neve-child') . '</th>';
            echo '<th>' . __('Order Count', 'neve-child') . '</th>';
            echo '<th>' . __('Action', 'neve-child') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($results as $row) {
                $status = $row->post_status;
                $count = $row->count;
                $label = $all_statuses[$status] ?? __('Unknown Status', 'neve-child');
                $is_allowed = in_array($status, $allowed_statuses);

                echo '<tr>';
                echo '<td><code>' . esc_html($status) . '</code></td>';
                echo '<td>' . esc_html($label) . '</td>';
                echo '<td>' . intval($count) . '</td>';
                echo '<td>';
                
                if ($is_allowed) {
                    echo '<span style="color: green;">✅ ' . __('Keep', 'neve-child') . '</span>';
                } else {
                    echo '<span style="color: red;">❌ ' . __('Remove', 'neve-child') . '</span>';
                    if ($count > 0) {
                        echo '<br><small style="color: orange;">⚠️ ' . sprintf(__('%d orders will need status update', 'neve-child'), $count) . '</small>';
                    }
                }
                
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        } else {
            echo '<p>' . __('No orders found.', 'neve-child') . '</p>';
        }
    }

    /**
     * Obtener estados permitidos (método público para otros usos)
     * 
     * @return array
     */
    public function getAllowedStatuses(): array
    {
        return array_merge(
            self::CORE_WOOCOMMERCE_STATUSES,
            self::ALLOWED_CUSTOM_STATUSES
        );
    }

    /**
     * Verificar si un estado está permitido
     * 
     * @param string $status
     * @return bool
     */
    public function isStatusAllowed(string $status): bool
    {
        return in_array($status, $this->getAllowedStatuses());
    }

    // =====================================
    // ELIMINACIÓN DE ESTADOS DUPLICADOS - TEMPORALMENTE DESACTIVADO
    // =====================================

    /**
     * Estados legacy duplicados que deben eliminarse del registro global
     * Estos estados causan conflictos con el nuevo sistema
     * 
     * NOTA: 'wc-prepared' REMOVIDO de esta lista porque ahora es un estado válido
     */
    private const LEGACY_DUPLICATE_STATUSES = [
        'wc-in-progress',  // Duplicado con wc-warehouse
        // 'wc-prepared',  // REMOVIDO: Ahora es un estado válido en StatusManager
        'wc-customized',   // Estado desactivado en functions.php
        'wc-pickup',       // Estado comentado/desactivado 
        'wc-estimate',     // Estado comentado/desactivado
    ];

    /**
     * Eliminar estados legacy duplicados del registro global
     * Similar al patrón usado para wc-customized en functions.php
     * 
     * TEMPORALMENTE DESACTIVADO PARA DEBUG
     */
    public function removeLegacyDuplicateStatuses(): void
    {
        // DESACTIVADO TEMPORALMENTE PARA EVITAR ELIMINAR 'wc-prepared'
        return;
        
        global $wp_post_statuses;

        foreach (self::LEGACY_DUPLICATE_STATUSES as $status) {
            if (isset($wp_post_statuses[$status])) {
                unset($wp_post_statuses[$status]);
            }
        }
    }

    /**
     * Eliminar estados legacy duplicados del listado de WooCommerce
     * Similar al patrón usado para wc-customized en functions.php
     * 
     * TEMPORALMENTE DESACTIVADO PARA DEBUG
     * 
     * @param array $order_statuses
     * @return array
     */
    public function removeLegacyDuplicateFromList(array $order_statuses): array
    {
        // DESACTIVADO TEMPORALMENTE PARA EVITAR ELIMINAR 'wc-prepared'
        return $order_statuses;
        
        foreach (self::LEGACY_DUPLICATE_STATUSES as $status) {
            if (isset($order_statuses[$status])) {
                unset($order_statuses[$status]);
            }
        }
        return $order_statuses;
    }
}

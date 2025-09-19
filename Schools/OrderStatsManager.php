<?php
/**
 * Order Statistics Manager for Schools
 * 
 * Muestra estadísticas de pedidos (revisado, master order, processing) 
 * en la columna del admin de centros escolares
 * 
 * @package SchoolManagement\Schools
 * @since 1.0.0
 */

namespace SchoolManagement\Schools;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for managing order statistics display in schools admin
 */
class OrderStatsManager
{
    /**
     * Tipo de post de escuela
     */
    private const POST_TYPE = 'coo_school';

    /**
     * Estados de órdenes a rastrear
     */
    private const ORDER_STATUSES = [
        'reviewed' => 'wc-reviewed',
        'master' => 'wc-master-order', 
        'processing' => 'wc-processing'
    ];

    /**
     * Posibles meta keys para school ID (en orden de preferencia)
     */
    private const POSSIBLE_SCHOOL_META_KEYS = [
        '_school_id',
        'school_id', 
        '_centro_id',
        'centro_id',
        '_institution_id',
        'institution_id'
    ];

    /**
     * Meta key de escuela en caché
     */
    private static $school_meta_key = null;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->initHooks();
    }

    /**
     * Inicializar hooks de WordPress
     */
    public function initHooks(): void
    {
        // Agregar columna de estadísticas de órdenes al admin de escuelas
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'addOrderStatsColumn']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'displayOrderStatsColumn'], 10, 2);
        
        // Agregar CSS para mejor estilo
        add_action('admin_head', [$this, 'addAdminStyles']);
        
        // Hacer la columna ordenable (opcional)
        add_filter('manage_edit-' . self::POST_TYPE . '_sortable_columns', [$this, 'makeOrderStatsColumnSortable']);
        
        // Manejar lógica de ordenamiento (opcional)
        add_action('pre_get_posts', [$this, 'handleOrderStatsColumnSorting']);
        
        // Agregar acción de admin para rellenar datos
        add_action('admin_init', [$this, 'maybeAddBackfillAction']);
    }

    /**
     * Agregar columna de estadísticas de órdenes a la lista de escuelas
     * 
     * @param array $columns Columnas existentes
     * @return array Columnas modificadas
     */
    public function addOrderStatsColumn(array $columns): array
    {
        $new_columns = [];

        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            // Agregar después de la columna title
            if ($key === 'title') {
                $new_columns['order_stats'] = __('Order Statistics', 'neve-child');
            }
        }

        // Si la columna title no existe, agregar al final
        if (!isset($new_columns['order_stats'])) {
            $new_columns['order_stats'] = __('Order Statistics', 'neve-child');
        }

        return $new_columns;
    }

    /**
     * Mostrar estadísticas de órdenes en la columna
     * 
     * @param string $column_name Nombre de la columna
     * @param int $post_id ID de la escuela
     * @return void
     */
    public function displayOrderStatsColumn(string $column_name, int $post_id): void
    {
        if ($column_name !== 'order_stats') {
            return;
        }

        // Obtener estadísticas directamente sin caché
        $stats = $this->getSchoolOrderStats($post_id);
        
        if (empty($stats) || array_sum($stats) === 0) {
            echo '<span class="order-stats-empty">' . __('No orders', 'neve-child') . '</span>';
            return;
        }

        echo '<div class="order-stats-container">';
        
        foreach ($stats as $status => $count) {
            if ($count > 0) {
                $status_label = $this->getStatusLabel($status);
                $status_class = $this->getStatusClass($status);
                
                echo sprintf(
                    '<div class="order-stat-item %s">
                        <span class="status-label">%s</span>
                        <span class="status-count">%d</span>
                    </div>',
                    esc_attr($status_class),
                    esc_html($status_label),
                    $count
                );
            }
        }
        
        echo '</div>';
    }

    /**
     * Obtener estadísticas de órdenes para una escuela (método público)
     * 
     * @param int $school_id ID de la escuela
     * @return array Array de estadísticas con conteos de estados
     */
    public function getOrderStatistics(int $school_id): array
    {
        return $this->getSchoolOrderStats($school_id);
    }

    /**
     * Obtener el meta key correcto para school ID detectándolo automáticamente
     * 
     * @return string|null El meta key correcto o null si no se encuentra
     */
    private function detectSchoolMetaKey(): ?string
    {
        if (self::$school_meta_key !== null) {
            return self::$school_meta_key;
        }

        global $wpdb;
        
        // Primero, obtener algunos IDs de escuelas para probar
        $school_ids = $wpdb->get_col("
            SELECT ID 
            FROM {$wpdb->posts} 
            WHERE post_type = 'coo_school' 
            AND post_status = 'publish'
            LIMIT 10
        ");
        
        if (empty($school_ids)) {
            return null;
        }
        
        $school_ids_str = implode(',', array_map('intval', $school_ids));
        
        // Probar cada posible meta key en las tablas HPOS
        foreach (self::POSSIBLE_SCHOOL_META_KEYS as $meta_key) {
            $count = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(DISTINCT o.id)
                FROM {$wpdb->prefix}wc_orders o
                INNER JOIN {$wpdb->prefix}wc_orders_meta om ON o.id = om.order_id
                WHERE om.meta_key = %s
                AND om.meta_value IN ({$school_ids_str})
            ", $meta_key));
            
            if ($count > 0) {
                self::$school_meta_key = $meta_key;
                return $meta_key;
            }
        }
        
        // Intentar encontrar cualquier meta key con IDs de escuela como valores en HPOS
        $dynamic_meta = $wpdb->get_row("
            SELECT meta_key, COUNT(*) as count
            FROM {$wpdb->prefix}wc_orders_meta om
            INNER JOIN {$wpdb->prefix}wc_orders o ON om.order_id = o.id
            WHERE om.meta_value IN ({$school_ids_str})
            GROUP BY meta_key
            ORDER BY count DESC
            LIMIT 1
        ");
        
        if ($dynamic_meta) {
            self::$school_meta_key = $dynamic_meta->meta_key;
            return $dynamic_meta->meta_key;
        }
        
        // Cachear el resultado null para evitar consultas repetidas
        self::$school_meta_key = false;
        return null;
    }

    /**
     * Obtener estadísticas de órdenes para una escuela
     * 
     * @param int $school_id ID de la escuela
     * @return array Array de estadísticas con conteos de estados
     */
    private function getSchoolOrderStats(int $school_id): array
    {
        global $wpdb;
        
        $stats = [
            'reviewed' => 0,
            'master' => 0,
            'processing' => 0
        ];

        // Detectar el meta key correcto para school ID
        $school_meta_key = $this->detectSchoolMetaKey();
        
        if (!$school_meta_key) {
            // No se encontró meta key de escuela, retornar estadísticas vacías
            return $stats;
        }

        // Usar tablas HPOS para órdenes de WooCommerce
        $query = "
            SELECT o.status, COUNT(*) as count
            FROM {$wpdb->prefix}wc_orders o
            INNER JOIN {$wpdb->prefix}wc_orders_meta om ON o.id = om.order_id
            WHERE om.meta_key = %s
            AND om.meta_value = %d
            AND o.status IN ('" . implode("','", array_values(self::ORDER_STATUSES)) . "')
            GROUP BY o.status
        ";
        
        $results = $wpdb->get_results($wpdb->prepare($query, $school_meta_key, $school_id));
        
        if ($results) {
            foreach ($results as $result) {
                $status_key = $this->getStatusKeyFromWCStatus($result->status);
                if ($status_key) {
                    $stats[$status_key] = (int) $result->count;
                }
            }
        }

        return $stats;
    }

    /**
     * Obtener clave de estado desde estado de WooCommerce
     * 
     * @param string $wc_status Estado de WooCommerce (ej. 'wc-reviewed')
     * @return string|null Clave de estado o null si no se encuentra
     */
    private function getStatusKeyFromWCStatus(string $wc_status): ?string
    {
        $status_map = array_flip(self::ORDER_STATUSES);
        return $status_map[$wc_status] ?? null;
    }

    /**
     * Obtener etiqueta legible del estado
     * 
     * @param string $status Clave del estado
     * @return string Etiqueta del estado
     */
    private function getStatusLabel(string $status): string
    {
        $labels = [
            'reviewed' => __('Reviewed', 'neve-child'),
            'master' => __('Master', 'neve-child'),
            'processing' => __('Processing', 'neve-child')
        ];

        return $labels[$status] ?? ucfirst($status);
    }

    /**
     * Obtener clase CSS para estilo del estado
     * 
     * @param string $status Clave del estado
     * @return string Clase CSS
     */
    private function getStatusClass(string $status): string
    {
        $classes = [
            'reviewed' => 'status-reviewed',
            'master' => 'status-master',
            'processing' => 'status-processing'
        ];

        return $classes[$status] ?? 'status-default';
    }

    /**
     * Agregar estilos CSS para la columna de estadísticas de órdenes
     * 
     * @return void
     */
    public function addAdminStyles(): void
    {
        $screen = get_current_screen();
        
        if (!$screen || $screen->post_type !== self::POST_TYPE || $screen->base !== 'edit') {
            return;
        }
        ?>
        <style>
        .order-stats-container {
            font-size: 12px;
            line-height: 1.4;
            min-width: 120px;
        }
        
        .order-stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 5px 8px;
            margin: 10px 0;
            border-radius: 4px;
            background: #f6f7f7;
            border-left: 3px solid #ddd;
        }
        
        .order-stat-item.status-reviewed {
            background: #e8f4fd;
            border-left-color: #0073aa;
        }
        
        .order-stat-item.status-master {
            background: #fff2e8;
            border-left-color: #d63638;
        }
        
        .order-stat-item.status-processing {
            background: #edf5e8;
            border-left-color: #00a32a;
        }
        
        .status-label {
            font-weight: 500;
            color: #555;
            font-size: 11px;
        }
        
        .status-count {
            font-weight: bold;
            color: #333;
            background: rgba(255,255,255,0.9);
            padding: 2px 6px;
            border-radius: 10px;
            min-width: 18px;
            text-align: center;
            font-size: 11px;
            line-height: 1.2;
        }
        
        .order-stats-empty {
            color: #999;
            font-style: italic;
            font-size: 11px;
            text-align: center;
            padding: 8px;
        }
        
        .column-order_stats {
            width: 140px;
        }
        
        /* Comportamiento responsivo */
        @media screen and (max-width: 1200px) {
            .column-order_stats {
                width: 120px;
            }
            .status-label {
                font-size: 10px;
            }
            .status-count {
                font-size: 10px;
                padding: 1px 4px;
                min-width: 16px;
            }
        }
        
        @media screen and (max-width: 782px) {
            .column-order_stats {
                display: none;
            }
        }
        </style>
        <?php
    }

    /**
     * Hacer la columna de estadísticas de órdenes ordenable
     * 
     * @param array $columns Columnas ordenables
     * @return array Columnas ordenables modificadas
     */
    public function makeOrderStatsColumnSortable(array $columns): array
    {
        $columns['order_stats'] = 'order_stats_total';
        return $columns;
    }

    /**
     * Manejar ordenamiento para la columna de estadísticas de órdenes
     * 
     * @param \WP_Query $query Consulta de WordPress
     * @return void
     */
    public function handleOrderStatsColumnSorting(\WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        $orderby = $query->get('orderby');
        
        if ($orderby === 'order_stats_total') {
            // Ordenar por conteo total de órdenes calculado dinámicamente
            $query->set('orderby', 'ID');
        }
    }

    /**
     * Agregar acción de relleno si es necesario
     * 
     * @return void
     */
    public function maybeAddBackfillAction(): void
    {
        // Solo agregar si estamos en admin y el usuario tiene permisos apropiados
        if (is_admin() && current_user_can('manage_options')) {
            add_action('admin_notices', [$this, 'showBackfillNotice']);
            
            // Manejar acción de relleno
            if (isset($_GET['action']) && $_GET['action'] === 'backfill_school_orders' && 
                isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'backfill_school_orders')) {
                $this->backfillSchoolOrderData();
            }
        }
    }

    /**
     * Mostrar aviso de relleno si existen órdenes sin school_id
     * 
     * @return void
     */
    public function showBackfillNotice(): void
    {
        global $wpdb;
        
        // Mostrar resultado de relleno si está disponible
        $backfill_result = get_transient('order_stats_backfill_result');
        if ($backfill_result) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>' . esc_html($backfill_result) . '</p>';
            echo '</div>';
            delete_transient('order_stats_backfill_result');
            return;
        }
        
        // Solo mostrar en páginas relevantes
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['edit-shop_order', 'woocommerce_page_wc-orders', 'edit-coo_school'])) {
            return;
        }
        
        // Verificar si hay órdenes sin school_id pero con usuarios que tienen school_id
        $orders_without_school = $wpdb->get_var("
            SELECT COUNT(DISTINCT o.id)
            FROM {$wpdb->prefix}wc_orders o
            INNER JOIN {$wpdb->prefix}wc_orders_meta om_user ON o.id = om_user.order_id
            INNER JOIN {$wpdb->usermeta} um ON om_user.meta_value = um.user_id
            LEFT JOIN {$wpdb->prefix}wc_orders_meta om_school ON o.id = om_school.order_id AND om_school.meta_key = '_school_id'
            WHERE om_user.meta_key = '_customer_user'
            AND om_user.meta_value > 0
            AND um.meta_key = 'school_id'
            AND um.meta_value != ''
            AND om_school.order_id IS NULL
        ");
        
        if ($orders_without_school > 0) {
            $backfill_url = wp_nonce_url(
                admin_url('edit.php?post_type=coo_school&action=backfill_school_orders'),
                'backfill_school_orders'
            );
            
            echo '<div class="notice notice-info is-dismissible">';
            echo '<p><strong>Order Statistics:</strong> ';
            printf(
                __('Found %d orders that could be assigned to schools. <a href="%s" class="button button-secondary">Assign School Data</a>', 'neve-child'),
                $orders_without_school,
                esc_url($backfill_url)
            );
            echo '</p></div>';
        }
    }

    /**
     * Rellenar datos de escuela para órdenes existentes
     * 
     * @return void
     */
    public function backfillSchoolOrderData(): void
    {
        global $wpdb;
        
        // Obtener órdenes que necesitan asignación de school_id usando tablas HPOS
        $orders_to_update = $wpdb->get_results("
            SELECT DISTINCT o.id, om_user.meta_value as user_id, um.meta_value as school_id
            FROM {$wpdb->prefix}wc_orders o
            INNER JOIN {$wpdb->prefix}wc_orders_meta om_user ON o.id = om_user.order_id
            INNER JOIN {$wpdb->usermeta} um ON om_user.meta_value = um.user_id
            LEFT JOIN {$wpdb->prefix}wc_orders_meta om_school ON o.id = om_school.order_id AND om_school.meta_key = '_school_id'
            WHERE om_user.meta_key = '_customer_user'
            AND om_user.meta_value > 0
            AND um.meta_key = 'school_id'
            AND um.meta_value != ''
            AND om_school.order_id IS NULL
            LIMIT 100
        ");
        
        $updated_count = 0;
        $errors = 0;
        
        foreach ($orders_to_update as $order_data) {
            $order = wc_get_order($order_data->id);
            
            if (!$order) {
                $errors++;
                continue;
            }
            
            try {
                // Agregar datos de escuela a la orden
                $order->update_meta_data('_school_id', (int) $order_data->school_id);
                
                // Agregar nombre de escuela
                $school_name = get_the_title($order_data->school_id);
                if ($school_name) {
                    $order->update_meta_data('_school_name', $school_name);
                }
                
                // Agregar datos de vendor si están disponibles
                $vendor_id = get_field('vendor', $order_data->school_id);
                if ($vendor_id) {
                    $order->update_meta_data('_vendor_id', $vendor_id);
                    $vendor_name = get_the_title($vendor_id);
                    if ($vendor_name) {
                        $order->update_meta_data('_vendor_name', $vendor_name);
                    }
                }
                
                $order->save();
                $updated_count++;
                
            } catch (Exception $e) {
                $errors++;
            }
        }
        
        // Mostrar aviso de admin con resultados
        $message = sprintf(
            __('Backfill complete: %d orders updated, %d errors.', 'neve-child'),
            $updated_count,
            $errors
        );
        
        set_transient('order_stats_backfill_result', $message, 30);
        
        // Redireccionar para remover parámetros de acción
        wp_redirect(admin_url('edit.php?post_type=coo_school'));
        exit;
    }
}

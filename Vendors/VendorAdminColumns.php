<?php
/**
 * Vendor Admin Columns Manager
 * 
 * Gestiona las columnas personalizadas en el admin de vendors de WordPress,
 * incluyendo totales de dinero por vendor
 * 
 * LÓGICA DE PREVENCIÓN DE DUPLICACIONES:
 * Este sistema cuenta SOLO órdenes individuales para evitar duplicaciones:
 * 
 * - Excluye completamente las Master Orders del conteo
 * - Solo cuenta órdenes individuales que NO pertenecen a una Master Order
 * - Esto previene la doble contabilidad ya que las Master Orders son contenedores 
 * - Las órdenes individuales contienen los valores reales de productos y totales
 * 
 * @package SchoolManagement\Vendors
 * @since 1.0.0
 */

namespace SchoolManagement\Vendors;

use SchoolManagement\Shared\Constants;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for managing custom columns in WordPress vendor admin
 */
class VendorAdminColumns
{
    /**
     * Post types
     */
    private const VENDOR_POST_TYPE = 'coo_vendor';
    private const SCHOOL_POST_TYPE = 'coo_school';

    /**
     * Estados de órdenes individuales para vendors
     * EXCLUYE Master Orders para evitar duplicaciones
     */
    const DEFAULT_ORDER_STATUSES = [
        // Solo estados de órdenes individuales
        'wc-processing' => 'processing',
        'wc-reviewed' => 'reviewed',
        'wc-warehouse' => 'warehouse',
        'wc-prepared' => 'prepared',
        'wc-completed' => 'completed'
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->initHooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function initHooks(): void
    {
        // Agregar columnas personalizadas a la lista de vendors
        add_filter('manage_' . self::VENDOR_POST_TYPE . '_posts_columns', [$this, 'addCustomColumns']);
        add_action('manage_' . self::VENDOR_POST_TYPE . '_posts_custom_column', [$this, 'displayCustomColumn'], 10, 2);

        // Hacer columnas ordenables
        add_filter('manage_edit-' . self::VENDOR_POST_TYPE . '_sortable_columns', [$this, 'makeColumnsSortable']);
        add_action('pre_get_posts', [$this, 'handleCustomSorting']);

        // Agregar estilos para las columnas
        add_action('admin_head', [$this, 'addCustomStyles']);

    }

    /**
     * Add custom columns
     * 
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public function addCustomColumns(array $columns): array
    {
        $new_columns = [];

        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            // Añadir columnas después del título
            if ($key === 'title') {
                $new_columns['total_sales'] = '<span title="' . __('Total vendor sales', 'neve-child') . '">💰 ' . __('Total Sales', 'neve-child') . '</span>';
                $new_columns['schools_count'] = '<span title="' . __('Number of associated schools', 'neve-child') . '">🏫 ' . __('Schools', 'neve-child') . '</span>';
                $new_columns['orders_count'] = '<span title="' . __('Total number of orders', 'neve-child') . '">📦 ' . __('Orders', 'neve-child') . '</span>';
                $new_columns['last_order'] = '<span title="' . __('Last order date', 'neve-child') . '">📅 ' . __('Last Order', 'neve-child') . '</span>';
            }
        }

        return $new_columns;
    }

    /**
     * Display custom column content
     * 
     * @param string $column_name Column name
     * @param int $post_id Post ID
     */
    public function displayCustomColumn(string $column_name, int $post_id): void
    {
        switch ($column_name) {
            case 'total_sales':
                echo $this->getTotalSalesColumn($post_id);
                break;

            case 'schools_count':
                echo $this->getSchoolsCountColumn($post_id);
                break;

            case 'orders_count':
                echo $this->getOrdersCountColumn($post_id);
                break;

            case 'last_order':
                echo $this->getLastOrderColumn($post_id);
                break;
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
        $columns['total_sales'] = 'total_sales';
        $columns['schools_count'] = 'schools_count';
        $columns['orders_count'] = 'orders_count';
        $columns['last_order'] = 'last_order';
        
        return $columns;
    }

    /**
     * Handle custom sorting
     * 
     * @param \WP_Query $query Query object
     */
    public function handleCustomSorting(\WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        $orderby = $query->get('orderby');

        if (!$orderby || $query->get('post_type') !== self::VENDOR_POST_TYPE) {
            return;
        }

        switch ($orderby) {
            case 'total_sales':
                // Sales sorting is handled with meta_query in JavaScript
                break;

            case 'schools_count':
                // Schools sorting is calculated dynamically
                break;

            case 'orders_count':
                // Orders sorting is calculated dynamically
                break;

            case 'last_order':
                // Last order sorting is calculated dynamically
                break;
        }
    }



    /**
     * Generar condición SQL para evitar órdenes que pertenecen a Master Orders
     * Solo contar órdenes individuales sin Master Order padre
     */
    private function getDuplicationPreventionCondition(): string
    {
        return "parent_order.id IS NULL";  // Solo órdenes SIN Master Order padre
    }

    /**
     * Obtener estados de órdenes para usar en consultas DB
     */
    private function getOrderStatusesForQuery(): array
    {
        // Usar estados por defecto que incluyen Master Orders
        return array_keys(self::DEFAULT_ORDER_STATUSES);
    }

    /**
     * Get total sales column
     * 
     * @param int $vendor_id Vendor ID
     * @return string Column HTML
     */
    private function getTotalSalesColumn(int $vendor_id): string
    {
        global $wpdb;

        // Get statuses for query (only individual orders)
        $query_statuses = $this->getOrderStatusesForQuery();
        $duplication_condition = $this->getDuplicationPreventionCondition();

        // JOIN to exclude orders that belong to Master Orders
        $master_order_join = "
            LEFT JOIN {$wpdb->prefix}wc_orders_meta parent_meta ON o.id = parent_meta.order_id 
                AND parent_meta.meta_key = 'master_order_id'
            LEFT JOIN {$wpdb->prefix}wc_orders parent_order ON parent_meta.meta_value = parent_order.id";

        // In HPOS, total is in main table wp_wc_orders
        $query = $wpdb->prepare("
            SELECT COALESCE(SUM(CAST(o.total_amount AS DECIMAL(10,2))), 0) as total_sales
            FROM {$wpdb->prefix}wc_orders o
            INNER JOIN {$wpdb->prefix}wc_orders_meta om_vendor ON o.id = om_vendor.order_id
            {$master_order_join}
            WHERE om_vendor.meta_key = %s
                AND om_vendor.meta_value = %s
                AND o.status IN ('" . implode("','", $query_statuses) . "')
                AND {$duplication_condition}
        ", 
            Constants::ORDER_META_VENDOR_ID,
            $vendor_id
        );

        $total = $wpdb->get_var($query);

        $total = floatval($total);

        if ($total <= 0) {
            return '<div class="vendor-sales-info">
                <span class="amount">0,00 €</span>
                <small class="no-sales">' . __('No sales', 'neve-child') . '</small>
            </div>';
        }

        // Format total
        $formatted_total = number_format($total, 2, ',', '.') . ' €';

        return '<div class="vendor-sales-info">
            <span class="amount" style="font-weight: bold; color: #00a32a;">' . esc_html($formatted_total) . '</span>
        </div>';
    }

    /**
     * Get schools count column
     * 
     * @param int $vendor_id Vendor ID
     * @return string Column HTML
     */
    private function getSchoolsCountColumn(int $vendor_id): string
    {
        global $wpdb;

        // Count schools associated with this vendor
        $count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = %s
                AND p.post_status = 'publish'
                AND pm.meta_key = 'vendor'
                AND pm.meta_value = %s
        ", 
            self::SCHOOL_POST_TYPE,
            $vendor_id
        ));

        $count = $count ? (int) $count : 0;

        if ($count === 0) {
            return '<div class="schools-count-info">
                <span class="count">0</span>
                <small>' . __('schools', 'neve-child') . '</small>
            </div>';
        }

        return '<div class="schools-count-info">
            <span class="count" style="font-weight: bold;">' . $count . '</span>
            <small>' . ($count === 1 ? __('school', 'neve-child') : __('schools', 'neve-child')) . '</small>
        </div>';
    }

    /**
     * Get orders count column
     * 
     * @param int $vendor_id Vendor ID
     * @return string Column HTML
     */
    private function getOrdersCountColumn(int $vendor_id): string
    {
        global $wpdb;

        // Get statuses for query (only individual orders)
        $query_statuses = $this->getOrderStatusesForQuery();
        $duplication_condition = $this->getDuplicationPreventionCondition();

        // JOIN to exclude orders that belong to Master Orders
        $master_order_join = "
            LEFT JOIN {$wpdb->prefix}wc_orders_meta parent_meta ON o.id = parent_meta.order_id 
                AND parent_meta.meta_key = 'master_order_id'
            LEFT JOIN {$wpdb->prefix}wc_orders parent_order ON parent_meta.meta_value = parent_order.id";

        // Count vendor orders with anti-duplication logic
        $count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT o.id)
            FROM {$wpdb->prefix}wc_orders o
            INNER JOIN {$wpdb->prefix}wc_orders_meta om ON o.id = om.order_id
            {$master_order_join}
            WHERE om.meta_key = %s
                AND om.meta_value = %s
                AND o.status IN ('" . implode("','", $query_statuses) . "')
                AND {$duplication_condition}
        ", 
            Constants::ORDER_META_VENDOR_ID,
            $vendor_id
        ));

        $count = $count ? (int) $count : 0;

        if ($count === 0) {
            return '<div class="orders-count-info">
                <span class="count">0</span>
                <small>' . __('orders', 'neve-child') . '</small>
            </div>';
        }

        return '<div class="orders-count-info">
            <span class="count" style="font-weight: bold; color: #2271b1;">' . $count . '</span>
            <small>' . ($count === 1 ? __('order', 'neve-child') : __('orders', 'neve-child')) . '</small>
        </div>';
    }

    /**
     * Get last order column
     * 
     * @param int $vendor_id Vendor ID
     * @return string Column HTML
     */
    private function getLastOrderColumn(int $vendor_id): string
    {
        global $wpdb;

        // Get statuses for query (only individual orders)
        $query_statuses = $this->getOrderStatusesForQuery();
        $duplication_condition = $this->getDuplicationPreventionCondition();

        // JOIN to exclude orders that belong to Master Orders
        $master_order_join = "
            LEFT JOIN {$wpdb->prefix}wc_orders_meta parent_meta ON o.id = parent_meta.order_id 
                AND parent_meta.meta_key = 'master_order_id'
            LEFT JOIN {$wpdb->prefix}wc_orders parent_order ON parent_meta.meta_value = parent_order.id";

        // Search last order with anti-duplication logic
        $last_order = $wpdb->get_row($wpdb->prepare("
            SELECT o.id, o.date_created_gmt
            FROM {$wpdb->prefix}wc_orders o
            INNER JOIN {$wpdb->prefix}wc_orders_meta om ON o.id = om.order_id
            {$master_order_join}
            WHERE om.meta_key = %s
                AND om.meta_value = %s
                AND o.status IN ('" . implode("','", $query_statuses) . "')
                AND {$duplication_condition}
            ORDER BY o.date_created_gmt DESC
            LIMIT 1
        ", 
            Constants::ORDER_META_VENDOR_ID,
            $vendor_id
        ));

        if (!$last_order) {
            return '<div class="last-order-info">
                <span class="no-orders">' . __('No orders', 'neve-child') . '</span>
            </div>';
        }

        $date = new \DateTime($last_order->date_created_gmt);
        $now = new \DateTime();
        $diff = $now->diff($date);

        // Format elapsed time
        if ($diff->days === 0) {
            $time_ago = __('Today', 'neve-child');
        } elseif ($diff->days === 1) {
            $time_ago = __('Yesterday', 'neve-child');
        } elseif ($diff->days < 7) {
            $time_ago = sprintf(_n('%d day', '%d days', $diff->days, 'neve-child'), $diff->days);
        } elseif ($diff->days < 30) {
            $weeks = floor($diff->days / 7);
            $time_ago = sprintf(_n('%d week', '%d weeks', $weeks, 'neve-child'), $weeks);
        } elseif ($diff->days < 365) {
            $months = floor($diff->days / 30);
            $time_ago = sprintf(_n('%d month', '%d months', $months, 'neve-child'), $months);
        } else {
            $years = floor($diff->days / 365);
            $time_ago = sprintf(_n('%d year', '%d years', $years, 'neve-child'), $years);
        }

        $order_link = admin_url('post.php?post=' . $last_order->id . '&action=edit');

        return '<div class="last-order-info">
            <a href="' . esc_url($order_link) . '" title="' . sprintf(__('View order #%s', 'neve-child'), $last_order->id) . '">
                <strong>#' . $last_order->id . '</strong>
            </a>
            <small style="color: #666;">' . sprintf(__('%s ago', 'neve-child'), $time_ago) . '</small>
        </div>';
    }

    /**
     * Add custom styles
     */
    public function addCustomStyles(): void
    {
        $screen = get_current_screen();
        
        if (!$screen || $screen->post_type !== self::VENDOR_POST_TYPE || $screen->base !== 'edit') {
            return;
        }

        ?>
        <style>
        /* === COLUMNAS DE VENDORS === */
        .wp-list-table .column-total_sales { width: 12%; text-align: center; }
        .wp-list-table .column-schools_count { width: 8%; text-align: center; }
        .wp-list-table .column-orders_count { width: 8%; text-align: center; }
        .wp-list-table .column-last_order { width: 12%; text-align: center; }

        /* === ESTILOS DE CONTENIDO === */
        .vendor-sales-info, .schools-count-info, 
        .orders-count-info, .last-order-info {
            font-size: 13px;
            line-height: 1.4;
            padding: 4px 0;
            text-align: left;
        }

        .vendor-sales-info .amount {
            display: block;
            font-size: 14px;
            font-weight: bold;
            margin: 0 auto;
        }

        .vendor-sales-info .no-sales {
            color: #d63638;
            font-style: italic;
            display: block;
            margin-top: 2px;
        }

        .schools-count-info .count,
        .orders-count-info .count {
            display: block;
            font-size: 16px;
            font-weight: bold;
            margin: 0 auto 2px;
        }

        .schools-count-info small,
        .orders-count-info small {
            color: #646970;
            font-size: 11px;
            display: block;
        }

        .last-order-info {
            display: flex;
            flex-direction: column;
            justify-content: left;
        }

        .last-order-info a {
            text-decoration: none;
            color: #2271b1;
            margin-bottom: 2px;
        }

        .last-order-info a:hover {
            text-decoration: underline;
        }

        .last-order-info small {
            color: #666;
            font-size: 11px;
        }

        .no-orders {
            color: #d63638;
            font-style: italic;
            font-size: 12px;
        }

        /* === RESPONSIVE === */
        @media screen and (max-width: 1200px) {
            .wp-list-table .column-last_order { display: none; }
            .wp-list-table .column-total_sales { width: 14%; }
            .wp-list-table .column-schools_count { width: 10%; }
            .wp-list-table .column-orders_count { width: 10%; }
        }

        @media screen and (max-width: 900px) {
            .wp-list-table .column-schools_count { display: none; }
            .wp-list-table .column-total_sales { width: 16%; }
            .wp-list-table .column-orders_count { width: 12%; }
        }

        /* === FILTRO DE VENTAS === */
        .sales-filter-wrapper {
            display: inline-block;
            margin-left: 10px;
        }

        .sales-filter-wrapper select {
            margin-right: 5px;
        }
        </style>
        <?php
    }

    /**
     * Get general vendor statistics
     * 
     * @param int $vendor_id Vendor ID
     * @return array Vendor statistics
     */
    public function getVendorStats(int $vendor_id): array
    {
        global $wpdb;

        // Get statuses for query (only individual orders)
        $query_statuses = $this->getOrderStatusesForQuery();
        $duplication_condition = $this->getDuplicationPreventionCondition();

        // JOIN to exclude orders that belong to Master Orders
        $master_order_join = "
            LEFT JOIN {$wpdb->prefix}wc_orders_meta parent_meta ON o.id = parent_meta.order_id 
                AND parent_meta.meta_key = 'master_order_id'
            LEFT JOIN {$wpdb->prefix}wc_orders parent_order ON parent_meta.meta_value = parent_order.id";

        // Unified query to get vendor statistics with anti-duplication
        $stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(DISTINCT o.id) as total_orders,
                COALESCE(SUM(CAST(o.total_amount AS DECIMAL(10,2))), 0) as total_sales,
                MAX(o.date_created_gmt) as last_order_date
            FROM {$wpdb->prefix}wc_orders o
            INNER JOIN {$wpdb->prefix}wc_orders_meta om_vendor ON o.id = om_vendor.order_id
            {$master_order_join}
            WHERE om_vendor.meta_key = %s
                AND om_vendor.meta_value = %s
                AND o.status IN ('" . implode("','", $query_statuses) . "')
                AND {$duplication_condition}
        ", 
            Constants::ORDER_META_VENDOR_ID,
            $vendor_id
        ));

        // Separate query to count schools
        $schools_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = %s
                AND p.post_status = 'publish'
                AND pm.meta_key = 'vendor'
                AND pm.meta_value = %s
        ", 
            self::SCHOOL_POST_TYPE,
            $vendor_id
        ));

        return [
            'total_orders' => intval($stats->total_orders ?? 0),
            'total_sales' => floatval($stats->total_sales ?? 0),
            'schools_count' => intval($schools_count ?? 0),
            'last_order_date' => $stats->last_order_date ?? null
        ];
    }
}

<?php
/**
 * ProductReport
 * 
 * Gestiona específicamente el informe de productos y sus estadísticas
 * 
 * @package SchoolManagement\Reports
 * @since 1.0.0
 */

namespace SchoolManagement\Reports;

if (!defined('ABSPATH')) {
    exit;
}
 
class ProductReport 
{
    /**
     * Estados de órdenes que se incluyen en el informe de productos
     * Actualizado para incluir solo estados relevantes
     */
    const ORDER_STATUSES = [
        // Estados de Master Orders
        'wc-master-order' => 'master-order',
        'wc-mast-warehs' => 'mast-warehs', 
        'wc-mast-prepared' => 'mast-prepared',
        'wc-mast-complete' => 'mast-complete',
        
        // Estados de órdenes individuales específicos
        'wc-processing' => 'processing',
        'wc-reviewed' => 'reviewed',
        'wc-warehouse' => 'warehouse',
        'wc-prepared' => 'prepared',
        'wc-completed' => 'completed'
    ];
    
    /**
     * Meta key cache para school ID
     */
    private static $school_meta_key = null;

    /**
     * Constructor
     */
    public function __construct() {
        $this->setupHooks();
    }

    /**
     * Obtener estados de órdenes específicos para filtros
     * Solo estados de master orders e individuales relevantes
     */
    public function getAllOrderStatuses(): array
    {
        // Estados específicos que queremos mostrar en el filtro
        $filtered_statuses = [
            // Estados de Master Orders
            'wc-master-order' => __('Master Validated', 'neve-child'),
            'wc-mast-warehs' => __('Master Warehouse', 'neve-child'),
            'wc-mast-prepared' => __('Master Prepared', 'neve-child'),
            'wc-mast-complete' => __('Master Complete', 'neve-child'),
            
            // Estados de órdenes individuales específicos
            'wc-processing' => __('Processing', 'neve-child'),
            'wc-reviewed' => __('Reviewed', 'neve-child'),
            'wc-warehouse' => __('Warehouse', 'neve-child'),
            'wc-prepared' => __('Prepared', 'neve-child'),
            'wc-completed' => __('Completed', 'neve-child')
        ];
        
        return $filtered_statuses;
    }

    /**
     * Configurar hooks específicos para el informe de productos
     */
    private function setupHooks() {
        add_action('admin_menu', [$this, 'addProductReportPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        
        // Ajax handler específico para productos
        add_action('wp_ajax_get_product_report_data', [$this, 'handleProductReportAjax']);
    }

    /**
     * Detectar dinámicamente el meta key correcto para school ID
     */
    private function detectSchoolMetaKey(): ?string
    {
        // Si ya está en caché, devolver
        if (self::$school_meta_key !== null) {
            return self::$school_meta_key ?: null;
        }
        
        global $wpdb;
        
        // Buscar meta keys que podrían ser school_id en órdenes HPOS
        $query = "
            SELECT DISTINCT meta_key, COUNT(*) as usage_count
            FROM {$wpdb->prefix}wc_orders_meta 
            WHERE meta_key LIKE '%school%' 
            OR meta_key LIKE '%centro%'
            OR meta_key LIKE '%_school_id%'
            GROUP BY meta_key
            ORDER BY usage_count DESC
            LIMIT 5
        ";
        
        $potential_keys = $wpdb->get_results($query);
        
        // Revisar cada posible key para ver si tiene valores numéricos (school IDs)
        foreach ($potential_keys as $key_data) {
            $test_query = "
                SELECT meta_value
                FROM {$wpdb->prefix}wc_orders_meta 
                WHERE meta_key = %s 
                AND meta_value REGEXP '^[0-9]+$'
                LIMIT 1
            ";
            
            $test_result = $wpdb->get_var($wpdb->prepare($test_query, $key_data->meta_key));
            
            if ($test_result && is_numeric($test_result)) {
                // Verificar que este ID corresponde a un post de tipo coo_school
                $school_check = $wpdb->get_var($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'coo_school'",
                    intval($test_result)
                ));
                
                if ($school_check) {
                    self::$school_meta_key = $key_data->meta_key;
                    return $key_data->meta_key;
                }
            }
        }
        
        // Si no encontramos nada, intentar con meta keys más específicos
        $fallback_keys = ['school_id', '_school_id', 'coo_school_id', '_coo_school_id'];
        
        foreach ($fallback_keys as $fallback_key) {
            $dynamic_meta = $wpdb->get_row($wpdb->prepare("
                SELECT meta_key, meta_value 
                FROM {$wpdb->prefix}wc_orders_meta 
                WHERE meta_key = %s 
                AND meta_value REGEXP '^[0-9]+$'
                LIMIT 1
            ", $fallback_key));
            
            if ($dynamic_meta && $dynamic_meta->meta_value) {
                // Verificar que es un post de escuela válido
                $school_exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'coo_school'",
                    intval($dynamic_meta->meta_value)
                ));
                
                if ($school_exists) {
                    self::$school_meta_key = $dynamic_meta->meta_key;
                    return $dynamic_meta->meta_key;
                }
            }
        }
        
        // Cachear resultado negativo
        self::$school_meta_key = false;
        return null;
    }

    /**
     * Agregar página de informe de productos al menú
     */
    public function addProductReportPage(): void
    {
        add_submenu_page(
            'informes',
            __('Products Report', 'neve-child'),
            __('Products', 'neve-child'),
            'manage_woocommerce',
            'informe-productos',
            [$this, 'renderProductReportPage']
        );
    }

    /**
     * Cargar assets específicos para el informe de productos
     */
    public function enqueueAssets($hook): void
    {
        // Cargar assets si estamos en la página de informe de productos
        $is_product_report_page = (
            $hook === 'informes_page_informe-productos' || 
            $hook === 'reports_page_informe-productos' || 
            $hook === 'admin_page_informe-productos' ||
            (isset($_GET['page']) && $_GET['page'] === 'informe-productos')
        );
        
        if (!$is_product_report_page) {
            return;
        }

        // Select2 CSS y JS
        wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0');
        wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0', true);
        
        // CSS - Assets del informe de productos
        wp_enqueue_style(
            'reports-admin', 
            get_stylesheet_directory_uri() . '/custom/assets/css/reports-admin.css', 
            ['select2'], 
            '1.0.0'
        );
        
        wp_enqueue_style(
            'product-report-admin', 
            get_stylesheet_directory_uri() . '/custom/assets/css/product-report-admin.css', 
            ['reports-admin'], 
            '1.0.0'
        );
        
        // JS
        wp_enqueue_script(
            'product-report-admin', 
            get_stylesheet_directory_uri() . '/custom/assets/js/product-report-admin.js', 
            ['jquery', 'select2'], 
            '1.0.0', 
            true
        );
        
        // Localizar script para Ajax
        wp_localize_script('product-report-admin', 'productReportAjax', [
            'url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('product_report_nonce')
        ]);
    }

    /**
     * Renderizar página de informe de productos
     */
    public function renderProductReportPage(): void
    {
        ?>
        <div class="wrap product-report-wrap report-wrap">
            <div class="product-report-header report-header">
                <h1 class="wp-heading-inline"  style="margin:10px;">
                    <?php _e('Products Report', 'neve-child'); ?>
                </h1>
                <hr class="wp-header-end">
            </div>
            
            <div class="product-controls report-controls">
                <div class="product-filter-group filter-group-multi">
                    <div class="product-selector-group">
                        <label for="product-specific-filter" class="filter-label">
                            <?php _e('Filter products:', 'neve-child'); ?>
                        </label>
                        <select id="product-specific-filter" class="product-specific-filter-select filter-select" multiple="multiple">
                            <option value="all">📦 Todos los productos</option>
                            <?php 
                            // Obtener todos los productos disponibles
                            $products = get_posts([
                                'post_type' => 'product',
                                'post_status' => 'publish',
                                'numberposts' => -1,
                                'orderby' => 'title',
                                'order' => 'ASC'
                            ]);
                            
                            foreach ($products as $product) {
                                echo '<option value="' . esc_attr($product->ID) . '">' . esc_html($product->post_title) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div class="status-selector-group">
                        <label for="status-filter" class="filter-label">
                            <?php _e('Order statuses:', 'neve-child'); ?>
                        </label>
                        <select id="status-filter" class="status-filter-select filter-select" multiple="multiple">
                            <option value="all">Todos los estados</option>
                            <?php 
                            $all_statuses = $this->getAllOrderStatuses();
                            foreach ($all_statuses as $status_key => $status_label): 
                            ?>                            ?>
                                <option value="<?php echo esc_attr($status_key); ?>" <?php echo in_array($status_key, array_keys(self::ORDER_STATUSES)) ? 'selected' : ''; ?>>
                                    <?php echo esc_html($status_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="sort-selector-group">
                        <label for="product-filter" class="filter-label">
                            <?php _e('Sort by:', 'neve-child'); ?>
                        </label>
                        <select id="product-filter" class="product-filter-select filter-select">
                            <option value="most-ordered"><?php _e('🔥 Most ordered (quantity)', 'neve-child'); ?></option>
                            <option value="least-ordered"><?php _e('📉 Least ordered (quantity)', 'neve-child'); ?></option>
                            <option value="most-schools"><?php _e('🏫 Most schools', 'neve-child'); ?></option>
                            <option value="highest-amount"><?php _e('💰 Highest amount', 'neve-child'); ?></option>
                            <option value="lowest-amount"><?php _e('💸 Lowest amount', 'neve-child'); ?></option>
                        </select>
                    </div>
                </div>
                
                <div class="product-action-buttons action-buttons">
                    <button type="button" id="refresh-product-report" class="button button-primary">
                        <span class="dashicons dashicons-chart-bar" style="margin-top: 3px;"></span>
                        <?php _e('Generate Report', 'neve-child'); ?>
                    </button>
                    
                    <button type="button" id="clear-product-filter" class="button button-secondary">
                        <span class="dashicons dashicons-dismiss" style="margin-top: 3px;"></span>
                        <?php _e('Clear Filters', 'neve-child'); ?>
                    </button>
                </div>
            </div>

            <div id="product-report-loading" class="loading-spinner" style="display: none;">
                <span class="spinner is-active"></span>
                <span><?php _e('Generating products report...', 'neve-child'); ?></span>
            </div>

            <div id="product-report-container" class="product-report-container report-container">
                <div class="initial-message">
                    <div class="dashicons dashicons-chart-bar" style="font-size: 20px; margin-right: 8px; color: #d63638;"></div>
                    <span><?php _e('Click "Generate Report" to view product statistics.', 'neve-child'); ?></span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Obtener estadísticas completas de productos
     * Retorna productos filtrados según criterios especificados
     */
    public function getProductStats(string $filter = 'most-ordered', array $product_ids = [], array $statuses = []): array
    {
        global $wpdb;
        
        // Detectar el meta key correcto para school ID
        $school_meta_key = $this->detectSchoolMetaKey();
        
        if (!$school_meta_key) {
            return [];
        }

        // Determinar orden
        $order_by = 'total_quantity DESC';
        if ($filter === 'least-ordered') {
            $order_by = 'total_quantity ASC';
        } elseif ($filter === 'most-ordered') {
            $order_by = 'total_quantity DESC';
        } elseif ($filter === 'most-schools') {
            $order_by = 'school_count DESC, total_quantity DESC';
        } elseif ($filter === 'highest-amount') {
            $order_by = 'total_amount DESC';
        } elseif ($filter === 'lowest-amount') {
            $order_by = 'total_amount ASC';
        }

        // Preparar filtros adicionales
        $additional_conditions = '';
        $prepare_values = [$school_meta_key];

        // Filtro por productos específicos
        if (!empty($product_ids) && !in_array('all', $product_ids)) {
            $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
            $additional_conditions .= " AND oim.meta_value IN ($placeholders)";
            $prepare_values = array_merge($prepare_values, $product_ids);
        }

        // Filtro por estados de órdenes
        $status_condition = '';
        if (!empty($statuses) && !in_array('all', $statuses)) {
            $status_placeholders = implode("','", array_map('esc_sql', $statuses));
            $status_condition = "AND o.status IN ('$status_placeholders')";
        } else {
            $status_condition = "AND o.status IN ('" . implode("','", array_keys(self::ORDER_STATUSES)) . "')";
        }

        // Query para estadísticas de productos (con filtros)
        $query = "
            SELECT 
                oim.meta_value as product_id,
                pr.post_title as product_name,
                SUM(CAST(oiqty.meta_value AS UNSIGNED)) as total_quantity,
                COUNT(DISTINCT o.id) as order_count,
                COUNT(DISTINCT om.meta_value) as school_count,
                SUM(CAST(oitotal.meta_value AS DECIMAL(10,2)) + CAST(COALESCE(oitax.meta_value, 0) AS DECIMAL(10,2))) as total_amount
            FROM {$wpdb->prefix}wc_orders o
            INNER JOIN {$wpdb->prefix}wc_orders_meta om ON o.id = om.order_id
            INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON o.id = oi.order_id
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oiqty ON oi.order_item_id = oiqty.order_item_id
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oitotal ON oi.order_item_id = oitotal.order_item_id
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oitax ON oi.order_item_id = oitax.order_item_id AND oitax.meta_key = '_line_tax'
            INNER JOIN {$wpdb->posts} pr ON oim.meta_value = pr.ID
            WHERE om.meta_key = %s
            $status_condition
            AND oi.order_item_type = 'line_item'
            AND oim.meta_key = '_product_id'
            AND oiqty.meta_key = '_qty'
            AND oitotal.meta_key = '_line_total'
            AND pr.post_type = 'product'
            $additional_conditions
            GROUP BY oim.meta_value
            ORDER BY {$order_by}
        ";

        $results = $wpdb->get_results($wpdb->prepare($query, $school_meta_key));

        $products_data = [];
        $ranking = 1;
        
        foreach ($results as $row) {
            $total_quantity = intval($row->total_quantity);
            $school_count = intval($row->school_count);
            $order_count = intval($row->order_count);
            $total_amount = floatval($row->total_amount);
            
            // Calcular métricas adicionales
            $avg_per_school = $school_count > 0 ? round($total_quantity / $school_count, 1) : 0;
            $avg_per_order = $order_count > 0 ? round($total_quantity / $order_count, 1) : 0;
            $avg_amount_per_school = $school_count > 0 ? round($total_amount / $school_count, 2) : 0;
            
            // Determinar badge de performance
            $performance_badge = $this->getPerformanceBadge($total_quantity, $school_count, $order_count);
            
            $products_data[] = [
                'ranking' => $ranking,
                'product_id' => intval($row->product_id),
                'product_name' => $row->product_name,
                'total_quantity' => $total_quantity,
                'total_amount' => $total_amount,
                'order_count' => $order_count,
                'school_count' => $school_count,
                'avg_per_school' => $avg_per_school,
                'avg_per_order' => $avg_per_order,
                'avg_amount_per_school' => $avg_amount_per_school,
                'performance_badge' => $performance_badge
            ];
            
            $ranking++;
        }

        return $products_data;
    }

    /**
     * Determinar badge de performance basado en métricas
     */
    private function getPerformanceBadge(int $quantity, int $schools, int $orders): array
    {
        // Badge basado en cantidad total
        if ($quantity >= 1000) {
            return ['type' => 'top', 'label' => 'Top Seller', 'color' => '#d63638'];
        } elseif ($quantity >= 500) {
            return ['type' => 'high', 'label' => 'High Demand', 'color' => '#f56e28'];
        } elseif ($quantity >= 100) {
            return ['type' => 'medium', 'label' => 'Popular', 'color' => '#0073aa'];
        } elseif ($schools >= 5) {
            return ['type' => 'widespread', 'label' => 'Widespread', 'color' => '#00a32a'];
        } else {
            return ['type' => 'standard', 'label' => 'Standard', 'color' => '#646970'];
        }
    }

    /**
     * Obtener resumen estadístico general con filtros
     */
    public function getOverallStats(array $product_ids = [], array $statuses = []): array
    {
        global $wpdb;
        
        $school_meta_key = $this->detectSchoolMetaKey();
        
        if (!$school_meta_key) {
            return [];
        }

        // Preparar filtros adicionales
        $additional_conditions = '';
        $prepare_values = [$school_meta_key];

        // Filtro por productos específicos
        if (!empty($product_ids) && !in_array('all', $product_ids)) {
            $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
            $additional_conditions .= " AND oim.meta_value IN ($placeholders)";
            $prepare_values = array_merge($prepare_values, $product_ids);
        }

        // Filtro por estados de órdenes
        $status_condition = '';
        if (!empty($statuses) && !in_array('all', $statuses)) {
            $status_placeholders = implode("','", array_map('esc_sql', $statuses));
            $status_condition = "AND o.status IN ('$status_placeholders')";
        } else {
            $status_condition = "AND o.status IN ('" . implode("','", array_keys(self::ORDER_STATUSES)) . "')";
        }

        // Consulta para estadísticas generales
        $stats_query = "
            SELECT 
                COUNT(DISTINCT oim.meta_value) as total_products,
                SUM(CAST(oiqty.meta_value AS UNSIGNED)) as total_quantity,
                COUNT(DISTINCT o.id) as total_orders,
                COUNT(DISTINCT om.meta_value) as total_schools,
                SUM(CAST(oitotal.meta_value AS DECIMAL(10,2)) + CAST(COALESCE(oitax.meta_value, 0) AS DECIMAL(10,2))) as total_amount
            FROM {$wpdb->prefix}wc_orders o
            INNER JOIN {$wpdb->prefix}wc_orders_meta om ON o.id = om.order_id
            INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON o.id = oi.order_id
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oiqty ON oi.order_item_id = oiqty.order_item_id
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oitotal ON oi.order_item_id = oitotal.order_item_id
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oitax ON oi.order_item_id = oitax.order_item_id AND oitax.meta_key = '_line_tax'
            INNER JOIN {$wpdb->posts} pr ON oim.meta_value = pr.ID
            WHERE om.meta_key = %s
            $status_condition
            AND oi.order_item_type = 'line_item'
            AND oim.meta_key = '_product_id'
            AND oiqty.meta_key = '_qty'
            AND oitotal.meta_key = '_line_total'
            AND pr.post_type = 'product'
            $additional_conditions
        ";

        if (!empty($prepare_values) && count($prepare_values) > 1) {
            $stats = $wpdb->get_row($wpdb->prepare($stats_query, $prepare_values));
        } else {
            $stats = $wpdb->get_row($wpdb->prepare($stats_query, $school_meta_key));
        }

        if (!$stats) {
            return [];
        }

        return [
            'total_products' => intval($stats->total_products),
            'total_quantity' => intval($stats->total_quantity),
            'total_orders' => intval($stats->total_orders),
            'total_schools' => intval($stats->total_schools),
            'total_amount' => floatval($stats->total_amount),
            'avg_products_per_school' => $stats->total_schools > 0 ? round($stats->total_products / $stats->total_schools, 1) : 0,
            'avg_quantity_per_order' => $stats->total_orders > 0 ? round($stats->total_quantity / $stats->total_orders, 1) : 0,
            'avg_amount_per_order' => $stats->total_orders > 0 ? round($stats->total_amount / $stats->total_orders, 2) : 0
        ];
    }

    /**
     * Manejar Ajax para informe de productos con nuevos filtros
     */
    public function handleProductReportAjax() {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'product_report_nonce')) {
            wp_die('Acceso denegado');
        }
        
        $filter = $_POST['filter'] ?? 'most-ordered';
        $product_ids = $_POST['product_ids'] ?? [];
        $statuses = $_POST['statuses'] ?? [];
        
        // Limpiar y validar product_ids
        if (is_string($product_ids)) {
            $product_ids = explode(',', $product_ids);
        }
        $product_ids = array_filter(array_map('trim', $product_ids));
        
        // Limpiar y validar statuses
        if (is_string($statuses)) {
            $statuses = explode(',', $statuses);
        }
        $statuses = array_filter(array_map('trim', $statuses));
        
        $data = $this->getProductStats($filter, $product_ids, $statuses);
        $overall_stats = $this->getOverallStats($product_ids, $statuses);
        
        if (empty($data)) {
            wp_send_json_error([
                'message' => __('No product data found for the selected filters', 'neve-child')
            ]);
        } else {
            wp_send_json_success([
                'products' => $data,
                'overall_stats' => $overall_stats
            ]);
        }
    }
}
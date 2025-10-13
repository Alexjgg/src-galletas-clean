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
     * Estados de órdenes por defecto para el informe de productos
     * MISMA LÓGICA QUE SchoolReport.php para consistencia
     */
    const DEFAULT_ORDER_STATUSES = [
        // Estados de Master Orders
        'wc-master-order' => 'master-order',
        'wc-mast-warehs' => 'mast-warehs',
        'wc-mast-prepared' => 'mast-prepared',
        'wc-mast-complete' => 'mast-complete',
        'wc-processing' => 'processing',
        
        // Estados de órdenes individuales específicos
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
     * Obtener estados de órdenes para usar en consultas DB
     * MISMA LÓGICA que SchoolReport.php
     */
    private function getOrderStatusesForQuery(?array $selected_statuses = null): array
    {
        if ($selected_statuses !== null && !empty($selected_statuses)) {
            // Usar estados seleccionados
            return $selected_statuses;
        }
        
        // Usar estados por defecto (igual que SchoolReport.php)
        return array_keys(self::DEFAULT_ORDER_STATUSES);
    }

    /**
     * Obtener estados de órdenes específicos para filtros
     * MISMA LÓGICA QUE SchoolReport.php
     */
    public function getAllOrderStatuses(): array
    {
        // Estados específicos que queremos mostrar en el filtro (igual que SchoolReport)
        $filtered_statuses = [
            // Estados de Master Orders
            'wc-master-order' => __('Master Validated', 'neve-child'),
            'wc-mast-warehs' => __('Master Warehouse', 'neve-child'),
            'wc-mast-prepared' => __('Master Prepared', 'neve-child'),
            'wc-mast-complete' => __('Master Complete', 'neve-child'),
            'wc-processing' => __('Processing', 'neve-child'),
            
            // Estados de órdenes individuales específicos
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
     * MISMA IMPLEMENTACIÓN SIMPLIFICADA que SchoolReport.php
     */
    private function detectSchoolMetaKey(): ?string
    {
        // Si ya está en caché, devolver
        if (self::$school_meta_key !== null) {
            return self::$school_meta_key ?: null;
        }
        
        // Usar directamente _school_id que es el estándar (igual que SchoolReport.php)
        self::$school_meta_key = '_school_id';
        return '_school_id';
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
                                <option value="<?php echo esc_attr($status_key); ?>" <?php echo in_array($status_key, array_keys(self::DEFAULT_ORDER_STATUSES)) ? 'selected' : ''; ?>>
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
     * USANDO EXACTAMENTE LA MISMA LÓGICA QUE SchoolReport.php
     */
    public function getProductStats(string $filter = 'most-ordered', array $product_ids = [], array $statuses = [], $date_filter = null): array
    {
        global $wpdb;
        
        // Detectar el meta key correcto para school ID
        $school_meta_key = $this->detectSchoolMetaKey();
        
        if (!$school_meta_key) {
            return [];
        }

        // Condición de fecha (igual que SchoolReport)
        $date_condition = "";
        if (!empty($date_filter)) {
            if ($date_filter['type'] === 'specific') {
                $date_condition = $wpdb->prepare("AND DATE(o.date_created_gmt) = %s", $date_filter['date']);
            } elseif ($date_filter['type'] === 'range') {
                $date_condition = $wpdb->prepare("AND o.date_created_gmt BETWEEN %s AND %s", 
                    $date_filter['start'] . ' 00:00:00', 
                    $date_filter['end'] . ' 23:59:59'
                );
            }
        }

        // Construir filtros adicionales para productos específicos
        $where_conditions = [];
        $prepare_values = [$school_meta_key];
        
        if (!empty($product_ids) && !in_array('all', $product_ids) && is_array($product_ids)) {
            $product_ids_placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
            $where_conditions[] = "AND oim.meta_value IN ({$product_ids_placeholders})";
            $prepare_values = array_merge($prepare_values, $product_ids);
        }
        
        $additional_where = implode(' ', $where_conditions);

        // Obtener estados a usar en la consulta (EXACTO COMO SchoolReport)
        $query_statuses = $this->getOrderStatusesForQuery($statuses);
        
        // LÓGICA CORRECTA: wc-reviewed es el individual de wc-master-order (evitar duplicación)
        $master_order_statuses = ['wc-master-order', 'wc-mast-warehs', 'wc-mast-prepared', 'wc-mast-complete'];
        $neutral_statuses = ['wc-processing']; // Estados que incluyen TODO sin filtro master/individual
        // CRÍTICO: wc-reviewed es el INDIVIDUAL correspondiente a wc-master-order (MISMO pedido)
        
        $has_master_statuses = false;
        $has_individual_statuses = false;
        
        foreach ($query_statuses as $status) {
            if (in_array($status, $master_order_statuses)) {
                $has_master_statuses = true;
            } elseif (!in_array($status, $neutral_statuses)) {
                $has_individual_statuses = true;
            }
        }
        
        // LÓGICA ANTI-DUPLICACIÓN: wc-reviewed + wc-master-order son EL MISMO PEDIDO
        $master_statuses = array_intersect($query_statuses, $master_order_statuses);
        $individual_statuses = array_diff($query_statuses, array_merge($master_order_statuses, $neutral_statuses));
        $neutral_statuses_filtered = array_intersect($query_statuses, $neutral_statuses);
        
        // VERIFICAR: Detectar TODOS los pares master/individual que causan duplicación
        $status_pairs = [
            'wc-master-order' => 'wc-reviewed',
            'wc-mast-warehs' => 'wc-warehouse', 
            'wc-mast-prepared' => 'wc-prepared',
            'wc-mast-complete' => 'wc-completed'
        ];
        
        $detected_pairs = [];
        foreach ($status_pairs as $master => $individual) {
            if (in_array($master, $query_statuses) && in_array($individual, $query_statuses)) {
                $detected_pairs[$master] = $individual;
            }
        }
        
        $status_parts = [];
        
        // PARTE 1: Estados neutrales siempre se incluyen
        if (!empty($neutral_statuses_filtered)) {
            $status_parts[] = "o.status IN ('" . implode("','", $neutral_statuses_filtered) . "')";
        }
        
        // PARTE 2: Lógica anti-duplicación para TODOS los pares master/individual
        if (!empty($detected_pairs)) {
            // HAY DUPLICACIÓN: Aplicar prioridad MASTER para todos los pares detectados
            $conflicted_individuals = array_values($detected_pairs); // ['wc-reviewed', 'wc-warehouse', etc.]
            
            // Agregar estados master (que tienen prioridad sobre sus individuales)
            $masters_with_pairs = array_keys($detected_pairs);
            $masters_in_query = array_intersect($master_statuses, $masters_with_pairs);
            if (!empty($masters_in_query)) {
                $status_parts[] = "(o.status IN ('" . implode("','", $masters_in_query) . "') 
                                   AND EXISTS (
                                       SELECT 1 FROM {$wpdb->prefix}wc_orders_meta imo 
                                       WHERE imo.order_id = o.id 
                                       AND imo.meta_key = '_is_master_order' 
                                       AND imo.meta_value = 'yes'
                                   ))";
            }
            
            // Agregar otros estados master (no en conflicto)
            $other_masters = array_diff($master_statuses, $masters_with_pairs);
            if (!empty($other_masters)) {
                $status_parts[] = "(o.status IN ('" . implode("','", $other_masters) . "') 
                                   AND EXISTS (
                                       SELECT 1 FROM {$wpdb->prefix}wc_orders_meta imo 
                                       WHERE imo.order_id = o.id 
                                       AND imo.meta_key = '_is_master_order' 
                                       AND imo.meta_value = 'yes'
                                   ))";
            }
            
            // Agregar estados individuales (excluyendo los que tienen conflicto con masters)
            $safe_individuals = array_diff($individual_statuses, $conflicted_individuals);
            if (!empty($safe_individuals)) {
                $status_parts[] = "(o.status IN ('" . implode("','", $safe_individuals) . "')
                                   AND NOT EXISTS (
                                       SELECT 1 FROM {$wpdb->prefix}wc_orders_meta imo 
                                       WHERE imo.order_id = o.id 
                                       AND imo.meta_key = '_is_master_order' 
                                       AND imo.meta_value = 'yes'
                                   ))";
            }
        } else {
            // NO hay duplicación: usar lógica normal
            
            // Estados master
            if (!empty($master_statuses)) {
                $status_parts[] = "(o.status IN ('" . implode("','", $master_statuses) . "') 
                                   AND EXISTS (
                                       SELECT 1 FROM {$wpdb->prefix}wc_orders_meta imo 
                                       WHERE imo.order_id = o.id 
                                       AND imo.meta_key = '_is_master_order' 
                                       AND imo.meta_value = 'yes'
                                   ))";
            }
            
            // Estados individuales
            if (!empty($individual_statuses)) {
                $status_parts[] = "(o.status IN ('" . implode("','", $individual_statuses) . "')
                                   AND NOT EXISTS (
                                       SELECT 1 FROM {$wpdb->prefix}wc_orders_meta imo 
                                       WHERE imo.order_id = o.id 
                                       AND imo.meta_key = '_is_master_order' 
                                       AND imo.meta_value = 'yes'
                                   ))";
            }
        }
        
        // COMBINAR todas las condiciones
        if (!empty($status_parts)) {
            $status_condition = "AND (" . implode(" OR ", $status_parts) . ")";
        } else {
            $status_condition = "";
        }

        // CONSULTA EXACTAMENTE IGUAL que SchoolReport pero AGRUPADA POR PRODUCTOS
        $products_query = "
            SELECT 
                oim.meta_value as product_id,
                pr.post_title as product_name,
                SUM(CAST(oiqty.meta_value AS UNSIGNED)) as total_quantity,
                COUNT(DISTINCT o.id) as order_count,
                COUNT(DISTINCT om.meta_value) as school_count,
                SUM(CAST(oitotal.meta_value AS DECIMAL(10,2)) + CAST(COALESCE(oitax.meta_value, 0) AS DECIMAL(10,2))) as total_amount,
                COUNT(DISTINCT oi.order_item_id) as unique_items_count
            FROM {$wpdb->prefix}wc_orders o
            INNER JOIN {$wpdb->prefix}wc_orders_meta om ON o.id = om.order_id
            INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON o.id = oi.order_id
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oiqty ON oi.order_item_id = oiqty.order_item_id
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oitotal ON oi.order_item_id = oitotal.order_item_id
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oitax ON oi.order_item_id = oitax.order_item_id AND oitax.meta_key = '_line_tax'
            INNER JOIN {$wpdb->prefix}posts pr ON oim.meta_value = pr.ID
            WHERE om.meta_key = %s
            AND o.status NOT IN ('wc-trash', 'trash', 'wc-auto-draft', 'wc-draft', 'wc-cancelled', 'wc-refunded', 'wc-failed')
            {$status_condition}
            {$date_condition}
            {$additional_where}
            AND oi.order_item_type = 'line_item'
            AND oim.meta_key = '_product_id'
            AND oiqty.meta_key = '_qty'
            AND oitotal.meta_key = '_line_total'
            AND pr.post_type = 'product'
            GROUP BY oim.meta_value, pr.post_title
        ";
        
        // Determinar orden
        $order_clause = 'ORDER BY total_quantity DESC';
        if ($filter === 'least-ordered') {
            $order_clause = 'ORDER BY total_quantity ASC';
        } elseif ($filter === 'most-ordered') {
            $order_clause = 'ORDER BY total_quantity DESC';
        } elseif ($filter === 'most-schools') {
            $order_clause = 'ORDER BY school_count DESC, total_quantity DESC';
        } elseif ($filter === 'highest-amount') {
            $order_clause = 'ORDER BY total_amount DESC';
        } elseif ($filter === 'lowest-amount') {
            $order_clause = 'ORDER BY total_amount ASC';
        }
        
        $products_query .= ' ' . $order_clause;

        $results = $wpdb->get_results($wpdb->prepare($products_query, ...$prepare_values));

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
     * CON LÓGICA ANTI-DUPLICACIÓN de pedidos maestros/hijos
     */
    public function getOverallStats(array $product_ids = [], array $statuses = [], $date_filter = null): array
    {
        global $wpdb;
        
        $school_meta_key = $this->detectSchoolMetaKey();
        
        if (!$school_meta_key) {
            return [
                'total_products' => 0,
                'total_quantity' => 0,
                'total_orders' => 0,
                'total_schools' => 0,
                'total_amount' => 0,
                'avg_products_per_school' => 0,
                'avg_quantity_per_order' => 0,
                'avg_amount_per_order' => 0
            ];
        }

        // Condición de fecha igual que SchoolReport
        $date_condition = "";
        if (!empty($date_filter)) {
            if ($date_filter['type'] === 'specific') {
                $date_condition = $wpdb->prepare("AND DATE(o.date_created_gmt) = %s", $date_filter['date']);
            } elseif ($date_filter['type'] === 'range') {
                $date_condition = $wpdb->prepare("AND o.date_created_gmt BETWEEN %s AND %s", 
                    $date_filter['start'] . ' 00:00:00', 
                    $date_filter['end'] . ' 23:59:59'
                );
            }
        }

        // Preparar filtros adicionales para productos específicos
        $additional_conditions = '';
        $prepare_values = [$school_meta_key];

        if (!empty($product_ids) && !in_array('all', $product_ids)) {
            $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
            $additional_conditions .= " AND oim.meta_value IN ($placeholders)";
            $prepare_values = array_merge($prepare_values, $product_ids);
        }

        // MISMA LÓGICA DE ESTADOS que SchoolReport (EXACTA)
        $query_statuses = $this->getOrderStatusesForQuery($statuses);
        
        $master_order_statuses = ['wc-master-order', 'wc-mast-warehs', 'wc-mast-prepared', 'wc-mast-complete'];
        $neutral_statuses = ['wc-processing'];
        
        $master_statuses = array_intersect($query_statuses, $master_order_statuses);
        $individual_statuses = array_diff($query_statuses, array_merge($master_order_statuses, $neutral_statuses));
        $neutral_statuses_filtered = array_intersect($query_statuses, $neutral_statuses);
        
        $status_pairs = [
            'wc-master-order' => 'wc-reviewed',
            'wc-mast-warehs' => 'wc-warehouse', 
            'wc-mast-prepared' => 'wc-prepared',
            'wc-mast-complete' => 'wc-completed'
        ];
        
        $detected_pairs = [];
        foreach ($status_pairs as $master => $individual) {
            if (in_array($master, $query_statuses) && in_array($individual, $query_statuses)) {
                $detected_pairs[$master] = $individual;
            }
        }
        
        $status_parts = [];
        
        if (!empty($neutral_statuses_filtered)) {
            $status_parts[] = "o.status IN ('" . implode("','", $neutral_statuses_filtered) . "')";
        }
        
        if (!empty($detected_pairs)) {
            $conflicted_individuals = array_values($detected_pairs);
            $masters_with_pairs = array_keys($detected_pairs);
            $masters_in_query = array_intersect($master_statuses, $masters_with_pairs);
            
            if (!empty($masters_in_query)) {
                $status_parts[] = "(o.status IN ('" . implode("','", $masters_in_query) . "') 
                                   AND EXISTS (
                                       SELECT 1 FROM {$wpdb->prefix}wc_orders_meta imo 
                                       WHERE imo.order_id = o.id 
                                       AND imo.meta_key = '_is_master_order' 
                                       AND imo.meta_value = 'yes'
                                   ))";
            }
            
            $other_masters = array_diff($master_statuses, $masters_with_pairs);
            if (!empty($other_masters)) {
                $status_parts[] = "(o.status IN ('" . implode("','", $other_masters) . "') 
                                   AND EXISTS (
                                       SELECT 1 FROM {$wpdb->prefix}wc_orders_meta imo 
                                       WHERE imo.order_id = o.id 
                                       AND imo.meta_key = '_is_master_order' 
                                       AND imo.meta_value = 'yes'
                                   ))";
            }
            
            $safe_individuals = array_diff($individual_statuses, $conflicted_individuals);
            if (!empty($safe_individuals)) {
                $status_parts[] = "(o.status IN ('" . implode("','", $safe_individuals) . "')
                                   AND NOT EXISTS (
                                       SELECT 1 FROM {$wpdb->prefix}wc_orders_meta imo 
                                       WHERE imo.order_id = o.id 
                                       AND imo.meta_key = '_is_master_order' 
                                       AND imo.meta_value = 'yes'
                                   ))";
            }
        } else {
            if (!empty($master_statuses)) {
                $status_parts[] = "(o.status IN ('" . implode("','", $master_statuses) . "') 
                                   AND EXISTS (
                                       SELECT 1 FROM {$wpdb->prefix}wc_orders_meta imo 
                                       WHERE imo.order_id = o.id 
                                       AND imo.meta_key = '_is_master_order' 
                                       AND imo.meta_value = 'yes'
                                   ))";
            }
            
            if (!empty($individual_statuses)) {
                $status_parts[] = "(o.status IN ('" . implode("','", $individual_statuses) . "')
                                   AND NOT EXISTS (
                                       SELECT 1 FROM {$wpdb->prefix}wc_orders_meta imo 
                                       WHERE imo.order_id = o.id 
                                       AND imo.meta_key = '_is_master_order' 
                                       AND imo.meta_value = 'yes'
                                   ))";
            }
        }
        
        if (!empty($status_parts)) {
            $status_condition = "AND (" . implode(" OR ", $status_parts) . ")";
        } else {
            $status_condition = "";
        }

        // Consulta CORREGIDA para estadísticas generales - AGRUPAR PRIMERO POR ORDEN+PRODUCTO
        $stats_query = "
            SELECT 
                COUNT(DISTINCT product_id) as total_products,
                SUM(qty_per_order) as total_quantity,
                COUNT(DISTINCT order_id) as total_orders,
                COUNT(DISTINCT school_id) as total_schools,
                SUM(total_per_order) as total_amount
            FROM (
                SELECT 
                    oim_pid.meta_value as product_id,
                    o.id as order_id,
                    om.meta_value as school_id,
                    SUM(CAST(oim_qty.meta_value AS UNSIGNED)) as qty_per_order,
                    SUM(CAST(oim_total.meta_value AS DECIMAL(10,2)) + CAST(COALESCE(oim_tax.meta_value, 0) AS DECIMAL(10,2))) as total_per_order
                FROM {$wpdb->prefix}wc_orders o
                INNER JOIN {$wpdb->prefix}wc_orders_meta om ON o.id = om.order_id AND om.meta_key = %s
                INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON o.id = oi.order_id AND oi.order_item_type = 'line_item'
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_pid ON oi.order_item_id = oim_pid.order_item_id AND oim_pid.meta_key = '_product_id'
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty ON oi.order_item_id = oim_qty.order_item_id AND oim_qty.meta_key = '_qty'
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_total ON oi.order_item_id = oim_total.order_item_id AND oim_total.meta_key = '_line_total'
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_tax ON oi.order_item_id = oim_tax.order_item_id AND oim_tax.meta_key = '_line_tax'
                INNER JOIN {$wpdb->prefix}posts pr ON oim_pid.meta_value = pr.ID AND pr.post_type = 'product' AND pr.post_status = 'publish'
                WHERE o.type = 'shop_order'
                AND o.status NOT IN ('wc-trash', 'trash', 'wc-auto-draft', 'wc-draft', 'wc-cancelled', 'wc-refunded', 'wc-failed')
                {$status_condition}
                {$date_condition}
                {$additional_conditions}
                GROUP BY oim_pid.meta_value, o.id, om.meta_value
            ) as stats_data
        ";

        $stats = $wpdb->get_row($wpdb->prepare($stats_query, ...$prepare_values));

        if (!$stats) {
            return [
                'total_products' => 0,
                'total_quantity' => 0,
                'total_orders' => 0,
                'total_schools' => 0,
                'total_amount' => 0,
                'avg_products_per_school' => 0,
                'avg_quantity_per_order' => 0,
                'avg_amount_per_order' => 0
            ];
        }

        $total_products = intval($stats->total_products);
        $total_quantity = intval($stats->total_quantity);
        $total_orders = intval($stats->total_orders);
        $total_schools = intval($stats->total_schools);
        $total_amount = floatval($stats->total_amount);

        return [
            'total_products' => $total_products,
            'total_quantity' => $total_quantity,
            'total_orders' => $total_orders,
            'total_schools' => $total_schools,
            'total_amount' => $total_amount,
            'avg_products_per_school' => $total_schools > 0 ? round($total_products / $total_schools, 1) : 0,
            'avg_quantity_per_order' => $total_orders > 0 ? round($total_quantity / $total_orders, 1) : 0,
            'avg_amount_per_order' => $total_orders > 0 ? round($total_amount / $total_orders, 2) : 0
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
        
        try {
            $filter = $_POST['filter'] ?? 'most-ordered';
            $product_ids = $_POST['product_ids'] ?? [];
            $statuses = $_POST['statuses'] ?? [];
            $date_filter = $_POST['date_filter'] ?? null;
        
        // Limpiar y validar product_ids
        if (is_string($product_ids)) {
            $product_ids = explode(',', $product_ids);
        }
        $product_ids = array_filter(array_map('trim', $product_ids));
        
        // Si contiene "all", convertir a array vacío (significa todos)
        if (in_array('all', $product_ids)) {
            $product_ids = [];
        }
        
        // Limpiar y validar statuses
        if (is_string($statuses)) {
            $statuses = explode(',', $statuses);
        }
        $statuses = array_filter(array_map('trim', $statuses));
        
        // Si contiene "all", convertir a array vacío (significa todos)
        if (in_array('all', $statuses)) {
            $statuses = [];
        }
        
            $data = $this->getProductStats($filter, $product_ids, $statuses, $date_filter);
            $overall_stats = $this->getOverallStats($product_ids, $statuses, $date_filter);
            
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
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => 'Error interno del servidor'
            ]);
        }
    }
}
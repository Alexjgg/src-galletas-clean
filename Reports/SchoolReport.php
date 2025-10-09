<?php
/**
 * SchoolReport
 * 
 * Gestiona específicamente el informe de escuelas y sus productos
 * 
 * @package SchoolManagement\Reports
 *          
 */

namespace SchoolManagement\Reports;

if (!defined('ABSPATH')) {
    exit;
}

class SchoolReport 
{
    /**
     * Estados de órdenes por defecto para el informe de escuelas
     * Actualizado para incluir solo estados relevantes
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
     * Obtener estados de órdenes para usar en consultas DB
     */
    private function getOrderStatusesForQuery(?array $selected_statuses = null): array
    {
        if ($selected_statuses !== null && !empty($selected_statuses)) {
            // Usar estados seleccionados
            return $selected_statuses;
        }
        
        // Usar estados por defecto
        return array_keys(self::DEFAULT_ORDER_STATUSES);
    }

    /**
     * Configurar hooks específicos para el informe de escuelas
     */
    private function setupHooks() {
        add_action('admin_menu', [$this, 'addSchoolReportPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        
        // Ajax handlers específicos para escuelas
        add_action('wp_ajax_get_school_report_data', [$this, 'handleSchoolReportAjax']);
        add_action('wp_ajax_get_schools_by_zones', [$this, 'handleSchoolsByZonesAjax']);
        add_action('wp_ajax_get_all_schools', [$this, 'handleAllSchoolsAjax']);
        add_action('wp_ajax_get_school_orders_url', [$this, 'handleSchoolOrdersUrlAjax']);
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
     * Agregar página de informe de escuelas al menú
     */
    public function addSchoolReportPage(): void
    {
        add_submenu_page(
            'informes',
            __('Schools Report', 'neve-child'),
            __('Schools', 'neve-child'),
            'manage_woocommerce',
            'informe-escuelas',
            [$this, 'renderSchoolReportPage']
        );
    }

    /**
     * Cargar assets específicos para el informe de escuelas
     */
    public function enqueueAssets($hook): void
    {
        // Cargar assets si estamos en la página de informe de escuelas
        $is_school_report_page = (
            $hook === 'informes_page_informe-escuelas' || 
            $hook === 'reports_page_informe-escuelas' || 
            $hook === 'admin_page_informe-escuelas' ||
            (isset($_GET['page']) && $_GET['page'] === 'informe-escuelas')
        );
        
        if (!$is_school_report_page) {
            return;
        }

        // Select2 CSS y JS
        wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0');
        wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0', true);
        
        // CSS - Assets del informe de escuelas
        wp_enqueue_style(
            'reports-admin', 
            get_stylesheet_directory_uri() . '/custom/assets/css/reports-admin.css', 
            ['select2'], 
            '1.0.0'
        );
        
        wp_enqueue_style(
            'school-report-admin', 
            get_stylesheet_directory_uri() . '/custom/assets/css/school-report-admin-clean.css', 
            ['reports-admin'], 
            '2.1.0'
        );
        
        // JS
        wp_enqueue_script(
            'school-report-admin', 
            get_stylesheet_directory_uri() . '/custom/assets/js/school-report-admin.js', 
            ['jquery', 'select2'], 
            '1.0.3', 
            true
        );
        
        // Localizar script para Ajax
        wp_localize_script('school-report-admin', 'schoolReportAjax', [
            'url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('school_report_nonce'),
            'adminUrl' => admin_url()
        ]);
    }

    /**
     * Renderizar página de informe de escuelas
     */
    public function renderSchoolReportPage(): void
    {
        // Obtener lista de escuelas para el filtro
        $schools = get_posts([
            'post_type' => 'coo_school',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ]);
        
        // Obtener zonas disponibles
        $zones = get_terms([
            'taxonomy' => 'coo_zone',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ]);
        
        ?>
        <div class="wrap school-report-wrap report-wrap">
            <div class="school-report-header report-header">
                <h1 class="wp-heading-inline" style="margin:10px;">
                    <?php _e('Schools Report', 'neve-child'); ?>
                </h1>
                <hr class="wp-header-end">
            </div>
            
            <div class="school-controls report-controls">
                <div class="school-filter-group filter-group">
                    <div class="zone-selector-group">
                        <label for="zone-filter" class="zone-filter-label filter-label">
                            <?php _e('Filter by zones:', 'neve-child'); ?>
                        </label>
                        <select id="zone-filter" class="zone-filter-select filter-select" multiple>
                            <option value="all"><?php _e('🌍 All zones', 'neve-child'); ?></option>
                            <?php foreach ($zones as $zone): ?>
                                <option value="<?php echo esc_attr($zone->term_id); ?>">
                                    <?php echo esc_html($zone->name); ?> (<?php echo $zone->count; ?> escuelas)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="school-selector-group">
                        <label for="school-filter" class="school-filter-label filter-label">
                            <?php _e('Select schools:', 'neve-child'); ?>
                        </label>
                        <select id="school-filter" class="school-filter-select filter-select" multiple>
                            <option value="all"><?php _e('🏫 All schools', 'neve-child'); ?></option>
                            <?php foreach ($schools as $school): ?>
                                <option value="<?php echo esc_attr($school->ID); ?>">
                                    <?php echo esc_html($school->post_title); ?> (ID: <?php echo $school->ID; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="status-selector-group">
                        <label for="status-filter" class="status-filter-label filter-label">
                            <?php _e('Order statuses:', 'neve-child'); ?>
                        </label>
                        <select id="status-filter" class="status-filter-select filter-select" multiple>
                            <option value="all"><?php _e('All statuses', 'neve-child'); ?></option>
                            <?php 
                            $all_statuses = $this->getAllOrderStatuses();
                            foreach ($all_statuses as $status_key => $status_label): 
                            ?>
                                <option value="<?php echo esc_attr($status_key); ?>" <?php echo in_array($status_key, array_keys(self::DEFAULT_ORDER_STATUSES)) ? 'selected' : ''; ?>>
                                    <?php echo esc_html($status_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="school-action-buttons action-buttons">
                    <button type="button" id="refresh-school-report" class="button button-primary">
                        <span class="dashicons dashicons-groups" style="margin-top: 3px;"></span>
                        <?php _e('Generate Report', 'neve-child'); ?>
                    </button>
                    
                    <button type="button" id="clear-school-filter" class="button button-secondary" style="display: none;">
                        <span class="dashicons dashicons-dismiss" style="margin-top: 3px;"></span>
                        <?php _e('Clear Filters', 'neve-child'); ?>
                    </button>
                </div>
            </div>

            <div id="school-report-loading" class="loading-spinner" style="display: none;">
                <span class="spinner is-active"></span>
                <span><?php _e('Generating schools report...', 'neve-child'); ?></span>
            </div>

            <div id="school-report-container" class="school-report-container report-container">
                <div class="initial-message">
                    <div class="dashicons dashicons-groups" style="font-size: 20px; margin-right: 8px; color: #0073aa;"></div>
                    <span><?php _e('Click "Generate Report" to view school statistics.', 'neve-child'); ?></span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Obtener estadísticas de productos por escuela
     */
    public function getSchoolProductStats(?array $school_ids = null, ?array $zone_ids = null, ?array $order_statuses = null): array
    {
        global $wpdb;
        
        // Detectar el meta key correcto para school ID
        $school_meta_key = $this->detectSchoolMetaKey();
        
        if (!$school_meta_key) {
            return [];
        }

        // Construir filtros
        $where_conditions = [];
        $prepare_values = [$school_meta_key];
        
        if ($school_ids !== null && !empty($school_ids) && is_array($school_ids)) {
            $school_ids_placeholders = implode(',', array_fill(0, count($school_ids), '%d'));
            $where_conditions[] = "AND om.meta_value IN ({$school_ids_placeholders})";
            $prepare_values = array_merge($prepare_values, $school_ids);
        }
        
        // Si hay filtro por zonas, obtener IDs de escuelas en esas zonas
        if ($zone_ids !== null && !empty($zone_ids)) {
            $schools_in_zones = get_posts([
                'post_type' => 'coo_school',
                'numberposts' => -1,
                'fields' => 'ids',
                'meta_query' => [],
                'tax_query' => [
                    [
                        'taxonomy' => 'coo_zone',
                        'field' => 'term_id',
                        'terms' => $zone_ids,
                        'operator' => 'IN'
                    ]
                ]
            ]);
            
            if (empty($schools_in_zones)) {
                return []; // No hay escuelas en estas zonas
            }
            
            $school_ids_placeholders = implode(',', array_fill(0, count($schools_in_zones), '%d'));
            $where_conditions[] = "AND om.meta_value IN ({$school_ids_placeholders})";
            $prepare_values = array_merge($prepare_values, $schools_in_zones);
        }
        
        $additional_where = implode(' ', $where_conditions);

        // Obtener estados a usar en la consulta
        $query_statuses = $this->getOrderStatusesForQuery($order_statuses);
        
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

        // Primera consulta: obtener escuelas con órdenes ORDENADAS POR ZONA Y ALFABÉTICAMENTE
        // CRÍTICO: Usar la misma lógica anti-duplicación que en products_query
        $schools_query = "
            SELECT 
                om.meta_value as school_id,
                p.post_title as school_name,
                COUNT(DISTINCT o.id) as order_count,
                COALESCE(t.name, 'Sin zona asignada') as zone_name
            FROM {$wpdb->prefix}wc_orders o
            INNER JOIN {$wpdb->prefix}wc_orders_meta om ON o.id = om.order_id
            LEFT JOIN {$wpdb->posts} p ON om.meta_value = p.ID
            LEFT JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
            LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id 
                AND tt.taxonomy = 'coo_zone'
            LEFT JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
            WHERE om.meta_key = %s
            {$status_condition}
            {$additional_where}
            GROUP BY om.meta_value
            ORDER BY 
                CASE WHEN t.name IS NULL THEN 1 ELSE 0 END,
                t.name ASC,
                p.post_title ASC
        ";

        $schools_results = $wpdb->get_results($wpdb->prepare($schools_query, ...$prepare_values));
        
        if (empty($schools_results)) {
            return [];
        }

        $schools_data = [];
        
        // Para cada escuela, obtener sus productos
        foreach ($schools_results as $school_row) {
            $school_id_current = intval($school_row->school_id);
            $school_name = $school_row->school_name ?: "Escuela #{$school_id_current}";
            
            // Consulta para obtener productos de esta escuela específica
            // CRÍTICO: Usar COUNT(DISTINCT oi.order_item_id) para evitar duplicar items cuando hay múltiples metas
            $products_query = "
                SELECT 
                    oim.meta_value as product_id,
                    pr.post_title as product_name,
                    SUM(CAST(oiqty.meta_value AS UNSIGNED)) as total_quantity,
                    COUNT(DISTINCT o.id) as order_count,
                    SUM(CAST(oitotal.meta_value AS DECIMAL(10,2)) + CAST(COALESCE(oitax.meta_value, 0) AS DECIMAL(10,2))) as total_amount,
                    COUNT(DISTINCT oi.order_item_id) as unique_items_count
                FROM {$wpdb->prefix}wc_orders o
                INNER JOIN {$wpdb->prefix}wc_orders_meta om ON o.id = om.order_id
                INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON o.id = oi.order_id
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oiqty ON oi.order_item_id = oiqty.order_item_id
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oitotal ON oi.order_item_id = oitotal.order_item_id
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oitax ON oi.order_item_id = oitax.order_item_id AND oitax.meta_key = '_line_tax'
                INNER JOIN {$wpdb->posts} pr ON oim.meta_value = pr.ID
                WHERE om.meta_key = %s
                AND om.meta_value = %d
                {$status_condition}
                AND oi.order_item_type = 'line_item'
                AND oim.meta_key = '_product_id'
                AND oiqty.meta_key = '_qty'
                AND oitotal.meta_key = '_line_total'
                AND pr.post_type = 'product'
                GROUP BY oim.meta_value, pr.post_title
                ORDER BY total_quantity DESC
            ";
            
            $products_results = $wpdb->get_results($wpdb->prepare($products_query, $school_meta_key, $school_id_current));
            
            // Obtener zonas de la escuela desde la taxonomía coo_zone
            $school_zones = wp_get_post_terms($school_id_current, 'coo_zone', array('fields' => 'all'));
            $zones_data = [];
            if (!is_wp_error($school_zones) && !empty($school_zones)) {
                foreach ($school_zones as $zone) {
                    $zones_data[] = [
                        'id' => $zone->term_id,
                        'name' => $zone->name,
                        'slug' => $zone->slug
                    ];
                }
            }
            
            $schools_data[$school_id_current] = [
                'school_id' => $school_id_current,
                'school_name' => $school_name,
                'zones' => $zones_data,
                'total_products' => count($products_results),
                'total_quantity' => 0,
                'total_amount' => 0,
                'order_count' => intval($school_row->order_count),
                'products' => []
            ];
            
            foreach ($products_results as $product_row) {
                $quantity = intval($product_row->total_quantity);
                $total_amount = floatval($product_row->total_amount);
                
                $schools_data[$school_id_current]['products'][] = [
                    'product_id' => intval($product_row->product_id),
                    'product_name' => $product_row->product_name,
                    'quantity' => $quantity,
                    'order_count' => intval($product_row->order_count),
                    'total_amount' => $total_amount
                ];
                
                $schools_data[$school_id_current]['total_quantity'] += $quantity;
                $schools_data[$school_id_current]['total_amount'] += $total_amount;
            }
        }

        return $schools_data;
    }

    /**
     * Agrupar escuelas por zonas manteniendo la ordenación
     */
    private function groupSchoolsByZones(array $schools_data): array
    {
        $zones_data = [];
        
        // Como las escuelas ya vienen ordenadas por zona y alfabéticamente del SQL,
        // solo necesitamos agruparlas manteniendo ese orden
        foreach ($schools_data as $school_id => $school) {
            $zones = $school['zones'] ?? [];
            
            if (empty($zones)) {
                // Escuelas sin zona asignada
                $zone_slug = 'sin-zona';
                $zone_name = 'Sin zona asignada';
                
                if (!isset($zones_data[$zone_slug])) {
                    $zones_data[$zone_slug] = [
                        'zone_info' => [
                            'id' => 0,
                            'name' => $zone_name,
                            'slug' => $zone_slug
                        ],
                        'schools' => []
                    ];
                }
                
                $zones_data[$zone_slug]['schools'][$school_id] = $school;
            } else {
                // Escuelas con zona(s) asignada(s)
                // Si una escuela tiene múltiples zonas, la ponemos en la primera
                $zone = $zones[0]; // Usar la primera zona
                $zone_slug = $zone['slug'] ?? sanitize_title($zone['name']);
                
                if (!isset($zones_data[$zone_slug])) {
                    $zones_data[$zone_slug] = [
                        'zone_info' => [
                            'id' => $zone['id'],
                            'name' => $zone['name'],
                            'slug' => $zone_slug
                        ],
                        'schools' => []
                    ];
                }
                
                $zones_data[$zone_slug]['schools'][$school_id] = $school;
            }
        }
        
        // Las zonas ya deberían estar ordenadas porque las escuelas vienen ordenadas por zona del SQL
        // Pero por seguridad, vamos a ordenar las zonas alfabéticamente
        uksort($zones_data, function($a, $b) use ($zones_data) {
            // "Sin zona asignada" siempre va al final
            if ($a === 'sin-zona') return 1;
            if ($b === 'sin-zona') return -1;
            
            // El resto alfabéticamente por nombre de zona
            $zone_a = $zones_data[$a]['zone_info']['name'];
            $zone_b = $zones_data[$b]['zone_info']['name'];
            
            return strcasecmp($zone_a, $zone_b);
        });
        
        return $zones_data;
    }

    /**
     * Obtener resumen estadístico general del informe de escuelas
     */
    public function getOverallStats($school_ids = null, $zone_ids = null, $order_statuses = null): array
    {
        global $wpdb;
        
        $school_meta_key = $this->detectSchoolMetaKey();
        
        if (!$school_meta_key) {
            return [];
        }

        // Si no se especifican estados, usar los por defecto
        if (empty($order_statuses)) {
            $order_statuses = array_keys(self::DEFAULT_ORDER_STATUSES);
        }

        // LÓGICA CORRECTA: wc-reviewed es el individual de wc-master-order (evitar duplicación)
        $master_order_statuses = ['wc-master-order', 'wc-mast-warehs', 'wc-mast-prepared', 'wc-mast-complete'];
        $neutral_statuses = ['wc-processing']; // Estados que incluyen TODO sin filtro master/individual
        // CRÍTICO: wc-reviewed es el INDIVIDUAL correspondiente a wc-master-order (MISMO pedido)
        
        $has_master_statuses = false;
        $has_individual_statuses = false;
        
        foreach ($order_statuses as $status) {
            if (in_array($status, $master_order_statuses)) {
                $has_master_statuses = true;
            } elseif (!in_array($status, $neutral_statuses)) {
                $has_individual_statuses = true;
            }
        }
        
        // LÓGICA ANTI-DUPLICACIÓN: wc-reviewed + wc-master-order son EL MISMO PEDIDO (igual que getSchoolProductStats)
        $neutral_statuses_filtered = array_intersect($order_statuses, $neutral_statuses);
        $master_statuses = array_intersect($order_statuses, $master_order_statuses);
        $individual_statuses = array_diff($order_statuses, array_merge($master_order_statuses, $neutral_statuses));
        
        // VERIFICAR: Detectar TODOS los pares master/individual que causan duplicación
        $status_pairs = [
            'wc-master-order' => 'wc-reviewed',
            'wc-mast-warehs' => 'wc-warehouse', 
            'wc-mast-prepared' => 'wc-prepared',
            'wc-mast-complete' => 'wc-completed'
        ];
        
        $detected_pairs = [];
        foreach ($status_pairs as $master => $individual) {
            if (in_array($master, $order_statuses) && in_array($individual, $order_statuses)) {
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
        
        // COMBINAR todas las condiciones
        if (!empty($status_parts)) {
            $status_condition = "AND (" . implode(" OR ", $status_parts) . ")";
        } else {
            $status_condition = "";
        }

        // Construir condiciones WHERE adicionales
        $where_conditions = [];
        $where_params = [];

        // Filtro por escuelas específicas
        if (!empty($school_ids)) {
            $school_placeholders = str_repeat(',%d', count($school_ids) - 1);
            $where_conditions[] = "AND om.meta_value IN (%d{$school_placeholders})";
            $where_params = array_merge($where_params, $school_ids);
        }

        // Filtro por zonas (requiere JOIN con school_zone_meta)
        $zone_join = '';
        if (!empty($zone_ids)) {
            $zone_join = "
                INNER JOIN {$wpdb->postmeta} szm ON szm.post_id = om.meta_value 
                AND szm.meta_key = 'school_zone'
            ";
            $zone_placeholders = str_repeat(',%d', count($zone_ids) - 1);
            $where_conditions[] = "AND szm.meta_value IN (%d{$zone_placeholders})";
            $where_params = array_merge($where_params, $zone_ids);
        }

        $additional_where = implode(' ', $where_conditions);

        // Consulta para estadísticas generales enfocadas en escuelas
        // CRÍTICO: Evitar duplicación al unir múltiples tablas de metadatos
        $stats_query = "
            SELECT 
                COUNT(DISTINCT om.meta_value) as total_schools,
                COUNT(DISTINCT o.id) as total_orders,
                SUM(CAST(oiqty.meta_value AS UNSIGNED)) as total_quantity,
                SUM(CAST(oitotal.meta_value AS DECIMAL(10,2)) + CAST(COALESCE(oitax.meta_value, 0) AS DECIMAL(10,2))) as total_amount,
                COUNT(DISTINCT oim.meta_value) as total_products,
                COUNT(DISTINCT oi.order_item_id) as total_line_items,
                AVG(school_totals.school_quantity) as avg_quantity_per_school,
                AVG(school_totals.school_orders) as avg_orders_per_school
            FROM {$wpdb->prefix}wc_orders o
            INNER JOIN {$wpdb->prefix}wc_orders_meta om ON o.id = om.order_id AND om.meta_key = %s
            {$zone_join}
            INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON o.id = oi.order_id AND oi.order_item_type = 'line_item'
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_product_id'
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oiqty ON oi.order_item_id = oiqty.order_item_id AND oiqty.meta_key = '_qty'
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oitotal ON oi.order_item_id = oitotal.order_item_id AND oitotal.meta_key = '_line_total'
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oitax ON oi.order_item_id = oitax.order_item_id AND oitax.meta_key = '_line_tax'
            INNER JOIN {$wpdb->posts} pr ON oim.meta_value = pr.ID AND pr.post_type = 'product'
            INNER JOIN (
                SELECT 
                    om2.meta_value as school_id,
                    SUM(CAST(oiqty2.meta_value AS UNSIGNED)) as school_quantity,
                    COUNT(DISTINCT o2.id) as school_orders
                FROM {$wpdb->prefix}wc_orders o2
                INNER JOIN {$wpdb->prefix}wc_orders_meta om2 ON o2.id = om2.order_id AND om2.meta_key = %s
                INNER JOIN {$wpdb->prefix}woocommerce_order_items oi2 ON o2.id = oi2.order_id AND oi2.order_item_type = 'line_item'
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oiqty2 ON oi2.order_item_id = oiqty2.order_item_id AND oiqty2.meta_key = '_qty'
                WHERE 1=1 " . $status_condition . "
                GROUP BY om2.meta_value
            ) school_totals ON school_totals.school_id = om.meta_value
            WHERE om.meta_key = %s
            " . $status_condition . "
            {$additional_where}
        ";

        // Preparar parámetros completos
        $params = array_merge([$school_meta_key, $school_meta_key, $school_meta_key], $where_params);
        $stats = $wpdb->get_row($wpdb->prepare($stats_query, $params));

        if (!$stats) {
            return [];
        }

        return [
            'total_schools' => intval($stats->total_schools),
            'total_orders' => intval($stats->total_orders),
            'total_quantity' => intval($stats->total_quantity),
            'total_amount' => floatval($stats->total_amount),
            'total_products' => intval($stats->total_products),
            'avg_quantity_per_school' => $stats->avg_quantity_per_school ? round($stats->avg_quantity_per_school, 1) : 0,
            'avg_orders_per_school' => $stats->avg_orders_per_school ? round($stats->avg_orders_per_school, 1) : 0
        ];
    }

    /**
     * Manejar Ajax para informe de escuelas
     */
    public function handleSchoolReportAjax() {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'school_report_nonce')) {
            wp_die('Acceso denegado');
        }
        
        // Procesar múltiples school_ids
        $school_ids = null;
        if (isset($_POST['school_ids']) && is_array($_POST['school_ids'])) {
            $school_ids = array_map('intval', $_POST['school_ids']);
            $school_ids = array_filter($school_ids); // Quitar valores vacíos o ceros
            
            // Si incluye "all" o está vacío, no filtrar por escuelas
            if (empty($school_ids) || in_array(0, $school_ids)) {
                $school_ids = null;
            }
        }
        
        // Procesar múltiples zonas
        $zone_ids = null;
        if (isset($_POST['zone_ids']) && is_array($_POST['zone_ids'])) {
            $zone_ids = array_map('intval', $_POST['zone_ids']);
            $zone_ids = array_filter($zone_ids); // Quitar valores vacíos o ceros
            
            // Si incluye "all" o está vacío, no filtrar por zonas
            if (empty($zone_ids) || in_array(0, $zone_ids)) {
                $zone_ids = null;
            }
        }
        
        // Procesar estados de órdenes
        $order_statuses = null;
        if (isset($_POST['order_statuses']) && is_array($_POST['order_statuses'])) {
            $order_statuses = array_map('sanitize_text_field', $_POST['order_statuses']);
            $order_statuses = array_filter($order_statuses); // Quitar valores vacíos
            
            // Si incluye "all" o está vacío, usar estados por defecto (no null)
            if (empty($order_statuses) || in_array('all', $order_statuses)) {
                $order_statuses = array_keys(self::DEFAULT_ORDER_STATUSES);
            }
        } else {
            // Si no se especifican estados, usar los por defecto
            $order_statuses = array_keys(self::DEFAULT_ORDER_STATUSES);
        }
        
        $data = $this->getSchoolProductStats($school_ids, $zone_ids, $order_statuses);
        $overall_stats = $this->getOverallStats($school_ids, $zone_ids, $order_statuses);
        
        if (empty($data)) {
            wp_send_json_error([
                'message' => 'No se encontraron datos de escuelas'
            ]);
        } else {
            // Agrupar escuelas por zonas para mantener la ordenación correcta
            $schools_by_zones = $this->groupSchoolsByZones($data);
            
            // Crear array de escuelas ordenado por zona para el frontend
            // Importante: usar array numérico para mantener el orden
            $schools_ordered = [];
            foreach ($schools_by_zones as $zone_data) {
                foreach ($zone_data['schools'] as $school_id => $school) {
                    $schools_ordered[] = $school; // Array numérico mantiene el orden
                }
            }
            
            // Debug temporal - ver order de escuelas
            error_log('Schools ordered count: ' . count($schools_ordered));
            if (!empty($schools_ordered)) {
                error_log('First 3 schools: ' . implode(', ', array_slice(array_column($schools_ordered, 'school_name'), 0, 3)));
            }
            
            wp_send_json_success([
                'by_zones' => $schools_by_zones,
                'schools' => $schools_ordered, // Escuelas ordenadas por zona
                'overall_stats' => $overall_stats,
                'order_statuses' => $order_statuses // Pasar los estados para generar URLs en frontend
            ]);
        }
    }

    /**
     * Manejar Ajax para obtener escuelas por múltiples zonas
     */
    public function handleSchoolsByZonesAjax() {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'school_report_nonce')) {
            wp_die('Acceso denegado');
        }
        
        $zone_ids = isset($_POST['zone_ids']) && is_array($_POST['zone_ids']) ? array_map('intval', $_POST['zone_ids']) : [];
        $zone_ids = array_filter($zone_ids); // Quitar valores vacíos
        
        if (empty($zone_ids)) {
            wp_send_json_error(['message' => 'IDs de zonas requeridos']);
            return;
        }
        
        // Obtener escuelas de las zonas específicas
        $schools = get_posts([
            'post_type' => 'coo_school',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'tax_query' => [
                [
                    'taxonomy' => 'coo_zone',
                    'field' => 'term_id',
                    'terms' => $zone_ids,
                    'operator' => 'IN'
                ]
            ]
        ]);
        
        $schools_data = [];
        foreach ($schools as $school) {
            $schools_data[] = [
                'id' => $school->ID,
                'name' => $school->post_title
            ];
        }
        
        wp_send_json_success($schools_data);
    }

    /**
     * Manejar Ajax para obtener todas las escuelas
     */
    public function handleAllSchoolsAjax() {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'school_report_nonce')) {
            wp_die('Acceso denegado');
        }
        
        // Obtener todas las escuelas
        $schools = get_posts([
            'post_type' => 'coo_school',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ]);
        
        $schools_data = [];
        foreach ($schools as $school) {
            $schools_data[] = [
                'id' => $school->ID,
                'name' => $school->post_title
            ];
        }
        
        wp_send_json_success($schools_data);
    }

    /**
     * Manejar Ajax para generar URL del admin de pedidos
     */
    public function handleSchoolOrdersUrlAjax() {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'school_report_nonce')) {
            wp_die('Acceso denegado');
        }
        
        $school_id = intval($_POST['school_id'] ?? 0);
        if ($school_id <= 0) {
            wp_send_json_error(['message' => 'ID de escuela requerido']);
            return;
        }
        
        $order_statuses = [];
        if (isset($_POST['order_statuses']) && is_array($_POST['order_statuses'])) {
            $order_statuses = array_map('sanitize_text_field', $_POST['order_statuses']);
            $order_statuses = array_filter($order_statuses);
        }
        
        $url = $this->generateOrdersAdminUrl($school_id, $order_statuses);
        
        wp_send_json_success([
            'url' => $url,
            'school_id' => $school_id,
            'order_statuses' => $order_statuses
        ]);
    }

    /**
     * Generar URL del admin de pedidos con filtros aplicados
     * 
     * @param int $school_id ID de la escuela
     * @param array $order_statuses Estados de órdenes seleccionados
     * @return string URL del admin de pedidos con filtros
     */
    public function generateOrdersAdminUrl(int $school_id, array $order_statuses = []): string
    {
        // URL base del admin de pedidos (sistema HPOS)
        $base_url = admin_url('admin.php?page=wc-orders');
        
        // Parámetros de filtro
        $params = [];
        
        // Filtro por escuela usando el filtro del AdvancedOrderFilters
        $params['aof_school'] = $school_id;
        
        // Filtro por estados si se proporcionan
        if (!empty($order_statuses)) {
            // Limpiar estados - quitar prefijo 'wc-' si existe
            $clean_statuses = array_map(function($status) {
                return str_replace('wc-', '', $status);
            }, $order_statuses);
            
            // Solo añadir si no es 'all' o array vacío
            if (!in_array('all', $clean_statuses) && !empty($clean_statuses)) {
                $params['aof_order_statuses'] = $clean_statuses;
            }
        }
        
        // Construir URL con parámetros
        $url = add_query_arg($params, $base_url);
        
        return $url;
    }
}

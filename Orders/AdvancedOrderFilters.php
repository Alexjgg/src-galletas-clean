<?php
/**
 * Advanced Order Filters - Filtros avanzados para el admin de pedidos HPOS
 * 
 * Agrega filtros Select2 múltiples para:
 * 1. Estados de pedidos (múltiple selección)
 * 2. Escuelas (select simple)
 * 
 * Compatible con el nuevo sistema HPOS de WooCommerce donde los pedidos
 * están en tablas separadas y usan diferentes hooks y funciones.
 * 
 * @package SchoolManagement\Orders
 * @since 1.0.0
 */

namespace SchoolManagement\Orders;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para filtros avanzados en el listado de pedidos
 */
class AdvancedOrderFilters
{
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
    private function initHooks(): void
    {
        // Solo cargar en admin
        if (!is_admin()) {
            return;
        }

        add_action('woocommerce_order_list_table_restrict_manage_orders', [$this, 'addAdvancedFilters'], 20); // Prioridad después de los filtros de pago
        add_filter('woocommerce_order_list_table_prepare_items_query_args', [$this, 'handleAdvancedFiltering'], 50);
        
        // Cargar assets (CSS/JS) para Select2
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        
        // AJAX handlers para cargar datos dinámicamente
        add_action('wp_ajax_get_schools_for_filter', [$this, 'handleGetSchoolsAjax']);
        add_action('wp_ajax_get_order_statuses_for_filter', [$this, 'handleGetOrderStatusesAjax']);
        

    }

    /**
     * Agregar filtros avanzados para sistema HPOS
     */
    public function addAdvancedFilters(): void
    {
        // Verificar que estamos en la página correcta
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'woocommerce_page_wc-orders') {
            return;
        }

        // Evitar renderizado múltiple - usar static para recordar entre llamadas
        static $already_rendered = false;
        if ($already_rendered) {
            return;
        }
        $already_rendered = true;

        $this->renderFilters();
    }

    /**
     * Agregar filtros avanzados para sistema legacy CPT
     */
    public function addAdvancedFiltersLegacy(): void
    {
        global $typenow;
        
        if ($typenow !== 'shop_order') {
            return;
        }

        $this->renderFilters();
    }

    /**
     * Verificar si el usuario actual es profesor
     */
    private function isCurrentUserTeacher(): bool
    {
        $current_user = wp_get_current_user();
        return in_array('teacher', $current_user->roles);
    }

    /**
     * Renderizar los filtros HTML
     */
    private function renderFilters(): void
    {
        // Verificar si el usuario actual es profesor
        $is_teacher = $this->isCurrentUserTeacher();
        
        // Obtener valores actuales de los filtros
        $selected_statuses = $_GET['aof_order_statuses'] ?? [];
        $selected_school = $_GET['aof_school'] ?? '';
        
        // Asegurar que selected_statuses sea array
        if (!is_array($selected_statuses)) {
            $selected_statuses = !empty($selected_statuses) ? [$selected_statuses] : [];
        }

        echo '<div class="advanced-order-filters-wrapper" id="aof-wrapper-unique">';
        
        // Filtro de Estados Múltiple
        echo '<select name="aof_order_statuses[]" id="aof_order_statuses_unique" multiple="multiple" data-placeholder="' . esc_attr__('Select statuses...', 'neve-child') . '" style="width: 180px; margin-right: 5px;">';
        
        $all_statuses = $this->getAllOrderStatuses();
        foreach ($all_statuses as $status_key => $status_label) {
            $clean_key = str_replace('wc-', '', $status_key);
            $selected = in_array($status_key, $selected_statuses) || in_array($clean_key, $selected_statuses) ? 'selected' : '';
            echo '<option value="' . esc_attr($clean_key) . '" ' . $selected . '>' . esc_html($status_label) . '</option>';
        }
        
        echo '</select>';
        
        // Filtro de Escuelas - SOLO mostrar si NO es profesor
        if (!$is_teacher) {
            echo '<select name="aof_school" id="aof_school_unique" data-placeholder="' . esc_attr__('Select school...', 'neve-child') . '" style="width: 180px; margin-right: 5px;">';
            echo '<option value="">' . __('All schools', 'neve-child') . '</option>';
            
            $schools = $this->getAllSchools();
            if (empty($schools)) {
                echo '<option value="" disabled>' . __('No schools available', 'neve-child') . '</option>';
            } else {
                foreach ($schools as $school_id => $school_name) {
                    $selected = ($selected_school == $school_id) ? 'selected' : '';
                    echo '<option value="' . esc_attr($school_id) . '" ' . $selected . '>' . esc_html($school_name) . '</option>';
                }
            }
            
            echo '</select>';
        }
        
        echo '</div>';
    }

    /**
     * Manejar filtrado avanzado para sistema HPOS
     */
    public function handleAdvancedFiltering(array $query_args): array
    {
        $selected_statuses = $_GET['aof_order_statuses'] ?? [];
        $selected_school = $_GET['aof_school'] ?? '';
        
        // Filtro por estados múltiples
        if (!empty($selected_statuses) && is_array($selected_statuses)) {
            // Limpiar y validar estados
            $valid_statuses = [];
            foreach ($selected_statuses as $status) {
                $clean_status = sanitize_text_field($status);
                if (!empty($clean_status)) {
                    // Asegurar que tenga el prefijo 'wc-' si no lo tiene
                    if (!str_starts_with($clean_status, 'wc-')) {
                        $clean_status = 'wc-' . $clean_status;
                    }
                    $valid_statuses[] = $clean_status;
                }
            }
            
            if (!empty($valid_statuses)) {
                $query_args['status'] = $valid_statuses;
            }
        }
        
        // Filtro por escuela - Versión simplificada
        if (!empty($selected_school)) {
            $school_id = intval($selected_school);
            
            if ($school_id > 0) {
                // VERSIÓN SIMPLE: usar meta_query directamente sin wc_get_orders
                if (!isset($query_args['meta_query'])) {
                    $query_args['meta_query'] = [];
                }
                
                $query_args['meta_query'][] = [
                    'key' => '_school_id',
                    'value' => $school_id,
                    'compare' => '='
                ];
            }
        }
        return $query_args;
    }

    /**
     * Manejar filtrado avanzado para sistema legacy CPT
     */
    public function handleAdvancedFilteringLegacy(\WP_Query $query): void
    {
        global $pagenow, $typenow;
        
        if ($pagenow !== 'edit.php' || $typenow !== 'shop_order' || !is_admin()) {
            return;
        }

        // Filtro por estados múltiples
        $selected_statuses = $_GET['aof_order_statuses'] ?? [];
        if (!empty($selected_statuses) && is_array($selected_statuses)) {
            $valid_statuses = [];
            foreach ($selected_statuses as $status) {
                $clean_status = sanitize_text_field($status);
                if (!empty($clean_status)) {
                    if (!str_starts_with($clean_status, 'wc-')) {
                        $clean_status = 'wc-' . $clean_status;
                    }
                    $valid_statuses[] = $clean_status;
                }
            }
            
            if (!empty($valid_statuses)) {
                $query->set('post_status', $valid_statuses);
            }
        }
        
        // Filtro por escuela
        $selected_school = $_GET['aof_school'] ?? '';
        if (!empty($selected_school)) {
            $school_id = intval($selected_school);
            if ($school_id > 0) {
                $query->set('meta_key', \SchoolManagement\Shared\Constants::ORDER_META_SCHOOL_ID);
                $query->set('meta_value', $school_id);
            }
        }
    }

    /**
     * Obtener estados de pedidos para filtros
     * Todos los estados para profesores, específicos para otros usuarios
     */
    private function getAllOrderStatuses(): array
    {
        // Si es profesor, mostrar TODOS los estados disponibles
        if ($this->isCurrentUserTeacher()) {
            return wc_get_order_statuses();
        }
        
        // Para otros usuarios (administradores), estados específicos
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
     * Obtener todas las escuelas disponibles
     */
    private function getAllSchools(): array
    {
        $schools = [];
        
        // Usar WP_Query para obtener escuelas más eficientemente
        $query = new \WP_Query([
            'post_type' => 'coo_school', // Post type correcto para escuelas
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'fields' => 'ids' // Solo obtener IDs para optimizar
        ]);
        
        if ($query->have_posts()) {
            foreach ($query->posts as $school_id) {
                $school_title = get_the_title($school_id);
                if (!empty($school_title)) {
                    $schools[$school_id] = $school_title;
                }
            }
        }
        
        wp_reset_postdata();
        
        return $schools;
    }
    
    /**
     * Cargar assets CSS y JavaScript
     */
    public function enqueueAssets($hook): void
    {
        // Solo cargar en páginas de pedidos
        if ($hook !== 'woocommerce_page_wc-orders' && $hook !== 'edit.php') {
            return;
        }
        
        $screen = get_current_screen();
        if (!$screen || ($screen->id !== 'woocommerce_page_wc-orders' && $screen->id !== 'edit-shop_order')) {
            return;
        }

        // Cargar Select2 usando WooCommerce's built-in version
        wp_enqueue_script('selectWoo');
        wp_enqueue_style('select2');
        
        // Renderizar assets inline
        add_action('admin_footer', [$this, 'renderInlineJs'], 999);
        
        // Localizar script con datos necesarios
        wp_localize_script('jquery', 'aofData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aof_ajax_nonce'),
            'strings' => [
                'selectStatuses' => __('Select statuses...', 'neve-child'),
                'selectSchool' => __('Select school...', 'neve-child'),
                'allStatuses' => __('All statuses', 'neve-child'),
                'allSchools' => __('All schools', 'neve-child'),
                'loading' => __('Loading...', 'neve-child')
            ]
        ]);
    }

    /**
     * Renderizar JavaScript inline
     */
    public function renderInlineJs(): void
    {
        $screen = get_current_screen();
        if (!$screen || ($screen->id !== 'woocommerce_page_wc-orders' && $screen->id !== 'edit-shop_order')) {
            return;
        }
        
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Evitar duplicados - solo procesar una vez
            if (window.aofFiltersInitialized) {
                return;
            }
            window.aofFiltersInitialized = true;
            
            // Agregar CSS dinámicamente para evitar problemas
            if (!document.getElementById('aof-dynamic-styles')) {
                var css = `
                    .advanced-order-filters-wrapper {
                        display: inline-block;
                        vertical-align: top;
                        margin: 0 10px 10px 0;
                        white-space: nowrap;
                        position: relative;
                        z-index: 1;
                    }
                    .advanced-order-filters-wrapper select {
                        margin-right: 5px !important;
                        height: 28px !important;
                        line-height: 26px !important;
                        vertical-align: top !important;
                    }
                    .advanced-order-filters-wrapper .select2-container,
                    .advanced-order-filters-wrapper .selectWoo {
                        vertical-align: top !important;
                        margin-right: 5px !important;
                        display: inline-block !important;
                        position: relative !important;
                    }
                    .advanced-order-filters-wrapper .select2-dropdown {
                        z-index: 999999 !important;
                        border: 1px solid #8c8f94 !important;
                        border-radius: 3px !important;
                        background: #fff !important;
                        box-shadow: 0 2px 8px rgba(0,0,0,0.15) !important;
                    }
                    .advanced-order-filters-wrapper .select2-selection,
                    .advanced-order-filters-wrapper .selectWoo .select2-selection {
                        border: 1px solid #8c8f94 !important;
                        border-radius: 3px !important;
                        background: #fff !important;
                    }
                    /* Single selection */
                    .advanced-order-filters-wrapper .select2-selection--single {
                        height: 28px !important;
                        line-height: 26px !important;
                    }
                    .advanced-order-filters-wrapper .select2-selection--single .select2-selection__rendered {
                        padding-left: 8px !important;
                        padding-right: 20px !important;
                        line-height: 26px !important;
                        color: #1d2327 !important;
                    }
                    /* Multiple selection - DINÁMICO SIN SCROLL INTERNO */
                    .advanced-order-filters-wrapper .select2-selection--multiple {
                        min-height: 28px !important;
                        border: 1px solid #8c8f94 !important;
                        border-radius: 3px !important;
                        padding: 4px 6px !important;
                        line-height: 1.2 !important;
                        overflow: hidden !important;
                        /* NO max-height para permitir crecimiento dinámico */
                    }
                    .advanced-order-filters-wrapper .select2-selection--multiple .select2-selection__rendered {
                        padding: 0 !important;
                        display: block !important;
                        overflow: visible !important;
                        white-space: normal !important;
                    }
                    /* Pills/Choices - DISEÑO COMO EN INFORMES */
                    .advanced-order-filters-wrapper .select2-selection__choice {
                        background-color: #0073aa !important;
                        border: 1px solid #0073aa !important;
                        border-radius: 3px !important;
                        color: white !important;
                        cursor: default !important;
                        float: left !important;
                        margin: 2px 4px 2px 2px !important;
                        padding: 3px 6px !important;
                        font-size: 12px !important;
                        line-height: 1.2 !important;
                        max-width: calc(100% - 10px) !important;
                        box-sizing: border-box !important;
                        display: inline-flex !important;
                        align-items: center !important;
                        overflow: hidden !important;
                        text-overflow: ellipsis !important;
                    }
                    /* Remove button */
                    .advanced-order-filters-wrapper .select2-selection__choice__remove {
                        color: white !important;
                        cursor: pointer !important;
                        display: inline-block !important;
                        font-weight: bold !important;
                        margin-right: 6px !important;
                        margin-left: 0 !important;
                        padding: 0 !important;
                        border: none !important;
                        background: transparent !important;
                        font-size: 14px !important;
                        line-height: 1 !important;
                        order: -1 !important;
                    }
                    .wc-wp-version-gte-53 .select2-container .select2-search--inline .select2-search__field{
                        margin:0px !important;
                    }
                       .select2-search.select2-search--inline{
                        margin: 0px 3px !important;    
                        }
                        .select2-selection--multiple.aof-status-container {
                            padding: 0 !important;
                        }
                    .advanced-order-filters-wrapper .select2-selection__choice__remove:hover {
                        color: #f0f0f0 !important;
                        background: rgba(255,255,255,0.2) !important;
                        border-radius: 2px !important;
                    }
                    /* Search input */
                    .advanced-order-filters-wrapper .select2-search--inline .select2-search__field {
                        margin-top: 2px !important;
                        height: 20px !important;
                        line-height: 20px !important;
                        min-width: 50px !important;
                        border: none !important;
                        background: transparent !important;
                    }
                    /* Ocultar duplicados */
                    .tablenav .actions select[name="aof_school"]:not(#aof_school_unique),
                    .tablenav .actions select[name="aof_order_statuses[]"]:not(#aof_order_statuses_unique) {
                        display: none !important;
                    }
                    .aof-status-container, .aof-school-container {
                        z-index: 999 !important;
                    }
                    .aof-status-dropdown, .aof-school-dropdown {
                        z-index: 999999 !important;
                        max-height: 200px !important;
                        overflow-y: auto !important;
                    }
                `;
                
                var styleElement = document.createElement('style');
                styleElement.type = 'text/css';
                styleElement.id = 'aof-dynamic-styles';
                styleElement.innerHTML = css;
                document.head.appendChild(styleElement);
            }
            
            // Verificar que SelectWoo esté disponible
            var selectMethod = 'selectWoo';
            if (typeof $.fn.selectWoo !== 'function') {
                selectMethod = 'select2';
                if (typeof $.fn.select2 !== 'function') {
                    console.error('Ni SelectWoo ni Select2 están disponibles');
                    return;
                }
            }
            
            // Inicializar Select para estados múltiples
            var $statusSelect = $('#aof_order_statuses_unique');
            if ($statusSelect.length && !$statusSelect.hasClass('select2-hidden-accessible')) {
                $statusSelect[selectMethod]({
                    placeholder: '<?php echo esc_js(__('Select statuses...', 'neve-child')); ?>',
                    allowClear: true,
                    width: '180px',
                    closeOnSelect: false,
                    dropdownAutoWidth: true,
                    containerCssClass: 'aof-status-container',
                    dropdownCssClass: 'aof-status-dropdown'
                });
            }
            
            // Inicializar Select para escuelas - SOLO si existe el elemento (no es profesor)
            var $schoolSelect = $('#aof_school_unique');
            if ($schoolSelect.length && !$schoolSelect.hasClass('select2-hidden-accessible')) {
                $schoolSelect[selectMethod]({
                    placeholder: '<?php echo esc_js(__('Select school...', 'neve-child')); ?>',
                    allowClear: true,
                    width: '180px',
                    dropdownAutoWidth: true,
                    containerCssClass: 'aof-school-container',
                    dropdownCssClass: 'aof-school-dropdown'
                });
                
                // Auto-submit cuando cambie la selección de escuela
                $schoolSelect.on('change', function() {
                    $(this).closest('form').submit();
                });
            }
        });
        </script>
        <?php
    }

    /**
     * AJAX handler para obtener escuelas dinámicamente
     */
    public function handleGetSchoolsAjax(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'aof_ajax_nonce')) {
            wp_die('Nonce verification failed');
        }
        
        // Verificar permisos
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }
        
        $search = sanitize_text_field($_POST['search'] ?? '');
        $schools = $this->getAllSchools();
        
        // Filtrar por búsqueda si se proporciona
        if (!empty($search)) {
            $schools = array_filter($schools, function($school_name) use ($search) {
                return stripos($school_name, $search) !== false;
            });
        }
        
        $results = [];
        foreach ($schools as $school_id => $school_name) {
            $results[] = [
                'id' => $school_id,
                'text' => $school_name
            ];
        }
        
        wp_send_json_success([
            'results' => $results,
            'pagination' => ['more' => false]
        ]);
    }

    /**
     * AJAX handler para obtener estados de pedido dinámicamente
     */
    public function handleGetOrderStatusesAjax(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'aof_ajax_nonce')) {
            wp_die('Nonce verification failed');
        }
        
        // Verificar permisos
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }
        
        $statuses = $this->getAllOrderStatuses();
        
        $results = [];
        foreach ($statuses as $status_key => $status_label) {
            $results[] = [
                'id' => $status_key,
                'text' => $status_label
            ];
        }
        
        wp_send_json_success([
            'results' => $results,
            'pagination' => ['more' => false]
        ]);
    }

}

// Inicializar automáticamente
if (!isset($GLOBALS['advanced_order_filters'])) {
    $GLOBALS['advanced_order_filters'] = new AdvancedOrderFilters();
}

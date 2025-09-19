<?php
/**
 * Vendor Relationship Manager for schools
 * 
 * @package SchoolManagement\Schools
 * @since 1.0.0
 */

namespace SchoolManagement\Schools;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for managing school-vendor relationships
 */
class VendorRelationship
{
    /**
     * Post types and taxonomies
     */
    private const SCHOOL_POST_TYPE = 'coo_school';
    private const VENDOR_POST_TYPE = 'coo_vendor';
    private const ZONE_TAXONOMY = 'coo_zone';

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
    public function initHooks(): void
    {
        add_filter('acf/update_field', [$this, 'updateVendorField']);
        add_filter('acf/load_field/name=vendor', [$this, 'loadVendorField']);
        add_filter('acf/prepare_field/name=vendor', [$this, 'prepareVendorField']);
        add_action('save_post_' . self::SCHOOL_POST_TYPE, [$this, 'saveDefaultVendor'], 10, 3);
        add_action('init', [$this, 'registerFieldSettings'], 20);
        add_filter('manage_' . self::SCHOOL_POST_TYPE . '_posts_columns', [$this, 'addVendorColumn']);
        add_action('manage_' . self::SCHOOL_POST_TYPE . '_posts_custom_column', [$this, 'displayVendorColumn'], 10, 2);
        add_filter('manage_edit-' . self::SCHOOL_POST_TYPE . '_sortable_columns', [$this, 'makeVendorColumnSortable']);
        add_action('restrict_manage_posts', [$this, 'addVendorFilter']);
        add_filter('parse_query', [$this, 'filterSchoolsByVendor']);
        
        // Zone taxonomy filter
        add_action('restrict_manage_posts', [$this, 'addZoneFilter']);
        add_filter('parse_query', [$this, 'filterSchoolsByZone']);
    }

    /**
     * Register field settings programmatically
     * 
     * @return void
     */
    public function registerFieldSettings(): void
    {
        if (!function_exists('get_field_object')) {
            return;
        }

        $field = get_field_object('vendor', false, false);

        if (!$field) {
            return;
        }

        $field['type'] = 'post_object';
        $field['post_type'] = [self::VENDOR_POST_TYPE];
        $field['return_format'] = 'id';
        $field['ui'] = 1;
        $field['allow_null'] = 1;

        acf_update_field($field);
    }

    /**
     * Update vendor field when saved in ACF Field Editor
     * 
     * @param array $field The field being updated
     * @return array Modified field
     */
    public function updateVendorField(array $field): array
    {
        if ($field['name'] !== 'vendor') {
            return $field;
        }

        $field['type'] = 'post_object';
        $field['post_type'] = [self::VENDOR_POST_TYPE];
        $field['return_format'] = 'id';
        $field['ui'] = 1;
        $field['allow_null'] = 1;

        return $field;
    }

    /**
     * Dynamically modify the ACF field to load all vendors
     * 
     * @param array $field ACF field
     * @return array Modified ACF field
     */
    public function loadVendorField(array $field): array
    {
        $field['type'] = 'post_object';
        $field['post_type'] = [self::VENDOR_POST_TYPE];
        $field['return_format'] = 'id';
        $field['ui'] = 1;
        $field['allow_null'] = 1;
        $field['post_status'] = 'publish';
        $field['ajax'] = 0;

        return $field;
    }

    /**
     * Prepare vendor field for display
     * 
     * @param array $field ACF field
     * @return array Modified field
     */
    public function prepareVendorField(array $field): array
    {
        if ($field['type'] !== 'post_object') {
            return $field;
        }

        $vendors = $this->getAllVendors();
        
        if (!empty($vendors)) {
            $field['choices'] = [];
            foreach ($vendors as $vendor) {
                $field['choices'][$vendor->ID] = $vendor->post_title;
            }
        }

        return $field;
    }

    /**
     * Save default vendor when school is created
     * 
     * @param int $post_id Post ID
     * @param \WP_Post $post Post object
     * @param bool $update Whether this is an update
     * @return void
     */
    public function saveDefaultVendor(int $post_id, \WP_Post $post, bool $update): void
    {
        if ($update) {
            return;
        }

        $vendor_id = get_field('vendor', $post_id);
        
        if (empty($vendor_id)) {
            $vendors = $this->getAllVendors();
            if (!empty($vendors)) {
                $default_vendor = $vendors[0];
                update_field('vendor', $default_vendor->ID, $post_id);
            }
        }
    }

    /**
     * Get school's vendor
     * 
     * @param int $school_id School ID
     * @return int|null Vendor ID
     */
    public function getSchoolVendor(int $school_id): ?int
    {
        $vendor_id = get_field('vendor', $school_id);
        return $vendor_id ? (int) $vendor_id : null;
    }

    /**
     * Add vendor column to schools list
     * 
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public function addVendorColumn(array $columns): array
    {
        $new_columns = [];

        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            if ($key === 'title') {
                $new_columns['vendor'] = __('Vendor', 'neve-child');
            }
        }

        if (!isset($new_columns['vendor'])) {
            $new_columns['vendor'] = __('Vendor', 'neve-child');
        }

        return $new_columns;
    }

    /**
     * Display vendor in the column
     * 
     * @param string $column_name Column name
     * @param int $post_id Post ID
     * @return void
     */
    public function displayVendorColumn(string $column_name, int $post_id): void
    {
        if ($column_name !== 'vendor') {
            return;
        }

        $vendor_id = $this->getSchoolVendor($post_id);
        
        if ($vendor_id) {
            $vendor = get_post($vendor_id);
            if ($vendor) {
                echo '<strong>' . esc_html($vendor->post_title) . '</strong>';
            } else {
                echo '<em>' . __('Vendor not found', 'neve-child') . '</em>';
            }
        } else {
            echo '<em>' . __('No vendor', 'neve-child') . '</em>';
        }
    }

    /**
     * Make vendor column sortable
     * 
     * @param array $columns Sortable columns
     * @return array Modified columns
     */
    public function makeVendorColumnSortable(array $columns): array
    {
        $columns['vendor'] = 'vendor';
        return $columns;
    }

    /**
     * Add vendor filter to schools list
     * 
     * @return void
     */
    public function addVendorFilter(): void
    {
        global $typenow;
        
        if ($typenow !== self::SCHOOL_POST_TYPE) {
            return;
        }

        $vendors = $this->getAllVendors();
        $selected_vendor = $_GET['vendor_filter'] ?? '';

        echo '<select name="vendor_filter">';
        echo '<option value="">' . __('All vendors', 'neve-child') . '</option>';
        
        foreach ($vendors as $vendor) {
            $selected = selected($selected_vendor, $vendor->ID, false);
            echo '<option value="' . esc_attr($vendor->ID) . '" ' . $selected . '>' . esc_html($vendor->post_title) . '</option>';
        }
        
        echo '</select>';
    }

    /**
     * Add zone taxonomy filter to schools list
     * 
     * @return void
     */
    public function addZoneFilter(): void
    {
        global $typenow;
        
        if ($typenow !== self::SCHOOL_POST_TYPE) {
            return;
        }

        $zones = $this->getAllZones();
        $selected_zone = $_GET['zone_filter'] ?? '';

        echo '<select name="zone_filter">';
        echo '<option value="">' . __('All zones', 'neve-child') . '</option>';
        
        foreach ($zones as $zone) {
            $selected = selected($selected_zone, $zone->term_id, false);
            echo '<option value="' . esc_attr($zone->term_id) . '" ' . $selected . '>' . esc_html($zone->name) . '</option>';
        }
        
        echo '</select>';
    }

    /**
     * Filter schools by vendor
     * 
     * @param \WP_Query $query Query object
     * @return void
     */
    public function filterSchoolsByVendor(\WP_Query $query): void
    {
        global $pagenow;
        
        if (!is_admin() || $pagenow !== 'edit.php') {
            return;
        }

        if (!isset($_GET['post_type']) || $_GET['post_type'] !== self::SCHOOL_POST_TYPE) {
            return;
        }

        if (!isset($_GET['vendor_filter']) || empty($_GET['vendor_filter']) || $_GET['vendor_filter'] === '') {
            return;
        }

        // Verificar que es la query principal del admin
        if (!$query->is_main_query()) {
            return;
        }

        $vendor_id = (int) $_GET['vendor_filter'];
        
        // Obtener meta_query existente si existe
        $existing_meta_query = $query->get('meta_query', []);
        
        // Agregar el filtro de vendor
        $vendor_meta_query = [
            'key' => 'vendor',
            'value' => $vendor_id,
            'compare' => '='
        ];
        
        // Si ya hay meta_query, agregarlo con relación AND
        if (!empty($existing_meta_query)) {
            $existing_meta_query[] = $vendor_meta_query;
            $existing_meta_query['relation'] = 'AND';
        } else {
            $existing_meta_query = [$vendor_meta_query];
        }
        
        $query->set('meta_query', $existing_meta_query);
    }

    /**
     * Filter schools by zone taxonomy
     * 
     * @param \WP_Query $query Query object
     * @return void
     */
    public function filterSchoolsByZone(\WP_Query $query): void
    {
        global $pagenow;
        
        if (!is_admin() || $pagenow !== 'edit.php') {
            return;
        }

        if (!isset($_GET['post_type']) || $_GET['post_type'] !== self::SCHOOL_POST_TYPE) {
            return;
        }

        if (!isset($_GET['zone_filter']) || empty($_GET['zone_filter']) || $_GET['zone_filter'] === '') {
            return;
        }

        // Verificar que es la query principal del admin
        if (!$query->is_main_query()) {
            return;
        }

        $zone_id = (int) $_GET['zone_filter'];
        
        // Si ya hay una meta_query del vendor, mantenerla
        $existing_meta_query = $query->get('meta_query', []);
        
        // Obtener tax_query existente si existe
        $existing_tax_query = $query->get('tax_query', []);
        
        // Agregar el filtro de zona
        $zone_tax_query = [
            'taxonomy' => self::ZONE_TAXONOMY,
            'field'    => 'term_id',
            'terms'    => $zone_id,
        ];
        
        // Si ya hay tax_query, agregarlo con relación AND
        if (!empty($existing_tax_query)) {
            $existing_tax_query[] = $zone_tax_query;
            $existing_tax_query['relation'] = 'AND';
        } else {
            $existing_tax_query = [$zone_tax_query];
        }
        
        $query->set('tax_query', $existing_tax_query);
        
        // Mantener la meta_query si existe (para el filtro de vendor)
        if (!empty($existing_meta_query)) {
            $query->set('meta_query', $existing_meta_query);
        }
    }

    /**
     * Get all vendors
     * 
     * @return array
     */
    private function getAllVendors(): array
    {
        $vendors = get_posts([
            'post_type' => self::VENDOR_POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ]);

        return $vendors ?: [];
    }

    /**
     * Get all zones from the coo_zone taxonomy
     * 
     * @return array
     */
    private function getAllZones(): array
    {
        $zones = get_terms([
            'taxonomy' => self::ZONE_TAXONOMY,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ]);

        if (is_wp_error($zones)) {
            return [];
        }

        return $zones ?: [];
    }
}

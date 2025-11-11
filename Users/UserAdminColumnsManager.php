<?php
/**
 * User Admin Columns Manager
 * 
 * Manages custom columns in WordPress users admin,
 * including student information, schools and ACF fields
 * 
 * @package SchoolManagement\Users
 * @since 1.0.0
 */

namespace SchoolManagement\Users;

use SchoolManagement\Shared\Constants;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for managing custom columns in WordPress user admin
 */
class UserAdminColumnsManager
{
    /**
     * Constructor
     */
    public function __construct()
    {
        // Agregar columnas personalizadas a la lista de usuarios
        add_filter('manage_users_columns', [$this, 'addCustomColumnsToUsersList']);
        add_filter('manage_users_custom_column', [$this, 'displayCustomColumnContent'], 10, 3);

        // Hacer columnas ordenables
        add_filter('manage_users_sortable_columns', [$this, 'makeCustomColumnsSortable']);
        add_action('pre_get_users', [$this, 'handleCustomColumnsSorting']);

        // Filtros avanzados en el admin de usuarios - PRIORIDAD ALTA para ejecutar después del TeacherRoleManager
        add_action('manage_users_extra_tablenav', [$this, 'addAdvancedFiltersToUsersList']);
        add_action('pre_get_users', [$this, 'handleAdvancedFilters'], 20); // Prioridad 20 > 10 (default)

        // AJAX para búsquedas dinámicas
        add_action('wp_ajax_search_schools_admin', [$this, 'handleSchoolSearchAdmin']);
        add_action('wp_ajax_search_users_by_school', [$this, 'handleUsersBySchoolSearch']);

        // Cargar estilos CSS para las columnas
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        
        // Cargar Select2 para filtros avanzados
        add_action('admin_enqueue_scripts', [$this, 'enqueueSelect2Scripts']);

        // Acciones rápidas en la lista de usuarios
        add_filter('user_row_actions', [$this, 'addCustomUserRowActions'], 10, 2);
    }

    /**
     * Add custom columns to users list
     * 
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public function addCustomColumnsToUsersList(array $columns): array
    {
        // Insertar nuevas columnas después de 'name' pero antes de 'email'
        $new_columns = [];
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            // Después de la columna 'name', agregar nuestras columnas personalizadas
            if ($key === 'name') {
                $new_columns[Constants::COLUMN_STUDENT_INFO] = '<span title="' . esc_attr__('Student information', 'neve-child') . '">👨‍🎓 ' . __('Student', 'neve-child') . '</span>';
                $new_columns[Constants::COLUMN_SCHOOL_INFO] = '<span title="' . esc_attr__('School information', 'neve-child') . '">🏫 ' . __('School', 'neve-child') . '</span>';
                $new_columns[Constants::COLUMN_USER_NUMBER] = '<span title="' . esc_attr__('Student number', 'neve-child') . '">🔢 ' . __('Number', 'neve-child') . '</span>';
                $new_columns[Constants::COLUMN_REGISTRATION_DATE] = '<span title="' . esc_attr__('Registration date', 'neve-child') . '">📅 ' . __('Registration', 'neve-child') . '</span>';
                // Eliminada la columna 'user_msrp_total' - solo mantenemos Incoming
            }
        }
        
        // Agregar la columna "Incoming" al final
        $new_columns['user_incoming_total'] = '<span title="' . esc_attr__('Incoming: Total Sales - MSRP', 'neve-child') . '">📈 ' . __('Incoming', 'neve-child') . '</span>';
        
        return $new_columns;
    }

    /**
     * Display content for custom columns
     * 
     * @param mixed $value Current column value
     * @param string $column_name Column name
     * @param int $user_id User ID
     * @return string Modified column content
     */
    public function displayCustomColumnContent($value, string $column_name, int $user_id): string
    {
        switch ($column_name) {
            case Constants::COLUMN_STUDENT_INFO:
                return $this->getStudentInfoColumn($user_id);
                
            case Constants::COLUMN_SCHOOL_INFO:
                return $this->getSchoolInfoColumn($user_id);
                
            case Constants::COLUMN_USER_NUMBER:
                return $this->getUserNumberColumn($user_id);
                
            case 'user_incoming_total':
                return $this->getUserIncomingTotalColumn($user_id);
                
            case Constants::COLUMN_REGISTRATION_DATE:
                return $this->getRegistrationDateColumn($user_id);
                
            default:
                return $value;
        }
    }

    /**
     * Get student information for column display
     * 
     * @param int $user_id User ID
     * @return string HTML content
     */
    private function getStudentInfoColumn(int $user_id): string
    {
        $user_name = get_field(Constants::ACF_USER_NAME, 'user_' . $user_id);
        $first_surname = get_field(Constants::ACF_USER_FIRST_SURNAME, 'user_' . $user_id);
        $second_surname = get_field(Constants::ACF_USER_SECOND_SURNAME, 'user_' . $user_id);
        
        if (!$user_name && !$first_surname) {
            return '<span style="color: #999;">' . __('Not defined', 'neve-child') . '</span>';
        }
        
        $full_name = trim($user_name . ' ' . $first_surname . ' ' . $second_surname);
        
        $html = '<div class="student-info">';
        $html .= '<strong>' . esc_html($full_name) . '</strong>';
        
        // Agregar información adicional si existe
        $user = get_userdata($user_id);
        if ($user && $user->user_email) {
            $html .= '<br><small style="color: #666;">📧 ' . esc_html($user->user_email) . '</small>';
        }
        
        $html .= '</div>';
        
        return $html;
    }

    /**
     * Get school information for column display
     * 
     * @param int $user_id User ID
     * @return string HTML content
     */
    private function getSchoolInfoColumn(int $user_id): string
    {
        $school_id = get_user_meta($user_id, Constants::USER_META_SCHOOL_ID, true);
        
        if (!$school_id) {
            return '<span style="color: #d63638;">❌ ' . __('School', 'neve-child') . '</span>';
        }
        
        $school_post = get_post($school_id);
        $school_name = $school_post->post_title;
        
        // Obtener información adicional del colegio
        $province = get_field(Constants::ACF_SCHOOL_PROVINCE, $school_id);
        $city = get_field(Constants::ACF_SCHOOL_CITY, $school_id);
        
        $html = '<div class="school-info">';
        $html .= '<strong style="color: #00a32a;">' . esc_html($school_name) . '</strong>';
        
        if ($province || $city) {
            $location = implode(', ', array_filter([$city, $province]));
            $html .= '<br><small style="color: #666;">📍 ' . esc_html($location) . '</small>';
        }
        
        $html .= '</div>';
        
        return $html;
    }

    /**
     * Get user number for column display
     * 
     * @param int $user_id User ID
     * @return string HTML content
     */
    private function getUserNumberColumn(int $user_id): string
    {
        $user_number = get_field(Constants::ACF_USER_NUMBER, 'user_' . $user_id);
        
        if (!$user_number) {
            return '<span style="color: #999;">—</span>';
        }
        
        return '<span class="user-number" style="font-weight: bold; color: #2271b1;">' . esc_html($user_number) . '</span>';
    }

    /**
     * Get user incoming total for column display (MSRP - Total Sales = Beneficio)
     * 
     * @param int $user_id User ID
     * @return string HTML content
     */
    private function getUserIncomingTotalColumn(int $user_id): string
    {
        // ✅ CORREGIDO: Leer directamente el valor calculado por MSRPAccumulator
        // En lugar de recalcular, usar el valor que ya maneja reembolsos correctamente
        $incoming_total = (float) get_user_meta($user_id, '_user_incoming_total', true);
        
        if ($incoming_total <= 0) {
            return '<span style="color: #999;">€0.00</span>';
        }
        
        // Formatear el número con símbolo de euro
        $formatted_amount = number_format($incoming_total, 2, ',', '.') . ' €';
        
        // Determinar el color basado en la cantidad
        $color = '#2271b1'; // Azul por defecto
        if ($incoming_total >= 1000) {
            $color = '#00a32a'; // Verde para cantidades altas
        } elseif ($incoming_total >= 500) {
            $color = '#d63638'; // Rojo para cantidades medias-altas
        }
        
        return '<strong style="color: ' . $color . ';">' . esc_html($formatted_amount) . '</strong>';
    }

    /**
     * Get registration date for column display
     * 
     * @param int $user_id User ID
     * @return string HTML content
     */
    private function getRegistrationDateColumn(int $user_id): string
    {
        $user = get_userdata($user_id);
        if (!$user) {
            return '<span style="color: #999;">—</span>';
        }
        
        $registered_date = $user->user_registered;
        $formatted_date = mysql2date('d/m/Y', $registered_date);
        $time_ago = human_time_diff(strtotime($registered_date), current_time('timestamp'));
        
        $html = '<div class="registration-info">';
        $html .= '<strong>' . esc_html($formatted_date) . '</strong>';
        $html .= '<br><small style="color: #666;">' . sprintf(__('%s ago', 'neve-child'), esc_html($time_ago)) . '</small>';
        $html .= '</div>';
        
        return $html;
    }

    /**
     * Calculate user total sales using the same logic as woocommerce-users.php
     * Reutiliza la lógica existente sin modificar el archivo original
     * 
     * @param int $user_id User ID
     * @return float Total sales amount
     */
    private function calculateUserTotalSales(int $user_id): float
    {
        // Usar la misma lógica que woocommerce-users.php (líneas 75-88)
        $args = array(
            'customer_id' => $user_id,
            'limit' => -1,
        );
        $orders = wc_get_orders($args);
        
        // Filtrar pedidos no cancelados (misma lógica que el archivo original)
        $non_cancelled_orders = array_filter($orders, function ($order) {
            return $order->get_status() !== 'cancelled';
        });

        // Calcular el total (misma lógica que el archivo original)
        $total_spent = 0;
        foreach ($non_cancelled_orders as $order) {
            $total_spent += $order->get_total();
        }
        
        return (float) $total_spent;
    }

    /**
     * Make custom columns sortable
     * 
     * @param array $columns Sortable columns
     * @return array Modified sortable columns
     */
    public function makeCustomColumnsSortable(array $columns): array
    {
        $columns[Constants::COLUMN_REGISTRATION_DATE] = 'registered';
        $columns[Constants::COLUMN_SCHOOL_INFO] = Constants::USER_META_SCHOOL_ID;
        $columns['user_incoming_total'] = '_user_incoming_total';
        
        return $columns;
    }

    /**
     * Handle sorting for custom columns
     * 
     * @param \WP_User_Query $query User query
     */
    public function handleCustomColumnsSorting(\WP_User_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        
        $orderby = $query->get('orderby');
        
        switch ($orderby) {
            case Constants::USER_META_SCHOOL_ID:
                $query->set('meta_key', Constants::USER_META_SCHOOL_ID);
                $query->set('orderby', 'meta_value_num');
                break;
                
            case '_user_msrp_total':
                $query->set('meta_key', '_user_msrp_total');
                $query->set('orderby', 'meta_value_num');
                break;
                
            case '_user_incoming_total':
                $query->set('meta_key', '_user_incoming_total');
                $query->set('orderby', 'meta_value_num');
                break;
        }
    }

    /**
     * Add advanced filters to users list
     */
    public function addAdvancedFiltersToUsersList($which): void
    {
        // Solo mostrar en la parte superior (top)
        if ($which !== 'top') {
            return;
        }
        
        // Si el usuario es teacher (y no super admin), no mostrar filtros
        if (current_user_can('teacher_role') && !current_user_can('administrator')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'users') {
            return;
        }

        // ⚠️ NO abrir <form> nuevo. Este hook ya está dentro del form del listado.
        $selected_school = isset($_GET['filter_school']) ? sanitize_text_field(wp_unslash($_GET['filter_school'])) : '';

        echo '<div class="alignleft actions" style="margin-top: 1px;">';

        // Select del filtro de escuelas (asegurar name="filter_school")
        echo '<label class="screen-reader-text" for="filter_school">' . esc_html__('Filter by school', 'neve-child') . '</label>';
        echo '<select id="filter_school" name="filter_school" style="min-width: 250px">';
        echo '<option value="">' . esc_html__('Search schools...', 'neve-child') . '</option>';

        if ($selected_school !== '' && is_numeric($selected_school)) {
            $school = get_post((int)$selected_school);
            if ($school && $school->post_status === 'publish') {
                $province = get_field(Constants::ACF_SCHOOL_PROVINCE, $school->ID);
                $city = get_field(Constants::ACF_SCHOOL_CITY, $school->ID);
                $location_parts = array_filter([$city, $province]);
                $display_text = $school->post_title . (!empty($location_parts) ? ' (' . implode(', ', $location_parts) . ')' : '');
                echo '<option value="' . esc_attr($selected_school) . '" selected>' . esc_html($display_text) . '</option>';
            }
        }
        echo '</select>';

        // Botón de submit del propio form del listado
        submit_button(__('Filter', 'neve-child'), 'secondary', 'filter_action', false);

        // Enlace limpiar (quita el parámetro y paginación)
        if ($selected_school !== '') {
            $clear_url = remove_query_arg(['filter_school', 'filter_action', 'paged']);
            echo ' <a class="button" href="' . esc_url($clear_url) . '">' . esc_html__('Clear', 'neve-child') . '</a>';
            
            // Mostrar información del filtro activo
            $user_count = $this->getUserCountBySchool((int)$selected_school);
            echo '<span class="school-filter-info" style="margin-left: 10px; color: #666; font-size: 12px; font-style: italic;">(' . 
                 sprintf(_n('%d user found', '%d users found', $user_count, 'neve-child'), $user_count) . 
                 ')</span>';
        }

        echo '</div>';
    }

    /**
     * Get user count for a specific school
     * 
     * @param int $school_id School ID
     * @return int User count
     */
    private function getUserCountBySchool(int $school_id): int
    {
        $users = get_users([
            'meta_key' => Constants::USER_META_SCHOOL_ID,
            'meta_value' => $school_id,
            'count_total' => true,
            'fields' => 'ids'
        ]);
        
        return is_array($users) ? count($users) : 0;
    }

    /**
     * Handle advanced filters in user query
     * 
     * @param \WP_User_Query $query User query
     */
    public function handleAdvancedFilters(\WP_User_Query $query): void
    {
        if (!is_admin()) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'users') {
            return;
        }

        $filter_school = isset($_GET['filter_school']) ? sanitize_text_field(wp_unslash($_GET['filter_school'])) : '';

        if ($filter_school !== '' && is_numeric($filter_school)) {
            $meta_query = (array) $query->get('meta_query');
            $meta_query[] = [
                'key'     => Constants::USER_META_SCHOOL_ID,
                'value'   => (int) $filter_school,
                'compare' => '='
            ];
            $query->set('meta_query', $meta_query);
            
            // (Opcional) Si necesitas compatibilidad con búsquedas y ordenaciones,
            // no toques otros args; WP se encarga de combinarlos.
        }

        // Filtro por rol (mantener funcionalidad existente)
        $filter_role = isset($_GET['filter_role']) ? sanitize_text_field(wp_unslash($_GET['filter_role'])) : '';
        if ($filter_role !== '') {
            $query->set('role', $filter_role);
        }
    }

    /**
     * Handle AJAX search for schools in admin
     */
    public function handleSchoolSearchAdmin(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'search_schools_admin')) {
            wp_die('Security check failed');
        }
        
        // Verificar permisos
        if (!current_user_can('manage_options') && !current_user_can('list_users')) {
            wp_die('Insufficient permissions');
        }
        
        $search_term = sanitize_text_field($_GET['q'] ?? $_POST['q'] ?? '');
        
        if (empty($search_term) || strlen($search_term) < 2) {
            wp_send_json([]);
        }
        
        try {
            $schools = get_posts([
                'post_type' => Constants::POST_TYPE_SCHOOL,
                'post_status' => 'publish',
                's' => $search_term,
                'posts_per_page' => 20,
                'orderby' => 'title',
                'order' => 'ASC'
            ]);
            
            $results = [];
            foreach ($schools as $school) {
                $province = get_field(Constants::ACF_SCHOOL_PROVINCE, $school->ID);
                $city = get_field(Constants::ACF_SCHOOL_CITY, $school->ID);
                $location = implode(', ', array_filter([$city, $province]));
                
                $results[] = [
                    'id' => $school->ID,
                    'text' => $school->post_title . ($location ? ' (' . $location . ')' : '')
                ];
            }
            
            wp_send_json($results);
            
        } catch (Exception $e) {
            wp_send_json([]);
        }
    }

    /**
     * Handle AJAX search for users by school
     */
    public function handleUsersBySchoolSearch(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $school_id = intval($_GET['school_id'] ?? 0);
        
        if (!$school_id) {
            wp_send_json([]);
        }
        
        $users = get_users([
            'meta_key' => Constants::USER_META_SCHOOL_ID,
            'meta_value' => $school_id,
            'number' => 50
        ]);
        
        $results = [];
        foreach ($users as $user) {
            $user_name = get_field(Constants::ACF_USER_NAME, 'user_' . $user->ID);
            $display_name = $user_name ? $user_name . ' (' . $user->user_login . ')' : $user->display_name;
            
            $results[] = [
                'id' => $user->ID,
                'text' => $display_name
            ];
        }
        
        wp_send_json($results);
    }

    /**
     * Enqueue admin assets
     */
    public function enqueueAdminAssets(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'users') {
            return;
        }
                // Cargar el archivo CSS principal con versión forzada
        $css_url = get_stylesheet_directory_uri() . '/custom/assets/css/user-admin-columns.css';
        $css_path = get_stylesheet_directory() . '/custom/assets/css/user-admin-columns.css';
        $css_version = file_exists($css_path) ? filemtime($css_path) : time(); // Usar timestamp actual para forzar recarga
        
        wp_enqueue_style(
            'user-admin-columns-css',
            $css_url,
            [],
            $css_version
        );

    }

    /**
     * Enqueue Select2 scripts for advanced filters
     */
    public function enqueueSelect2Scripts(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'users') {
            return;
        }
        
        // Enqueue Select2
        wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0', true);
        wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0');
        
        // Add custom CSS and JS for Select2 initialization
        wp_add_inline_style('select2', '
            .school-filter-container {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                margin-right: 15px;
            }
            .school-filter-container .select2-container {
                vertical-align: middle;
            }
            .school-filter-container .button {
                height: 30px;
                line-height: 28px;
                padding: 0 12px;
                font-size: 13px;
            }
            .school-filter-info {
                margin-left: 10px;
                color: #666;
                font-size: 12px;
                font-style: italic;
            }
        ');
        
        wp_add_inline_script('select2', '
            jQuery(document).ready(function($) {
                // Configuración mejorada de Select2 para el filtro de escuelas
                $("#filter_school").select2({
                    placeholder: "' . esc_js(__('Search schools...', 'neve-child')) . '",
                    allowClear: true,
                    minimumInputLength: 2,
                    width: "250px",
                    ajax: {
                        url: ajaxurl,
                        dataType: "json",
                        delay: 250,
                        method: "POST",
                        data: function (params) {
                            return {
                                action: "search_schools_admin",
                                q: params.term,
                                page: params.page || 1,
                                nonce: "' . wp_create_nonce('search_schools_admin') . '"
                            };
                        },
                        processResults: function (data, params) {
                            // El handler devuelve directamente un array
                            if (Array.isArray(data)) {
                                return {
                                    results: data,
                                    pagination: {
                                        more: false
                                    }
                                };
                            } else {
                                // Fallback para respuestas con formato de éxito/error
                                if (data && data.success && Array.isArray(data.data)) {
                                    return {
                                        results: data.data,
                                        pagination: {
                                            more: false
                                        }
                                    };
                                } else {
                                    console.error("Error en búsqueda de escuelas:", data);
                                    return { results: [] };
                                }
                            }
                        },
                        cache: true
                    }
                });
                
                // Logging de cambios para debugging
                $("#filter_school").on("change", function() {
                    var selectedValue = $(this).val();
                    
                    // Mostrar/ocultar botón clear
                    if (selectedValue) {
                        $("#clear_school_filter").show();
                    } else {
                        $("#clear_school_filter").hide();
                    }
                });
                
                // Limpiar filtro
                $("#clear_school_filter").on("click", function(e) {
                    e.preventDefault();
                    $("#filter_school").val(null).trigger("change");
                    $(this).closest("form").submit();
                });
                
                // Ocultar botón clear si no hay selección inicial
                if (!$("#filter_school").val()) {
                    $("#clear_school_filter").hide();
                }
                
            });
        ');
    }

    /**
     * Add custom row actions to user list
     * 
     * @param array $actions Existing actions
     * @param \WP_User $user_object User object
     * @return array Modified actions
     */
    public function addCustomUserRowActions(array $actions, \WP_User $user_object): array
    {
        if (!current_user_can('manage_options')) {
            return $actions;
        }
        
        $school_id = get_user_meta($user_object->ID, Constants::USER_META_SCHOOL_ID, true);
        if ($school_id) {
            $school_edit_url = admin_url('post.php?post=' . $school_id . '&action=edit');
            $actions['edit_school'] = '<a href="' . esc_url($school_edit_url) . '">Editar colegio</a>';
        }
        
        return $actions;
    }
}

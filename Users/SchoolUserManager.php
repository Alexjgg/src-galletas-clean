<?php
/**
 * School User Manager for managing user-school relationships
 * 
 * Gestiona el registro de usuarios con códigos de centro,
 * asignación automática de centros y roles de estudiante
 * 
 * @package SchoolManagement\Users
 * @since 1.0.0
 */

namespace SchoolManagement\Users;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for managing school-user relationships and registration
 */
class SchoolUserManager
{
    /**
     * School post type
     */
    private const POST_TYPE = 'coo_school';

    /**
     * Constructor
     */
    public function __construct()
    {
        // Generar clave al crear un nuevo centro escolar
        add_action('save_post_' . self::POST_TYPE, [$this, 'generateSchoolKey'], 10, 3);

        // Añadir campos al formulario de registro de WooCommerce
        add_action('woocommerce_register_form', [$this, 'addUserRegistrationFields'], 60);

        // Validar clave durante el registro de WooCommerce
        add_filter('woocommerce_registration_errors', [$this, 'validateSchoolKeyWoocommerce'], 10, 3);

        // Asociar usuario al centro después del registro de WooCommerce
        add_action('woocommerce_created_customer', [$this, 'saveUserSchoolRelation']);

        // Añadir campos de centro en el perfil de administración
        add_action('show_user_profile', [$this, 'addSchoolFieldToUserProfile']);
        add_action('edit_user_profile', [$this, 'addSchoolFieldToUserProfile']);

        // Guardar el campo de centro en el perfil
        add_action('personal_options_update', [$this, 'saveSchoolFieldInUserProfile']);
        add_action('edit_user_profile_update', [$this, 'saveSchoolFieldInUserProfile']);

        // Inicializar campos ACF
        add_action('acf/init', [$this, 'createUserACFFields']);

        // Validación de número de usuario en ACF
        add_filter('acf/validate_value/name=user_number', [$this, 'validateUserNumber'], 10, 4);

        // Handler AJAX para búsqueda de escuelas en perfil de usuario
        add_action('wp_ajax_search_schools_admin', [$this, 'handleSchoolSearchAjax']);

    }

    /**
     * Create ACF fields for users
     */
    public function createUserACFFields(): void
    {
        if (!function_exists('acf_add_local_field_group')) {
            return;
        }

        $target_group_key = 'group_68ac0c520bf69';
        $existing_group = acf_get_field_group($target_group_key);
        
        if ($existing_group) {
            $this->addFieldsToCooUserGroup($target_group_key);
        }
    }

    /**
     * Add fields to existing coo_user group
     */
    private function addFieldsToCooUserGroup(string $group_key): void
    {
        $existing_fields = acf_get_fields($group_key);
        $existing_field_names = array_column($existing_fields, 'name');

        $fields_to_add = [
            [
                'key' => 'field_user_name_' . substr($group_key, -8),
                'label' => 'Nombre del alumno',
                'name' => 'user_name',
                'type' => 'text',
                'required' => 0,
                'parent' => $group_key,
            ],
            [
                'key' => 'field_user_first_surname_' . substr($group_key, -8),
                'label' => 'Primer apellido del alumno',
                'name' => 'user_first_surname',
                'type' => 'text',
                'required' => 0,
                'parent' => $group_key,
            ],
            [
                'key' => 'field_user_second_surname_' . substr($group_key, -8),
                'label' => 'Segundo apellido del alumno',
                'name' => 'user_second_surname',
                'type' => 'text',
                'required' => 0,
                'parent' => $group_key,
            ],
            [
                'key' => 'field_user_number_' . substr($group_key, -8),
                'label' => 'Número de alumno',
                'name' => 'user_number',
                'type' => 'number',
                'required' => 0,
                'min' => 1,
                'instructions' => 'Número único del alumno dentro del colegio. Se asigna automáticamente, pero se puede cambiar manualmente.',
                'parent' => $group_key,
            ],
        ];

        foreach ($fields_to_add as $field) {
            if (!in_array($field['name'], $existing_field_names)) {
                acf_add_local_field($field);
            }
        }
    }

    /**
     * Generate random key for a school when creating it
     */
    public function generateSchoolKey(int $post_id, \WP_Post $post, bool $update): void
    {
        if ($update) {
            return;
        }

        $existing_key = get_post_meta($post_id, 'school_key', true);
        
        if (empty($existing_key)) {
            $key = $this->generateUniqueKey();
            
            // Usar ACF si está disponible
            if (function_exists('update_field')) {
                update_field('school_key', $key, $post_id);
            } else {
                update_post_meta($post_id, 'school_key', $key);
            }
        }
    }

    /**
     * Add user registration fields to WooCommerce registration form
     * 
     * @return void
     */
    public function addUserRegistrationFields(): void
    {
        $school_key_value = isset($_GET['school_key']) ? sanitize_text_field($_GET['school_key']) : '';
        
        // Inicializar variables para los campos del formulario
        $user_name = isset($_POST['user_name']) ? sanitize_text_field($_POST['user_name']) : '';
        $user_first_surname = isset($_POST['user_first_surname']) ? sanitize_text_field($_POST['user_first_surname']) : '';
        $user_second_surname = isset($_POST['user_second_surname']) ? sanitize_text_field($_POST['user_second_surname']) : '';
        
        ?>
        <!-- Campos de información personal -->
                <!-- Nombre del alumno -->
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="reg_user_name"><?php esc_html_e('Student name', 'neve-child'); ?> <span class="required">*</span></label>
            <input type="text" 
                   class="woocommerce-Input woocommerce-Input--text input-text" 
                   name="user_name" 
                   id="reg_user_name" 
                   value="<?php echo esc_attr($user_name); ?>" 
                   required />
        </p>

        <!-- Apellidos del alumno -->
        <div style="display: flex; gap: 10px;">
            <p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first" style="flex: 1;">
                <label for="reg_user_first_surname"><?php esc_html_e('Student first surname', 'neve-child'); ?> <span class="required">*</span></label>
                <input type="text" 
                       class="woocommerce-Input woocommerce-Input--text input-text" 
                       name="user_first_surname" 
                       id="reg_user_first_surname" 
                       value="<?php echo esc_attr($user_first_surname); ?>" 
                       required />
            </p>

            <p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last" style="flex: 1;">
                <label for="reg_user_second_surname"><?php esc_html_e('Student second surname', 'neve-child'); ?></label>
                <input type="text" 
                       class="woocommerce-Input woocommerce-Input--text input-text" 
                       name="user_second_surname" 
                       id="reg_user_second_surname" 
                       value="<?php echo esc_attr($user_second_surname); ?>" />
            </p>
        </div>

        

        <!-- Campo de clave de centro -->
        <?php
        // Verificar si viene con school_key para mostrar nombre de escuela
        $school_name = '';
        $field_disabled = false;
        
        if (!empty($school_key_value)) {
            $school_id = $this->findSchoolByKey($school_key_value);
            if ($school_id) {
                $school_post = get_post($school_id);
                if ($school_post) {
                    $school_name = $school_post->post_title;
                    $field_disabled = true;
                }
            }
        }
        ?>
        
        <?php if ($field_disabled && !empty($school_name)): ?>
            <!-- Mostrar nombre de escuela cuando viene por QR -->
            <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                <label for="school_display"><?php esc_html_e('School', 'neve-child'); ?></label>
                <input type="text" 
                       class="woocommerce-Input woocommerce-Input--text input-text" 
                       id="school_display" 
                       value="<?php echo esc_attr($school_name); ?>" 
                       disabled 
                       style="background-color: #f5f5f5; color: #666;" />
                <input type="hidden" name="school_key" value="<?php echo esc_attr($school_key_value); ?>" />
                <small style="color: #666; font-style: italic;">
                    <?php esc_html_e('School automatically selected from QR code', 'neve-child'); ?>
                </small>
            </p>
        <?php else: ?>
            <!-- Campo normal de clave de centro -->
            <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                <label for="school_key"><?php esc_html_e('School key', 'neve-child'); ?>&nbsp;<span class="required">*</span></label>
                <input type="text" 
                       class="woocommerce-Input woocommerce-Input--text input-text" 
                       name="school_key" 
                       id="school_key" 
                       value="<?php echo esc_attr($school_key_value); ?>" 
                       required />
                <small style="color: #666;">
                    <?php esc_html_e('Enter the school key provided by your educational center', 'neve-child'); ?>
                </small>
            </p>
        <?php endif; ?>
        <?php
    }

    /**
     * Validate user registration fields during WooCommerce registration
     * 
     * @param \WP_Error $errors Error object
     * @param string $username Username
     * @param string $email Email
     * @return \WP_Error
     */
    public function validateSchoolKeyWoocommerce(\WP_Error $errors, string $username, string $email): \WP_Error
    {
        // Validar nombre del alumno
        if (empty($_POST['user_name'])) {
            $errors->add('user_name_empty', __('<strong>Error</strong>: Student name is required.', 'neve-child'));
        }

        // Validar primer apellido del alumno
        if (empty($_POST['user_first_surname'])) {
            $errors->add('user_first_surname_empty', __('<strong>Error</strong>: Student first surname is required.', 'neve-child'));
        }

        // Validar clave de centro
        if (empty($_POST['school_key'])) {
            $errors->add('school_key_empty', __('<strong>Error</strong>: You must indicate the school key.', 'neve-child'));
        } else {
            $key = sanitize_text_field($_POST['school_key']);
            $school_id = $this->findSchoolByKey($key);
            
            if (!$school_id) {
                $errors->add('school_key_invalid', __('<strong>Error</strong>: The school key is not valid.', 'neve-child'));
            }
        }
        
        return $errors;
    }

    /**
     * Save user-school relationship after user creation
     */
    public function saveUserSchoolRelation(int $user_id): void
    {
        if (!empty($_POST['user_name'])) {
            $user_name = sanitize_text_field($_POST['user_name']);
            if (function_exists('update_field')) {
                update_field('user_name', $user_name, 'user_' . $user_id);
            }
        }

        if (!empty($_POST['user_first_surname'])) {
            $user_first_surname = sanitize_text_field($_POST['user_first_surname']);
            if (function_exists('update_field')) {
                update_field('user_first_surname', $user_first_surname, 'user_' . $user_id);
            }
        }

        if (!empty($_POST['user_second_surname'])) {
            $user_second_surname = sanitize_text_field($_POST['user_second_surname']);
            if (function_exists('update_field')) {
                update_field('user_second_surname', $user_second_surname, 'user_' . $user_id);
            }
        }

        if (!empty($_POST['school_key'])) {
            $key = sanitize_text_field($_POST['school_key']);
            $school_id = $this->findSchoolByKey($key);

            if ($school_id) {
                update_user_meta($user_id, 'school_id', $school_id);

                $user_number = $this->getNextUserNumber($school_id);
                if (function_exists('update_field')) {
                    update_field('user_number', $user_number, 'user_' . $user_id);
                }

                $user = new \WP_User($user_id);
                $user->set_role('student');
            }
        }
    }

    /**
     * Get registration URL with school key
     */
    public function getRegisterUrlWithKey(int $school_id): ?string
    {
        $key = get_post_meta($school_id, 'school_key', true);
        if (empty($key)) {
            return null;
        }

        $my_account_page = get_option('woocommerce_myaccount_page_id');
        if (!$my_account_page) {
            return null;
        }

        return add_query_arg(
            ['school_key' => $key],
            get_permalink($my_account_page)
        );
    }

    /**
     * Add school field to user profile
     * 
     * @param \WP_User $user User object
     * @return void
     */
    public function addSchoolFieldToUserProfile(\WP_User $user): void
{
    // No mostrar el campo de escuela si el usuario actual es profesor (teacher)
    // Solo administradores y shop_managers pueden editar la asignación de escuela
    if (!current_user_can('administrator') && !current_user_can('shop_manager')) {
        return;
    }
    
    $school_id = get_user_meta($user->ID, 'school_id', true);
    $selected_school = $school_id ? get_post($school_id) : null;
    
    // Generar nonce para usar el handler existente de UserAdminColumnsManager
    $ajax_nonce = wp_create_nonce('search_schools_admin');
    
    // Usar Select2 de WooCommerce si está disponible, sino cargar desde CDN
    if (function_exists('WC') && wp_script_is('selectWoo', 'registered')) {
        wp_enqueue_script('selectWoo');
        wp_enqueue_style('select2');
    } else {
        wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0', true);
        wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0');
    }
    ?>
    <h3><?php _e('School Information', 'neve-child'); ?></h3>
    <table class="form-table school_form">
        <tr>
            <th><label for="school_id"><?php _e('School', 'neve-child'); ?></label></th>
            <td>
                <select name="school_id" id="school_id_<?php echo $user->ID; ?>" style="width: 100%; max-width: 400px;">
                    <option value=""><?php _e('Select school...', 'neve-child'); ?></option>
                    <?php if ($selected_school && $selected_school->post_status === 'publish'): ?>
                        <option value="<?php echo esc_attr($selected_school->ID); ?>" selected="selected">
                            <?php echo esc_html($selected_school->post_title); ?>
                        </option>
                    <?php endif; ?>
                </select>
                <p class="description"><?php _e('School that the user belongs to. You can search by typing the name.', 'neve-child'); ?></p>
            </td>
        </tr>
    </table>
    
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Datos embebidos directamente desde PHP
        var ajaxConfig = {
            url: <?php echo json_encode(admin_url('admin-ajax.php')); ?>,
            nonce: <?php echo json_encode($ajax_nonce); ?>
        };
        
        $('#school_id_<?php echo $user->ID; ?>').select2({
            placeholder: <?php echo json_encode(__('Search school...', 'neve-child')); ?>,
            allowClear: true,
            minimumInputLength: 2,
            language: {
                noResults: function() { return <?php echo json_encode(__('No schools found', 'neve-child')); ?>; },
                searching: function() { return <?php echo json_encode(__('Searching...', 'neve-child')); ?>; },
                inputTooShort: function() { return <?php echo json_encode(__('Type at least 2 characters', 'neve-child')); ?>; },
                errorLoading: function() { return <?php echo json_encode(__('Error loading results', 'neve-child')); ?>; }
            },
            ajax: {
                url: ajaxConfig.url,
                dataType: 'json',
                delay: 250,
                method: 'POST',
                data: function(params) {
                    var data = {
                        action: 'search_schools_admin',
                        q: params.term, // Cambiar de 'search' a 'q' para coincidir con el handler
                        page: params.page || 1,
                        nonce: ajaxConfig.nonce
                    };
                    return data;
                },
                processResults: function(data, params) {
                    // El handler devuelve directamente un array, no un objeto con success
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
                            return { results: [] };
                        }
                    }
                },
                cache: true
            }
        });
    });
    </script>
    
    <style>
        .school_form span{
            max-width: 300px;
        }
    </style>
    <?php
}

    /**
     * Save school field in user profile
     * 
     * @param int $user_id User ID
     * @return void
     */
    public function saveSchoolFieldInUserProfile(int $user_id): void
    {
        // Solo permitir a administradores y shop_managers editar la asignación de escuela
        if (!current_user_can('administrator') && !current_user_can('shop_manager')) {
            return;
        }
        
        if (current_user_can('edit_user', $user_id)) {
            $school_id = isset($_POST['school_id']) ? intval($_POST['school_id']) : '';
            update_user_meta($user_id, 'school_id', $school_id);
        }
    }

    /**
     * Find school by key
     * 
     * @param string $key School key
     * @return int|null School ID or null
     */
    private function findSchoolByKey(string $key): ?int
    {
        $query = new \WP_Query([
            'post_type' => self::POST_TYPE,
            'meta_key' => 'school_key',
            'meta_value' => $key,
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => 1,
        ]);

        return $query->have_posts() ? $query->posts[0] : null;
    }

    /**
     * Validate user number field in ACF to prevent duplicates within same school
     * 
     * @param bool $valid Current validation status
     * @param mixed $value Field value being validated
     * @param array $field Field configuration
     * @param string $input Input name
     * @return bool|string True if valid, error message if invalid
     */
    public function validateUserNumber($valid, $value, $field, $input): bool|string
    {
        // Si ya hay un error de validación, mantenerlo
        if (!$valid) {
            return $valid;
        }

        // Si el valor está vacío, es válido (se asignará automáticamente)
        if (empty($value)) {
            return true;
        }

        // Convertir a entero y validar que sea positivo
        $user_number = intval($value);
        if ($user_number <= 0) {
            return 'El número de alumno debe ser mayor que 0';
        }

        // Obtener el ID del usuario actual desde diferentes contextos
        $user_id = 0;
        
        // Contexto 1: URL de edición de usuario (?user_id=X)
        if (isset($_GET['user_id'])) {
            $user_id = intval($_GET['user_id']);
        }
        
        // Contexto 2: POST data durante el guardado
        if (!$user_id && isset($_POST['user_id'])) {
            $user_id = intval($_POST['user_id']);
        }
        
        // Contexto 3: Desde el input name (formato: acf[field_xxx])
        if (!$user_id && strpos($input, 'user_') === 0) {
            // Extraer user_id del input name si existe
            preg_match('/user_(\d+)/', $input, $matches);
            if (!empty($matches[1])) {
                $user_id = intval($matches[1]);
            }
        }
        
        // Contexto 4: Variable global de WordPress
        if (!$user_id && function_exists('get_queried_object_id')) {
            $queried_id = get_queried_object_id();
            if ($queried_id && get_userdata($queried_id)) {
                $user_id = $queried_id;
            }
        }

        // Si no podemos determinar el usuario, permitir la validación
        if (!$user_id) {
            return true;
        }

        // Obtener la escuela del usuario
        $school_id = get_user_meta($user_id, 'school_id', true);
        if (!$school_id) {
            return true; // Sin escuela asignada, no podemos validar duplicados
        }

        // Verificar si ya existe otro usuario con este número en la misma escuela
        $existing_user = $this->findUserByNumber($user_number, intval($school_id));
        
        if ($existing_user && $existing_user->ID !== $user_id) {
            $school_name = get_the_title($school_id);
            return sprintf(
                'El número %d ya está asignado a otro alumno (%s) en el colegio "%s"',
                $user_number,
                $existing_user->display_name,
                $school_name
            );
        }

        return true;
    }

    /**
     * Find user by user number within a specific school
     * 
     * @param int $user_number User number
     * @param int $school_id School ID
     * @return \WP_User|null User object or null
     */
    private function findUserByNumber(int $user_number, int $school_id): ?\WP_User
    {
        $users = get_users([
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'user_number',
                    'value' => $user_number,
                    'compare' => '='
                ],
                [
                    'key' => 'school_id',
                    'value' => $school_id,
                    'compare' => '='
                ]
            ],
            'number' => 1
        ]);

        return !empty($users) ? $users[0] : null;
    }

    /**
     * Get next user number for school (with concurrency protection)
     */
    private function getNextUserNumber(int $school_id): int
    {
        global $wpdb;
        
        // Usar transacción para evitar problemas de concurrencia
        $wpdb->query('START TRANSACTION');
        
        try {
            // Buscar el último número con bloqueo
            $last_number = $wpdb->get_var($wpdb->prepare("
                SELECT MAX(CAST(um1.meta_value AS UNSIGNED)) 
                FROM {$wpdb->usermeta} um1
                INNER JOIN {$wpdb->usermeta} um2 ON um1.user_id = um2.user_id
                WHERE um1.meta_key = 'user_number' 
                AND um2.meta_key = 'school_id' 
                AND um2.meta_value = %d
                AND um1.meta_value REGEXP '^[0-9]+$'
                FOR UPDATE
            ", $school_id));

            $next_number = intval($last_number) + 1;
            
            // Verificar que el número no esté ya en uso
            $exists = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) 
                FROM {$wpdb->usermeta} um1
                INNER JOIN {$wpdb->usermeta} um2 ON um1.user_id = um2.user_id
                WHERE um1.meta_key = 'user_number' 
                AND um1.meta_value = %d
                AND um2.meta_key = 'school_id' 
                AND um2.meta_value = %d
            ", $next_number, $school_id));
            
            // Si ya existe, buscar el siguiente disponible
            while ($exists > 0) {
                $next_number++;
                $exists = $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(*) 
                    FROM {$wpdb->usermeta} um1
                    INNER JOIN {$wpdb->usermeta} um2 ON um1.user_id = um2.user_id
                    WHERE um1.meta_key = 'user_number' 
                    AND um1.meta_value = %d
                    AND um2.meta_key = 'school_id' 
                    AND um2.meta_value = %d
                ", $next_number, $school_id));
            }
            
            $wpdb->query('COMMIT');
            return $next_number;
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return $this->getFallbackUserNumber($school_id);
        }
    }
    
    /**
     * Fallback method to get user number
     */
    private function getFallbackUserNumber(int $school_id): int
    {
        $users = get_users([
            'meta_query' => [
                [
                    'key' => 'school_id',
                    'value' => $school_id,
                    'compare' => '='
                ]
            ],
            'fields' => 'ID'
        ]);
        
        $used_numbers = [];
        foreach ($users as $user_id) {
            $number = get_user_meta($user_id, 'user_number', true);
            if ($number && is_numeric($number)) {
                $used_numbers[] = intval($number);
            }
        }
        
        if (empty($used_numbers)) {
            return 1;
        }
        
        sort($used_numbers);
        return max($used_numbers) + 1;
    }

    /**
     * Generate unique key
     * 
     * @return string
     */
    private function generateUniqueKey(): string
    {
        do {
            $key = wp_generate_password(12, false, false);
        } while ($this->findSchoolByKey($key));
        
        return $key;
    }

    /**
     * Handle AJAX search for schools in user profile
     * 
     * @return void
     */
    public function handleSchoolSearchAjax(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'search_schools_admin')) {
            wp_die('Security check failed');
        }

        // Solo permitir a administradores y shop_managers
        if (!current_user_can('administrator') && !current_user_can('shop_manager')) {
            wp_die('Insufficient permissions');
        }

        $search_term = sanitize_text_field($_GET['q'] ?? $_POST['q'] ?? '');
        
        if (empty($search_term)) {
            wp_send_json([]);
        }

        $schools = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            's' => $search_term,
            'posts_per_page' => 20,
            'orderby' => 'title',
            'order' => 'ASC'
        ]);

        $results = [];
        foreach ($schools as $school) {
            // Obtener información adicional si está disponible
            $province = '';
            $city = '';
            
            if (function_exists('get_field')) {
                $province = get_field('province', $school->ID);
                $city = get_field('city', $school->ID);
            }
            
            $location = implode(', ', array_filter([$city, $province]));
            
            $results[] = [
                'id' => $school->ID,
                'text' => $school->post_title . ($location ? ' (' . $location . ')' : '')
            ];
        }

        wp_send_json($results);
    }
}

<?php
/**
 * Validador de Formulario para Post Type 'coo_school' - School
 * Archivo para validar y procesar datos del formulario de centros educativos
 * 
 * @package SchoolManagement\Schools
 * @since 1.0.0
 */

namespace SchoolManagement\Schools;

use Factupress\Facturae\Api\CensusBridge;

/**
 * Clase para validar formularios de centros educativos (post_type: coo_school)
 * Ahora valida todos los posts de tipo 'coo_school' en lugar de un post específico
 */
class FormValidator {
    
    /**
     * ID del post que contiene el formulario
     */
    private $post_id;
    
    /**
     * Errores de validación
     */
    private $errors = [];
    
    /**
     * Advertencias de validación
     */
    private $warnings = [];
    
    /**
     * Mensajes de éxito
     */
    private $success_messages = [];
    
    /**
     * Constructor
     * 
     * @param int|null $post_id ID del post del formulario (opcional, ahora se valida por post_type)
     */
    public function __construct($post_id = null) {
        $this->post_id = $post_id;
        $this->init_hooks();
    }
    
    /**
     * Inicializar hooks de WordPress
     */
    private function init_hooks() {
        // Hook para validar cuando se guarda el post (PRIORIDAD ALTA como el Vendor)
        add_action('acf/save_post', [$this, 'validateSchoolBeforeSave'], 1);
        
        // Admin notices para mostrar errores de validación
        add_action('admin_notices', [$this, 'showValidationErrors']);
    }
    
    /**
     * Validate school data before saving post (SERVER-SIDE BLOCKING)
     * Ahora valida todos los posts de tipo 'coo_school' en lugar de un post específico
     * 
     * @param int $post_id Post ID being saved
     */
    public function validateSchoolBeforeSave($post_id): void
    {
        // Obtener información del post
        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        // Solo validar posts de tipo 'coo_school'
        if ($post->post_type !== 'coo_school') {
            return;
        }

        // Skip autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Skip si es una revisión
        if (wp_is_post_revision($post_id)) {
            return;
        }
        
        // Actualizar el post_id actual para las validaciones
        $this->post_id = $post_id;
        
        // Recopilar datos del formulario ACF desde $_POST
        $form_data = $this->collectFormDataFromPost();
        
        // Realizar validación completa
        $validation_result = $this->validate_form_data($form_data);
        
        // Si hay errores, BLOQUEAR el guardado (estrategia del Vendor)
        if (!$validation_result['is_valid']) {
            // Guardar errores para mostrar en la misma página (como VendorFieldValidator)
            set_transient('school_validation_errors_' . $post_id, $validation_result['errors'], 300);
            set_transient('school_validation_blocked_' . $post_id, true, 300);
            
            // Redireccionar de vuelta al formulario con errores
            $redirect_url = admin_url('post.php?post=' . $post_id . '&action=edit');
            wp_redirect($redirect_url);
            exit;
        }
    }
    
    /**
     * Recopilar datos del formulario desde $_POST (como VendorFieldValidator)
     */
    private function collectFormDataFromPost(): array
    {
        $form_data = [];
        
        if (!isset($_POST['acf']) || !is_array($_POST['acf'])) {
            return $form_data;
        }
        
        foreach ($_POST['acf'] as $field_key => $value) {
            // Intentar encontrar el nombre del campo
            $field_name = $this->getFieldNameFromKey($field_key);
            
            if ($field_name) {
                $form_data[$field_name] = $value;
            }
        }
        
        return $form_data;
    }
    
    /**
     * Obtener nombre del campo desde la clave ACF
     */
    private function getFieldNameFromKey(string $field_key): ?string
    {
        $field_object = get_field_object($field_key);
        
        if ($field_object && isset($field_object['name'])) {
            return $field_object['name'];
        }
        
        return null;
    }
    
    /**
     * Mostrar errores de validación como admin notices (como VendorFieldValidator)
     */
    public function showValidationErrors(): void
    {
        global $post;

        if (!$post) {
            return;
        }

        // Solo mostrar errores para posts de tipo 'coo_school'
        if ($post->post_type !== 'coo_school') {
            return;
        }

        $errors = get_transient('school_validation_errors_' . $post->ID);
        $was_blocked = get_transient('school_validation_blocked_' . $post->ID);

        if ($errors && $was_blocked) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>❌ ' . esc_html__('Form validation errors:', 'neve-child') . '</strong></p>';
            echo '<ul>';
            foreach ($errors as $error) {
                echo '<li>' . esc_html($error) . '</li>';
            }
            echo '</ul>';
            echo '<p><em>' . esc_html__('Please correct these errors before saving.', 'neve-child') . '</em></p>';
            echo '</div>';
            
            // Limpiar transients después de mostrar
            delete_transient('school_validation_errors_' . $post->ID);
            delete_transient('school_validation_blocked_' . $post->ID);
        }
    }
    
    /**
     * Validar cuando se guarda un post
     */
    public function validate_on_post_save($post_id, $post, $update) {
        // Solo validar posts de tipo 'coo_school'
        if ($post->post_type !== 'coo_school') {
            return;
        }
        
        // Actualizar el post_id actual
        $this->post_id = $post_id;
        
        // Obtener datos ACF actuales
        $acf_data = get_fields($post_id);
        
        if ($acf_data) {
            // Validar los datos
            $results = $this->validate_form_data($acf_data);
        }
    }
    
    /**
     * Validar cuando se guardan campos ACF
     */
    public function validate_on_acf_save($post_id) {
        // Obtener información del post
        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        // Solo validar posts de tipo 'coo_school'
        if ($post->post_type !== 'coo_school') {
            return;
        }
        
        // Actualizar el post_id actual
        $this->post_id = $post_id;
        
        // Los datos ACF pueden no estar aún disponibles aquí, usar $_POST
        $acf_data = [];
        
        // Extraer campos ACF del $_POST
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'acf[') === 0) {
                // Extraer el nombre del campo ACF
                preg_match('/acf\[([^\]]+)\]/', $key, $matches);
                if (isset($matches[1])) {
                    $field_name = $matches[1];
                    $acf_data[$field_name] = $value;
                }
            }
        }
        
        if (!empty($acf_data)) {
            // Validar los datos
            $results = $this->validate_form_data($acf_data);
        }
    }
    
    /**
     * Validar datos del formulario
     * 
     * @param array $data Datos a validar
     * @return array Resultado de la validación
     */
    public function validate_form_data($data) {
        $this->reset_validation_state();
        
        // Validar que el post existe
        $post = get_post($this->post_id);
        if (!$post) {
            $this->errors[] = sprintf(__('Post %d does not exist', 'neve-child'), $this->post_id);
            return $this->get_validation_results();
        }
        
        $this->success_messages[] = sprintf(__('Post %d found successfully', 'neve-child'), $this->post_id);
        
        // Obtener configuración de campos ACF
        $acf_fields = get_fields($this->post_id);
        
        if (empty($data)) {
            $this->warnings[] = __('No data provided for validation', 'neve-child');
            return $this->get_validation_results();
        }
        
        // *** ANÁLISIS DE LÓGICA CONDICIONAL ***
        $billing_by_school = $this->get_billing_by_school_value($data);
        
        // Validar cada campo
        foreach ($data as $field_name => $field_value) {
            $this->validate_individual_field($field_name, $field_value, $data);
        }
        
        $results = $this->get_validation_results();
        
        return $results;
    }
    
    /**
     * Validar un campo individual
     * 
     * @param string $field_name Nombre del campo
     * @param mixed $field_value Valor del campo
     * @param array $all_data Todos los datos para contexto
     */
    private function validate_individual_field($field_name, $field_value, $all_data = []) {
        // *** LÓGICA CONDICIONAL: Solo validar campos de billing si the_billing_by_the_school está activo ***
        $billing_by_school = $this->get_billing_by_school_value($all_data);
        $is_billing_field = $this->is_billing_field($field_name);
        $is_optional_field = $this->is_optional_field($field_name);
        
        if ($is_billing_field && !$billing_by_school) {
            $this->success_messages[] = sprintf(__('Field \'%s\' skipped (billing disabled)', 'neve-child'), $field_name);
            return;
        }
        
        // Validaciones básicas (solo para campos obligatorios)
        if (empty($field_value) && $field_value !== '0' && $field_value !== 0) {
            // Solo marcar error si es un campo obligatorio
            if ($this->is_required_field($field_name) || ($is_billing_field && $billing_by_school && !$is_optional_field)) {
                $this->errors[] = sprintf(__('Field \'%s\' is empty', 'neve-child'), $field_name);
                return;
            } else {
                // Si es opcional y está vacío, no validar más
                if ($is_optional_field) {
                    $this->success_messages[] = sprintf(__('Field \'%s\' empty (optional field)', 'neve-child'), $field_name);
                }
                return;
            }
        }
        
        // Validaciones específicas por nombre de campo
        switch (strtolower($field_name)) {
            case 'email':
            case 'correo':
            case 'email_contacto':
                $this->validate_email($field_name, $field_value);
                break;
                
            case 'telefono':
            case 'phone':
            case 'movil':
                $this->validate_phone($field_name, $field_value);
                break;
                
            case 'nif':
            case 'dni':
                $this->validate_spanish_id($field_name, $field_value);
                break;
                
            case 'cif':
                $this->validate_cif_or_nif_with_census($field_name, $field_value, $all_data);
                break;
                
            case 'codigo_postal':
            case 'cp':
            case 'postal_code':
            case 'postcode':
                $this->validate_postal_code($field_name, $field_value);
                break;
                
            case 'url':
            case 'website':
            case 'web':
                $this->validate_url($field_name, $field_value);
                break;
                
            case 'nombre_centro':
            case 'nombre_colegio':
            case 'school_name':
                $this->validate_school_name($field_name, $field_value);
                break;
                
            // Campos específicos del ACF del post 138
            case 'school_key':
                $this->validate_school_key($field_name, $field_value);
                break;
                
            case 'vendor':
                $this->validate_vendor($field_name, $field_value);
                break;
                
            case 'the_billing_by_the_school':
                $this->validate_boolean_field($field_name, $field_value);
                break;
                
            case 'company':
                $this->validate_company_name($field_name, $field_value);
                break;
                
            case 'address':
                $this->validate_address($field_name, $field_value);
                break;
                
            case 'city':
                $this->validate_city($field_name, $field_value);
                break;
                
            case 'state':
                $this->validate_state($field_name, $field_value);
                break;
        }
        
        // Validaciones basadas en campos ACF
        $acf_fields = get_fields($this->post_id);
        if (!empty($acf_fields) && isset($acf_fields[$field_name])) {
            $this->validate_acf_field($field_name, $field_value);
        }
        
        // Si llegamos aquí sin errores, el campo es válido
        if (!$this->has_field_errors($field_name)) {
            $this->success_messages[] = sprintf(__('Field \'%s\' is valid', 'neve-child'), $field_name);
        }
    }
    
    /**
     * Obtener valor del campo the_billing_by_the_school
     */
    private function get_billing_by_school_value($form_data = []) {
        // Primero intentar desde los datos actuales de validación
        if (isset($form_data['the_billing_by_the_school'])) {
            $value = $form_data['the_billing_by_the_school'];
            $result = ($value === '1' || $value === 1 || $value === true);
            return $result;
        }
        
        // Si no está disponible, obtener desde el post actual
        $value = get_field('the_billing_by_the_school', $this->post_id);
        $result = ($value === '1' || $value === 1 || $value === true);
        return $result;
    }
    
    /**
     * Verificar si un campo es de billing
     */
    private function is_billing_field($field_name) {
        $billing_fields = [
            'company',
            'address', 
            'city',
            'state',
            'postcode'
        ];
        
        return in_array(strtolower($field_name), $billing_fields);
    }
    
    /**
     * Verificar si un campo es opcional (puede estar vacío)
     */
    private function is_optional_field($field_name) {
        $optional_fields = [
            'email',
            'phone'
        ];
        
        return in_array(strtolower($field_name), $optional_fields);
    }
    
    /**
     * Verificar si un campo es siempre obligatorio (independiente del billing)
     */
    private function is_required_field($field_name) {
        $always_required = [
            'school_key',
            'vendor'
        ];
        
        return in_array(strtolower($field_name), $always_required);
    }
    
    /**
     * Validar email
     */
    private function validate_email($field_name, $value) {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = sprintf(__('Invalid email format for \'%s\'', 'neve-child'), $field_name);
        }
    }
    
    /**
     * Validar teléfono
     */
    private function validate_phone($field_name, $value) {
        if (!preg_match('/^[+]?[0-9\s\-\(\)]+$/', $value)) {
            $this->errors[] = sprintf(__('Invalid phone format for \'%s\'', 'neve-child'), $field_name);
        }
    }
    
    /**
     * Validar NIF/NIE español
     */
    private function validate_spanish_id($field_name, $value) {
        if (!$this->is_valid_spanish_id($value)) {
            $this->errors[] = sprintf(__('Invalid Spanish ID format for \'%s\'', 'neve-child'), $field_name);
        }
    }
    
    /**
     * Validar NIF/NIE con validación oficial del censo
     * Similar al VendorFieldValidator, pero siempre usa el campo 'company' para validar
     * 
     * @param string $field_name Nombre del campo
     * @param string $cif_value Valor del NIF/NIE
     * @param array $all_data Todos los datos del formulario para obtener el company
     */
    private function validate_cif_with_census($field_name, $cif_value, $all_data = []) {
        $cif = trim($cif_value);
        
        if (empty($cif)) {
            $this->errors[] = sprintf(__('NIF/NIE is required for \'%s\'', 'neve-child'), $field_name);
            return;
        }
        
        // Primero validar formato del NIF/NIE
        if (!$this->isValidCIF($cif)) {
            $this->errors[] = sprintf(__('Invalid NIF/NIE format for \'%s\'. Must follow format: letter + 7 digits + control character', 'neve-child'), $field_name);
            return;
        }
        
        // Obtener el nombre de la empresa desde el campo 'company'
        $company_name = '';
        if (isset($all_data['company'])) {
            $company_name = trim($all_data['company']);
        } else {
            // Si no está en los datos, intentar obtenerlo desde ACF
            $company_name = trim(get_field('company', $this->post_id) ?? '');
        }
        
        if (empty($company_name)) {
            $this->errors[] = __('Company name is required to validate NIF/NIE with official census', 'neve-child');
            return;
        }
        
        // Realizar validación oficial con censo
        $this->validateWithOfficialCensus($cif, $company_name);
    }
    
    /**
     * Validar NIF/NIE con validación oficial del censo
     * Acepta tanto NIF/NIE (empresas) como NIF/NIE (personas físicas)
     * 
     * @param string $field_name Nombre del campo
     * @param string $id_value Valor del NIF/NIE
     * @param array $all_data Todos los datos del formulario
     */
    private function validate_cif_or_nif_with_census($field_name, $id_value, $all_data = []) {
        $id = trim($id_value);
        
        if (empty($id)) {
            $this->errors[] = sprintf(__('NIF/NIE is required for \'%s\'', 'neve-child'), $field_name);
            return;
        }
        
        // Validar formato (puede ser NIF/NIE)
        if (!$this->is_valid_spanish_id($id)) {
            $this->errors[] = sprintf(__('Invalid NIF/NIE format for \'%s\'. Must be a valid NIF/NIE', 'neve-child'), $field_name);
            return;
        }
        
        // Determinar si es NIF/NIE
        $is_cif = $this->isValidCIF($id);
        $is_dni_nie = preg_match('/^[0-9]{8}[A-Z]$/', strtoupper($id)) || preg_match('/^[XYZ][0-9]{7}[A-Z]$/', strtoupper($id));
        
        if ($is_cif) {
            $this->validateAsCIF($id, $all_data);
        } elseif ($is_dni_nie) {
            $this->validateAsDNI($id, $all_data);
        } else {
            $this->errors[] = sprintf(__('Could not determine if \'%s\' is NIF/NIE', 'neve-child'), $field_name);
        }
    }
    
    /**
     * Validar como NIF/NIE (empresa) usando el campo company
     */
    private function validateAsCIF($cif, $all_data) {
        // Obtener el nombre de la empresa desde el campo 'company'
        $company_name = '';
        if (isset($all_data['company'])) {
            $company_name = trim($all_data['company']);
        } else {
            $company_name = trim(get_field('company', $this->post_id) ?? '');
        }
        
        if (empty($company_name)) {
            $this->errors[] = __('Company name is required to validate NIF/NIE with official census', 'neve-child');
            return;
        }
        
        $this->validateWithOfficialCensus($cif, $company_name);
    }
    
    /**
     * Validar como DNI/NIE (persona) usando el campo company como nombre completo
     */
    private function validateAsDNI($dni, $all_data) {
        // Para DNI/NIE, usar el campo company como nombre de la persona
        $person_name = '';
        if (isset($all_data['company'])) {
            $person_name = trim($all_data['company']);
        } else {
            $person_name = trim(get_field('company', $this->post_id) ?? '');
        }
        
        if (empty($person_name)) {
            $this->errors[] = __('Full name is required to validate DNI/NIE with official census', 'neve-child');
            return;
        }
        
        $this->validateWithOfficialCensus($dni, $person_name);
    }
    
    /**
     * Validar con censo oficial (genérico para NIF/NIE)
     */
    private function validateWithOfficialCensus($vat_number, $full_name) {
        try {
            if (class_exists('Factupress\\Facturae\\Api\\CensusBridge')) {
                $census_result = CensusBridge::verifyCensus($vat_number, $full_name);
                
                // Verificar diferentes formatos de respuesta del censo
                $is_valid = false;
                $error_message = 'Error desconocido en la validación del censo';
                
                if ($census_result && is_array($census_result)) {
                    // Formato 1: {"success": true}
                    if (isset($census_result['success']) && $census_result['success']) {
                        $is_valid = true;
                    }
                    // Formato 2: {"exists": true} 
                    elseif (isset($census_result['exists']) && $census_result['exists']) {
                        $is_valid = true;
                    }
                    // Si hay mensaje de error específico
                    if (isset($census_result['message'])) {
                        $error_message = $census_result['message'];
                    }
                }
                
                if (!$is_valid) {
                    $this->errors[] = sprintf(__('Census validation error for \'%s\': %s', 'neve-child'), $vat_number, $error_message);
                } else {
                    $this->success_messages[] = sprintf(__('ID \'%s\' successfully validated with official census', 'neve-child'), $vat_number);
                }
            } else {
                $this->warnings[] = __('Census validation system not available. Format validation completed.', 'neve-child');
            }
        } catch (\Exception $e) {
            $this->errors[] = __('Technical error in census validation: ', 'neve-child') . $e->getMessage();
        }
    }
    
    /**
     * Validar formato específico de NIF/NIE
     * 
     * @param string $cif
     * @return bool
     */
    private function isValidCIF(string $cif): bool {
        $cif = strtoupper(trim($cif));
        
        // Formato NIF/NIE: [ABCDEFGHJNPQRSUVW][0-9]{7}[0-9A-J]
        if (!preg_match('/^[ABCDEFGHJNPQRSUVW][0-9]{7}[0-9A-J]$/', $cif)) {
            return false;
        }
        
        // Validación adicional del dígito de control para NIF/NIE
        $first_letter = $cif[0];
        $numbers = substr($cif, 1, 7);
        $control = $cif[8];
        
        // Calcular dígito de control
        $sum = 0;
        for ($i = 0; $i < 7; $i++) {
            $digit = (int)$numbers[$i];
            if ($i % 2 === 0) {
                // Posiciones pares (1, 3, 5, 7): multiplicar por 2
                $double = $digit * 2;
                $sum += ($double > 9) ? ($double - 9) : $double;
            } else {
                // Posiciones impares (2, 4, 6): sumar directamente
                $sum += $digit;
            }
        }
        
        $expected_digit = (10 - ($sum % 10)) % 10;
        $expected_letter = 'JABCDEFGHI'[$expected_digit];
        
        // Dependiendo del tipo de organización, el control puede ser número o letra
        if (in_array($first_letter, ['A', 'B', 'E', 'H'])) {
            // Debe ser número
            return $control === (string)$expected_digit;
        } else {
            // Puede ser número o letra
            return $control === (string)$expected_digit || $control === $expected_letter;
        }
    }
    
    /**
     * Validar código postal
     */
    private function validate_postal_code($field_name, $value) {
        if (!preg_match('/^[0-9]{5}$/', $value)) {
            $this->errors[] = sprintf(__('Postal code must have 5 digits for \'%s\'', 'neve-child'), $field_name);
        }
    }
    
    /**
     * Validar URL
     */
    private function validate_url($field_name, $value) {
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            $this->warnings[] = sprintf(__('URL might have incorrect format for \'%s\'', 'neve-child'), $field_name);
        }
    }
    
    /**
     * Validar nombre de centro educativo
     */
    private function validate_school_name($field_name, $value) {
        $value = trim($value);
        
        if (strlen($value) < 3) {
            $this->errors[] = __("School name must have at least 3 characters for '{$field_name}'", 'neve-child');
        }
        
        if (strlen($value) > 100) {
            $this->warnings[] = sprintf(__('School name is too long for \'%s\'', 'neve-child'), $field_name);
        }
        
        // Verificar caracteres válidos
        if (!preg_match('/^[a-zA-ZáéíóúüñÁÉÍÓÚÜÑ0-9\s\-\.\,]+$/', $value)) {
            $this->errors[] = sprintf(__('School name contains invalid characters for \'%s\'', 'neve-child'), $field_name);
        }
    }
    
    /**
     * Validar school_key (clave única del centro)
     */
    private function validate_school_key($field_name, $value) {
        $value = trim($value);
        
        if (empty($value)) {
            $this->errors[] = __("School key (school_key) is required for '{$field_name}'", 'neve-child');
            return;
        }
        
        // La clave debe ser alfanumérica, sin espacios
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $value)) {
            $this->errors[] = sprintf(__('School key can only contain letters, numbers, hyphens and underscores for \'%s\'', 'neve-child'), $field_name);
        }
        
        // Longitud mínima y máxima
        if (strlen($value) < 3) {
            $this->errors[] = sprintf(__('School key must have at least 3 characters for \'%s\'', 'neve-child'), $field_name);
        }
        
        if (strlen($value) > 50) {
            $this->errors[] = sprintf(__('School key cannot exceed 50 characters for \'%s\'', 'neve-child'), $field_name);
        }
    }
    
    /**
     * Validar vendor (Post Object)
     */
    private function validate_vendor($field_name, $value) {
        // Si es un ID de post
        if (is_numeric($value)) {
            $post = get_post($value);
            if (!$post) {
                $this->errors[] = sprintf(__('Selected vendor does not exist for \'%s\'', 'neve-child'), $field_name);
            } else {
                $this->success_messages[] = sprintf(__('Vendor \'%s\' found for \'%s\'', 'neve-child'), $post->post_title, $field_name);
            }
        }
        // Si es un objeto de post
        elseif (is_object($value) && isset($value->ID)) {
            $this->success_messages[] = sprintf(__('Vendor \'%s\' valid for \'%s\'', 'neve-child'), $value->post_title, $field_name);
        }
        // Si es un array con ID
        elseif (is_array($value) && isset($value['ID'])) {
            $post = get_post($value['ID']);
            if (!$post) {
                $this->errors[] = sprintf(__('Selected vendor does not exist for \'%s\'', 'neve-child'), $field_name);
            }
        }
        else {
            $this->errors[] = sprintf(__('Invalid vendor format for \'%s\'', 'neve-child'), $field_name);
        }
    }
    
    /**
     * Validar campo booleano (True/False)
     */
    private function validate_boolean_field($field_name, $value) {
        // ACF devuelve 1/0 o true/false para campos True/False
        if (!is_bool($value) && $value !== '1' && $value !== '0' && $value !== 1 && $value !== 0) {
            $this->errors[] = sprintf(__('Field \'%s\' must be true or false', 'neve-child'), $field_name);
        }
    }
    
    /**
     * Validar nombre de empresa
     */
    private function validate_company_name($field_name, $value) {
        $value = trim($value);
        
        if (strlen($value) < 2) {
            $this->errors[] = sprintf(__('Company name must have at least 2 characters for \'%s\'', 'neve-child'), $field_name);
        }
        
        if (strlen($value) > 100) {
            $this->warnings[] = sprintf(__('Company name is too long for \'%s\'', 'neve-child'), $field_name);
        }
        
        // Verificar caracteres válidos para nombres de empresa
        if (!preg_match('/^[a-zA-ZáéíóúüñÁÉÍÓÚÜÑ0-9\s\-\.\,&()]+$/', $value)) {
            $this->errors[] = sprintf(__('Company name contains invalid characters for \'%s\'', 'neve-child'), $field_name);
        }
    }
    
    /**
     * Validar dirección
     */
    private function validate_address($field_name, $value) {
        $value = trim($value);
        
        if (strlen($value) < 5) {
            $this->errors[] = sprintf(__('Address must have at least 5 characters for \'%s\'', 'neve-child'), $field_name);
        }
        
        if (strlen($value) > 200) {
            $this->warnings[] = sprintf(__('Address is too long for \'%s\'', 'neve-child'), $field_name);
        }
        
        // Verificar que contenga al menos un número (típico en direcciones)
        if (!preg_match('/\d/', $value)) {
            $this->warnings[] = sprintf(__('Address should contain a number for \'%s\'', 'neve-child'), $field_name);
        }
    }
    
    /**
     * Validar ciudad
     */
    private function validate_city($field_name, $value) {
        $value = trim($value);
        
        if (strlen($value) < 2) {
            $this->errors[] = sprintf(__('City name must have at least 2 characters for \'%s\'', 'neve-child'), $field_name);
        }
        
        if (strlen($value) > 50) {
            $this->warnings[] = sprintf(__('City name is too long for \'%s\'', 'neve-child'), $field_name);
        }
        
        // Solo letras, espacios, guiones y acentos
        if (!preg_match('/^[a-zA-ZáéíóúüñÁÉÍÓÚÜÑ\s\-]+$/', $value)) {
            $this->errors[] = sprintf(__('City name can only contain letters, spaces and hyphens for \'%s\'', 'neve-child'), $field_name);
        }
    }
    
    /**
     * Validar estado/provincia
     */
    private function validate_state($field_name, $value) {
        $value = trim($value);
        
        if (strlen($value) < 2) {
            $this->errors[] = sprintf(__('State/province name must have at least 2 characters for \'%s\'', 'neve-child'), $field_name);
        }
        
        if (strlen($value) > 50) {
            $this->warnings[] = sprintf(__('State/province name is too long for \'%s\'', 'neve-child'), $field_name);
        }
        
        // Solo letras, espacios, guiones y acentos
        if (!preg_match('/^[a-zA-ZáéíóúüñÁÉÍÓÚÜÑ\s\-]+$/', $value)) {
            $this->errors[] = sprintf(__('State/province name can only contain letters, spaces and hyphens for \'%s\'', 'neve-child'), $field_name);
        }
    }
    
    /**
     * Validar campo ACF específico
     */
    private function validate_acf_field($field_name, $value) {
        $field_config = acf_get_field($field_name);
        
        if (!$field_config) {
            return;
        }
        
        $field_type = $field_config['type'] ?? 'text';
        
        switch ($field_type) {
            case 'email':
                $this->validate_email($field_name, $value);
                break;
                
            case 'number':
                $this->validate_acf_number($field_name, $value, $field_config);
                break;
                
            case 'select':
            case 'radio':
                $this->validate_acf_choice($field_name, $value, $field_config);
                break;
                
            case 'checkbox':
                $this->validate_acf_checkbox($field_name, $value);
                break;
                
            case 'date_picker':
                $this->validate_acf_date($field_name, $value);
                break;
        }
    }
    
    /**
     * Validar número ACF
     */
    private function validate_acf_number($field_name, $value, $config) {
        if (!is_numeric($value)) {
            $this->errors[] = sprintf(__('Value must be numeric for \'%s\'', 'neve-child'), $field_name);
            return;
        }
        
        if (isset($config['min']) && $value < $config['min']) {
            $this->errors[] = sprintf(__('Value below minimum allowed (%s) for \'%s\'', 'neve-child'), $config['min'], $field_name);
        }
        
        if (isset($config['max']) && $value > $config['max']) {
            $this->errors[] = sprintf(__('Value above maximum allowed (%s) for \'%s\'', 'neve-child'), $config['max'], $field_name);
        }
    }
    
    /**
     * Validar campo de elección ACF
     */
    private function validate_acf_choice($field_name, $value, $config) {
        $choices = $config['choices'] ?? [];
        if (!empty($choices) && !array_key_exists($value, $choices)) {
            $this->errors[] = sprintf(__('Value is not in allowed options for \'%s\'', 'neve-child'), $field_name);
        }
    }
    
    /**
     * Validar checkbox ACF
     */
    private function validate_acf_checkbox($field_name, $value) {
        if (!is_array($value)) {
            $this->warnings[] = sprintf(__('Checkbox field should be an array for \'%s\'', 'neve-child'), $field_name);
        }
    }
    
    /**
     * Validar fecha ACF
     */
    private function validate_acf_date($field_name, $value) {
        if (!strtotime($value)) {
            $this->errors[] = sprintf(__('Invalid date format for \'%s\'', 'neve-child'), $field_name);
        }
    }
    
    /**
     * Validar NIF/NIE español
     */
    private function is_valid_spanish_id($id) {
        $id = strtoupper(trim($id));
        
        // Validar DNI
        if (preg_match('/^[0-9]{8}[A-Z]$/', $id)) {
            $letters = 'TRWAGMYFPDXBNJZSQVHLCKE';
            $number = substr($id, 0, 8);
            $letter = substr($id, 8, 1);
            return $letters[$number % 23] === $letter;
        }
        
        // Validar NIE
        if (preg_match('/^[XYZ][0-9]{7}[A-Z]$/', $id)) {
            $nie_letters = ['X' => 0, 'Y' => 1, 'Z' => 2];
            $letters = 'TRWAGMYFPDXBNJZSQVHLCKE';
            $number = $nie_letters[$id[0]] . substr($id, 1, 7);
            $letter = substr($id, 8, 1);
            return $letters[$number % 23] === $letter;
        }
        
        // Validar NIF/NIE (básico)
        if (preg_match('/^[ABCDEFGHJNPQRSUVW][0-9]{7}[0-9A-J]$/', $id)) {
            return true; // Validación básica de formato
        }
        
        return false;
    }
    
    /**
     * Verificar si un campo tiene errores
     */
    private function has_field_errors($field_name) {
        foreach ($this->errors as $error) {
            if (strpos($error, "'{$field_name}'") !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Resetear estado de validación
     */
    private function reset_validation_state() {
        $this->errors = [];
        $this->warnings = [];
        $this->success_messages = [];
    }
    
    /**
     * Obtener resultados de validación
     */
    private function get_validation_results() {
        return [
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'success' => $this->success_messages,
            'is_valid' => empty($this->errors)
        ];
    }
    
    /**
     * Obtener campos esperados del formulario
     * 
     * @param int|null $post_id ID del post (opcional)
     */
    public function get_expected_fields($post_id = null) {
        if ($post_id === null) {
            $post_id = $this->post_id;
        }
        
        if (!$post_id) {
            return [];
        }
        
        $acf_fields = get_fields($post_id);
        $expected = [];
        
        if ($acf_fields) {
            foreach ($acf_fields as $field_name => $field_value) {
                $expected[$field_name] = gettype($field_value);
            }
        }
        
        return $expected;
    }
    
    /**
     * Obtener información del post del formulario
     * 
     * @param int|null $post_id ID del post (opcional)
     */
    public function get_form_info($post_id = null) {
        if ($post_id === null) {
            $post_id = $this->post_id;
        }
        
        if (!$post_id) {
            return null;
        }
        
        $post = get_post($post_id);
        if (!$post) {
            return null;
        }
        
        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'type' => $post->post_type,
            'status' => $post->post_status,
            'url' => get_permalink($post_id),
            'edit_url' => admin_url("post.php?post={$post_id}&action=edit")
        ];
    }
}

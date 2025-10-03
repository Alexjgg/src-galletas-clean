<?php
/**
 * Vendor Field Validator for Integration System
 * 
 * Sistema de validación de campos para vendors que funciona únicamente en el SERVIDOR.
 * NO incluye validaciones JavaScript/tiempo real. Valida justo antes de guardar los datos
 * y bloquea la actualización si hay errores.
 * 
 * CAMPOS VALIDADOS:
 * - _personTypeCode: Tipo de persona (F=Física, J=Jurídica)
 * - _taxIdentificationNumber: NIF/NIE (incluye validación oficial con censo de Hacienda)
 * - _phone: Teléfono
 * - _email: Correo electrónico
 * - _Name: Nombre (requerido para persona física)
 * - _firstSurname: Primer apellido (requerido para persona física)
 * - _secondSurname: Segundo apellido (opcional)
 * - _corporateName: Razón social (requerido para persona jurídica)
 * - _address: Dirección
 * - _town: Ciudad
 * - _postCode: Código postal
 * - _province: Provincia
 * - _legalLiteral: Aviso legal
 * - _filename: Nombre del archivo de factura
 * - _certificate: Certificado digital
 * - _sufijo: Sufijo de factura (opcional)
 * 
 * VALIDACIÓN OFICIAL CENSO:
 * - Persona Física (F): Valida NIF/NIE + Nombre + Apellidos contra censo oficial
 * - Persona Jurídica (J): Valida NIF/NIE + Razón Social contra censo oficial
 * 
 * FUNCIONAMIENTO:
 * - Hook: acf/save_post (prioridad 1)
 * - Si hay errores: wp_redirect() bloquea el guardado
 * - Si todo está bien: permite la actualización
 * 
 * @package SchoolManagement\Integration
 * @since 1.0.0
 */

namespace SchoolManagement\Integration;

use Factupress\Facturae\Api\CensusBridge;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for validating vendor fields on SERVER-SIDE ONLY
 * No JavaScript/real-time validation. Only validates before saving and blocks update if errors exist.
 */
class VendorFieldValidator
{
    /**
     * Validation errors
     */
    private array $errors = [];

    /**
     * Form data to validate
     */
    private array $data = [];

    /**
     * Vendor post type for all vendor posts
     */
    private const VENDOR_POST_TYPE = 'coo_vendor';

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
        // Allow certificate uploads
        add_filter('upload_mimes', [$this, 'allowCertificateMimes'], 1, 1);
        
        // Handle uploaded certificate files
        add_filter('wp_handle_upload', [$this, 'handleUploadedFile'], 10, 2);
        
        // ACF native validation hook
        add_action('acf/validate_save_post', [$this, 'validateWithACFNativeHook'], 10);
        
        // Admin notices for validation errors
        add_action('admin_notices', [$this, 'showValidationErrors']);
    }



    /**
     * Allow certificate uploads (.p12, .pfx, .pem)
     */
    public function allowCertificateMimes($mime_types)
    {
        if (current_user_can('upload_files')) {
            $mime_types['p12'] = 'application/x-pkcs12';
            $mime_types['pfx'] = 'application/x-pkcs12';
            $mime_types['pem'] = 'application/x-pem-file';
        }
        return $mime_types;
    }

    /**
     * Handle uploaded certificate files and move to secure location
     */
    public function handleUploadedFile($upload, $context)
    {
        // Only process successful uploads
        if (isset($upload['error']) && !empty($upload['error'])) {
            return $upload;
        }

        // Verify file exists
        if (!isset($upload['file']) || !file_exists($upload['file'])) {
            return $upload;
        }

        $file_path = $upload['file'];
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        
        // Only process certificate files
        if (!in_array($ext, ['pfx', 'p12', 'pem'])) {
            return $upload;
        }

        // Verify vendor context
        $post_id = $this->getCurrentPostId();
        if (!$post_id || !$this->isVendorPost($post_id)) {
            return $upload;
        }

        // Move to secure location
        $secure_upload = $this->moveToSecureLocation($upload);
        return $secure_upload ?: $upload;
    }

    /**
     * Move uploaded file to secure location
     */
    private function moveToSecureLocation($upload)
    {
        $original_file = $upload['file'];
        $original_url = $upload['url'];
        
        // Configure secure location
        $upload_dir = wp_upload_dir();
        $year = date("Y");
        $target_dir = trailingslashit($upload_dir['basedir']) . ".vendor-certs/{$year}/";
        $target_url_base = trailingslashit($upload_dir['baseurl']) . ".vendor-certs/{$year}/";

        // Create directory if needed
        if (!file_exists($target_dir)) {
            if (!wp_mkdir_p($target_dir)) {
                return false;
            }
            $this->createVendorCertProtectionFiles($target_dir);
        }

        // Generate secure filename
        $ext = strtolower(pathinfo($original_file, PATHINFO_EXTENSION));
        $new_filename = wp_generate_password(64, false) . '.' . $ext;
        $new_file_path = $target_dir . $new_filename;
        $new_url = $target_url_base . $new_filename;

        // Move file
        if (rename($original_file, $new_file_path)) {
            return [
                'file' => $new_file_path,
                'url' => $new_url,
                'type' => $upload['type']
            ];
        }

        return false;
    }

    /**
     * Get current post ID from different contexts
     */
    private function getCurrentPostId()
    {
        // From POST data
        if (isset($_POST['post_ID'])) {
            return (int)$_POST['post_ID'];
        } elseif (isset($_POST['post_id'])) {
            return (int)$_POST['post_id'];
        } elseif (isset($_GET['post'])) {
            return (int)$_GET['post'];
        }
        
        // From global $post
        global $post;
        return $post && $post->ID ? $post->ID : 0;
    }

    /**
     * Check if a post is a vendor post
     * 
     * @param int $post_id
     * @return bool
     */
    private function isVendorPost($post_id): bool
    {
        $post_type = get_post_type($post_id);
        return $post_type === self::VENDOR_POST_TYPE;
    }

    /**
     * Mover un attachment existente a la ubicación segura
     */
    private function moveAttachmentToSecureLocation($attachment_id): bool
    {
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return false;
        }

        $filename = basename($file_path);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        // Solo mover archivos de certificado
        if (!in_array($ext, ['pfx', 'p12', 'pem'])) {
            return false;
        }

        // Configurar nueva ubicación
        $upload_dir = wp_upload_dir();
        $year = date("Y");
        $target_dir = trailingslashit($upload_dir['basedir']) . ".vendor-certs/{$year}/";
        $target_url = trailingslashit($upload_dir['baseurl']) . ".vendor-certs/{$year}/";

        // Crear directorio si no existe
        if (!file_exists($target_dir)) {
            if (!wp_mkdir_p($target_dir)) {
                return false;
            }
            $this->createVendorCertProtectionFiles($target_dir);
        }

        // Generar nuevo nombre aleatorio
        $new_filename = wp_generate_password(64, false) . '.' . $ext;
        $new_file_path = $target_dir . $new_filename;

        // Mover el archivo
        if (rename($file_path, $new_file_path)) {
            // Actualizar la información del attachment
            update_attached_file($attachment_id, $new_file_path);
            
            // Actualizar URL del attachment
            $attachment_url = $target_url . $new_filename;
            wp_update_post([
                'ID' => $attachment_id,
                'guid' => $attachment_url
            ]);

            // Actualizar metadatos si existen
            $metadata = wp_get_attachment_metadata($attachment_id);
            if ($metadata) {
                $metadata['file'] = ".vendor-certs/{$year}/" . $new_filename;
                wp_update_attachment_metadata($attachment_id, $metadata);
            }

            return true;
        }

        return false;
    }

    /**
     * ACF native validation hook - recommended approach
     * ACF has processed the data and is available via get_field()
     */
    public function validateWithACFNativeHook(): void
    {
        global $post;
        
        // Only validate vendor posts
        if (!$post || !$this->isVendorPost($post->ID)) {
            return;
        }

        // Collect data directly from ACF processed fields
        $this->collectDataFromACFFields($post->ID);
        
        // Perform complete validation
        $validation_result = $this->performCompleteValidation();
        
        // If there are errors, use ACF's native error function
        if (!$validation_result['is_valid']) {
            foreach ($validation_result['errors'] as $field_name => $messages) {
                foreach ($messages as $message) {
                    acf_add_validation_error($field_name, $message);
                }
            }
        }
    }

    /**
     * Collect data directly from ACF fields (no manual mapping needed)
     * ACF has processed $_POST and we can use get_field() directly
     * 
     * @param int $post_id
     */
    private function collectDataFromACFFields($post_id): void
    {
        // List of fields we want to validate
        $vendor_fields = [
            '_personTypeCode',
            '_taxIdentificationNumber', 
            '_phone',
            '_email',
            '_Name',
            '_firstSurname',
            '_secondSurname',
            '_corporateName',
            '_address',
            '_town',
            '_postCode',
            '_province',
            '_legalLiteral',
            '_certificate',
            '_invoice_number',
            '_prefix',
            '_suffix'
        ];

        $this->data = [];

        // Get values directly with get_field() - ACF handles everything internally
        foreach ($vendor_fields as $field_name) {
            $value = get_field($field_name, $post_id);
            if ($value !== false && $value !== null && $value !== '') {
                $this->data[$field_name] = $value;
            }
        }

        // Normalize certificate if exists
        if (isset($this->data['_certificate'])) {
            $cert = $this->data['_certificate'];
            if (is_array($cert)) {
                $this->data['_certificate'] = $cert['id'] ?? $cert['ID'] ?? '';
            }
        }
    }

    /**
     * Obtener nombre legible del campo para mostrar

    /**
     * Obtener nombre legible del campo para mostrar
     * 
     * @param string $field_name
     * @return string
     */
    private function getFieldDisplayName(string $field_name): string
    {
        $field_names = [
            '_personTypeCode' => __('Person Type', 'neve-child'),
            '_taxIdentificationNumber' => __('Tax ID (NIF/NIE)', 'neve-child'),
            '_phone' => __('Phone', 'neve-child'),
            '_email' => __('Email', 'neve-child'),
            '_Name' => __('Name', 'neve-child'),
            '_firstSurname' => __('First Surname', 'neve-child'),
            '_secondSurname' => __('Second Surname', 'neve-child'),
            '_corporateName' => __('Corporate Name', 'neve-child'),
            '_address' => __('Address', 'neve-child'),
            '_town' => __('City', 'neve-child'),
            '_postCode' => __('Postal Code', 'neve-child'),
            '_province' => __('Province', 'neve-child'),
            '_legalLiteral' => __('Legal Notice', 'neve-child'),
            '_certificate' => __('Digital Certificate', 'neve-child'),
            '_invoice_number' => __('Invoice Number', 'neve-child'),
            '_prefix' => __('Invoice Prefix', 'neve-child'),
            '_suffix' => __('Invoice Suffix', 'neve-child')
        ];

        return $field_names[$field_name] ?? $field_name;
    }

    /**
     * Get existing vendor meta as defaults, normalized for validation
     * 
     * @param int $post_id The post ID to get data for
     */
    private function getExistingVendorData($post_id): array
    {
        $defaults = [];

        // Avoid fatal if ACF not available yet
        if (!function_exists('get_field')) {
            return $defaults;
        }

        // Try with known field names
        $known_fields = [
            '_personTypeCode', '_taxIdentificationNumber', '_phone', '_email', 
            '_Name', '_firstSurname', '_secondSurname', '_corporateName',
            '_address', '_town', '_postCode', '_province', '_legalLiteral',
            '_filename', '_certificate', '_invoice_number', '_sufijo', '_prefijo',
            '_InvoicePrefix', '_InvoiceSuffix'
        ];

        foreach ($known_fields as $field_name) {
            $value = get_field($field_name, $post_id);
            if (!empty($value)) {
                $defaults[$field_name] = $value;
            }
        }

        // Normalize certificate
        if (isset($defaults['_certificate'])) {
            $val = $defaults['_certificate'];
            
            if (is_array($val)) {
                $id = $val['id'] ?? $val['ID'] ?? null;
                $defaults['_certificate'] = $id ? (int)$id : '';
            } elseif (is_numeric($val)) {
                $defaults['_certificate'] = (int)$val;
            } elseif (is_string($val) && ctype_digit($val)) {
                $defaults['_certificate'] = (int)$val;
            }
        }

        return $defaults;
    }

    /**
     * Check if field belongs to vendor validation system
     * 
     * @param string $field_name
     * @return bool
     */
    private function isVendorField(string $field_name): bool
    {
        $vendor_fields = [
            '_personTypeCode',
            '_taxIdentificationNumber',
            '_phone',
            '_email',
            '_Name',
            '_firstSurname',
            '_secondSurname',
            '_corporateName',
            '_address',
            '_town',
            '_postCode',
            '_province',
            '_legalLiteral',
            '_filename',
            '_certificate',
            '_invoice_number',
            '_sufijo',
            '_prefijo',
            '_InvoicePrefix',
            '_InvoiceSuffix'
        ];

        return in_array($field_name, $vendor_fields);
    }

    /**
     * Perform complete validation of all fields
     * 
     * @return array
     */
    public function performCompleteValidation(): array
    {
        $this->errors = [];

        // Required field validations
        $this->validatePersonType();
        $this->validateTaxIdentificationNumber();
        $this->validatePhone();
        $this->validateEmail();
        $this->validateAddress();
        $this->validateTown();
        $this->validatePostCode();
        $this->validateProvince();
        $this->validateLegalLiteral();
        $this->validateCertificate();
        $this->validateInvoiceSuffix();
        
        // Conditional validations based on person type
        $this->validateConditionalFields();

        return [
            'is_valid' => empty($this->errors),
            'errors' => $this->errors,
            'data' => $this->data
        ];
    }

    /**
     * Validate person type field
     */
    private function validatePersonType(): void
    {
        $field = '_personTypeCode';
        $value = $this->data[$field] ?? '';

        if (empty($value)) {
            $this->addError($field, __('Person type is required', 'neve-child'));
        } elseif (!in_array($value, ['F', 'J'])) {
            $this->addError($field, __('Person type must be Individual (F) or Corporate (J)', 'neve-child'));
        }
    }

    /**
     * Validate tax identification number (NIF/NIE) with official census verification
     */
    private function validateTaxIdentificationNumber(): void
    {
        $field = '_taxIdentificationNumber';
        $nif = trim($this->data[$field] ?? '');

        if (empty($nif)) {
            $this->addError($field, __('Tax ID (NIF/NIE) is required', 'neve-child'));
            return;
        }

        // First, validate format
        if (!$this->isValidNIF($nif)) {
            // Analizar el formato para dar un mensaje más específico
            $nif_upper = strtoupper($nif);
            $length = strlen($nif_upper);
            
            if ($length < 9) {
                $this->addError($field, sprintf(__('Tax ID must have at least 9 characters. Received: "%s" (%d characters). If it\'s a NIE, verify it includes the initial letter (X, Y, Z).', 'neve-child'), $nif, $length));
            } elseif ($length > 9) {
                $this->addError($field, sprintf(__('Tax ID cannot have more than 9 characters. Received: "%s" (%d characters).', 'neve-child'), $nif, $length));
            } else {
                if (preg_match('/^[0-9]{9}$/', $nif_upper)) {
                    $this->addError($field, sprintf(__('Format appears incorrect. DNI must be 8 digits + 1 letter. Is the final letter missing? Received: "%s"', 'neve-child'), $nif));
                } elseif (preg_match('/^[0-9]{8}[A-Z]$/', $nif_upper)) {
                    $this->addError($field, sprintf(__('DNI with incorrect letter. Verify the control digit. Received: "%s"', 'neve-child'), $nif));
                } elseif (preg_match('/^[XYZ][0-9]{7}[A-Z]$/', $nif_upper)) {
                    $this->addError($field, sprintf(__('NIE with incorrect letter. Verify the control digit. Received: "%s"', 'neve-child'), $nif));
                } else {
                    $this->addError($field, sprintf(__('Tax ID format not recognized. NIF/NIE should look like 12345678A, X1234567A or A12345678. Received: "%s"', 'neve-child'), $nif));
                }
            }
            return;
        }

        // Census validation is handled separately when needed
    }

    /**
     * Validate NIF/NIE against official Hacienda census
     * 
     * @param string $vat_number
     */
    private function validateWithOfficialCensus(string $vat_number): void
    {
        $personType = $this->data['_personTypeCode'] ?? '';
        
        // Determine full name based on person type
        $full_name = '';
        
        if ($personType === 'F') { // Physical person
            $name = trim($this->data['_Name'] ?? '');
            $firstSurname = trim($this->data['_firstSurname'] ?? '');
            $secondSurname = trim($this->data['_secondSurname'] ?? '');
            
            // Only proceed if we have at least name and first surname
            if (empty($name) || empty($firstSurname)) {
                // These errors will be caught by conditional validation
                return;
            }
            
            $full_name = trim($name . ' ' . $firstSurname . ' ' . $secondSurname);
            
        } elseif ($personType === 'J') { // Legal person
            $corporateName = trim($this->data['_corporateName'] ?? '');
            
            if (empty($corporateName)) {
                // This error will be caught by conditional validation
                return;
            }
            
            $full_name = $corporateName;
        } else {
            // No person type specified, skip census validation
            return;
        }

        try {
            $census_result = CensusBridge::verifyCensus($vat_number, $full_name);
            
            if ($census_result === null) {
                $this->addError('_taxIdentificationNumber', __('Could not verify Tax ID against the official Tax Agency census. Please check API configuration.', 'neve-child'));
            } elseif (isset($census_result['error'])) {
                $this->addError('_taxIdentificationNumber', sprintf(__('Error in census verification: %s', 'neve-child'), $census_result['error']));
            } elseif (isset($census_result['exists'])) {
                $exists = $census_result['exists'];
                
                if (empty($exists) || $exists === false || $exists === '0' || $exists === 0) {
                    if ($personType === 'F') {
                        $this->addError('_taxIdentificationNumber', sprintf(__('The Tax ID "%s" does not match the name "%s" in the official Tax Agency census.', 'neve-child'), $vat_number, $full_name));
                    } else {
                        $this->addError('_taxIdentificationNumber', sprintf(__('The Tax ID "%s" does not match the company name "%s" in the official Tax Agency census.', 'neve-child'), $vat_number, $full_name));
                    }
                }
            } else {
                $this->addError('_taxIdentificationNumber', __('Unexpected response from census verification service. Please contact the administrator.', 'neve-child'));
            }
            
        } catch (\Exception $e) {
            $this->addError('_taxIdentificationNumber', __('Could not connect to census verification service. Please verify data manually.', 'neve-child'));
        }
    }

    /**
     * Validate phone number
     */
    private function validatePhone(): void
    {
        $field = '_phone';
        $phone = trim($this->data[$field] ?? '');

        if (empty($phone)) {
            $this->addError($field, __('Phone number is required', 'neve-child'));
            return;
        }

        if (!$this->isValidSpanishPhone($phone)) {
            $this->addError($field, __('Phone number format is invalid', 'neve-child'));
        }
    }

    /**
     * Validate email address
     */
    private function validateEmail(): void
    {
        $field = '_email';
        $email = trim($this->data[$field] ?? '');

        if (empty($email)) {
            $this->addError($field, __('Email address is required', 'neve-child'));
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addError($field, __('Email address format is invalid', 'neve-child'));
        }
    }

    /**
     * Validate address
     */
    private function validateAddress(): void
    {
        $field = '_address';
        if (empty(trim($this->data[$field] ?? ''))) {
            $this->addError($field, __('Address is required', 'neve-child'));
        }
    }

    /**
     * Validate town/city
     */
    private function validateTown(): void
    {
        $field = '_town';
        if (empty(trim($this->data[$field] ?? ''))) {
            $this->addError($field, __('City is required', 'neve-child'));
        }
    }

    /**
     * Validate postal code
     */
    private function validatePostCode(): void
    {
        $field = '_postCode';
        $postcode = trim($this->data[$field] ?? '');

        if (empty($postcode)) {
            $this->addError($field, __('Postal code is required', 'neve-child'));
            return;
        }

        if (!preg_match('/^[0-9]{5}$/', $postcode)) {
            $this->addError($field, __('Postal code must be 5 digits', 'neve-child'));
        }
    }

    /**
     * Validate province
     */
    private function validateProvince(): void
    {
        $field = '_province';
        if (empty(trim($this->data[$field] ?? ''))) {
            $this->addError($field, __('Province is required', 'neve-child'));
        }
    }

    /**
     * Validate legal literal
     */
    private function validateLegalLiteral(): void
    {
        $field = '_legalLiteral';
        if (empty(trim($this->data[$field] ?? ''))) {
            $this->addError($field, __('Legal notice is required', 'neve-child'));
        }
    }

    /**
     * Validate certificate
     */
    private function validateCertificate(): void
    {
        $field = '_certificate';

        // If already have a stored attachment ID, validate it exists
        if (!empty($this->data[$field]) && (is_int($this->data[$field]) || ctype_digit((string)$this->data[$field]))) {
            $attachment_id = (int) $this->data[$field];
            $file_path = get_attached_file($attachment_id);
            
            if ($file_path && file_exists($file_path)) {
                return; // Valid existing attachment
            } else {
                $this->addError($field, __('The stored certificate file no longer exists. Please upload a new certificate.', 'neve-child'));
                return;
            }
        }

        if (empty($this->data[$field])) {
            $this->addError($field, __('Digital certificate is required', 'neve-child'));
            return;
        }
    }

    /**
     * Crear archivos de protección en el directorio de certificados de vendors
     * 
     * @param string $target_dir Directorio donde crear los archivos de protección
     */
    private function createVendorCertProtectionFiles($target_dir): void
    {
        // Crear .htaccess para denegar acceso público
        $htaccess_content = "# Apache 2.4 and up" . PHP_EOL;
        $htaccess_content .= "<IfModule mod_authz_core.c>" . PHP_EOL;
        $htaccess_content .= " Require all denied" . PHP_EOL;
        $htaccess_content .= "</IfModule>" . PHP_EOL . PHP_EOL;
        $htaccess_content .= "# Apache 2.3 and down" . PHP_EOL;
        $htaccess_content .= "<IfModule !mod_authz_core.c>" . PHP_EOL;
        $htaccess_content .= " Order allow,deny" . PHP_EOL;
        $htaccess_content .= " Deny from all" . PHP_EOL;
        $htaccess_content .= "</IfModule>" . PHP_EOL . PHP_EOL;
        $htaccess_content .= "# Prevent incorrect MIME type detection" . PHP_EOL;
        $htaccess_content .= "AddType application/x-pkcs12 .pfx" . PHP_EOL;
        $htaccess_content .= "AddType application/x-pkcs12 .p12" . PHP_EOL;
        $htaccess_content .= "AddType application/x-pem-file .pem" . PHP_EOL;

        file_put_contents(trailingslashit($target_dir) . '.htaccess', $htaccess_content);

        // Crear index.php para protección adicional
        if (!file_exists($target_dir . '/index.php')) {
            $index_content = '<?php // Silence is golden';
            file_put_contents($target_dir . '/index.php', $index_content);
        }
    }

    /**
     * Validate invoice suffix
     */
    private function validateInvoiceSuffix(): void
    {
        $suffix = trim($this->data['_suffix'] ?? '');
        $field = '_suffix';

        // Suffix is optional, but if provided, validate format
        if (!empty($suffix)) {
            // Allow letters, numbers, and common invoice separators like hyphens and underscores
            if (!preg_match('/^[a-zA-Z0-9_-]{1,10}$/', $suffix)) {
                $this->addError($field, __('Suffix must contain only letters, numbers, hyphens, and underscores (maximum 10 characters)', 'neve-child'));
            }
        }
    }

    /**
     * Validate conditional fields based on person type
     */
    private function validateConditionalFields(): void
    {
        $personType = $this->data['_personTypeCode'] ?? '';

        if ($personType === 'F') { // Physical person
            $name = trim($this->data['_Name'] ?? '');
            $firstSurname = trim($this->data['_firstSurname'] ?? '');
            
            if (empty($name)) {
                $this->addError('_Name', __('Name is required for physical persons', 'neve-child'));
            }

            if (empty($firstSurname)) {
                $this->addError('_firstSurname', __('First surname is required for physical persons', 'neve-child'));
            }

        } elseif ($personType === 'J') { // Legal person
            $corporateName = trim($this->data['_corporateName'] ?? '');
            
            if (empty($corporateName)) {
                $this->addError('_corporateName', __('Corporate name is required for legal entities', 'neve-child'));
            }
        }
    }

    /**
     * Validate Spanish NIF/NIE/NIE format
     * 
     * @param string $nif
     * @return bool
     */
    private function isValidNIF(string $nif): bool
    {
        $nif = strtoupper(trim($nif));

        // Validate DNI (Spanish National ID)
        if (preg_match('/^[0-9]{8}[A-Z]$/', $nif)) {
            $number = (int)substr($nif, 0, 8);
            $letter = substr($nif, 8, 1);
            $letters = 'TRWAGMYFPDXBNJZSQVHLCKE';
            return $letters[$number % 23] === $letter;
        }

        // Validate NIE (Foreigner ID)
        if (preg_match('/^[XYZ][0-9]{7}[A-Z]$/', $nif)) {
            $prefix = substr($nif, 0, 1);
            $number = (int)substr($nif, 1, 7);
            $letter = substr($nif, 8, 1);

            $prefixNumbers = ['X' => 0, 'Y' => 1, 'Z' => 2];
            $fullNumber = $prefixNumbers[$prefix] . $number;

            $letters = 'TRWAGMYFPDXBNJZSQVHLCKE';
            return $letters[(int)$fullNumber % 23] === $letter;
        }

        // Validate NIF/NIE (Company ID) - basic format validation
        return preg_match('/^[ABCDEFGHJNPQRSUVW][0-9]{7}[0-9A-J]$/', $nif);
    }

    /**
     * Validate Spanish phone number format
     * 
     * @param string $phone
     * @return bool
     */
    private function isValidSpanishPhone(string $phone): bool
    {
        // Remove spaces and dashes, then validate
        $cleanPhone = str_replace([' ', '-'], '', $phone);
        return preg_match('/^(\+34|0034|34)?[6-9][0-9]{8}$/', $cleanPhone);
    }

    /**
     * Add validation error
     * 
     * @param string $field
     * @param string $message
     */
    private function addError(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }
        $this->errors[$field][] = $message;
    }

    /**
     * Show validation errors as admin notices in the same page
     */
    public function showValidationErrors(): void
    {
        global $post;

        if (!$post || !$this->isVendorPost($post->ID)) {
            return;
        }

        $errors = get_transient('vendor_validation_errors_' . $post->ID);
        $was_blocked = get_transient('vendor_validation_blocked_' . $post->ID);

        if ($errors && $was_blocked) {
            echo '<div class="notice notice-error is-dismissible" style="padding: 15px; margin: 20px 0; border-left: 4px solid #dc3545;">';
            echo '<h3 style="margin-top: 0; color: #dc3545;">❌ ' . __('Validation Errors - Data NOT Saved', 'neve-child') . '</h3>';
            echo '<p style="margin-bottom: 15px; font-weight: bold; color: #721c24;">' . __('The following fields contain errors and must be corrected:', 'neve-child') . '</p>';
            
            echo '<div style="background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; padding: 10px; margin-bottom: 15px;">';
            foreach ($errors as $field => $messages) {
                $field_name = $this->getFieldDisplayName($field);
                echo '<div style="margin-bottom: 10px;">';
                echo '<strong style="color: #721c24;">🔸 ' . esc_html($field_name) . ':</strong><br>';
                
                foreach ($messages as $message) {
                    echo '<span style="color: #721c24; margin-left: 15px; font-size: 14px;">• ' . esc_html($message) . '</span><br>';
                }
                echo '</div>';
            }
            echo '</div>';
            
            echo '<p style="margin-bottom: 0; font-style: italic; color: #6c757d;">💡 <strong>' . __('Important:', 'neve-child') . '</strong> ' . __('Fix the errors above and click "Update" again.', 'neve-child') . '</p>';
            echo '</div>';
            
            // Clean up transients after display
            delete_transient('vendor_validation_errors_' . $post->ID);
            delete_transient('vendor_validation_blocked_' . $post->ID);
        }
    }

    /**
     * Get validation errors for a specific field
     * 
     * @param string $field
     * @return array
     */
    public function getFieldErrors(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    /**
     * Check if field has errors
     * 
     * @param string $field
     * @return bool
     */
    public function hasFieldErrors(string $field): bool
    {
        return !empty($this->errors[$field]);
    }

    /**
     * Get all validation errors
     * 
     * @return array
     */
    public function getAllErrors(): array
    {
        return $this->errors;
    }

    /**
     * Set form data for validation
     * 
     * @param array $data
     */
    public function setFormData(array $data): void
    {
        $this->data = $data;
    }
}

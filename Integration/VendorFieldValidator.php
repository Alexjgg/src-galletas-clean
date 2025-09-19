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
        // Permitir subida de certificados .p12/.pfx desde este módulo
        add_filter('upload_mimes', [$this, 'allowCertificateMimes'], 1, 1);
        
        // INTERCEPTAR DESPUÉS DE QUE WORDPRESS PROCESE EL ARCHIVO
        add_filter('wp_handle_upload', [$this, 'handleUploadedFile'], 10, 2);
        
        // Validación del servidor antes de guardar
        add_action('acf/save_post', [$this, 'validateVendorBeforeSave'], 25);
        
        // Admin notices para mostrar errores de validación
        add_action('admin_notices', [$this, 'showValidationErrors']);
        
                // MIGRACIÓN TEMPORALMENTE DESHABILITADA
        // add_action('acf/save_post', [$this, 'migrateVendorFieldsAfterSave'], 20);

        // CARGA DE VALORES ANTIGUOS TEMPORALMENTE DESHABILITADA
        // add_filter('acf/load_value', [$this, 'forceLoadVendorFieldValues'], 10, 3);
        // add_filter('acf/load_field', [$this, 'preloadVendorFieldValue']);
    }



    /**
     * Permite subir certificados .p12 y .pfx
     */
    public function allowCertificateMimes($mime_types)
    {
        // Limitar a usuarios con capacidad de subir ficheros (editores/administradores)
        if (current_user_can('upload_files')) {
            $mime_types['p12'] = 'application/x-pkcs12';
            $mime_types['pfx'] = 'application/x-pkcs12';
            $mime_types['pem'] = 'application/x-pem-file';
        }
        return $mime_types;
    }

    /**
     * INTERCEPTAR ARCHIVOS DESPUÉS DE QUE WORDPRESS LOS PROCESE CORRECTAMENTE
     * Este método se ejecuta DESPUÉS de que WordPress haya validado y subido el archivo
     */
    public function handleUploadedFile($upload, $context)
    {
        // Solo procesar si la subida fue exitosa
        if (isset($upload['error']) && !empty($upload['error'])) {
            return $upload;
        }

        // Verificar si es un certificado
        if (!isset($upload['file']) || !file_exists($upload['file'])) {
            return $upload;
        }

        $file_path = $upload['file'];
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        
        if (!in_array($ext, ['pfx', 'p12', 'pem'])) {
            return $upload; // No es un certificado, continuar normalmente
        }

        // Verificar si estamos en un contexto de vendor
        $post_id = $this->getCurrentPostId();
        if (!$post_id || !$this->isVendorPost($post_id)) {
            return $upload; // No es un vendor, continuar normalmente
        }

        // MOVER EL ARCHIVO A UBICACIÓN SEGURA
        $secure_upload = $this->moveToSecureLocation($upload);
        
        return $secure_upload ? $secure_upload : $upload;
    }

    /**
     * Mover archivo subido a ubicación segura
     */
    private function moveToSecureLocation($upload)
    {
        $original_file = $upload['file'];
        $original_url = $upload['url'];
        
        // Configurar ubicación segura
        $upload_dir = wp_upload_dir();
        $year = date("Y");
        $target_dir = trailingslashit($upload_dir['basedir']) . ".vendor-certs/{$year}/";
        $target_url_base = trailingslashit($upload_dir['baseurl']) . ".vendor-certs/{$year}/";

        // Crear directorio si no existe
        if (!file_exists($target_dir)) {
            if (!wp_mkdir_p($target_dir)) {
                return false;
            }
            $this->createVendorCertProtectionFiles($target_dir);
        }

        // Generar nombre aleatorio
        $ext = strtolower(pathinfo($original_file, PATHINFO_EXTENSION));
        $new_filename = wp_generate_password(64, false) . '.' . $ext;
        $new_file_path = $target_dir . $new_filename;
        $new_url = $target_url_base . $new_filename;

        // Mover archivo
        if (rename($original_file, $new_file_path)) {
            // Devolver la nueva información del archivo
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
        if (isset($_POST['post_ID'])) return (int)$_POST['post_ID'];
        if (isset($_POST['post_id'])) return (int)$_POST['post_id'];
        
        // From GET parameter
        if (isset($_GET['post'])) return (int)$_GET['post'];
        
        // From global $post
        global $post;
        if ($post && $post->ID) return $post->ID;
        
        return 0;
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
     * MIGRAR CAMPOS DESPUÉS DE GUARDAR - TEMPORALMENTE DESHABILITADO
     * Se ejecuta después de que ACF termine de procesar todos los campos
     * 
     * @param int $post_id Post ID being saved
     */
    public function migrateFieldsAfterSave($post_id): void
    {
        // MIGRACIÓN TEMPORALMENTE DESHABILITADA
        return;
        
        /*
        // Solo actuar en posts de tipo vendor
        if (!$this->isVendorPost($post_id)) {
            return;
        }

        // Skip autosave y revisiones
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id)) {
            return;
        }

        // Mapeo de nombres antiguos a nuevos
        $field_migration = [
            '_PersonTypeCode' => '_personTypeCode',
            '_TaxIdentificationNumber' => '_taxIdentificationNumber',
            '_FirstSurname' => '_firstSurname',
            '_SecondSurname' => '_secondSurname',
            '_CorporateName' => '_corporateName',
            '_Address' => '_address',
            '_Town' => '_town',
            '_PostCode' => '_postCode',
            '_Province' => '_province',
            '_LegalLiteral' => '_legalLiteral'
        ];

        $migrated = 0;

        foreach ($field_migration as $old_name => $new_name) {
            // Obtener valores de ambos campos
            $new_value = get_post_meta($post_id, $new_name, true);
            $old_value = get_post_meta($post_id, $old_name, true);
            
            // Solo migrar si:
            // 1. El campo antiguo tiene valor
            // 2. Y el campo nuevo está vacío O tiene el mismo valor (evitar sobrescribir cambios)
            if (!empty($old_value) && (empty($new_value) || $new_value === $old_value)) {
                // Migrar el valor
                $result = update_post_meta($post_id, $new_name, $old_value);
                
                if ($result) {
                    $migrated++;
                }
            }
        }

        if ($migrated > 0) {
            // Limpiar caché para asegurar que se leen los nuevos valores
            wp_cache_delete($post_id, 'post_meta');
            clean_post_cache($post_id);
        }
        */
    }

    /**
     * Validate vendor data before saving post (SERVER-SIDE ONLY)
     * Si hay errores, bloquea la actualización y muestra errores en la misma página
     * 
     * @param int $post_id Post ID being saved
     */
    public function validateVendorBeforeSave($post_id): void
    {
        // Solo validar posts de tipo vendor
        if (!$this->isVendorPost($post_id)) {
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
        
        // Recopilar datos del formulario ACF
        $this->collectFormData($post_id);
        
        // Realizar validación completa
        $validation_result = $this->performCompleteValidation();
        
        // Si hay errores, BLOQUEAR el guardado
        if (!$validation_result['is_valid']) {
            
            // Guardar errores para mostrar en la misma página
            set_transient('vendor_validation_errors_' . $post_id, $validation_result['errors'], 300);
            set_transient('vendor_validation_blocked_' . $post_id, true, 300);
            
            // Redireccionar de vuelta al formulario con errores
            $redirect_url = admin_url('post.php?post=' . $post_id . '&action=edit');
            wp_redirect($redirect_url);
            exit;
        }
    }

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
     * Collect form data from $_POST ACF fields
     * 
     * @param int $post_id The post ID to collect data for
     */
    private function collectFormData($post_id): void
    {
        $this->data = [];
        
        // Prefill with existing stored values
        $this->data = $this->getExistingVendorData($post_id);
        
        if (!isset($_POST['acf']) || !is_array($_POST['acf'])) {
            return;
        }

        // Usar el mapeo manual para obtener datos del formulario
        $field_mapping = $this->getFieldMapping();
        
        foreach ($_POST['acf'] as $field_key => $value) {
            if (isset($field_mapping[$field_key])) {
                $field_name = $field_mapping[$field_key];
                $this->data[$field_name] = $value;
            }
        }
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

        // Intentar con nombres específicos conocidos
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

        // Compatibilidad con nombres antiguos (solo si no hay datos nuevos)
        $field_name_compatibility = [
            '_personTypeCode' => '_PersonTypeCode',
            '_taxIdentificationNumber' => '_TaxIdentificationNumber', 
            '_firstSurname' => '_FirstSurname',
            '_secondSurname' => '_SecondSurname',
            '_corporateName' => '_CorporateName',
            '_address' => '_Address',
            '_town' => '_Town',
            '_postCode' => '_PostCode',
            '_province' => '_Province',
            '_legalLiteral' => '_LegalLiteral',
        ];

        foreach ($field_name_compatibility as $new_name => $old_name) {
            if (!isset($defaults[$new_name]) || empty($defaults[$new_name])) {
                $old_value = get_field($old_name, $post_id);
                if (!empty($old_value)) {
                    $defaults[$new_name] = $old_value;
                }
            }
        }

        // Normalizar certificado
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
     * Get ACF field key to field name mapping
     * 
     * @return array
     */
    private function getFieldMapping(): array
    {
        return [
            // Mapeo basado en el grupo ACF group_6398863b844ce
            'field_personTypeCode' => '_personTypeCode',
            'field_taxIdentificationNumber' => '_taxIdentificationNumber', 
            'field_phone' => '_phone',
            'field_email' => '_email',
            'field_Name' => '_Name',
            'field_firstSurname' => '_firstSurname',
            'field_secondSurname' => '_secondSurname',
            'field_corporateName' => '_corporateName',
            'field_address' => '_address',
            'field_town' => '_town',
            'field_postCode' => '_postCode',
            'field_province' => '_province',
            'field_legalLiteral' => '_legalLiteral',
            'field_prefix' => '_prefix',
            'field_suffix' => '_suffix',
            'field_invoice_number' => '_invoice_number',
            'field_certificate' => '_certificate'
        ];
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

        // Validaciones de campos obligatorios
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
        // Validaciones condicionales según el tipo de persona
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

        // If format is valid, proceed with official census verification
        // COMENTADO: Validación con censo oficial desactivada temporalmente
        // $this->validateWithOfficialCensus($nif);
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

        // If already have a stored attachment ID, accept it (no need to re-upload on every save)
        if (!empty($this->data[$field]) && (is_int($this->data[$field]) || ctype_digit((string)$this->data[$field]))) {
            // Verificar que el attachment existe
            $attachment_id = (int) $this->data[$field];
            $file_path = get_attached_file($attachment_id);
            
            if ($file_path && file_exists($file_path)) {
                return; // OK: existing attachment ID
            } else {
                $this->addError($field, __('The stored certificate file no longer exists. Please upload a new certificate.', 'neve-child'));
                return;
            }
        }

        if (empty($this->data[$field])) {
            $this->addError($field, __('Digital certificate is required', 'neve-child'));
            return;
        }

        // Si hay datos pero no es un ID válido, continuar
        // La interceptación se hace DESPUÉS de la subida con wp_handle_upload
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
            if (!preg_match('/^[a-zA-Z0-9]{1,10}$/', $suffix)) {
                $this->addError($field, __('Suffix must contain only letters and numbers (maximum 10 characters)', 'neve-child'));
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

    /**
     * FORZAR CARGA DE VALORES ACF - TEMPORALMENTE DESHABILITADO
     * Hook: acf/load_value - Se ejecuta cuando ACF intenta cargar un valor para mostrar
     * 
     * @param mixed $value El valor que ACF quiere mostrar
     * @param int $post_id ID del post
     * @param array $field Información del campo ACF
     * @return mixed
     */
    public function forceLoadVendorFieldValues($value, $post_id, $field)
    {
        // CARGA AUTOMÁTICA DE VALORES ANTIGUOS TEMPORALMENTE DESHABILITADA
        return $value;
        
        /*
        // Solo actuar en posts de tipo vendor
        if (!$this->isVendorPost($post_id)) {
            return $value;
        }

        // Solo actuar en campos de vendor conocidos
        $vendor_field_keys = $this->getFieldMapping();
        $field_key = $field['key'] ?? '';
        
        if (!isset($vendor_field_keys[$field_key])) {
            return $value;
        }

        $field_name = $vendor_field_keys[$field_key];
        
        // Si ACF ya tiene un valor válido, no interferir
        if (!empty($value)) {
            return $value;
        }

        // Intentar cargar desde post meta directo
        $meta_value = get_post_meta($post_id, $field_name, true);
        
        // Si no hay valor en el campo nuevo, buscar en el campo antiguo
        if (empty($meta_value)) {
            $old_field_name = $this->getOldFieldName($field_name);
            if ($old_field_name) {
                $meta_value = get_post_meta($post_id, $old_field_name, true);
                
                // Si encontramos valor en campo antiguo, copiarlo al nuevo automáticamente
                if (!empty($meta_value)) {
                    update_post_meta($post_id, $field_name, $meta_value);
                }
            }
        }

        // Si encontramos valor, devolverlo para que ACF lo muestre
        if (!empty($meta_value)) {
            return $meta_value;
        }

        return $value;
        */
    }

    /**
     * PRECARGAR VALORES POR DEFECTO - TEMPORALMENTE DESHABILITADO
     * Hook: acf/load_field - Se ejecuta cuando ACF carga la definición del campo
     * 
     * @param array $field La definición del campo ACF
     * @return array
     */
    public function preloadVendorFieldValue($field)
    {
        // PRECARGA DE VALORES ANTIGUOS TEMPORALMENTE DESHABILITADA
        return $field;
        
        /*
        global $post;
        
        // Solo en posts de vendor
        if (!$post || !$this->isVendorPost($post->ID)) {
            return $field;
        }

        $vendor_fields = $this->getFieldMapping();
        $field_key = $field['key'] ?? '';
        
        if (isset($vendor_fields[$field_key])) {
            $field_name = $vendor_fields[$field_key];
            
            // Intentar cargar el valor guardado
            $meta_value = get_post_meta($post->ID, $field_name, true);
            
            // Si no hay valor en el nuevo, buscar en el antiguo
            if (empty($meta_value)) {
                $old_field_name = $this->getOldFieldName($field_name);
                if ($old_field_name) {
                    $meta_value = get_post_meta($post->ID, $old_field_name, true);
                    
                    // Auto-migrar si encontramos datos en campo antiguo
                    if (!empty($meta_value)) {
                        update_post_meta($post->ID, $field_name, $meta_value);
                    }
                }
            }

            // Establecer el valor por defecto si lo encontramos
            if (!empty($meta_value)) {
                $field['default_value'] = $meta_value;
            }
        }

        return $field;
        */
    }

    /**
     * Get old field name for compatibility - TEMPORALMENTE DESHABILITADO
     * 
     * @param string $new_field_name
     * @return string|null
     */
    private function getOldFieldName($new_field_name): ?string
    {
        // MAPEO DE COMPATIBILIDAD TEMPORALMENTE DESHABILITADO
        return null;
        
        /*
        $compatibility_map = [
            '_personTypeCode' => '_PersonTypeCode',
            '_taxIdentificationNumber' => '_TaxIdentificationNumber',
            '_firstSurname' => '_FirstSurname',
            '_secondSurname' => '_SecondSurname',
            '_corporateName' => '_CorporateName',
            '_address' => '_Address',
            '_town' => '_Town',
            '_postCode' => '_PostCode',
            '_province' => '_Province',
            '_legalLiteral' => '_LegalLiteral'
        ];

        return $compatibility_map[$new_field_name] ?? null;
        */
    }
}

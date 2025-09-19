<?php
/**
 * VendorDataManager - Sistema simple con hook directo desde AEATApiBridge
 * 
 * FLUJO SIMPLE:
 * 1. AEATApiBridge ejecuta hook 'factupress_before_generate_register'
 * 2. VendorDataManager establece contexto con order_id
 * 3. Intercepta ÚNICAMENTE la siguiente llamada a get_option de Factupress
 * 4. Sustituye datos del vendor y limpia contexto
 * 
 * @package SchoolManagement\Integration
 * @since 2.0.0 - Simplificado: solo hook directo
 */

namespace SchoolManagement\Integration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * VendorDataManager - Solo hook desde AEATApiBridge
 */
class VendorDataManager
{
    private $current_vendor_id = null;
    private $current_order_id = null;

    public function __construct()
    {
        // ÚNICO HOOK: Solo desde AEATApiBridge - aquí hacemos todo el mapeo
        add_action('factupress_before_generate_register', [$this, 'setOrderContextFromAEAT'], 1, 2);
        
        // NUEVOS HOOKS: Para interceptar y personalizar numeración de PDF
        add_filter('wpo_wcpdf_document_number_settings', [$this, 'customizeInvoiceNumberFormat'], 10, 3);
        add_action('wpo_wcpdf_after_pdf_created', [$this, 'incrementVendorInvoiceNumber'], 10, 2);
        add_filter('wpo_wcpdf_document_number', [$this, 'applyVendorNumbering'], 10, 3);
        // Hook adicional para formato completo del número
        add_filter('wpo_wcpdf_format_document_number', [$this, 'applyVendorNumberingFormat'], 999, 4);
        
        // Hook para interceptar antes de la creación del documento
        add_action('wpo_wcpdf_before_pdf', [$this, 'setupVendorContextForPDF'], 5, 2);
        
        // Hook adicional para asegurar que la numeración se aplique en todas las situaciones
        add_filter('wpo_wcpdf_get_document_number', [$this, 'overrideDocumentNumber'], 100, 2);
    }

    /**
     * Establece contexto desde AEATApiBridge y mapea datos del vendor - TODO EN UNO
     */
    public function setOrderContextFromAEAT($order_id, $document_type)
    {
        // Obtener datos del vendor usando ACF
        $vendor_data = $this->getVendorDataFromOrder($order_id);
        if (!$vendor_data) {
            return;
        }

        // Establecer contexto interno
        $this->current_order_id = $order_id;
        $this->current_vendor_id = $vendor_data['vendor_id'] ?? null;

        // Solo mapear si es factura
        if ($document_type === 'invoice') {
            $this->mapVendorDataToFactupress($vendor_data);
        }
    }    /**
     * Obtener datos del vendor usando ACF con nombres correctos del grupo group_6398863b844ce
     */
    private function getVendorDataFromOrder($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return null;
        }

        // 1. Buscar school_id
        $school_id = $this->getSchoolIdFromOrder($order);
        if (!$school_id) {
            return null;
        }

        // 2. Obtener vendor desde la school
        $vendor_id = get_field('vendor', $school_id);
        if (!$vendor_id) {
            return null;
        }

        // 3. Obtener SOLO los campos ACF que necesita AEAT del grupo group_6398863b844ce
        
        $corporate_name = get_field('_corporateName', $vendor_id) ?: '';
        $tax_id = get_field('_taxIdentificationNumber', $vendor_id) ?: '';
        $address = get_field('_address', $vendor_id) ?: '';
        $city = get_field('_town', $vendor_id) ?: '';
        $postal_code = get_field('_postCode', $vendor_id) ?: '';
        $province_name = get_field('_province', $vendor_id) ?: '';
        $certificate = get_field('_certificate', $vendor_id) ?: '';
        
        $province_code = $this->convertProvinceToCode($province_name);
        
        $vendor_data = [
            'vendor_id' => $vendor_id,              // NUEVO: ID del vendor para referencia
            'corporate_name' => $corporate_name,    // Campo 9 - Corporate Name
            'tax_id' => $tax_id,                   // Campo 3 - NIF *
            'address' => $address,                 // Campo 10 - Address *
            'city' => $city,                       // Campo 11 - City *
            'postal_code' => $postal_code,         // Campo 12 - Post Code *
            'province' => $province_code,          // Campo 13 - Province * (convertido a código)
            'certificate' => $certificate,         // Certificado para firmar
        ];

        // Verificar qué campos están vacíos
        $empty_fields = [];
        foreach ($vendor_data as $key => $value) {
            if (empty($value)) {
                $empty_fields[] = $key;
            }
        }
        
        if (!empty($empty_fields)) {
        }

        return $vendor_data;
    }

    /**
     * Obtener school_id del order - busca en múltiples ubicaciones
     */
    private function getSchoolIdFromOrder($order)
    {
        // Intentar diferentes campos donde puede estar el school_id
        $fields = ['_school_id', 'school_id', '_school', 'school', 'centro_escolar'];
        
        foreach ($fields as $field) {
            $value = $order->get_meta($field);
            if ($value) {
                return (int) $value;
            }
        }

        // Buscar en user data del customer como fallback
        $customer_id = $order->get_customer_id();
        if ($customer_id) {
            $user_school = get_field('school', 'user_' . $customer_id);
            if ($user_school) {
                return (int) $user_school;
            }
        }

        return null;
    }

    /**
     * Convertir nombre de provincia a código AEAT
     */
    private function convertProvinceToCode($province_name)
    {
        $province_map = [
            'Álava' => 'VI',
            'Albacete' => 'AB',
            'Alicante' => 'A',
            'Almería' => 'AL',
            'Asturias' => 'O',
            'Ávila' => 'AV',
            'Badajoz' => 'BA',
            'Baleares' => 'PM',
            'Barcelona' => 'B',
            'Burgos' => 'BU',
            'Cáceres' => 'CC',
            'Cádiz' => 'CA',
            'Cantabria' => 'S',
            'Castellón' => 'CS',
            'Ciudad Real' => 'CR',
            'Córdoba' => 'CO',
            'Coruña' => 'C',
            'Cuenca' => 'CU',
            'Girona' => 'GI',
            'Granada' => 'GR',
            'Guadalajara' => 'GU',
            'Guipúzcoa' => 'SS',
            'Huelva' => 'H',
            'Huesca' => 'HU',
            'Jaén' => 'J',
            'León' => 'LE',
            'Lleida' => 'L',
            'Lugo' => 'LU',
            'Madrid' => 'M',
            'Málaga' => 'MA',
            'Murcia' => 'MU',
            'Navarra' => 'NA',
            'Ourense' => 'OR',
            'Palencia' => 'P',
            'Pontevedra' => 'PO',
            'Rioja' => 'LO',
            'Salamanca' => 'SA',
            'Segovia' => 'SG',
            'Sevilla' => 'SE',
            'Soria' => 'SO',
            'Tarragona' => 'T',
            'Teruel' => 'TE',
            'Toledo' => 'TO',
            'Valencia' => 'V',
            'Valladolid' => 'VA',
            'Vizcaya' => 'BI',
            'Zamora' => 'ZA',
            'Zaragoza' => 'Z',
        ];

        return $province_map[$province_name] ?? $province_name;
    }

    /**
     * Personalizar formato de numeración para usar datos del vendor
     */
    public function customizeInvoiceNumberFormat($settings, $document)
    {
        // Solo para facturas
        if ($document->get_type() !== 'invoice') {
            return $settings;
        }

        // Obtener order_id del documento
        $order_id = null;
        if (is_object($document) && property_exists($document, 'order_id')) {
            $order_id = $document->order_id;
        } elseif (is_object($document) && method_exists($document, 'get_order_id')) {
            $order_id = $document->get_order_id();
        } elseif (is_object($document) && property_exists($document, 'order') && is_object($document->order)) {
            $order_id = $document->order->get_id();
        }
        
        if (!$order_id) {
            return $settings;
        }
        
        // Obtener vendor ID directamente
        $vendor_id = $this->getCurrentVendorId($order_id);
        if (!$vendor_id) {
            return $settings;
        }

        // Obtener datos de numeración del vendor
        $vendor_prefix = get_field('_prefix', $vendor_id) ?: '';

        // Modificar el prefijo para incluir el prefijo del vendor
        if (!empty($vendor_prefix)) {
            // Limpiar configuración para control total en wpo_wcpdf_format_document_number
            $settings['prefix'] = '';
            $settings['suffix'] = '';
            $settings['padding'] = '';
        }

        return $settings;
    }

    /**
     * Aplicar numeración personalizada del vendor
     */
    public function applyVendorNumbering($formatted_number, $document, $context)
    {
        // Solo para facturas
        if ($document->get_type() !== 'invoice') {
            return $formatted_number;
        }

        // Obtener order_id del documento
        $order_id = null;
        if (is_object($document) && property_exists($document, 'order_id')) {
            $order_id = $document->order_id;
        } elseif (is_object($document) && method_exists($document, 'get_order_id')) {
            $order_id = $document->get_order_id();
        } elseif (is_object($document) && property_exists($document, 'order') && is_object($document->order)) {
            $order_id = $document->order->get_id();
        }
        
        if (!$order_id) {
            return $formatted_number;
        }
        
        // Obtener vendor ID directamente
        $vendor_id = $this->getCurrentVendorId($order_id);
        if (!$vendor_id) {
            return $formatted_number;
        }

        // Obtener datos de numeración del vendor
        $vendor_prefix = get_field('_prefix', $vendor_id) ?: '';
        $vendor_suffix = get_field('_suffix', $vendor_id) ?: '';
        $vendor_invoice_number = get_field('_invoice_number', $vendor_id) ?: 1;

        // Formatear número personalizado: PREFIX + NUMERO + SUFFIX
        if (!empty($vendor_prefix)) {
            $formatted_number_str = str_pad($vendor_invoice_number, 4, '0', STR_PAD_LEFT);
            $custom_number = $vendor_prefix . $formatted_number_str . (!empty($vendor_suffix) ? $vendor_suffix : '');
            return $custom_number;
        }

        return $formatted_number;
    }

    /**
     * Aplicar numeración personalizada del vendor usando el filtro de formato
     * Este hook se ejecuta después de que el plugin formatea el número con prefijo/sufijo
     */
    public function applyVendorNumberingFormat($formatted_number, $number_object, $document, $order)
    {
        // Solo para facturas
        if (!is_object($document) || $document->get_type() !== 'invoice') {
            return $formatted_number;
        }

        // Obtener order_id del documento (igual que en customizeInvoiceNumberFormat)
        $order_id = null;
        if (is_object($document) && property_exists($document, 'order_id')) {
            $order_id = $document->order_id;
        } elseif (is_object($document) && method_exists($document, 'get_order_id')) {
            $order_id = $document->get_order_id();
        } elseif (is_object($document) && property_exists($document, 'order') && is_object($document->order)) {
            $order_id = $document->order->get_id();
        }
        
        if (!$order_id) {
            return $formatted_number;
        }
        
        // Obtener vendor ID directamente
        $vendor_id = $this->getCurrentVendorId($order_id);
        if (!$vendor_id) {
            return $formatted_number;
        }

        // Obtener datos de numeración del vendor
        $vendor_prefix = get_field('_prefix', $vendor_id) ?: '';
        $vendor_suffix = get_field('_suffix', $vendor_id) ?: '';
        $vendor_invoice_number = get_field('_invoice_number', $vendor_id) ?: 1;

        // Reemplazar completamente el número formateado con el formato del vendor
        if (!empty($vendor_prefix)) {
            // Formato: PREFIX + NUMERO + SUFFIX (si existe)
            $formatted_number_str = str_pad($vendor_invoice_number, 4, '0', STR_PAD_LEFT);
            $custom_number = $vendor_prefix . $formatted_number_str . (!empty($vendor_suffix) ? $vendor_suffix : '');
            
            // Incrementar el número para la próxima factura (solo una vez por ejecución)
            $this->incrementVendorInvoiceNumberSafe($vendor_id, $order_id);
            
            return $custom_number;
        }

        return $formatted_number;
    }

    /**
     * Incrementar automáticamente el número de facturación del vendor después de crear el PDF
     */
    public function incrementVendorInvoiceNumber($document, $context)
    {
        // Solo para facturas
        if ($document->get_type() !== 'invoice') {
            return;
        }

        // Obtener order_id del documento
        $order_id = null;
        if (is_object($document) && property_exists($document, 'order_id')) {
            $order_id = $document->order_id;
        } elseif (is_object($document) && method_exists($document, 'get_order_id')) {
            $order_id = $document->get_order_id();
        } elseif (is_object($document) && property_exists($document, 'order') && is_object($document->order)) {
            $order_id = $document->order->get_id();
        }
        
        if (!$order_id) {
            return;
        }
        
        // Obtener vendor ID directamente
        $vendor_id = $this->getCurrentVendorId($order_id);
        if (!$vendor_id) {
            return;
        }

        // Obtener número actual
        $current_number = get_field('_invoice_number', $vendor_id) ?: 0;
        
        // Incrementar en 1
        $new_number = intval($current_number) + 1;
        
        // Actualizar el campo ACF
        update_field('_invoice_number', $new_number, $vendor_id);
    }

    /**
     * Incrementar número de factura de forma segura (evita múltiples incrementos)
     */
    private function incrementVendorInvoiceNumberSafe($vendor_id, $order_id)
    {
        // Verificar si ya se incrementó para este pedido
        $increment_meta_key = "_vendor_invoice_incremented_{$vendor_id}";
        $already_incremented = get_post_meta($order_id, $increment_meta_key, true);
        
        if ($already_incremented) {
            return;
        }
        
        // Marcar como incrementado antes de hacer el incremento
        update_post_meta($order_id, $increment_meta_key, true);
        
        // Obtener número actual
        $current_number = get_field('_invoice_number', $vendor_id) ?: 0;
        
        // Incrementar en 1
        $new_number = intval($current_number) + 1;
        
        // Actualizar el campo ACF
        $update_result = update_field('_invoice_number', $new_number, $vendor_id);
        
        if (!$update_result) {
            // Si falla, eliminar la marca para permitir reintento
            delete_post_meta($order_id, $increment_meta_key);
        }
    }

    /**
     * Obtener el vendor_id desde el contexto actual o desde un order_id específico
     */
    private function getCurrentVendorId($order_id = null)
    {
        $order_id = $order_id ?: $this->current_order_id;
        if (!$order_id) {
            return null;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return null;
        }

        $school_id = $this->getSchoolIdFromOrder($order);
        if (!$school_id) {
            return null;
        }

        return get_field('vendor', $school_id);
    }

    /**
     * Configurar contexto del vendor antes de generar PDF
     */
    public function setupVendorContextForPDF($document_type, $document)
    {
        // Solo para facturas
        if ($document_type !== 'invoice') {
            return;
        }

        // Obtener order_id del documento
        $order_id = null;
        if (is_object($document) && property_exists($document, 'order_id')) {
            $order_id = $document->order_id;
        } elseif (is_object($document) && method_exists($document, 'get_order_id')) {
            $order_id = $document->get_order_id();
        } elseif (is_object($document) && property_exists($document, 'order') && is_object($document->order)) {
            $order_id = $document->order->get_id();
        }
        
        if (!$order_id) {
            return;
        }
        
        // Obtener y guardar el vendor_id
        $this->current_order_id = $order_id;
        $previous_vendor = $this->current_vendor_id;
        $this->current_vendor_id = $this->getCurrentVendorId($order_id);
    }

    /**
     * Override final del número de documento para asegurar consistencia
     */
    public function overrideDocumentNumber($document_number, $document)
    {
        // Solo para facturas
        if ($document->get_type() !== 'invoice') {
            return $document_number;
        }

        // Si no tenemos vendor, intentar obtenerlo desde el documento
        if (!$this->current_vendor_id && $document->order_id) {
            $this->current_order_id = $document->order_id;
            $this->current_vendor_id = $this->getCurrentVendorId($document->order_id);
        }

        // Si aún no tenemos vendor, devolver número original
        if (!$this->current_vendor_id) {
            return $document_number;
        }

        // Generar número personalizado
        $vendor_prefix = get_field('_prefix', $this->current_vendor_id) ?: '';
        $vendor_suffix = get_field('_suffix', $this->current_vendor_id) ?: '';
        $vendor_invoice_number = get_field('_invoice_number', $this->current_vendor_id) ?: 1;

        if (!empty($vendor_prefix)) {
            $formatted_number_str = str_pad($vendor_invoice_number, 4, '0', STR_PAD_LEFT);
            $custom_number = $vendor_prefix . $formatted_number_str . (!empty($vendor_suffix) ? $vendor_suffix : '');
            
            return $custom_number;
        }

        return $document_number;
    }
}
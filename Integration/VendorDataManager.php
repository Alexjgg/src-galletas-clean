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
        // ÚNICO HOOK: Solo desde AEATApiBridge - aquí hacemos todo el mapeo de datos del vendor
        add_action('factupress_before_generate_register', [$this, 'setOrderContextFromAEAT'], 1, 2);
        
        // ⚠️ NUMERACIÓN DESACTIVADA - Ahora solo la maneja VendorPDFManager ⚠️
        // Los hooks de numeración fueron movidos a VendorPDFManager para evitar duplicados
        
        // NUEVOS HOOKS: Para interceptar también durante la generación de PDF (no solo AEAT)
        add_action('wpo_wcpdf_save_document', [$this, 'interceptPDFGeneration'], 1, 2);
        add_action('wpo_wcpdf_document_created_manually', [$this, 'interceptPDFGeneration'], 1, 2);
        
        // Hook para interceptar datos del vendor en tiempo real durante el PDF
        add_filter('option_factupress-settings-fields', [$this, 'interceptFactupressSettings'], 1);
        
        // HOOK ESPECÍFICO: Para generación manual de PDFs desde admin
        add_action('init', [$this, 'interceptManualPDFGeneration'], 1);
        
        // HOOKS para modificar datos en los PDFs
        // ⚠️ DESACTIVADO - VendorPDFManager ahora maneja estos hooks ⚠️
        // add_filter('wpo_wcpdf_shop_name', [$this, 'modifyPDFShopName'], 10, 2);
        // add_filter('wpo_wcpdf_shop_address', [$this, 'modifyPDFShopAddress'], 10, 2);
        add_filter('wpo_wcpdf_footer', [$this, 'modifyPDFFooter'], 10, 2);
        
        // Hook para datos de la tienda en general
        add_filter('wpo_wcpdf_document_store_data', [$this, 'modifyPDFStoreData'], 10, 2);
        
        // Hook antes de renderizar el template del PDF
        add_action('wpo_wcpdf_before_document', [$this, 'setupVendorDataForPDF'], 10, 2);
    }

    /**
     * Establece contexto desde AEATApiBridge y mapea datos del vendor - TODO EN UNO
     */
    public function setOrderContextFromAEAT($order_id, $document_type)
    {
        // CONTROL DE PROCESAMIENTO ÚNICO - Similar a AEATApiBridge
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        $meta_key = '_vendor_data_processed_' . $document_type;
        if ($order->get_meta($meta_key)) {
            return; // Ya procesado
        }
        
        // Marcar como procesado INMEDIATAMENTE para evitar duplicados
        $order->update_meta_data($meta_key, true);
        $order->save();
        
        // Obtener datos del vendor usando ACF
        $vendor_data = $this->getVendorDataFromOrder($order_id);
        if (!$vendor_data) {
            return;
        }

        // Establecer contexto interno
        $this->current_order_id = $order_id;
        $this->current_vendor_id = $vendor_data['vendor_id'] ?? null;

        // Mapear datos del vendor para todos los tipos de documento
        $this->mapVendorDataToFactupress($vendor_data);
    }

    /**
     * Mapea los datos del vendor a los ajustes de Factupress usando filtros
     */
    private function mapVendorDataToFactupress($vendor_data)
    {
        // Interceptar la siguiente llamada a get_option para 'factupress-settings-fields'
        add_filter('option_factupress-settings-fields', function($settings) use ($vendor_data) {
            // Solo aplicar una vez y luego remover el filtro
            remove_filter('option_factupress-settings-fields', __FUNCTION__);
            
            // Mapear datos del vendor a los campos de Factupress
            $settings['nombre_empresa'] = $vendor_data['corporate_name'];
            $settings['nif'] = $vendor_data['tax_id'];
            $settings['domicilio'] = $vendor_data['address'];
            $settings['municipio'] = $vendor_data['city'];
            $settings['codigo_postal'] = $vendor_data['postal_code'];
            $settings['provincia'] = $vendor_data['province'];
            
            // Certificado si existe
            if (!empty($vendor_data['certificate'])) {
                $settings['certificate'] = $vendor_data['certificate'];
            }
            
            return $settings;
        }, 1);
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
        $tax_id = trim(str_replace(':', '', $tax_id)); // Limpiar dos puntos y espacios
        $address = get_field('_address', $vendor_id) ?: '';
        $city = get_field('_town', $vendor_id) ?: '';
        $postal_code = get_field('_postCode', $vendor_id) ?: '';
        $province_name = get_field('_province', $vendor_id) ?: '';
        $certificate = get_field('_certificate', $vendor_id) ?: '';
        
        $province_code = $this->convertProvinceToCode($province_name);
        
        $vendor_data = [
            'vendor_id' => $vendor_id,              // NUEVO: ID del vendor para referencia
            'corporate_name' => $corporate_name,    // Campo 9 - Corporate Name
            'tax_id' => $tax_id,                   // Campo 3 - NIF * (SOLO para AEAT, no para PDFs)
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
        // Si es un refund, obtener el pedido padre
        $main_order = $order;
        if ($order instanceof \WC_Order_Refund) {
            $parent_id = $order->get_parent_id();
            if ($parent_id) {
                $main_order = wc_get_order($parent_id);
                if (!$main_order) {
                    return null;
                }
            }
        }

        // Intentar diferentes campos donde puede estar el school_id
        $fields = ['_school_id', 'school_id', '_school', 'school', 'centro_escolar'];
        
        // Buscar primero en el order actual (refund si aplica)
        foreach ($fields as $field) {
            $value = $order->get_meta($field);
            if ($value) {
                return (int) $value;
            }
        }

        // Buscar en el pedido principal (solo si es diferente)
        if ($main_order && $main_order !== $order) {
            foreach ($fields as $field) {
                $value = $main_order->get_meta($field);
                if ($value) {
                    return (int) $value;
                }
            }
        }

        // Buscar en user data del customer como fallback (solo si tenemos pedido principal)
        if ($main_order && method_exists($main_order, 'get_customer_id')) {
            $customer_id = $main_order->get_customer_id();
            if ($customer_id) {
                $user_school = get_field('school', 'user_' . $customer_id);
                if ($user_school) {
                    return (int) $user_school;
                }
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
     * ⚠️ DESACTIVADO - Numeración movida a VendorPDFManager ⚠️
     * @deprecated Ahora VendorPDFManager maneja toda la numeración
     */
    public function customizeDocumentNumberFormat($settings, $document)
    {
        // Método desactivado para evitar conflictos con VendorPDFManager
        return $settings;
    }

    /**
     * ⚠️ DESACTIVADO - Numeración movida a VendorPDFManager ⚠️
     * @deprecated Ahora VendorPDFManager maneja toda la numeración
     */
    public function applyVendorNumbering($formatted_number, $document, $context)
    {
        // Método desactivado para evitar conflictos con VendorPDFManager
        return $formatted_number;
    }

    /**
     * ⚠️ DESACTIVADO - Numeración movida a VendorPDFManager ⚠️
     * @deprecated Ahora VendorPDFManager maneja toda la numeración
     */
    public function applyVendorNumberingFormat($formatted_number, $number_object, $document, $order)
    {
        // Método desactivado para evitar conflictos con VendorPDFManager
        return $formatted_number;
    }

    /**
     * ⚠️ DESACTIVADO - Numeración movida a VendorPDFManager ⚠️
     * @deprecated Ahora VendorPDFManager maneja toda la numeración
     */
    public function incrementVendorDocumentNumber($document, $context)
    {
        // Método desactivado para evitar conflictos con VendorPDFManager
        return;
    }

    /**
     * Obtener el siguiente número de documento disponible
     * Maneja correctamente múltiples refunds del mismo pedido con sistema de bloqueo
     */
    private function getNextDocumentNumber($vendor_id, $order_id, $document_type)
    {
        // Obtener el orden actual para determinar si es refund o pedido principal
        $order = wc_get_order($order_id);
        if (!$order) {
            return 1;
        }

        $control_id = $order_id; // Para refunds, usar el ID del refund individual

        // Verificar si ya tenemos un número asignado para este documento específico
        $assigned_number_key = "_vendor_{$document_type}_assigned_number_{$vendor_id}";
        $assigned_number = get_post_meta($control_id, $assigned_number_key, true);
        
        if ($assigned_number) {
            return intval($assigned_number);
        }
        
        // SISTEMA DE BLOQUEO para evitar condiciones de carrera
        $lock_key = "_vendor_{$document_type}_lock_{$vendor_id}";
        $lock_value = time();
        $lock_timeout = 10; // 10 segundos máximo
        
        // Intentar obtener el bloqueo
        $existing_lock = get_transient($lock_key);
        if ($existing_lock && ($lock_value - $existing_lock) < $lock_timeout) {
            // Esperar un poco y reintentar
            usleep(100000); // 0.1 segundos
            $existing_lock = get_transient($lock_key);
            
            if ($existing_lock && ($lock_value - $existing_lock) < $lock_timeout) {
                // Si sigue bloqueado, usar fallback
                $fallback_number = intval(get_field($this->getNumberFieldForDocumentType($document_type), $vendor_id) ?: 0) + 1;
                update_post_meta($control_id, $assigned_number_key, $fallback_number);
                return $fallback_number;
            }
        }
        
        // Establecer bloqueo
        set_transient($lock_key, $lock_value, $lock_timeout);
        
        // Obtener campo de número según tipo de documento
        $number_field = $this->getNumberFieldForDocumentType($document_type);
        
        // Obtener número actual y calcular el siguiente
        $current_number = get_field($number_field, $vendor_id) ?: 0;
        $next_number = intval($current_number) + 1;
        
        // Actualizar inmediatamente el campo ACF para reservar el número
        $update_success = update_field($number_field, $next_number, $vendor_id);
        
        if ($update_success) {
            // Reservar este número para este documento específico
            update_post_meta($control_id, $assigned_number_key, $next_number);
        }
        
        // Liberar bloqueo
        delete_transient($lock_key);
        
        return $next_number;
    }

    /**
     * Incrementar número de documento de forma segura (evita múltiples incrementos)
     * NOTA: Ahora getNextDocumentNumber() ya actualiza el ACF, esto solo marca como completado
     */
    private function incrementVendorDocumentNumberSafe($vendor_id, $order_id, $document_type)
    {
        // Obtener el orden actual para determinar si es refund o pedido principal
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Para refunds, usar el ID del refund individual para control único
        // Para pedidos principales, usar el order_id normal
        $control_id = $order_id;
        if ($order instanceof \WC_Order_Refund) {
            // Cada refund tiene su propio ID único, esto permite múltiples refunds del mismo pedido
            $control_id = $order_id; // Ya es el ID del refund individual
        }

        // Verificar si ya se incrementó para este documento específico
        $increment_meta_key = "_vendor_{$document_type}_incremented_{$vendor_id}";
        $already_incremented = get_post_meta($control_id, $increment_meta_key, true);
        
        if ($already_incremented) {
            return;
        }
        
        // Verificar que tenemos el número asignado
        $assigned_number_key = "_vendor_{$document_type}_assigned_number_{$vendor_id}";
        $assigned_number = get_post_meta($control_id, $assigned_number_key, true);
        
        if ($assigned_number) {
            // Marcar como completado (el ACF ya fue actualizado por getNextDocumentNumber)
            update_post_meta($control_id, $increment_meta_key, true);
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
     * Obtener order_id de un documento PDF de WooCommerce
     * IMPORTANTE: Para refunds, esto devuelve el ID del refund individual, no del pedido padre
     */
    private function getOrderIdFromDocument($document)
    {
        if (is_object($document) && property_exists($document, 'order_id')) {
            return $document->order_id;
        } elseif (is_object($document) && method_exists($document, 'get_order_id')) {
            return $document->get_order_id();
        } elseif (is_object($document) && property_exists($document, 'order') && is_object($document->order)) {
            return $document->order->get_id();
        }
        
        return null;
    }

    /**
     * Obtener el campo de prefijo ACF según el tipo de documento
     */
    private function getPrefixFieldForDocumentType($document_type)
    {
        $field_map = [
            'invoice' => '_prefix',
            'simplified-invoice' => '_simplified_prefix',
            'credit-note' => '_credit_note_prefix',
            'simplified-credit-note' => '_simplified_credit_note_prefix'
        ];
        
        return $field_map[$document_type] ?? '_prefix';
    }

    /**
     * Obtener el campo de sufijo ACF según el tipo de documento
     */
    private function getSuffixFieldForDocumentType($document_type)
    {
        $field_map = [
            'invoice' => '_suffix',
            'simplified-invoice' => '_simplified_suffix',
            'credit-note' => '_credit_note_suffix',
            'simplified-credit-note' => '_simplified_credit_note_suffix'
        ];
        
        return $field_map[$document_type] ?? '_suffix';
    }

    /**
     * Obtener el campo de número ACF según el tipo de documento
     */
    private function getNumberFieldForDocumentType($document_type)
    {
        $field_map = [
            'invoice' => '_invoice_number',
            'simplified-invoice' => '_simplified_invoice_number',
            'credit-note' => '_credit_note_number',
            'simplified-credit-note' => '_simplified_credit_note_number'
        ];
        
        return $field_map[$document_type] ?? '_invoice_number';
    }

    /**
     * Configurar contexto del vendor antes de generar PDF
     */
    public function setupVendorContextForPDF($document_type, $document)
    {
        // Tipos de documento soportados
        $supported_types = ['invoice', 'simplified-invoice', 'credit-note', 'simplified-credit-note'];
        if (!in_array($document_type, $supported_types)) {
            return;
        }

        // Obtener order_id del documento
        $order_id = $this->getOrderIdFromDocument($document);
        if (!$order_id) {
            return;
        }
        
        // Establecer contexto completo (no solo vendor_id)
        $this->setOrderContextForPDF($order_id, $document_type);
    }

    /**
     * ⚠️ DESACTIVADO - Numeración movida a VendorPDFManager ⚠️ 
     * @deprecated Ahora VendorPDFManager maneja toda la numeración
     */
    public function overrideDocumentNumber($document_number, $document)
    {
        // Método desactivado para evitar conflictos con VendorPDFManager
        return $document_number;
    }

    /**
     * Intercepta la generación de PDF para establecer contexto del vendor
     * Este es el mismo hook que usa AEATApiBridge pero lo capturamos antes
     */
    public function interceptPDFGeneration($document, $order)
    {
        if (!is_object($document)) {
            return;
        }
        
        $document_type = $document->get_type();
        $supported_types = ['invoice', 'simplified-invoice', 'credit-note', 'simplified-credit-note'];
        
        if (!in_array($document_type, $supported_types)) {
            return;
        }

        // Resolver el order ID
        $order_id = null;
        if (is_object($order) && method_exists($order, 'get_id')) {
            $order_id = $order->get_id();
        } elseif (is_array($order) && isset($order[0])) {
            $order_obj = wc_get_order($order[0]);
            $order_id = $order_obj ? $order_obj->get_id() : null;
        } elseif (is_numeric($order)) {
            $order_id = $order;
        }

        if (!$order_id) {
            return;
        }

        // Establecer contexto para este documento específico
        $this->setOrderContextForPDF($order_id, $document_type);
    }

    /**
     * Establece contexto del vendor para generación de PDF
     */
    private function setOrderContextForPDF($order_id, $document_type)
    {
        // Obtener datos del vendor
        $vendor_data = $this->getVendorDataFromOrder($order_id);
        if (!$vendor_data) {
            return;
        }

        // Establecer contexto interno
        $this->current_order_id = $order_id;
        $this->current_vendor_id = $vendor_data['vendor_id'] ?? null;
        
        // Mapear datos del vendor
        $this->mapVendorDataToFactupress($vendor_data);
    }

    /**
     * Intercepta las configuraciones de Factupress en tiempo real
     * Para aplicar datos del vendor cuando se genere cualquier documento
     */
    public function interceptFactupressSettings($settings)
    {
        // Si no hay contexto activo, intentar detectarlo desde la petición actual
        if (!$this->current_vendor_id && wp_doing_ajax()) {
            $this->tryToDetectContextFromRequest();
        }

        // Solo actuar si tenemos un contexto de vendor activo
        if (!$this->current_vendor_id || !$this->current_order_id) {
            return $settings;
        }

        // Obtener datos frescos del vendor
        $vendor_data = $this->getVendorDataFromOrder($this->current_order_id);
        if (!$vendor_data) {
            return $settings;
        }

        // Mapear datos del vendor
        $settings['nombre_empresa'] = $vendor_data['corporate_name'];
        $settings['nif'] = $vendor_data['tax_id'];
        $settings['domicilio'] = $vendor_data['address'];
        $settings['municipio'] = $vendor_data['city'];
        $settings['codigo_postal'] = $vendor_data['postal_code'];
        $settings['provincia'] = $vendor_data['province'];
        
        // Certificado si existe
        if (!empty($vendor_data['certificate'])) {
            $settings['certificate'] = $vendor_data['certificate'];
        }

        return $settings;
    }

    /**
     * Intenta detectar el contexto del vendor desde la petición HTTP actual
     */
    private function tryToDetectContextFromRequest()
    {
        // Verificar si es una generación de PDF
        if (isset($_GET['action']) && $_GET['action'] === 'generate_wpo_wcpdf') {
            $order_ids = $_GET['order_ids'] ?? null;
            $document_type = $_GET['document_type'] ?? null;
            
            if ($order_ids && $document_type) {
                $order_ids_array = explode(',', $order_ids);
                $order_id = intval($order_ids_array[0]);
                
                if ($order_id) {
                    // Para credit notes, verificar si es un pedido padre con refunds
                    $actual_order_id = $this->resolveActualOrderId($order_id, $document_type);
                    
                    $this->setOrderContextForPDF($actual_order_id, $document_type);
                }
            }
        }
    }

    /**
     * Resuelve el ID real del order/refund basado en el tipo de documento
     * Para credit notes, intenta encontrar el refund específico si se pasa el pedido padre
     */
    private function resolveActualOrderId($order_id, $document_type)
    {
        // Si no es una nota de crédito, usar el ID original
        if (!in_array($document_type, ['credit-note', 'simplified-credit-note'])) {
            return $order_id;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return $order_id;
        }

        // Si ya es un refund, usar tal como está
        if ($order instanceof \WC_Order_Refund) {
            return $order_id;
        }

        // Si es un pedido padre, buscar refunds
        $refunds = $order->get_refunds();
        if (empty($refunds)) {
            return $order_id;
        }

        // ESTRATEGIA: Usar el refund más reciente que no tenga PDF generado aún
        $vendor_id = $this->getCurrentVendorId($order_id); // Usar pedido padre para obtener vendor
        
        if ($vendor_id) {
            foreach ($refunds as $refund) {
                $refund_id = $refund->get_id();
                
                // Verificar si este refund ya tiene un número asignado
                $assigned_number_key = "_vendor_{$document_type}_assigned_number_{$vendor_id}";
                $already_assigned = get_post_meta($refund_id, $assigned_number_key, true);
                
                if (!$already_assigned) {
                    // Este refund no tiene número asignado, es el candidato
                    return $refund_id;
                }
            }
        }

        // Si todos los refunds ya tienen número, usar el más reciente
        $latest_refund = reset($refunds);
        return $latest_refund->get_id();
    }

    /**
     * Intercepta la generación manual de PDFs desde el admin de WordPress
     * Detecta las URLs tipo: admin-ajax.php?action=generate_wpo_wcpdf&document_type=X&order_ids=Y
     */
    public function interceptManualPDFGeneration()
    {
        // Solo en admin y solo si es una petición AJAX de generación de PDF
        if (!is_admin() || !wp_doing_ajax()) {
            return;
        }

        // Verificar que es una petición de generación de PDF
        if (!isset($_GET['action']) || $_GET['action'] !== 'generate_wpo_wcpdf') {
            return;
        }

        // Obtener parámetros de la URL
        $document_type = $_GET['document_type'] ?? null;
        $order_ids = $_GET['order_ids'] ?? null;

        if (!$document_type || !$order_ids) {
            return;
        }

        // Tipos de documento soportados
        $supported_types = ['invoice', 'simplified-invoice', 'credit-note', 'simplified-credit-note'];
        if (!in_array($document_type, $supported_types)) {
            return;
        }

        // Puede ser múltiples IDs separados por comas
        $order_ids_array = explode(',', $order_ids);
        $original_order_id = intval($order_ids_array[0]); // Tomar el primero por ahora

        if (!$original_order_id) {
            return;
        }

        // Resolver el ID real (refund específico si es credit note)
        $actual_order_id = $this->resolveActualOrderId($original_order_id, $document_type);

        // Establecer contexto para esta generación manual
        $this->setOrderContextForPDF($actual_order_id, $document_type);

        // IMPORTANTE: Agregar hook temporal para asegurar que se aplique la numeración
        add_action('wpo_wcpdf_init_document', [$this, 'ensureVendorContextOnDocument'], 1, 1);
    }

    /**
     * Asegura que el contexto del vendor esté activo cuando se inicializa un documento
     * NOTA: wpo_wcpdf_init_document solo pasa el documento como parámetro
     */
    public function ensureVendorContextOnDocument($document)
    {
        if (!is_object($document)) {
            return;
        }

        $doc_type = $document->get_type();
        $supported_types = ['invoice', 'simplified-invoice', 'credit-note', 'simplified-credit-note'];
        
        if (!in_array($doc_type, $supported_types)) {
            return;
        }

        // Obtener order_id del documento
        $order_id = $this->getOrderIdFromDocument($document);
        if (!$order_id) {
            return;
        }

        // Si no tenemos contexto activo, establecerlo
        if (!$this->current_vendor_id) {
            $this->setOrderContextForPDF($order_id, $doc_type);
        }

        // Remover el hook temporal después de usar
        remove_action('wpo_wcpdf_init_document', [$this, 'ensureVendorContextOnDocument']);
    }

    /**
     * Modifica el nombre de la tienda en los PDFs según el vendor
     */
    public function modifyPDFShopName($shop_name, $document)
    {
        $vendor_data = $this->getVendorDataForCurrentDocument($document);
        if ($vendor_data && !empty($vendor_data['corporate_name'])) {
            return $vendor_data['corporate_name'];
        }
        return $shop_name;
    }

    /**
     * Modifica la dirección de la tienda en los PDFs según el vendor
     */
    public function modifyPDFShopAddress($shop_address, $document)
    {
        $vendor_data = $this->getVendorDataForCurrentDocument($document);
        if ($vendor_data) {
            $address_parts = [];
            
            if (!empty($vendor_data['address'])) {
                $address_parts[] = $vendor_data['address'];
            }
            
            if (!empty($vendor_data['postal_code']) && !empty($vendor_data['city'])) {
                $address_parts[] = $vendor_data['postal_code'] . ' ' . $vendor_data['city'];
            } elseif (!empty($vendor_data['city'])) {
                $address_parts[] = $vendor_data['city'];
            }
            
            if (!empty($address_parts)) {
                return implode("\n", $address_parts);
            }
        }
        return $shop_address;
    }

    /**
     * Modifica el footer de los PDFs según el vendor
     */
    public function modifyPDFFooter($footer, $document)
    {
        // Footer sin modificaciones por ahora
        return $footer;
    }

    /**
     * Modifica los datos completos de la tienda en los PDFs
     */
    public function modifyPDFStoreData($store_data, $document)
    {
        $vendor_data = $this->getVendorDataForCurrentDocument($document);
        if ($vendor_data) {
            // Sobrescribir datos de la tienda con datos del vendor (SIN VAT)
            if (!empty($vendor_data['corporate_name'])) {
                $store_data['name'] = $vendor_data['corporate_name'];
            }
            
            if (!empty($vendor_data['address'])) {
                $store_data['address'] = $vendor_data['address'];
            }
            
            if (!empty($vendor_data['postal_code'])) {
                $store_data['postcode'] = $vendor_data['postal_code'];
            }
            
            if (!empty($vendor_data['city'])) {
                $store_data['city'] = $vendor_data['city'];
            }
            
            // ⚠️ VAT NUMBER REMOVIDO - No personalizar por vendor ⚠️
        }
        
        return $store_data;
    }

    /**
     * Configura los datos del vendor antes de renderizar el PDF
     */
    public function setupVendorDataForPDF($document_type, $document)
    {
        // Solo actuar en documentos soportados
        $supported_types = ['invoice', 'simplified-invoice', 'credit-note', 'simplified-credit-note'];
        if (!in_array($document_type, $supported_types)) {
            return;
        }

        // Obtener order_id del documento
        $order_id = $this->getOrderIdFromDocument($document);
        if (!$order_id) {
            return;
        }

        // Asegurar que tenemos contexto del vendor activo
        if (!$this->current_vendor_id) {
            $this->setOrderContextForPDF($order_id, $document_type);
        }
    }

    /**
     * Obtiene los datos del vendor para el documento actual (SIN TAX_ID para PDFs)
     */
    private function getVendorDataForCurrentDocument($document)
    {
        if (!is_object($document)) {
            return null;
        }

        // Verificar que es un documento soportado
        $document_type = $document->get_type();
        $supported_types = ['invoice', 'simplified-invoice', 'credit-note', 'simplified-credit-note'];
        if (!in_array($document_type, $supported_types)) {
            return null;
        }

        // Obtener order_id del documento
        $order_id = $this->getOrderIdFromDocument($document);
        if (!$order_id) {
            return null;
        }

        // Si no tenemos contexto activo, establecerlo
        if (!$this->current_vendor_id) {
            $vendor_data = $this->getVendorDataFromOrder($order_id);
            if ($vendor_data) {
                $this->current_order_id = $order_id;
                $this->current_vendor_id = $vendor_data['vendor_id'] ?? null;
                
                // IMPORTANTE: Remover tax_id para uso en PDFs
                unset($vendor_data['tax_id']);
                return $vendor_data;
            }
            return null;
        }

        // Si ya tenemos contexto, obtener datos frescos del vendor (SIN TAX_ID)
        $vendor_data = $this->getVendorDataFromOrder($order_id);
        if ($vendor_data) {
            // IMPORTANTE: Remover tax_id para uso en PDFs
            unset($vendor_data['tax_id']);
        }
        return $vendor_data;
    }
}
<?php
/**
 * VendorDataManager - Sistema SOLO para AEAT/Factupress
 * 
 * FLUJO SIMPLE:
 * 1. AEATApiBridge ejecuta hook 'factupress_before_generate_register'
 * 2. VendorDataManager establece contexto con order_id
 * 3. Intercepta ÚNICAMENTE la siguiente llamada a get_option de Factupress
 * 4. Sustituye datos del vendor y limpia contexto
 * 
 * NOTA: Los PDFs son manejados completamente por VendorPDFManager
 * 
 * @package SchoolManagement\Integration
 * @since 3.0.0 - Simplificado: SOLO AEAT, PDFs en VendorPDFManager
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
        
        // Hook SOLO para AEAT/Factupress - VendorPDFManager maneja todo lo de PDFs
        add_filter('option_factupress-settings-fields', [$this, 'interceptFactupressSettings'], 1);
        
        // 🎯 HOOK SIMPLE: Interceptar NIF para QRs de VeriFactu
        add_filter('verifactu_qr_nif', [$this, 'changeNifForQR'], 10, 4);
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
     * Ahora simplificado: usa vendor_id directamente si está disponible (master orders que paga el centro)
     */
    private function getVendorDataFromOrder($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return null;
        }

        // 1. Primero verificar si la orden ya tiene vendor_id directamente asignado
        // (esto aplica para master orders que paga el centro y órdenes normales procesadas por OrderManager)
        $vendor_id = $order->get_meta('_vendor_id');
        
        // 2. Fallback: buscar vendor a través del school_id (para órdenes más antiguas)
        if (!$vendor_id) {
            $school_id = $this->getSchoolIdFromOrder($order);
            if (!$school_id) {
                return null;
            }
            $vendor_id = get_field('vendor', $school_id);
        }
        
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
     * ⚠️ DESACTIVADO - Movido a VendorPDFManager ⚠️
     * @deprecated Ahora VendorPDFManager maneja toda la numeración
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
     * ⚠️ DESACTIVADO - Movido a VendorPDFManager ⚠️
     * @deprecated Ahora VendorPDFManager maneja toda la numeración
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
        // ⚠️ SIMPLIFICADO - Solo detectar para AEAT, no para PDFs
        // VendorPDFManager maneja todo lo relacionado con PDFs
        return;
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
     * 🎯 INTERCEPTAR SETTINGS DE FACTUPRESS PARA QRs EN QRColumn
     * Detecta cuando QRColumn está generando QRs y sustituye el NIF por el del vendor
     */
    public function interceptFactupressSettingsForQR($settings)
    {
        // 🔍 DETECTAR SI ESTAMOS EN QRColumn generando QRs
        if (!$this->isQRColumnContext()) {
            return $settings;
        }

        // 🎯 OBTENER ORDER_ID del contexto actual
        $order_id = $this->detectOrderIdFromQRContext();
        if (!$order_id) {
            return $settings;
        }

        // 🔍 OBTENER DATOS DEL VENDOR para este pedido
        $vendor_data = $this->getVendorDataFromOrder($order_id);
        if (!$vendor_data || empty($vendor_data['tax_id'])) {
            return $settings;
        }

        // 🎯 SUSTITUIR ÚNICAMENTE EL NIF del vendor
        $settings['nif'] = $vendor_data['tax_id'];
        
        return $settings;
    }

    /**
     * 🔍 DETECTAR SI ESTAMOS EN EL CONTEXTO DE QRColumn
     */
    private function isQRColumnContext()
    {
        // Verificar si estamos en el admin de WooCommerce orders
        if (!is_admin()) {
            return false;
        }

        // Verificar si es la página de pedidos de WooCommerce
        $page = $_GET['page'] ?? '';
        $post_type = $_GET['post_type'] ?? '';
        
        $is_woo_orders_page = ($page === 'wc-orders');
        $is_shop_order_page = ($post_type === 'shop_order' && strpos($_SERVER['REQUEST_URI'], 'edit.php') !== false);
        
        if (!$is_woo_orders_page && !$is_shop_order_page) {
            return false;
        }

        // Verificar stack trace para confirmar que viene de QRColumn
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        
        foreach ($backtrace as $trace) {
            if (isset($trace['class']) && 
                (strpos($trace['class'], 'QRColumn') !== false || 
                 strpos($trace['class'], 'Factupress\\Verifactu\\Orders\\QRColumn') !== false)) {
                return true;
            }
            
            // También verificar si el archivo contiene QRColumn
            if (isset($trace['file']) && strpos($trace['file'], 'QRColumn.php') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 🎯 DETECTAR ORDER_ID desde el contexto de QRColumn
     */
    private function detectOrderIdFromQRContext()
    {
        // Verificar si hay un order_id en el contexto global que QRColumn esté procesando
        global $current_qr_order_id;
        if ($current_qr_order_id) {
            return $current_qr_order_id;
        }

        // Verificar en el backtrace si podemos encontrar el post_id/order_id
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 15);
        
        foreach ($backtrace as $trace) {
            // Buscar en los argumentos de la función renderQRColumn
            if (isset($trace['function']) && $trace['function'] === 'renderQRColumn' && 
                isset($trace['args']) && count($trace['args']) >= 2) {
                $post_id = $trace['args'][1]; // Segundo parámetro es post_id
                if (is_numeric($post_id)) {
                    return (int) $post_id;
                }
            }
            
            // Buscar variables locales que contengan order o post_id
            if (isset($trace['object'])) {
                $vars = get_object_vars($trace['object']);
                if (isset($vars['post_id'])) {
                    return (int) $vars['post_id'];
                }
            }
        }

        return null;
    }

    /**
     * 🎯 CAMBIAR NIF PARA QRs DE VERIFACTU SEGÚN EL VENDOR DEL PEDIDO
     * Método simple que recibe el order_id y devuelve el NIF correcto
     */
    public function changeNifForQR($nif, $order_id, $order, $refund = null)
    {
        // Obtener datos del vendor para este pedido
        $vendor_data = $this->getVendorDataFromOrder($order_id);
        
        if (!$vendor_data || empty($vendor_data['tax_id'])) {
            // Si no hay vendor o NIF, devolver el original
            return $nif;
        }
        
        // Devolver el NIF del vendor
        return $vendor_data['tax_id'];
    }


}
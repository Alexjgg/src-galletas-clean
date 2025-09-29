<?php
/**
 * VendorPDFManager - Versión Ultra Simplificada
 * Solo numeración personalizada y datos básicos del vendor en PDFs
 * 
 * @package SchoolManagement\Integration
 * @since 5.0.0 - Versión ultra simplificada
 */

namespace SchoolManagement\Integration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * VendorPDFManager - Solo lo absolutamente esencial
 */
class VendorPDFManager
{
    public function __construct()
    {
        // 🎯 HOOKS PRINCIPALES DE NUMERACIÓN - Prioridad alta para interceptar TODO
        add_filter('wpo_wcpdf_formatted_document_number', [$this, 'applyVendorNumbering'], 5, 4);
        add_filter('wpo_wcpdf_document_number', [$this, 'applyVendorNumbering'], 5, 3);
        add_filter('wpo_wcpdf_format_document_number', [$this, 'applyVendorNumberingFormat'], 5, 4);
        add_filter('wpo_wcpdf_get_document_number', [$this, 'overrideDocumentNumber'], 5, 2);
        
        // 🎯 HOOKS ESPECÍFICOS POR TIPO DE DOCUMENTO
        add_filter('wpo_wcpdf_invoice_number', [$this, 'applyInvoiceNumber'], 5, 4);
        add_filter('wpo_wcpdf_simplified_invoice_number', [$this, 'applyInvoiceNumber'], 5, 4);
        add_filter('wpo_wcpdf_credit_note_number', [$this, 'applyCreditNoteNumber'], 5, 4);
        add_filter('wpo_wcpdf_simplified_credit_note_number', [$this, 'applyCreditNoteNumber'], 5, 4);
        
        // 🎯 HOOKS PARA SETTINGS - Limpiar configuración por defecto
        add_filter('wpo_wcpdf_document_number_settings', [$this, 'customizeNumberSettings'], 5, 3);
        
        // Hooks específicos para el número de orden mostrado en PDFs
        add_filter('wpo_wcpdf_order_number', [$this, 'modifyOrderNumber'], 10, 2);
        add_filter('wpo_wcpdf_document_title', [$this, 'modifyDocumentTitle'], 10, 2);
        
        // Hook alternativo para algunos templates
        add_filter('woocommerce_order_number', [$this, 'modifyWooOrderNumber'], 10, 2);
        
        // Hooks para personalizar datos del vendor en PDFs - Prioridad más alta
        add_filter('wpo_wcpdf_shop_name', [$this, 'modifyPDFShopName'], 5, 2);
        add_filter('wpo_wcpdf_shop_address', [$this, 'modifyPDFShopAddress'], 5, 2);
        
        // Hook alternativo para la dirección completa - Prioridad más alta
        add_filter('wpo_wcpdf_formatted_shop_address', [$this, 'modifyFormattedShopAddress'], 5, 2);
        
        // 🎯 HOOKS CRÍTICOS PARA AEAT - Asegurar compatibilidad
        add_action('wpo_wcpdf_document_created_manually', [$this, 'ensureAEATCompatibility'], 1, 2);
        add_action('wpo_wcpdf_save_document', [$this, 'ensureAEATCompatibility'], 1, 2);
        add_action('wpo_wcpdf_after_pdf_created', [$this, 'ensureAEATCompatibility'], 1, 2);
        
        // 🎯 INTERCEPTAR CONFIGURACIÓN GLOBAL DEL PLUGIN PARA CAMBIAR VAT DINÁMICAMENTE
        add_filter('option_wpo_wcpdf_settings_general', [$this, 'modifyVATInGlobalSettings'], 10);
        add_filter('wpo_wcpdf_shop_vat_number', [$this, 'modifyPDFVatNumber'], 1, 2);

    }

    /**
     * 🎯 MÉTODO PRINCIPAL - Aplicar numeración personalizada del vendor
     * Maneja TODOS los hooks de numeración con parámetros variables
     */
    public function applyVendorNumbering($formatted_number, $document, $document_type = null, $order = null)
    {
        if (!is_object($document)) {
            return $formatted_number;
        }

        // Obtener document_type
        if (!$document_type && method_exists($document, 'get_type')) {
            $document_type = $document->get_type();
        }
        
        // Tipos de documento soportados
        $supported_types = ['invoice', 'simplified-invoice', 'credit-note', 'simplified-credit-note'];
        if (!in_array($document_type, $supported_types)) {
            return $formatted_number;
        }

        // Obtener order_id
        $order_id = $this->getOrderIdFromDocument($document);
        if (!$order_id && $order) {
            $order_id = is_numeric($order) ? $order : (is_object($order) && method_exists($order, 'get_id') ? $order->get_id() : null);
        }
        
        if (!$order_id) {
            return $formatted_number;
        }
        
        // Obtener vendor ID
        $vendor_id = $this->getVendorId($order_id);
        if (!$vendor_id) {
            return $formatted_number;
        }

        // Obtener datos de numeración del vendor
        $prefix_field = $this->getPrefixField($document_type);
        $suffix_field = $this->getSuffixField($document_type);
        
        $vendor_prefix = get_field($prefix_field, $vendor_id) ?: '';
        $vendor_suffix = get_field($suffix_field, $vendor_id) ?: '';
        
        if (empty($vendor_prefix)) {
            return $formatted_number;
        }

        // Obtener siguiente número
        $vendor_number = $this->getNextNumber($vendor_id, $order_id, $document_type);

        // Formatear: PREFIX + NUMERO + SUFFIX
        $number_str = str_pad($vendor_number, 4, '0', STR_PAD_LEFT);
        $custom_number = $vendor_prefix . $number_str . $vendor_suffix;
        
        // CRÍTICO: Guardar también en el metadato que AEAT lee
        $aeat_key = "_wcpdf_{$document_type}_number";
        update_post_meta($order_id, $aeat_key, $custom_number);
        
        return $custom_number;
    }

    /**
     * Aplicar numeración específica para credit notes
     */
    public function applyCreditNoteNumber($number, $document_type, $order, $formatted = false)
    {
        // Si ya está formateado, devolver tal como está
        if ($formatted) {
            return $number;
        }

        // Determinar el tipo correcto
        if (strpos($document_type, 'simplified') !== false) {
            $doc_type = 'simplified-credit-note';
        } else {
            $doc_type = 'credit-note';
        }

        // Obtener order_id
        $order_id = is_object($order) ? $order->get_id() : $order;
        if (!$order_id) {
            return $number;
        }
        
        // Obtener vendor ID
        $vendor_id = $this->getVendorId($order_id);
        if (!$vendor_id) {
            return $number;
        }

        // Generar número personalizado
        $custom_number = $this->getCustomNumberForOrder($vendor_id, $order_id, $doc_type);
        
        return $custom_number ?: $number;
    }

    /**
     * Reemplazar el Order Number en PDFs con el número personalizado del vendor
     */
    public function replaceOrderNumberInPDF($order_number, $document)
    {
        if (!is_object($document)) {
            return $order_number;
        }

        $document_type = $document->get_type();
        
        // Solo para credit notes - usar el número personalizado como "Order Number"
        if (!in_array($document_type, ['credit-note', 'simplified-credit-note'])) {
            return $order_number;
        }

        // Obtener el número personalizado del vendor
        $custom_number = $this->getCustomNumberForDocument($document, $document_type);
        
        // Si tenemos número personalizado, usarlo como Order Number
        return $custom_number ?: $order_number;
    }

    /**
     * Modificar el número de orden mostrado en el PDF
     * Para credit notes, mostrar el número personalizado del vendor en lugar del order number
     */
    public function modifyOrderNumber($order_number, $document)
    {
        if (!is_object($document)) {
            return $order_number;
        }

        $document_type = $document->get_type();
        
        // Solo aplicar para notas de crédito
        if (!in_array($document_type, ['credit-note', 'simplified-credit-note'])) {
            return $order_number;
        }

        // Obtener order_id
        $order_id = $this->getOrderIdFromDocument($document);
        if (!$order_id) {
            return $order_number;
        }
        
        // Obtener vendor ID
        $vendor_id = $this->getVendorId($order_id);
        if (!$vendor_id) {
            return $order_number;
        }

        // Obtener datos de numeración del vendor
        $prefix_field = $this->getPrefixField($document_type);
        $suffix_field = $this->getSuffixField($document_type);
        
        $vendor_prefix = get_field($prefix_field, $vendor_id) ?: '';
        $vendor_suffix = get_field($suffix_field, $vendor_id) ?: '';
        
        if (empty($vendor_prefix)) {
            return $order_number;
        }

        // Obtener número personalizado del vendor
        $vendor_number = $this->getNextNumber($vendor_id, $order_id, $document_type);

        // Formatear: PREFIX + NUMERO + SUFFIX
        $number_str = str_pad($vendor_number, 4, '0', STR_PAD_LEFT);
        $custom_number = $vendor_prefix . $number_str . $vendor_suffix;
        
        return $custom_number;
    }

    /**
     * Modificar el título del documento para incluir el número personalizado
     */
    public function modifyDocumentTitle($title, $document)
    {
        if (!is_object($document)) {
            return $title;
        }

        $document_type = $document->get_type();
        
        // Solo aplicar para notas de crédito
        if (!in_array($document_type, ['credit-note', 'simplified-credit-note'])) {
            return $title;
        }

        // Obtener el número personalizado
        $custom_number = $this->getCustomNumberForDocument($document, $document_type);
        
        if ($custom_number) {
            // Reemplazar el título con el número personalizado
            $document_name = ($document_type === 'simplified-credit-note') ? 'Simplified Credit Note' : 'Credit Note';
            return $document_name . ' ' . $custom_number;
        }

        return $title;
    }

    /**
     * Hook alternativo para el número de WooCommerce
     */
    public function modifyWooOrderNumber($order_number, $order)
    {
        // Solo actuar si estamos generando un PDF
        if (!$this->isGeneratingPDF()) {
            return $order_number;
        }

        if (!is_object($order)) {
            return $order_number;
        }

        // Verificar si es un refund (nota de crédito)
        if ($order instanceof \WC_Order_Refund) {
            $order_id = $order->get_id();
            $vendor_id = $this->getVendorId($order_id);
            
            if ($vendor_id) {
                $custom_number = $this->getCustomNumberForOrder($vendor_id, $order_id, 'credit-note');
                if ($custom_number) {
                    return $custom_number;
                }
            }
        }

        return $order_number;
    }

    /**
     * Verificar si estamos generando un PDF
     */
    private function isGeneratingPDF()
    {
        // Verificar si es una llamada AJAX para generar PDF
        if (wp_doing_ajax() && isset($_GET['action']) && $_GET['action'] === 'generate_wpo_wcpdf') {
            return true;
        }
        
        // Verificar otros contextos de generación de PDF
        if (isset($_GET['wpo_wcpdf_action'])) {
            return true;
        }

        return false;
    }

    /**
     * Obtener número personalizado para un documento específico
     */
    private function getCustomNumberForDocument($document, $document_type)
    {
        $order_id = $this->getOrderIdFromDocument($document);
        if (!$order_id) {
            return null;
        }
        
        $vendor_id = $this->getVendorId($order_id);
        if (!$vendor_id) {
            return null;
        }

        return $this->getCustomNumberForOrder($vendor_id, $order_id, $document_type);
    }

    /**
     * Obtener número personalizado para un order específico
     */
    private function getCustomNumberForOrder($vendor_id, $order_id, $document_type)
    {
        $prefix_field = $this->getPrefixField($document_type);
        $suffix_field = $this->getSuffixField($document_type);
        
        $vendor_prefix = get_field($prefix_field, $vendor_id) ?: '';
        $vendor_suffix = get_field($suffix_field, $vendor_id) ?: '';
        
        if (empty($vendor_prefix)) {
            return null;
        }

        $vendor_number = $this->getNextNumber($vendor_id, $order_id, $document_type);
        $number_str = str_pad($vendor_number, 4, '0', STR_PAD_LEFT);
        
        return $vendor_prefix . $number_str . $vendor_suffix;
    }

    /**
     * Modificar las variables del template del PDF
     */
    public function modifyTemplateVars($template_vars, $document_type, $document)
    {
        // Solo aplicar para notas de crédito
        if (!in_array($document_type, ['credit-note', 'simplified-credit-note'])) {
            return $template_vars;
        }

        if (!is_object($document)) {
            return $template_vars;
        }

        // Obtener el número personalizado
        $custom_number = $this->getCustomNumberForDocument($document, $document_type);
        
        if ($custom_number) {
            // Modificar el número de orden en las variables del template
            if (isset($template_vars['order'])) {
                // Si el orden está disponible como objeto
                if (is_object($template_vars['order']) && method_exists($template_vars['order'], 'get_order_number')) {
                    // Crear un wrapper para sobrescribir el método get_order_number
                    $template_vars['order'] = new class($template_vars['order'], $custom_number) {
                        private $original_order;
                        private $custom_number;

                        public function __construct($original_order, $custom_number) {
                            $this->original_order = $original_order;
                            $this->custom_number = $custom_number;
                        }

                        public function get_order_number() {
                            return $this->custom_number;
                        }

                        public function __call($method, $args) {
                            return call_user_func_array([$this->original_order, $method], $args);
                        }

                        public function __get($property) {
                            return $this->original_order->$property;
                        }
                    };
                }
            }

            // También agregar el número personalizado como variable separada
            $template_vars['vendor_order_number'] = $custom_number;
        }

        return $template_vars;
    }

    /**
     * Modificar nombre de la tienda en PDFs
     */
    public function modifyPDFShopName($shop_name, $document)
    {
        $vendor_data = $this->getVendorDataFromDocument($document);
        if ($vendor_data && !empty($vendor_data['corporate_name'])) {
            return $vendor_data['corporate_name'];
        }
        return $shop_name;
    }

    /**
     * Modificar dirección de la tienda en PDFs
     * Usa el mismo patrón que el plugin oficial: nl2br() + eliminación de \n
     */
    public function modifyPDFShopAddress($shop_address, $document)
    {
        $vendor_data = $this->getVendorDataFromDocument($document);
        if ($vendor_data) {
            $address_parts = [];
            
            // Dirección principal
            if (!empty($vendor_data['address'])) {
                $address_parts[] = $vendor_data['address'];
            }
            
            // Código postal y ciudad en línea separada
            if (!empty($vendor_data['postal_code']) && !empty($vendor_data['city'])) {
                $address_parts[] = $vendor_data['postal_code'] . ' ' . $vendor_data['city'];
            }
            
            if (!empty($address_parts)) {
                // Seguir patrón oficial: crear string con \n, aplicar nl2br(), luego eliminar \n
                $formatted_address = implode("\n", $address_parts);
                $formatted_address = nl2br($formatted_address);
                $formatted_address = str_replace("\n", '', $formatted_address);
                
                return $formatted_address;
            }
        }
        return $shop_address;
    }



    /**
     * Hook alternativo para dirección formateada - Con formato HTML
     * Usa el mismo patrón que wpo_wcpdf_format_address()
     */
    public function modifyFormattedShopAddress($formatted_address, $document)
    {
        $vendor_data = $this->getVendorDataFromDocument($document);
        if ($vendor_data) {
            $address_lines = [];
            
            // Dirección principal
            if (!empty($vendor_data['address'])) {
                $address_lines[] = $vendor_data['address'];
            }
            
            // Código postal y ciudad
            if (!empty($vendor_data['postal_code']) && !empty($vendor_data['city'])) {
                $address_lines[] = $vendor_data['postal_code'] . ' ' . $vendor_data['city'];
            }
            
            if (!empty($address_lines)) {
                // Seguir patrón oficial: crear string con \n, aplicar nl2br(), luego eliminar \n
                $formatted_address = implode("\n", $address_lines);
                $formatted_address = nl2br($formatted_address);
                $formatted_address = str_replace("\n", '', $formatted_address);
                
                return $formatted_address;
            }
        }
        return $formatted_address;
    }

    /**
     * Modificar configuración global del plugin para cambiar VAT dinámicamente
     * Funciona igual que modifyPDFShopName y modifyPDFShopAddress
     */
    public function modifyVATInGlobalSettings($settings)
    {
        // Solo actuar si estamos generando un PDF
        if (!$this->isGeneratingPDF() && !wp_doing_ajax()) {
            return $settings;
        }

        // Intentar detectar el contexto actual del vendor
        $vendor_vat = $this->getCurrentVendorVAT();
        
        if ($vendor_vat) {
            // IMPORTANTE: Cambiar dinámicamente el VAT global por el del vendor
            // Esto es lo mismo que hacemos con shop_name y shop_address
            $settings['vat_number'] = $vendor_vat;
        } else {
            // Si no hay vendor específico, limpiar el VAT para no mostrar nada
            $settings['vat_number'] = '';
        }
        
        return $settings;
    }

    /**
     * Modificar número de VAT en PDFs
     */
    public function modifyPDFVatNumber($vat_number, $document)
    {
        $vendor_data = $this->getVendorDataFromDocument($document);
        
        if ($vendor_data && !empty($vendor_data['tax_id'])) {
            return $vendor_data['tax_id'];
        }
        
        // Si no hay vendor data, devolver vacío para ocultar
        return '';
    }

    /**
     * Obtener VAT del vendor actual basado en el contexto
     * Usa la misma lógica que modifyPDFShopName y modifyPDFShopAddress
     */
    private function getCurrentVendorVAT()
    {
        // Método 1: Desde parámetros de la petición (generación manual)
        if (isset($_GET['order_ids']) && isset($_GET['document_type'])) {
            $order_ids = explode(',', $_GET['order_ids']);
            $order_id = intval($order_ids[0]);
            
            if ($order_id) {
                $vendor_data = $this->getVendorData($order_id);
                return $vendor_data['tax_id'] ?? '';
            }
        }
        
        // Método 2: Desde contexto de documento actual (si existe)
        // Este método funciona cuando ya hay un documento siendo procesado
        global $wpo_wcpdf;
        if (isset($wpo_wcpdf->current_document) && is_object($wpo_wcpdf->current_document)) {
            $document = $wpo_wcpdf->current_document;
            $vendor_data = $this->getVendorDataFromDocument($document);
            return $vendor_data['tax_id'] ?? '';
        }
        
        // Si no podemos detectar el vendor, devolver vacío para ocultar VAT
        return '';
    }

    /**
     * Asegurar compatibilidad con AEAT después de generar PDF
     */
    public function ensureAEATCompatibility($document, $order)
    {
        if (!is_object($document)) {
            return;
        }

        $document_type = $document->get_type();
        $supported_types = ['invoice', 'simplified-invoice', 'credit-note', 'simplified-credit-note'];
        
        if (!in_array($document_type, $supported_types)) {
            return;
        }

        // Obtener order_id
        $order_id = $this->getOrderIdFromDocument($document);
        if (!$order_id) {
            return;
        }

        // Obtener vendor ID
        $vendor_id = $this->getVendorId($order_id);
        if (!$vendor_id) {
            return;
        }

        // Verificar si ya existe el número en formato AEAT
        $aeat_key = "_wcpdf_{$document_type}_number";
        $existing_aeat_number = get_post_meta($order_id, $aeat_key, true);
        
        if ($existing_aeat_number) {
            return; // Ya existe, no hacer nada
        }

        // Obtener el número personalizado del vendor y guardarlo en formato AEAT
        $custom_number = $this->getCustomNumberForOrder($vendor_id, $order_id, $document_type);
        
        if ($custom_number) {
            // GUARDAR EN AMBOS FORMATOS para compatibilidad completa
            // 1. Formato con guión (original)
            update_post_meta($order_id, $aeat_key, $custom_number);
            
            // 2. Formato con guión bajo (que usa AEATApiBridge)
            $aeat_key_underscore = "_wcpdf_" . str_replace('-', '_', $document_type) . "_number";
            update_post_meta($order_id, $aeat_key_underscore, $custom_number);
        }
    }

    // ========== MÉTODOS DE UTILIDAD ==========

    /**
     * Obtener vendor_id desde un order_id
     */
    private function getVendorId($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return null;
        }

        $school_id = $this->getSchoolId($order);
        if (!$school_id) {
            return null;
        }

        return get_field('vendor', $school_id);
    }

    /**
     * Obtener school_id del order
     */
    private function getSchoolId($order)
    {
        // Si es un refund, obtener el pedido padre
        if ($order instanceof \WC_Order_Refund) {
            $parent_id = $order->get_parent_id();
            if ($parent_id) {
                $order = wc_get_order($parent_id);
            }
        }

        // Buscar en diferentes campos
        $fields = ['_school_id', 'school_id', '_school', 'school'];
        
        foreach ($fields as $field) {
            $value = $order->get_meta($field);
            if ($value) {
                return (int) $value;
            }
        }

        // Buscar en datos del usuario como fallback
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
     * Obtener datos del vendor para un documento
     */
    private function getVendorDataFromDocument($document)
    {
        if (!is_object($document)) {
            return null;
        }

        $order_id = $this->getOrderIdFromDocument($document);
        if (!$order_id) {
            return null;
        }

        return $this->getVendorData($order_id);
    }

    /**
     * Obtener datos completos del vendor
     */
    private function getVendorData($order_id)
    {
        $vendor_id = $this->getVendorId($order_id);
        if (!$vendor_id) {
            return null;
        }

        // Obtener tax_id y limpiarlo de caracteres no deseados
        $tax_id = get_field('_taxIdentificationNumber', $vendor_id) ?: '';
        $tax_id = trim($tax_id); // Eliminar espacios
        $tax_id = str_replace(':', '', $tax_id); // Eliminar dos puntos

        return [
            'vendor_id' => $vendor_id,
            'corporate_name' => get_field('_corporateName', $vendor_id) ?: '',
            'tax_id' => $tax_id,
            'address' => get_field('_address', $vendor_id) ?: '',
            'city' => get_field('_town', $vendor_id) ?: '',
            'postal_code' => get_field('_postCode', $vendor_id) ?: '',
        ];
    }

    /**
     * Obtener order_id de un documento
     */
    private function getOrderIdFromDocument($document)
    {
        if (!is_object($document)) {
            return null;
        }
        
        if (method_exists($document, 'get_order_id')) {
            return $document->get_order_id();
        }
        
        if (property_exists($document, 'order_id')) {
            return $document->order_id;
        }
        
        return null;
    }

    /**
     * Obtener siguiente número de documento
     */
    private function getNextNumber($vendor_id, $order_id, $document_type)
    {
        // Verificar si ya tiene número asignado
        $assigned_key = "_vendor_{$document_type}_number_{$vendor_id}";
        $assigned_number = get_post_meta($order_id, $assigned_key, true);
        
        if ($assigned_number) {
            return (int) $assigned_number;
        }
        
        // Obtener y actualizar el contador
        $number_field = $this->getNumberField($document_type);
        $current_number = get_field($number_field, $vendor_id) ?: 0;
        $next_number = (int) $current_number + 1;
        
        // Actualizar campo ACF
        update_field($number_field, $next_number, $vendor_id);
        
        // Guardar número asignado
        update_post_meta($order_id, $assigned_key, $next_number);
        
        // IMPORTANTE: También guardar en el formato que espera AEAT
        $vendor_prefix = get_field($this->getPrefixField($document_type), $vendor_id) ?: '';
        $vendor_suffix = get_field($this->getSuffixField($document_type), $vendor_id) ?: '';
        
        if (!empty($vendor_prefix)) {
            $number_str = str_pad($next_number, 4, '0', STR_PAD_LEFT);
            $formatted_number = $vendor_prefix . $number_str . $vendor_suffix;
            
            // Guardar en formato AEAT: _wcpdf_{document_type}_number
            $aeat_key = "_wcpdf_{$document_type}_number";
            update_post_meta($order_id, $aeat_key, $formatted_number);
        }
        
        return $next_number;
    }

    /**
     * Obtener campo de prefijo según tipo de documento
     */
    private function getPrefixField($document_type)
    {
        $fields = [
            'invoice' => '_prefix',
            'simplified-invoice' => '_simplified_prefix',
            'credit-note' => '_credit_note_prefix',
            'simplified-credit-note' => '_simplified_credit_note_prefix'
        ];
        
        return $fields[$document_type] ?? '_prefix';
    }

    /**
     * Obtener campo de sufijo según tipo de documento
     */
    private function getSuffixField($document_type)
    {
        $fields = [
            'invoice' => '_suffix',
            'simplified-invoice' => '_simplified_suffix',
            'credit-note' => '_credit_note_suffix',
            'simplified-credit-note' => '_simplified_credit_note_suffix'
        ];
        
        return $fields[$document_type] ?? '_suffix';
    }

    /**
     * Obtener campo de número según tipo de documento
     */
    private function getNumberField($document_type)
    {
        $fields = [
            'invoice' => '_invoice_number',
            'simplified-invoice' => '_simplified_invoice_number',
            'credit-note' => '_credit_note_number',
            'simplified-credit-note' => '_simplified_credit_note_number'
        ];
        
        return $fields[$document_type] ?? '_invoice_number';
    }

    // ========== MÉTODOS ADICIONALES PARA HOOKS ESPECÍFICOS ==========

    /**
     * Aplicar numeración para facturas normales y simplificadas
     */
    public function applyInvoiceNumber($number, $document_type, $order, $formatted = false)
    {
        // Determinar el tipo correcto
        $doc_type = (strpos($document_type, 'simplified') !== false) ? 'simplified-invoice' : 'invoice';
        
        // Reutilizar la lógica principal
        return $this->applyVendorNumbering($number, null, $doc_type, $order);
    }

    /**
     * Personalizar configuración de numeración
     */
    public function customizeNumberSettings($settings, $document)
    {
        if (!is_object($document)) {
            return $settings;
        }

        $document_type = $document->get_type();
        $supported_types = ['invoice', 'simplified-invoice', 'credit-note', 'simplified-credit-note'];
        
        if (!in_array($document_type, $supported_types)) {
            return $settings;
        }

        // Obtener vendor para este documento
        $order_id = $this->getOrderIdFromDocument($document);
        $vendor_id = $this->getVendorId($order_id);
        
        if (!$vendor_id) {
            return $settings;
        }

        // Si el vendor tiene prefijo, limpiar configuración por defecto
        $prefix_field = $this->getPrefixField($document_type);
        $vendor_prefix = get_field($prefix_field, $vendor_id) ?: '';
        
        if (!empty($vendor_prefix)) {
            // Limpiar configuración para control total
            $settings['prefix'] = '';
            $settings['suffix'] = '';
            $settings['padding'] = '';
        }

        return $settings;
    }

    /**
     * Aplicar formato de numeración personalizada
     */
    public function applyVendorNumberingFormat($formatted_number, $number_object, $document, $order)
    {
        if (!is_object($document)) {
            return $formatted_number;
        }

        $document_type = $document->get_type();
        
        // Reutilizar la lógica principal
        return $this->applyVendorNumbering($formatted_number, $document, $document_type, $order);
    }

    /**
     * Override final de número de documento
     */
    public function overrideDocumentNumber($document_number, $document)
    {
        if (!is_object($document)) {
            return $document_number;
        }

        $document_type = $document->get_type();
        
        // Reutilizar la lógica principal
        return $this->applyVendorNumbering($document_number, $document, $document_type);
    }
}
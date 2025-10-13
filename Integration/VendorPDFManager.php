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
        // ⚠️ ESTRATEGIA DE PROTECCIÓN: NO machacar números de facturas simplificadas existentes
        // ✅ Solo aplicar numeración personalizada para credit-notes o cuando no hay número previo
        
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
        
        // ⚠️ HOOKS REMOVIDOS: No necesitamos interceptar order numbers ni títulos
        // Los dejamos sin modificar para que mantengan sus valores originales
        
        // Hooks para personalizar datos del vendor en PDFs - Prioridad más alta
        add_filter('wpo_wcpdf_shop_name', [$this, 'modifyPDFShopName'], 5, 2);
        add_filter('wpo_wcpdf_shop_address', [$this, 'modifyPDFShopAddress'], 5, 2);
        
        // Hook alternativo para la dirección completa - Prioridad más alta
        add_filter('wpo_wcpdf_formatted_shop_address', [$this, 'modifyFormattedShopAddress'], 5, 2);
        
        // 🎯 HOOKS CRÍTICOS PARA AEAT - Asegurar compatibilidad
        add_action('wpo_wcpdf_document_created_manually', [$this, 'ensureAEATCompatibility'], 1, 2);
        add_action('wpo_wcpdf_save_document', [$this, 'ensureAEATCompatibility'], 1, 2);
        add_action('wpo_wcpdf_after_pdf_created', [$this, 'ensureAEATCompatibility'], 1, 2);
        
        // 🚀 HOOKS POST-CREACIÓN: Solo para confirmación y compatibilidad AEAT
        add_action('wpo_wcpdf_after_pdf_created', [$this, 'finalizeNumberAssignment'], 99, 2);
        add_action('wpo_wcpdf_document_saved', [$this, 'finalizeNumberAssignment'], 99, 2);
        
        // 🎯 INYECTAR VENDOR DATA EN SETTINGS GENERAL (coc_number Y vat_number)
        add_filter('option_wpo_wcpdf_settings_general', [$this, 'injectVendorDataInSettings'], 10);
        
        // 🎯 HOOK MÁS ESPECÍFICO: Interceptar settings del documento antes de que se use
        add_filter('wpo_wcpdf_document_store_settings', [$this, 'shouldStoreSettings'], 10, 2);
        
        // 🎯 HOOKS MÚLTIPLES para interceptar el Tax ID en diferentes momentos
        add_filter('wpo_wcpdf_shop_coc_number', [$this, 'filterShopCocNumber'], 5, 2);
        add_filter('wpo_wcpdf_formatted_shop_coc_number', [$this, 'filterShopCocNumber'], 5, 2);
        
        // 🎯 HOOKS PARA VAT NUMBER también (por si usa ese campo)
        add_filter('wpo_wcpdf_shop_vat_number', [$this, 'filterShopVatNumber'], 5, 2);
        add_filter('wpo_wcpdf_formatted_shop_vat_number', [$this, 'filterShopVatNumber'], 5, 2);
        
        // 🎯 HOOKS ESPECÍFICOS PARA SETTINGS TEXT (el que realmente controla el valor)
        add_filter('wpo_wcpdf_coc_number_settings_text', [$this, 'filterCocNumberSettingsText'], 5, 2);
        add_filter('wpo_wcpdf_vat_number_settings_text', [$this, 'filterVatNumberSettingsText'], 5, 2);
        
        // 🎯 HOOK MÁS TEMPRANO: Antes de que el documento se inicialice
        add_action('wpo_wcpdf_init_document', [$this, 'injectTaxIdEarly'], 1, 1);
        
        // 🎯 ESTABLECER VENDOR TAX ID ANTES DE PROCESAR DOCUMENTO
        add_action('wpo_wcpdf_before_document', [$this, 'setCurrentVendorTaxId'], 5, 2);
        
        // 🎯 HOOK ADICIONAL: Capturar contexto después de crear documento
        add_action('wpo_wcpdf_created_document', [$this, 'cacheDocumentContext'], 1, 1);
        
        // 🛡️ HOOKS ESPECÍFICOS PARA DETECTAR MODO PREVIEW DEL PLUGIN OFICIAL
        add_action('wpo_wcpdf_preview_after_reload_settings', [$this, 'markPreviewMode'], 1);
        add_action('wp_ajax_wpo_wcpdf_preview', [$this, 'markPreviewMode'], 1);

    }

    /**
     * 🎯 MÉTODO PRINCIPAL - Aplicar numeración personalizada del vendor
     * Maneja TODOS los hooks de numeración con parámetros variables
     */
    public function applyVendorNumbering($formatted_number, $document, $document_type = null, $order = null)
    {
        // �️ PROTECCIÓN CRÍTICA: NO procesar vistas previas del admin
        if ($this->isAdminPreview()) {

            // Para vistas previas, devolver un número simulado sin incrementar contadores
            // En preview, permitir que el plugin maneje la numeracion normalmente
            // PERO marcar una flag para que getTemporaryNumber no incremente
            $GLOBALS['wpo_wcpdf_preview_no_increment'] = true;
            // Continuar con la logica normal para que el plugin no se rompa
        }
        
        // �🐛 DEBUG TEMPORAL - Log de parámetros de entrada SIEMPRE




        

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
        
        // Obtener vendor ID SIEMPRE (independiente de la numeración)
        $vendor_id = $this->getVendorId($order_id);
        
        if (!$vendor_id) {
            return $formatted_number;
        }
            // Verificar si debemos aplicar numeración personalizada
        if (!$this->shouldApplyCustomNumbering($document_type, $order_id)) {
            // NO aplicar numeración personalizada, pero SÍ procesar Tax ID
            // (Este es el caso de las facturas normales que ya tienen número)
            
            // 🔧 FIX HPOS: Obtener el número correcto usando WooCommerce nativo
            $normalized_document_type = str_replace('-', '_', $document_type);
            $meta_key = "_wcpdf_{$normalized_document_type}_number";
            $order_obj = wc_get_order($order_id);
            $existing_meta = $order_obj ? $order_obj->get_meta($meta_key) : '';
            

            
            if (!empty($existing_meta)) {
                return $existing_meta; // Devolver el número guardado (ej: "00001-2025")
            }
            
            return $formatted_number; // Fallback al número original si no hay meta
        }

        // Obtener datos de numeración del vendor
        $prefix_field = $this->getPrefixField($document_type);
        $suffix_field = $this->getSuffixField($document_type);
        
        $vendor_prefix = get_field($prefix_field, $vendor_id) ?: '';
        $vendor_suffix = get_field($suffix_field, $vendor_id) ?: '';
        
        if (empty($vendor_prefix)) {
            return $formatted_number;
        }

        // 🚀 NUEVO: Obtener número temporal (SIN incrementar)
        $vendor_number = $this->getTemporaryNumber($vendor_id, $order_id, $document_type);

        // Formatear: PREFIX + NUMERO + SUFFIX
        $number_str = str_pad($vendor_number, 5, '0', STR_PAD_LEFT);
        $custom_number = $vendor_prefix . $number_str . $vendor_suffix;
        
        // PROTECCION: NO machacar numeros de facturas simplificadas para AEAT
        // Solo guardar en meta para credit notes, NO para invoices/simplified-invoices
        if (strpos($document_type, 'credit-note') !== false) {
            $aeat_key = "_wcpdf_{$document_type}_number";
            update_post_meta($order_id, $aeat_key, $custom_number);
        }
        
        // Limpiar flag de preview para evitar que afecte otros procesos
        if (isset($GLOBALS['wpo_wcpdf_preview_no_increment'])) {
            unset($GLOBALS['wpo_wcpdf_preview_no_increment']);
        }
        
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

        // 🚀 NUEVO: Usar número temporal
        $vendor_number = $this->getTemporaryNumber($vendor_id, $order_id, $document_type);
        $number_str = str_pad($vendor_number, 4, '0', STR_PAD_LEFT);
        
        return $vendor_prefix . $number_str . $vendor_suffix;
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
     * Inyectar datos del vendor en settings general - MÉTODO UNIFICADO Y AGRESIVO
     */
    public function injectVendorDataInSettings($settings)
    {
        $vendor_nif = $this->getCurrentVendorNifSimple();
        
        if ($vendor_nif) {
            // Inyectar en MÚLTIPLES campos posibles
            $settings['coc_number'] = $vendor_nif;
            $settings['vat_number'] = $vendor_nif;
            $settings['shop_coc_number'] = $vendor_nif;
            $settings['company_coc_number'] = $vendor_nif;
            $settings['tax_id'] = $vendor_nif;
            $settings['nif'] = $vendor_nif;
        }
        
        return $settings;
    }

    /**
     * Interceptar si el documento debe almacenar settings - FORZAR REGENERACIÓN
     */
    public function shouldStoreSettings($store_settings, $document)
    {
        if (!is_object($document)) {
            return $store_settings;
        }

        $document_type = method_exists($document, 'get_type') ? $document->get_type() : 'unknown';
        
        // Para facturas y facturas simplificadas, SIEMPRE usar settings frescos
        if (in_array($document_type, ['invoice', 'simplified-invoice'])) {
            return false; // No almacenar settings históricos, usar siempre actuales
        }
        
        return $store_settings;
    }

    /**
     * Filtro DIRECTO para el COC number - MÉTODO ESPECÍFICO
     */
    public function filterShopCocNumber($coc_number, $document)
    {
        if (!is_object($document)) {
            return $coc_number;
        }

        $order_id = $this->getOrderIdFromDocument($document);
        if ($order_id) {
            $vendor_id = $this->getVendorId($order_id);
            if ($vendor_id) {
                $tax_id = get_field('_taxIdentificationNumber', $vendor_id) ?: '';
                $cleaned_tax_id = trim(str_replace(':', '', $tax_id));
                
                if ($cleaned_tax_id) {
                    return $cleaned_tax_id;
                }
            }
        }
        return $coc_number;
    }

    /**
     * Filtro para VAT number (por si el plugin usa este campo en lugar de coc_number)
     */
    public function filterShopVatNumber($vat_number, $document)
    {
        if (!is_object($document)) {
            return $vat_number;
        }

        $order_id = $this->getOrderIdFromDocument($document);
        if ($order_id) {
            $vendor_id = $this->getVendorId($order_id);
            if ($vendor_id) {
                $tax_id = get_field('_taxIdentificationNumber', $vendor_id) ?: '';
                $cleaned_tax_id = trim(str_replace(':', '', $tax_id));
                
                if ($cleaned_tax_id) {
                    return $cleaned_tax_id;
                }
            }
        }
        return $vat_number;
    }

    /**
     * Inyectar Tax ID muy temprano en el proceso del documento
     */
    public function injectTaxIdEarly($document)
    {
        if (!is_object($document)) {
            return;
        }

        $order_id = $this->getOrderIdFromDocument($document);
        if ($order_id) {
            $vendor_id = $this->getVendorId($order_id);
            if ($vendor_id) {
                $tax_id = get_field('_taxIdentificationNumber', $vendor_id) ?: '';
                $cleaned_tax_id = trim(str_replace(':', '', $tax_id));
                
                if ($cleaned_tax_id) {
                    // Inyectar en múltiples formas
                    $GLOBALS['vendor_tax_id'] = $cleaned_tax_id;
                    
                    // Cache el order_id para uso posterior
                    wp_cache_set('wpo_wcpdf_current_order_id', $order_id, 'wpo_wcpdf', 300);
                    
                    // Hook temporal para modificar settings
                    add_filter('option_wpo_wcpdf_settings_general', function($settings) use ($cleaned_tax_id) {
                        $settings['coc_number'] = $cleaned_tax_id;
                        $settings['vat_number'] = $cleaned_tax_id;
                        $settings['shop_coc_number'] = $cleaned_tax_id;
                        $settings['company_coc_number'] = $cleaned_tax_id;
                        $settings['tax_id'] = $cleaned_tax_id;
                        $settings['nif'] = $cleaned_tax_id;
                        return $settings;
                    }, 1);
                }
            }
        }
    }

    /**
     * Establecer Tax ID del vendor en variable global antes de procesar documento
     */
    public function setCurrentVendorTaxId($document_type, $document)
    {
        if (!is_object($document)) {
            return;
        }

        $order_id = $this->getOrderIdFromDocument($document);
        if ($order_id) {
            $vendor_data = $this->getVendorData($order_id);
            if (!empty($vendor_data['tax_id'])) {
                $GLOBALS['current_vendor_tax_id'] = $vendor_data['tax_id'];
            }
        }
    }

    /**
     * Obtener NIF del vendor actual - MÉTODO MEJORADO CON MÚLTIPLES FUENTES
     */
    private function getCurrentVendorNifSimple()
    {
        // Método 1: Desde parámetros GET (generación manual de PDFs)
        if (isset($_GET['order_ids'])) {
            $order_ids = explode(',', $_GET['order_ids']);
            $order_id = intval($order_ids[0]);
            
            if ($order_id) {
                $vendor_id = $this->getVendorId($order_id);
                if ($vendor_id) {
                    $tax_id = get_field('_taxIdentificationNumber', $vendor_id) ?: '';
                    $cleaned = trim(str_replace(':', '', $tax_id));
                    return $cleaned;
                }
            }
        }

        // Método 2: Desde parámetros POST (AJAX)
        if (isset($_POST['order_ids'])) {
            $order_ids = is_array($_POST['order_ids']) ? $_POST['order_ids'] : explode(',', $_POST['order_ids']);
            $order_id = intval($order_ids[0]);
            
            if ($order_id) {
                $vendor_id = $this->getVendorId($order_id);
                if ($vendor_id) {
                    $tax_id = get_field('_taxIdentificationNumber', $vendor_id) ?: '';
                    $cleaned = trim(str_replace(':', '', $tax_id));
                    return $cleaned;
                }
            }
        }
        
        // Método 3: NUEVO - Desde cache establecido por injectTaxIdEarly
        $cached_order_id = wp_cache_get('wpo_wcpdf_current_order_id', 'wpo_wcpdf');
        if ($cached_order_id) {
            $vendor_id = $this->getVendorId($cached_order_id);
            if ($vendor_id) {
                $tax_id = get_field('_taxIdentificationNumber', $vendor_id) ?: '';
                $cleaned = trim(str_replace(':', '', $tax_id));
                return $cleaned;
            }
        }

        // Método 4: NUEVO - Desde variable global si fue establecida por otros hooks
        if (isset($GLOBALS['vendor_tax_id'])) {
            return $GLOBALS['vendor_tax_id'];
        }
        
        // Método 5: NUEVO - Intentar detectar desde el contexto actual del documento WooCommerce PDF
        global $wpo_wcpdf;
        if (isset($wpo_wcpdf->current_document) && is_object($wpo_wcpdf->current_document)) {
            $order_id = $this->getOrderIdFromDocument($wpo_wcpdf->current_document);
            if ($order_id) {
                $vendor_id = $this->getVendorId($order_id);
                if ($vendor_id) {
                    $tax_id = get_field('_taxIdentificationNumber', $vendor_id) ?: '';
                    $cleaned = trim(str_replace(':', '', $tax_id));
                    return $cleaned;
                }
            }
        }
        
        // Método 6: NUEVO - Buscar en el último order procesado (último recurso)
        if (defined('WC_PDF_IPS_VERSION')) {
            // Intentar obtener de la sesión o cache temporal
            $temp_order_id = wp_cache_get('wpo_wcpdf_current_order_id', 'wpo_wcpdf');
            if ($temp_order_id) {
                $vendor_id = $this->getVendorId($temp_order_id);
                if ($vendor_id) {
                    $tax_id = get_field('_taxIdentificationNumber', $vendor_id) ?: '';
                    $cleaned = trim(str_replace(':', '', $tax_id));
                    return $cleaned;
                }
            }
        }
        return '';
    }

    /**
     * Obtener NIF del vendor actual para inyectar en coc_number - MÉTODO ORIGINAL COMO BACKUP
     */
    private function getCurrentVendorNif()
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

        // Método 2: Desde POST (AJAX requests)
        if (isset($_POST['order_ids'])) {
            $order_ids = is_array($_POST['order_ids']) ? $_POST['order_ids'] : explode(',', $_POST['order_ids']);
            $order_id = intval($order_ids[0]);
            
            if ($order_id) {
                $vendor_data = $this->getVendorData($order_id);
                return $vendor_data['tax_id'] ?? '';
            }
        }
        
        // Método 3: Desde contexto de documento actual (si existe)
        global $wpo_wcpdf;
        if (isset($wpo_wcpdf->current_document) && is_object($wpo_wcpdf->current_document)) {
            $document = $wpo_wcpdf->current_document;
            $vendor_data = $this->getVendorDataFromDocument($document);
            return $vendor_data['tax_id'] ?? '';
        }

        // Método 4: Desde variable global si está disponible
        if (isset($GLOBALS['current_vendor_tax_id'])) {
            return $GLOBALS['current_vendor_tax_id'];
        }
        
        // Si no podemos detectar el vendor, devolver vacío
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

        // PROTECCIÓN: Solo guardar números personalizados para credit notes, NO para facturas
        if (strpos($document_type, 'credit-note') !== false) {
            // Obtener el número personalizado del vendor y guardarlo en formato AEAT
            $custom_number = $this->getCustomNumberForOrder($vendor_id, $order_id, $document_type);
            
            if ($custom_number) {
                // GUARDAR EN AMBOS FORMATOS para compatibilidad completa (solo credit notes)
                // 1. Formato con guión (original)
                update_post_meta($order_id, $aeat_key, $custom_number);
                
                // 2. Formato con guión bajo (que usa AEATApiBridge)
                $aeat_key_underscore = "_wcpdf_" . str_replace('-', '_', $document_type) . "_number";
                update_post_meta($order_id, $aeat_key_underscore, $custom_number);
            }
        }
    }

    // ========== MÉTODOS DE UTILIDAD ==========

    /**
     * Obtener vendor_id desde un order_id
     * Ahora simplificado: las master orders que paga el centro ya tienen vendor_id asignado directamente
     */
    private function getVendorId($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return null;
        }

        // Primero, verificar si la orden ya tiene vendor_id directamente asignado
        // (esto aplica para master orders que paga el centro y órdenes normales)
        $vendor_id = $order->get_meta('_vendor_id');
        if ($vendor_id) {
            return (int) $vendor_id;
        }

        // Fallback: buscar vendor a través del school_id (para órdenes más antiguas)
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
     * Obtener order_id de un documento - VERSIÓN MEJORADA PARA HPOS
     */
    private function getOrderIdFromDocument($document)
    {
        if (!is_object($document)) {
            return null;
        }
        
        // Método 1: get_order_id()
        if (method_exists($document, 'get_order_id')) {
            $order_id = $document->get_order_id();
            if ($order_id) {
                return $order_id;
            }
        }
        
        // Método 2: propiedad order_id
        if (property_exists($document, 'order_id')) {
            return $document->order_id;
        }
        
        // Método 3: get_order() y luego get_id()
        if (method_exists($document, 'get_order')) {
            $order = $document->get_order();
            if ($order && method_exists($order, 'get_id')) {
                return $order->get_id();
            }
        }
        
        // Método 4: Buscar en propiedades del objeto DocumentNumber
        if (property_exists($document, 'order')) {
            $order = $document->order;
            if ($order && method_exists($order, 'get_id')) {
                return $order->get_id();
            }
        }
        
        // Método 5: Debugging - inspeccionar el objeto para encontrar order_id
        $class_name = get_class($document);
        if (strpos($class_name, 'DocumentNumber') !== false) {
            // Para DocumentNumber, intentar diferentes propiedades
            $reflection = new \ReflectionClass($document);
            $properties = $reflection->getProperties();
            
            foreach ($properties as $property) {
                $property->setAccessible(true);
                $value = $property->getValue($document);
                
                // Si encontramos una propiedad que parece ser un order
                if (is_object($value) && method_exists($value, 'get_id')) {
                    return $value->get_id();
                }
                
                // Si encontramos directamente un order_id numérico
                if ($property->getName() === 'order_id' && is_numeric($value)) {
                    return intval($value);
                }
            }
        }
        
        return null;
    }

    /**
     * 🚀 NUEVO: Sistema de reserva de números inspirado en SequentialNumberStore
     * Reserva inmediatamente el siguiente número disponible para evitar duplicados
     */
    private function getTemporaryNumber($vendor_id, $order_id, $document_type)
    {
        // 0. PROTECCION CRITICA: Detectar si es vista previa del admin (NO incrementar numeros)
        if ($this->isAdminPreview() || (isset($GLOBALS['wpo_wcpdf_preview_no_increment']) && $GLOBALS['wpo_wcpdf_preview_no_increment'])) {

            // Devolver numero simulado sin incrementar contador real
            $number_field = $this->getNumberField($document_type);
            $current_number = get_field($number_field, $vendor_id) ?: 0;
            return (int) $current_number + 1;
        }
        
        // 1. Verificar si ya tiene número definitivo asignado
        $assigned_key = "_vendor_{$document_type}_number_{$vendor_id}";
        $assigned_number = get_post_meta($order_id, $assigned_key, true);
        
        if ($assigned_number) {

            return (int) $assigned_number;
        }
        
        // 2. SISTEMA DE SEMÁFORO para evitar condiciones de carrera (como el plugin original)
        $semaphore_key = "vendor_number_lock_{$vendor_id}_{$document_type}";
        $lock_timeout = 10; // 10 segundos máximo
        $lock_value = time();
        
        // Intentar obtener bloqueo
        $existing_lock = get_transient($semaphore_key);
        if ($existing_lock && ($lock_value - $existing_lock) < $lock_timeout) {
            // Esperar y reintentar una vez
            usleep(100000); // 0.1 segundos
            $existing_lock = get_transient($semaphore_key);
            
            if ($existing_lock && ($lock_value - $existing_lock) < $lock_timeout) {

                // Fallback: devolver número no reservado (puede causar duplicados pero evita bloqueo)
                $number_field = $this->getNumberField($document_type);
                $current_number = get_field($number_field, $vendor_id) ?: 0;
                return (int) $current_number + 1;
            }
        }
        
        // 3. Establecer bloqueo
        set_transient($semaphore_key, $lock_value, $lock_timeout);
        
        try {
            // 4. RESERVAR INMEDIATAMENTE EL SIGUIENTE NÚMERO (como hace SequentialNumberStore::increment)
            $number_field = $this->getNumberField($document_type);
            $current_number = get_field($number_field, $vendor_id) ?: 0;
            $next_number = (int) $current_number + 1;
            
            // 5. Actualizar INMEDIATAMENTE el campo ACF para reservar el número
            $update_success = update_field($number_field, $next_number, $vendor_id);
            
            if ($update_success) {
                // 6. Asignar este número específicamente a esta orden
                update_post_meta($order_id, $assigned_key, $next_number);
                

                
                // 7. Liberar bloqueo
                delete_transient($semaphore_key);
                
                return $next_number;
            } else {

                
                // Liberar bloqueo
                delete_transient($semaphore_key);
                
                // Fallback
                return $next_number;
            }
            
        } catch (\Exception $e) {

            
            // Liberar bloqueo en caso de excepción
            delete_transient($semaphore_key);
            
            // Fallback básico
            $number_field = $this->getNumberField($document_type);
            $current_number = get_field($number_field, $vendor_id) ?: 0;
            return (int) $current_number + 1;
        }
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
            
            // PROTECCIÓN: Solo guardar en metadatos AEAT para credit notes, NO para facturas
            if (strpos($document_type, 'credit-note') !== false) {
                $aeat_key = "_wcpdf_{$document_type}_number";
                update_post_meta($order_id, $aeat_key, $formatted_number);
            }
        }
        
        return $next_number;
    }

    /**
     * 🚀 SIMPLIFICADO: Solo confirmar que el PDF se creó correctamente
     * La numeración ya se hace en getTemporaryNumber() con sistema de semáforos
     */
    public function finalizeNumberAssignment($document, $filename = null)
    {
        if (!is_object($document)) {
            return;
        }

        // Obtener información del documento
        $document_type = method_exists($document, 'get_type') ? $document->get_type() : null;
        $order_id = $this->getOrderIdFromDocument($document);
        
        if (!$order_id || !$document_type) {
            return;
        }

        // Tipos soportados
        $supported_types = ['invoice', 'simplified-invoice', 'credit-note', 'simplified-credit-note'];
        if (!in_array($document_type, $supported_types)) {
            return;
        }

        $vendor_id = $this->getVendorId($order_id);
        if (!$vendor_id) {
            return;
        }

        // Verificar si debemos aplicar numeración personalizada
        if (!$this->shouldApplyCustomNumbering($document_type, $order_id)) {
            return;
        }

        // Obtener el número que ya fue reservado
        $assigned_key = "_vendor_{$document_type}_number_{$vendor_id}";
        $assigned_number = get_post_meta($order_id, $assigned_key, true);
        
        if ($assigned_number) {

            
            // Asegurar compatibilidad AEAT para credit notes
            if (strpos($document_type, 'credit-note') !== false) {
                $vendor_prefix = get_field($this->getPrefixField($document_type), $vendor_id) ?: '';
                $vendor_suffix = get_field($this->getSuffixField($document_type), $vendor_id) ?: '';
                
                if (!empty($vendor_prefix)) {
                    $number_str = str_pad($assigned_number, 4, '0', STR_PAD_LEFT);
                    $formatted_number = $vendor_prefix . $number_str . $vendor_suffix;
                    
                    $aeat_key = "_wcpdf_{$document_type}_number";
                    $existing_aeat = get_post_meta($order_id, $aeat_key, true);
                    
                    if (empty($existing_aeat)) {
                        update_post_meta($order_id, $aeat_key, $formatted_number);

                    }
                }
            }
        }
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

    /**
     * Verificar si debemos aplicar numeración personalizada para un tipo de documento
     * 
     * @param string $document_type Tipo de documento
     * @param int $order_id ID del pedido
     * @return bool True si podemos aplicar numeración personalizada
     */
    private function shouldApplyCustomNumbering($document_type, $order_id)
    {
        // SIEMPRE aplicar para credit notes (notas de crédito)
        if (strpos($document_type, 'credit-note') !== false) {
            return true;
        }
        
        // Para facturas (invoices), verificar si ya tienen número asignado
        if (strpos($document_type, 'invoice') !== false) {
            // 🔧 FIX: Convertir guiones a guiones bajos en el meta key
            $normalized_document_type = str_replace('-', '_', $document_type);
            $meta_key = "_wcpdf_{$normalized_document_type}_number";
            
            // 🚀 HPOS Compatible: Usar WooCommerce nativo en lugar de get_post_meta
            $order = wc_get_order($order_id);
            $existing_meta = $order ? $order->get_meta($meta_key) : '';
            

            
            $result = empty($existing_meta);
            
            // Solo aplicar si NO tiene número previo
            return $result;
        }
        
        return false;
    }

    // ========== MÉTODOS AVANZADOS COPIADOS DE VendorDataManager ==========

    /**
     * Resuelve el ID real del order/refund basado en el tipo de documento
     * Para credit notes, intenta encontrar el refund específico si se pasa el pedido padre
     * 
     * COPIADO DE VendorDataManager para no perder funcionalidad avanzada
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
        $vendor_id = $this->getVendorId($order_id); // Usar pedido padre para obtener vendor
        
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
     * Obtener el siguiente número de documento disponible - VERSIÓN AVANZADA
     * Maneja correctamente múltiples refunds del mismo pedido con sistema de bloqueo
     * 
     * COPIADO DE VendorDataManager para no perder funcionalidad avanzada
     */
    private function getNextDocumentNumberAdvanced($vendor_id, $order_id, $document_type)
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
                $fallback_number = intval(get_field($this->getNumberField($document_type), $vendor_id) ?: 0) + 1;
                update_post_meta($control_id, $assigned_number_key, $fallback_number);
                return $fallback_number;
            }
        }
        
        // Establecer bloqueo
        set_transient($lock_key, $lock_value, $lock_timeout);
        
        // Obtener campo de número según tipo de documento
        $number_field = $this->getNumberField($document_type);
        
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
     * 
     * COPIADO DE VendorDataManager para no perder funcionalidad avanzada
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
     * 🎯 Cachear contexto de documento para uso posterior
     */
    public function cacheDocumentContext($document)
    {
        if (!is_object($document)) {
            return;
        }

        $order_id = $this->getOrderIdFromDocument($document);
        if ($order_id) {
            // Cachear el order_id del documento actual
            wp_cache_set('wpo_wcpdf_current_order_id', $order_id, 'wpo_wcpdf', 600); // 10 minutos
            
            // También establecer en global para acceso inmediato
            $GLOBALS['wpo_wcpdf_current_order_id'] = $order_id;
        }
    }

    /**
     * 🎯 HOOK ESPECÍFICO: Filtrar COC Number Settings Text (el método definitivo)
     */
    public function filterCocNumberSettingsText($text, $document)
    {
        $document_type = is_object($document) ? $document->get_type() : 'unknown';
        
        // USAR EL MISMO MÉTODO PARA TODAS LAS FACTURAS (normales y simplificadas)
        if (is_object($document)) {
            $order_id = $this->getOrderIdFromDocument($document);
            if ($order_id) {
                // Cachear para que getCurrentVendorNifSimple pueda usarlo
                wp_cache_set('wpo_wcpdf_current_order_id', $order_id, 'wpo_wcpdf', 300);
                $GLOBALS['wpo_wcpdf_current_order_id'] = $order_id;
                
                // Obtener Tax ID directamente del vendor
                $vendor_id = $this->getVendorId($order_id);
                if ($vendor_id) {
                    $tax_id = get_field('_taxIdentificationNumber', $vendor_id) ?: '';
                    $cleaned_tax_id = trim(str_replace(':', '', $tax_id));
                    
                    if ($cleaned_tax_id) {
                        return $cleaned_tax_id;
                    }
                }
            }
        }
        
        // Fallback usando getCurrentVendorNifSimple
        $vendor_tax_id = $this->getCurrentVendorNifSimple();
        if (!empty($vendor_tax_id)) {
            return $vendor_tax_id;
        }
        return $text;
    }

    /**
     * 🎯 HOOK ESPECÍFICO: Filtrar VAT Number Settings Text 
     */
    public function filterVatNumberSettingsText($text, $document)
    {
        $document_type = is_object($document) ? $document->get_type() : 'unknown';
        
        // USAR EL MISMO MÉTODO PARA TODAS LAS FACTURAS (normales y simplificadas)
        if (is_object($document)) {
            $order_id = $this->getOrderIdFromDocument($document);
            if ($order_id) {
                // Cachear para que getCurrentVendorNifSimple pueda usarlo
                wp_cache_set('wpo_wcpdf_current_order_id', $order_id, 'wpo_wcpdf', 300);
                $GLOBALS['wpo_wcpdf_current_order_id'] = $order_id;
                
                // Obtener Tax ID directamente del vendor
                $vendor_id = $this->getVendorId($order_id);
                if ($vendor_id) {
                    $tax_id = get_field('_taxIdentificationNumber', $vendor_id) ?: '';
                    $cleaned_tax_id = trim(str_replace(':', '', $tax_id));
                    
                    if ($cleaned_tax_id) {
                        return $cleaned_tax_id;
                    }
                }
            }
        }
        
        // Fallback usando getCurrentVendorNifSimple
        $vendor_tax_id = $this->getCurrentVendorNifSimple();
        if (!empty($vendor_tax_id)) {
            return $vendor_tax_id;
        }
        return $text;
    }

    // ========== PREVIEW PROTECTION METHODS ==========

    /**
     * Mark preview mode using official plugin hooks
     */
    public function markPreviewMode()
    {
        $GLOBALS['wpo_wcpdf_preview_mode'] = true;

    }

    /**
     * Detect if we are in admin preview mode (DO NOT increment numbers)
     * Uses the same logic as WooCommerce PDF Invoices & Packing Slips plugin
     * 
     * @return bool True if admin preview
     */
    private function isAdminPreview()
    {
        // 🎯 MÉTODO 1: Variable global establecida por nuestros hooks del plugin oficial
        if (isset($GLOBALS['wpo_wcpdf_preview_mode']) && $GLOBALS['wpo_wcpdf_preview_mode']) {

            return true;
        }
        
        // 🎯 MÉTODO 2: Misma lógica que usa el plugin en OrderDocument.php línea 145-149
        // Este es el método más seguro porque usa exactamente la misma detección del plugin oficial
        if (
            isset( $_REQUEST['action'] ) &&
            'wpo_wcpdf_preview' === $_REQUEST['action'] &&
            isset( $_REQUEST['security'] ) &&
            wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['security'] ) ), 'wpo_wcpdf_preview' )
        ) {

            return true;
        }
        
        // 🎯 MÉTODO 3: Contexto del admin de configuración (más conservador)
        if (is_admin() && isset($_GET['page']) && $_GET['page'] === 'wpo_wcpdf_options_page') {
            // Solo considerar como preview si hay una acción específica de preview
            if (isset($_GET['action']) && strpos($_GET['action'], 'preview') !== false) {
                return true;
            }
        }
        
        // 🎯 MÉTODO 4: AJAX preview sin nonce (backup más permisivo)
        if (defined('DOING_AJAX') && DOING_AJAX && isset($_REQUEST['action']) && $_REQUEST['action'] === 'wpo_wcpdf_preview') {

            return true;
        }
        
        return false;
    }
}
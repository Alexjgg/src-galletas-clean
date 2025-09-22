<?php
/**
 * Invoice Button Filter - Controla cuándo mostrar botones de PDF de facturas
 * 
 * REGLAS DE NEGOCIO CORREGIDAS:
 * 1. Órdenes individuales: Solo mostrar botón si el instituto NO paga (pagos individuales por padres)
 *    - Incluye pedidos normales y pedidos "hijos" de master orders
 *    - Si el centro no paga, los padres facturan individualmente
 * 2. Órdenes maestras: Solo mostrar botón si el instituto SÍ paga (facturación centralizada)
 *    - El centro paga directamente al proveedor por todos los pedidos del grupo
 * 
 * INTERPRETACIÓN DEL CAMPO ACF:
 * - the_billing_by_the_school = true  → El colegio NO paga (los padres pagan individualmente)
 * - the_billing_by_the_school = false → El colegio SÍ paga (facturación centralizada al colegio)
 * 
 * @package SchoolManagement\Orders
 * @since 1.0.0
 */

namespace SchoolManagement\Orders;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para filtrar botones de PDF de facturas según reglas de facturación
 */
class InvoiceButtonFilter
{
    /**
     * Estados de orden maestra - TODOS los estados posibles
     */
    private const MASTER_ORDER_STATUSES = [
        'master-order',      // Estado inicial pedido maestro
        'mast-warehs',       // Master warehouse (almacén)  
        'mast-prepared',     // Master preparado
        'mast-complete'      // Estado final pedido maestro
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->initHooks();
    }

    /**
     * Inicializar hooks de WordPress
     */
    private function initHooks(): void
    {
        // Filtros para WC PDF Packing Slips - Lista de órdenes (admin) - ESTE SÍ FUNCIONA
        add_filter('wpo_wcpdf_listing_actions', [$this, 'filterInvoiceButtonsInListing'], 10, 2);
        
        // Hook alternativo para metaboxes individuales
        add_action('add_meta_boxes', [$this, 'removeInvoiceMetaboxes'], 20);
        
        // Hook para interceptar acceso directo a PDFs
        add_action('wp_ajax_generate_wpo_wcpdf', [$this, 'interceptPDFGeneration'], 1);
        add_action('wp_ajax_nopriv_generate_wpo_wcpdf', [$this, 'interceptPDFGeneration'], 1);
        
        // CRÍTICO: Bloquear facturación automática en cambios de estado
        add_filter('wpo_wcpdf_document_is_allowed', [$this, 'filterAutomaticInvoiceGeneration'], 10, 2);
        
        // Hook adicional para bloquear antes de crear el documento
        add_filter('wpo_wcpdf_document_store_enabled', [$this, 'filterInvoiceStorage'], 10, 2);
        
        // Bloquear antes de generar cualquier documento de factura
        add_action('wpo_wcpdf_init_document', [$this, 'interceptDocumentInit'], 1, 1);
    }

    /**
     * Filtrar botones de PDF en la lista de órdenes (admin)
     * 
     * @param array $actions Acciones disponibles
     * @param \WC_Order $order Objeto de orden
     * @return array Acciones filtradas
     */
    public function filterInvoiceButtonsInListing(array $actions, \WC_Order $order): array
    {
        // Si no hay acciones de factura, retornar sin cambios
        if (!isset($actions['invoice'])) {
            return $actions;
        }

        // Verificar si se debe mostrar la factura
        if (!$this->shouldShowInvoiceButton($order)) {
            // Remover botón de factura pero mantener otros (packing slip, etc.)
            unset($actions['invoice']);
        }

        return $actions;
    }

    /**
     * Filtrar acceso a documentos de factura (versión robusta)
     * 
     * @param bool $allowed Si el documento está permitido
     * @param mixed $document_object Objeto documento del plugin WC PDF
     * @return bool Si el documento está permitido
     */
    public function filterInvoiceDocumentAccess(bool $allowed, $document_object): bool
    {
        try {
            // Si no es un objeto, retornar sin cambios
            if (!is_object($document_object)) {
                return $allowed;
            }
            
            // Intentar obtener tipo del documento
            $document_type = '';
            if (method_exists($document_object, 'get_type')) {
                $document_type = $document_object->get_type();
            } elseif (property_exists($document_object, 'type')) {
                $document_type = $document_object->type;
            } else {
                // Si es un objeto Invoice, asumir que es 'invoice'
                $class_name = get_class($document_object);
                if (strpos($class_name, 'Invoice') !== false) {
                    $document_type = 'invoice';
                }
            }
            
            // Solo procesar facturas
            if ($document_type !== 'invoice') {
                return $allowed;
            }
            
            // Intentar obtener orden del documento
            $actual_order = null;
            if (property_exists($document_object, 'order')) {
                $actual_order = $document_object->order;
            } elseif (method_exists($document_object, 'get_order')) {
                $actual_order = $document_object->get_order();
            }
            
            // Si no podemos obtener la orden, permitir por seguridad
            if (!$actual_order || !method_exists($actual_order, 'get_id')) {
                return $allowed;
            }
            
            // Verificar si se debe permitir la factura
            $should_allow = $this->shouldShowInvoiceButton($actual_order);
            
            return $should_allow;
            
        } catch (\Exception $e) {
            // En caso de error, permitir el documento para no romper el sistema
            return $allowed;
        }
    }

    /**
     * Remover metaboxes de factura si no se debe mostrar
     */
    public function removeInvoiceMetaboxes(): void
    {
        global $post;
        
        // Solo en páginas de orden
        if (!$post || get_post_type($post) !== 'shop_order') {
            return;
        }
        
        $order = wc_get_order($post->ID);
        if (!$order || $this->shouldShowInvoiceButton($order)) {
            return;
        }
        
        // Remover metaboxes relacionados con facturas
        remove_meta_box('wpo_wcpdf-invoice', 'shop_order', 'side');
        remove_meta_box('wpo_wcpdf_invoice_metabox', 'shop_order', 'side');
        
    }
    
    /**
     * Interceptar generación directa de PDFs via AJAX
     */
    public function interceptPDFGeneration(): void
    {
        // Verificar que es una solicitud de factura
        if (!isset($_REQUEST['document_type']) || $_REQUEST['document_type'] !== 'invoice') {
            return;
        }
        
        // Verificar que hay order_ids
        if (!isset($_REQUEST['order_ids']) || empty($_REQUEST['order_ids'])) {
            return;
        }
        
        $order_ids = explode(',', $_REQUEST['order_ids']);
        
        foreach ($order_ids as $order_id) {
            $order = wc_get_order((int) $order_id);
            if (!$order) {
                continue;
            }
            
            if (!$this->shouldShowInvoiceButton($order)) {
                // Bloquear la generación
                wp_die(__('❌ You do not have permission to generate invoices for this order according to the school billing rules.', 'neve-child'), 
                       __('Access Denied', 'neve-child'), 
                       ['response' => 403]);
            }
        }
    }
    
    /**
     * Filtrar generación automática de facturas - CRÍTICO
     * Este hook se ejecuta cuando el plugin intenta generar facturas automáticamente
     * 
     * @param bool $allowed Si el documento está permitido
     * @param mixed $document Objeto documento
     * @return bool Si se permite generar el documento
     */
    public function filterAutomaticInvoiceGeneration(bool $allowed, $document): bool
    {
        // Solo procesar facturas
        if (!$this->isInvoiceDocument($document)) {
            return $allowed;
        }
        
        $order = $this->getOrderFromDocument($document);
        if (!$order) {
            return $allowed;
        }
        
        $should_allow = $this->shouldShowInvoiceButton($order);
        
        return $should_allow;
    }
    
    /**
     * Filtrar almacenamiento de facturas
     * 
     * @param bool $enabled Si el almacenamiento está habilitado
     * @param mixed $document Objeto documento
     * @return bool Si se permite almacenar
     */
    public function filterInvoiceStorage(bool $enabled, $document): bool
    {
        // Solo procesar facturas
        if (!$this->isInvoiceDocument($document)) {
            return $enabled;
        }
        
        $order = $this->getOrderFromDocument($document);
        if (!$order) {
            return $enabled;
        }
        
        $should_allow = $this->shouldShowInvoiceButton($order);
        
        return $should_allow;
    }
    
    /**
     * Interceptar inicialización de documentos
     * 
     * @param mixed $document Objeto documento
     */
    public function interceptDocumentInit($document): void
    {
        // Solo procesar facturas
        if (!$this->isInvoiceDocument($document)) {
            return;
        }
        
        // Obtener orden del documento
        $actual_order = $this->getOrderFromDocument($document);
        if (!$actual_order) {
            return;
        }
        
        if (!$this->shouldShowInvoiceButton($actual_order)) {
            // Intentar detener la ejecución
            wp_die(__('Invoice generation not allowed for this order according to billing rules.', 'neve-child'), 
                   __('Invoice Blocked', 'neve-child'), 
                   ['response' => 403]);
        }
    }

    /**
     * Determinar si se debe mostrar el botón de factura
     * 
     * @param \WC_Order $order Objeto de orden
     * @return bool Si se debe mostrar el botón
     */
    private function shouldShowInvoiceButton(\WC_Order $order): bool
    {
        // REGLA 1: Si es orden maestra, solo mostrar si el centro paga
        if ($this->isMasterOrder($order)) {
            $school_id = $this->getOrderSchoolId($order);
            if (!$school_id) {
                return true; // Si no hay escuela, permitir
            }
            
            // Para master orders: solo si el centro SÍ paga (facturación centralizada)
            return $this->doesSchoolPay($school_id);
        }
        
        // REGLA 2: Para pedidos individuales (con o sin master), solo si el centro NO paga
        $school_id = $this->getOrderSchoolId($order);
        if (!$school_id) {
            // Si no hay escuela asociada, SIEMPRE permitir facturar
            return true;
        }

        // Para pedidos individuales: solo si el centro NO paga (padres pagan individualmente)
        return !$this->doesSchoolPay($school_id);
    }

    /**
     * Verificar si el centro/escuela paga directamente
     * 
     * @param int $school_id ID de la escuela
     * @return bool True si el centro paga, False si pagan los padres
     */
    private function doesSchoolPay(int $school_id): bool
    {
        // Obtener el valor del campo ACF 'the_billing_by_the_school'
        $billing_value = get_field(\SchoolManagement\Shared\Constants::ACF_FIELD_SCHOOL_BILLING, $school_id);
        
        // INTERPRETACIÓN CORREGIDA:
        // the_billing_by_the_school = true  → El centro NO paga (los padres pagan individualmente)
        // the_billing_by_the_school = false → El centro SÍ paga (facturación centralizada)
        $field_is_true = ($billing_value === '1' || $billing_value === 1 || $billing_value === true);
        
        // Invertir porque el campo está al revés
        return !$field_is_true;
    }

    /**
     * Obtener ID de la escuela desde la orden
     * 
     * @param \WC_Order $order Objeto de orden
     * @return int|null ID de la escuela
     */
    private function getOrderSchoolId(\WC_Order $order): ?int
    {
        $school_id = $order->get_meta(\SchoolManagement\Shared\Constants::ORDER_META_SCHOOL_ID);
        return $school_id ? (int) $school_id : null;
    }

    /**
     * Verificar si es una orden maestra
     * 
     * @param \WC_Order $order Objeto de orden
     * @return bool Si es orden maestra
     */
    private function isMasterOrder(\WC_Order $order): bool
    {
        // Verificar meta _is_master_order (método más confiable)
        if ($order->get_meta('_is_master_order') === 'yes') {
            return true;
        }
        
        // Verificar por status como respaldo
        $status = $order->get_status();
        return in_array($status, self::MASTER_ORDER_STATUSES);
    }

    /**
     * Verificar si es una orden hija de master order
     * 
     * @param \WC_Order $order Objeto de orden
     * @return bool Si es orden hija
     */
    private function isChildOrder(\WC_Order $order): bool
    {
        $master_order_id = $order->get_meta('_master_order_id');
        return !empty($master_order_id);
    }

    /**
     * Verificar si una acción es relacionada con facturas
     * 
     * @param mixed $action Acción a verificar
     * @return bool Si es acción de factura
     */
    private function isInvoiceAction($action): bool
    {
        if (is_string($action)) {
            return strpos(strtolower($action), 'invoice') !== false;
        }
        
        if (is_array($action) && isset($action['type'])) {
            return $action['type'] === 'invoice';
        }
        
        return false;
    }
    
    /**
     * Verificar si un documento es de tipo factura
     * 
     * @param mixed $document Objeto documento
     * @return bool Si es documento de factura
     */
    private function isInvoiceDocument($document): bool
    {
        if (!is_object($document)) {
            return false;
        }
        
        // Verificar tipo del documento
        if (method_exists($document, 'get_type')) {
            return $document->get_type() === 'invoice';
        }
        
        if (property_exists($document, 'type')) {
            return $document->type === 'invoice';
        }
        
        // Verificar por nombre de clase
        $class_name = get_class($document);
        return strpos(strtolower($class_name), 'invoice') !== false;
    }
    
    /**
     * Obtener orden desde objeto documento
     * 
     * @param mixed $document Objeto documento
     * @return \WC_Order|null Objeto orden
     */
    private function getOrderFromDocument($document): ?\WC_Order
    {
        if (!is_object($document)) {
            return null;
        }
        
        // Intentar obtener orden del documento
        if (property_exists($document, 'order') && $document->order instanceof \WC_Order) {
            return $document->order;
        }
        
        if (method_exists($document, 'get_order')) {
            $order = $document->get_order();
            return $order instanceof \WC_Order ? $order : null;
        }
        
        // Intentar obtener order_id
        $order_id = null;
        if (property_exists($document, 'order_id')) {
            $order_id = $document->order_id;
        } elseif (method_exists($document, 'get_order_id')) {
            $order_id = $document->get_order_id();
        }
        
        return $order_id ? wc_get_order($order_id) : null;
    }
    
    /**
     * Resolver orden desde diferentes tipos de objetos
     * 
     * @param mixed $order Objeto orden o ID
     * @return \WC_Order|null Objeto orden
     */
    private function resolveOrder($order): ?\WC_Order
    {
        if ($order instanceof \WC_Order) {
            return $order;
        }
        
        if (is_numeric($order)) {
            return wc_get_order($order);
        }
        
        if (is_array($order) && isset($order[0])) {
            return wc_get_order($order[0]);
        }
        
        return null;
    }
}

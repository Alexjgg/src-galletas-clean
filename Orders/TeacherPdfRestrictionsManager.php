<?php
/**
 * Teacher PDF Restrictions Manager
 * 
 * Implementación moderna usando hooks nativos del plugin WooCommerce PDF Invoices & Packing Slips
 * para restringir el acceso a funcionalidades PDF para usuarios con rol de profesor.
 * 
 * ENFOQUE MODERNO:
 * - Usa hooks específicos del plugin WC PDF en lugar de filtros genéricos
 * - Bloquea la generación de PDFs a nivel de plugin, no solo UI
 * - Proporciona mejor integración y feedback al usuario
 * - Más mantenible y menos propenso a errores
 * - Soporte para facturas normales, simplificadas y packing slips
 * 
 * @package SchoolManagement\Orders
 * @since 1.0.0
 */

namespace SchoolManagement\Orders;

if (!defined('ABSPATH')) {
    exit;
}

class TeacherPdfRestrictionsManager
{
    /**
     * Tipos de documentos que están restringidos para profesores
     */
    private const RESTRICTED_DOCUMENT_TYPES = [
        'invoice',
        'simplified-invoice',
        'credit-note',
        'simplified-credit-note',
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->initHooks();
    }

    /**
     * Inicializar hooks del plugin WC PDF
     */
    private function initHooks(): void
    {
        // Solo aplicar si el usuario es profesor
        if (!$this->isCurrentUserTeacher()) {
            return;
        }

        // HOOKS NATIVOS DEL PLUGIN WC PDF - Más confiables y modernos
        
        // 1. Bloquear acceso a documentos PDF específicos
        add_filter('wpo_wcpdf_document_is_allowed', [$this, 'blockDocumentAccess'], 10, 2);
        
        // 2. Filtrar acciones en listing de órdenes (admin)
        add_filter('wpo_wcpdf_listing_actions', [$this, 'filterListingActions'], 10, 2);
        
        // 3. Bloquear generación masiva de PDFs
        add_action('wpo_wcpdf_before_bulk_document', [$this, 'blockBulkGeneration'], 1, 2);
        
        // 4. Interceptar solicitudes AJAX de generación de PDFs
        add_action('wpo_wcpdf_before_pdf', [$this, 'blockPdfGeneration'], 1, 2);
        
        // 5. Personalizar metaboxes en páginas individuales de orden
        add_action('wpo_wcpdf_meta_box_actions', [$this, 'filterMetaboxActions'], 10, 2);
        
        // 6. Mostrar mensaje informativo cuando se bloquee una acción
        add_action('admin_notices', [$this, 'showRestrictionNotice']);
        
        // 7. NUEVO: Bloquear acciones de Factupress Verifactu
        add_action('wp_ajax_verifactu_cancel_invoice', [$this, 'blockFactupressActions'], 1);
        add_filter('woocommerce_admin_order_actions', [$this, 'filterFactupressActions'], 10, 2);
        
        // 9. Ocultar botones de Factupress con CSS
        add_action('admin_head', [$this, 'hideFactupressButtons']);
    }

    /**
     * Verificar si el usuario actual es profesor
     * 
     * @return bool
     */
    private function isCurrentUserTeacher(): bool
    {
        if (!is_admin() || !function_exists('wp_get_current_user')) {
            return false;
        }

        $user = wp_get_current_user();
        return $user && in_array('teacher', (array) $user->roles);
    }

    /**
     * Bloquear acceso a documentos específicos usando hook nativo del plugin
     * 
     * @param bool $is_allowed Si el documento está permitido
     * @param mixed $document_object Objeto documento o string del tipo de documento
     * @return bool
     */
    public function blockDocumentAccess(bool $is_allowed, $document_object): bool
    {
        // Si ya está bloqueado, mantener el bloqueo
        if (!$is_allowed) {
            return false;
        }

        // Extraer el tipo de documento del objeto o string
        $document_type = $this->extractDocumentType($document_object);
        if (!$document_type) {
            return $is_allowed; // Si no podemos determinar el tipo, permitir
        }

        // Verificar si es un tipo de documento restringido
        if (in_array($document_type, self::RESTRICTED_DOCUMENT_TYPES)) {
            // Verificar si es generación masiva (más de un pedido)
            if ($this->isBulkGeneration()) {
                $this->setRestrictionMessage(
                    sprintf(
                        __('Teachers cannot generate %s documents in bulk.', 'neve-child'),
                        $this->getDocumentTypeName($document_type)
                    )
                );
                return false;
            }
        }

        return $is_allowed;
    }

    /**
     * Filtrar acciones en el listing de órdenes
     * 
     * @param array $actions Acciones disponibles
     * @param \WC_Order $order Objeto de orden
     * @return array
     */
    public function filterListingActions(array $actions, \WC_Order $order): array
    {
        // Remover acciones de documentos restringidos si hay múltiples órdenes seleccionadas
        if ($this->isBulkContext()) {
            foreach (self::RESTRICTED_DOCUMENT_TYPES as $doc_type) {
                $action_key = $this->getActionKeyForDocumentType($doc_type);
                if (isset($actions[$action_key])) {
                    unset($actions[$action_key]);
                }
            }
        }

        return $actions;
    }

    /**
     * Bloquear generación masiva de documentos
     * 
     * @param array $order_ids IDs de órdenes
     * @param string $document_type Tipo de documento
     */
    public function blockBulkGeneration(array $order_ids, string $document_type): void
    {
        if (count($order_ids) > 1 && in_array($document_type, self::RESTRICTED_DOCUMENT_TYPES)) {
            $this->setRestrictionMessage(
                sprintf(
                    __('Bulk generation of %s blocked. Teachers can generate documents individually.', 'neve-child'),
                    $this->getDocumentTypeName($document_type)
                )
            );
            
            // Redirigir con mensaje de error
            wp_safe_redirect(add_query_arg([
                'pdf_restriction' => 'bulk_blocked',
                'doc_type' => $document_type
            ], wp_get_referer()));
            exit;
        }
    }

    /**
     * Bloquear generación de PDFs antes de que se procesen
     * 
     * @param string $document_type Tipo de documento
     * @param array|\WC_Order $order_ids Órdenes a procesar
     */
    public function blockPdfGeneration(string $document_type, $order_ids): void
    {
        // Convertir a array si es necesario
        if (!is_array($order_ids)) {
            $order_ids = [$order_ids];
        }

        // Bloquear si es generación masiva de tipos restringidos
        if (count($order_ids) > 1 && in_array($document_type, self::RESTRICTED_DOCUMENT_TYPES)) {
            $this->blockBulkGeneration($order_ids, $document_type);
        }
    }

    /**
     * Filtrar acciones en metaboxes de órdenes individuales
     * 
     * @param array $actions Acciones del metabox
     * @param \WC_Order $order Objeto de orden
     * @return array
     */
    public function filterMetaboxActions(array $actions, \WC_Order $order): array
    {
        // Para órdenes individuales, los profesores SÍ pueden generar documentos
        // Solo restringimos acciones masivas
        return $actions;
    }

    /**
     * Mostrar aviso de restricción
     */
    public function showRestrictionNotice(): void
    {
        // Solo mostrar en páginas relevantes
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['edit-shop_order', 'woocommerce_page_wc-orders', 'shop_order'])) {
            return;
        }

        // Verificar si hay mensaje de restricción
        if (isset($_GET['pdf_restriction']) && $_GET['pdf_restriction'] === 'bulk_blocked') {
            $doc_type = sanitize_text_field($_GET['doc_type'] ?? 'documento');
            $doc_name = $this->getDocumentTypeName($doc_type);
            
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong><?php _e('Action Restricted', 'neve-child'); ?></strong><br>
                    <?php printf(
                        __('Teachers cannot generate %s in bulk for security and performance reasons.', 'neve-child'),
                        $doc_name
                    ); ?><br>
                    <em><?php _e('You can generate documents individually from each order.', 'neve-child'); ?></em>
                </p>
            </div>
            <?php
        }

        // Mostrar mensaje almacenado en sesión
        $message = $this->getRestrictionMessage();
        if ($message) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p><strong><?php _e('Access Restricted', 'neve-child'); ?>:</strong> <?php echo esc_html($message); ?></p>
            </div>
            <?php
            $this->clearRestrictionMessage();
        }
    }

    /**
     * Verificar si estamos en contexto de generación masiva
     * 
     * @return bool
     */
    private function isBulkGeneration(): bool
    {
        // Verificar parámetros de URL que indican generación masiva
        return isset($_REQUEST['bulk']) || 
               (isset($_REQUEST['order_ids']) && strpos($_REQUEST['order_ids'], 'x') !== false) ||
               (isset($_REQUEST['order_ids']) && is_array($_REQUEST['order_ids']) && count($_REQUEST['order_ids']) > 1);
    }

    /**
     * Verificar si estamos en contexto de acciones masivas
     * 
     * @return bool
     */
    private function isBulkContext(): bool
    {
        return isset($_REQUEST['action']) && $_REQUEST['action'] !== -1 && 
               isset($_REQUEST['post']) && is_array($_REQUEST['post']) && count($_REQUEST['post']) > 1;
    }

    /**
     * Obtener clave de acción para tipo de documento
     * 
     * @param string $document_type Tipo de documento
     * @return string
     */
    private function getActionKeyForDocumentType(string $document_type): string
    {
        $mapping = [
            'invoice' => 'invoice',
            'simplified-invoice' => 'simplified-invoice',
            'packing-slip' => 'packing-slip'
        ];

        return $mapping[$document_type] ?? $document_type;
    }

    /**
     * Obtener nombre amigable del tipo de documento
     * 
     * @param string $document_type Tipo de documento
     * @return string
     */
    private function getDocumentTypeName(string $document_type): string
    {
        $names = [
            'invoice' => __('invoices', 'neve-child'),
            'simplified-invoice' => __('simplified invoices', 'neve-child'),
            'packing-slip' => __('packing slips', 'neve-child')
        ];

        return $names[$document_type] ?? $document_type;
    }

    /**
     * Extraer el tipo de documento desde un objeto o string
     * 
     * @param mixed $document_object Objeto documento o string del tipo
     * @return string|null Tipo de documento o null si no se puede determinar
     */
    private function extractDocumentType($document_object): ?string
    {
        // Si es un string, devolverlo directamente
        if (is_string($document_object)) {
            return $document_object;
        }

        // Si es un objeto, intentar extraer el tipo
        if (is_object($document_object)) {
            // Método 1: Verificar si tiene método get_type()
            if (method_exists($document_object, 'get_type')) {
                return $document_object->get_type();
            }

            // Método 2: Verificar propiedad type
            if (property_exists($document_object, 'type')) {
                return $document_object->type;
            }

            // Método 3: Basado en el nombre de la clase
            $class_name = get_class($document_object);
            
            // Mapeo más específico basado en las clases del plugin WooCommerce PDF Invoices
            if (strpos($class_name, 'Invoice') !== false) {
                // Distinguir entre invoice normal y simplified
                if (strpos($class_name, 'Simplified') !== false) {
                    return 'simplified-invoice';
                }
                return 'invoice';
            }
            if (strpos($class_name, 'PackingSlip') !== false) {
                return 'packing-slip';
            }
            if (strpos($class_name, 'CreditNote') !== false) {
                // Distinguir entre credit-note normal y simplified
                if (strpos($class_name, 'Simplified') !== false) {
                    return 'simplified-credit-note';
                }
                return 'credit-note';
            }
            if (strpos($class_name, 'Receipt') !== false) {
                return 'receipt';
            }
            if (strpos($class_name, 'Proforma') !== false) {
                return 'proforma';
            }
        }

        // Si llegamos aquí, no pudimos determinar el tipo
        return null;
    }

    /**
     * Establecer mensaje de restricción en sesión
     * 
     * @param string $message Mensaje a mostrar
     */
    private function setRestrictionMessage(string $message): void
    {
        if (!session_id()) {
            session_start();
        }
        $_SESSION['pdf_restriction_message'] = $message;
    }

    /**
     * Obtener mensaje de restricción de sesión
     * 
     * @return string|null
     */
    private function getRestrictionMessage(): ?string
    {
        if (!session_id()) {
            session_start();
        }
        return $_SESSION['pdf_restriction_message'] ?? null;
    }

    /**
     * Limpiar mensaje de restricción de sesión
     */
    private function clearRestrictionMessage(): void
    {
        if (!session_id()) {
            session_start();
        }
        unset($_SESSION['pdf_restriction_message']);
    }

    /**
     * Verificar si el plugin WC PDF está activo
     * 
     * @return bool
     */
    public function isWcPdfPluginActive(): bool
    {
        return function_exists('WPO_WCPDF') || class_exists('WPO\WC\PDF_Invoices\Main');
    }

    /**
     * Obtener información del plugin
     * 
     * @return array
     */
    public function getPluginInfo(): array
    {
        if (!$this->isWcPdfPluginActive()) {
            return ['status' => 'inactive'];
        }

        $info = ['status' => 'active'];

        if (function_exists('WPO_WCPDF')) {
            $plugin = WPO_WCPDF();
            $info['version'] = $plugin->version ?? 'unknown';
            $info['main_class'] = 'WPO_WCPDF';
        }

        return $info;
    }

    /**
     * Bloquear acciones AJAX de Factupress Verifactu
     */
    public function blockFactupressActions(): void
    {
        // Verificar que es una solicitud de cancelación de factura
        if (!isset($_REQUEST['action']) || $_REQUEST['action'] !== 'verifactu_cancel_invoice') {
            return;
        }

        // Verificar nonce si está disponible
        if (isset($_REQUEST['nonce']) && !wp_verify_nonce($_REQUEST['nonce'], 'verifactu_cancel_invoice')) {
            return;
        }

        $order_id = isset($_REQUEST['order_id']) ? intval($_REQUEST['order_id']) : 0;
        
        $this->setRestrictionMessage(
            __('Teachers cannot cancel invoices in AEAT for fiscal security reasons.', 'neve-child')
        );
        
        // Respuesta JSON de error
        wp_send_json_error([
            'message' => __('Action not allowed. Teachers cannot cancel invoices in AEAT.', 'neve-child'),
            'error_code' => 'teacher_restriction_factupress'
        ]);
    }

    /**
     * Filtrar botones de acciones de Factupress en listing de órdenes
     * 
     * @param array $actions Acciones disponibles
     * @param \WC_Order $order Objeto de orden
     * @return array
     */
    public function filterFactupressActions(array $actions, \WC_Order $order): array
    {
        // Remover acciones específicas de Factupress que están restringidas
        $restricted_factupress_actions = [
            'verifactu_cancel_invoice',
            'factupress_cancel_invoice',
            'cancel_invoice_aeat'
        ];

        foreach ($restricted_factupress_actions as $action) {
            if (isset($actions[$action])) {
                unset($actions[$action]);
            }
        }

        return $actions;
    }

    /**
     * Agregar CSS para ocultar botones de Factupress de profesores
     */
    public function hideFactupressButtons(): void
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['edit-shop_order', 'woocommerce_page_wc-orders', 'shop_order'])) {
            return;
        }

        ?>
        <style>
        .verifactu-cancel-invoice-button,
        .factupress-cancel-invoice-button,
        a[href*="verifactu_cancel_invoice"] {
            display: none !important;
        }
        
        .postbox .verifactu-cancel-invoice-button,
        .postbox a[href*="verifactu_cancel_invoice"] {
            display: none !important;
        }
        </style>
        <?php
    }
}

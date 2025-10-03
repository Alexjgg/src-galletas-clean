<?php
/**
 * Teacher Master Order PDF Restrictions
 * 
 * Restringe a los profesores el acceso a los botones de generar PDFs (albaranes/facturas)
 * específicamente en los PEDIDOS MAESTROS (master orders).
 * 
 * Los profesores mantendrán acceso a:
 * - Bulk actions normales de WooCommerce
 * - Acciones individuales en pedidos normales
 * 
 * Los profesores NO tendrán acceso a:
 * - Botones de generar albaranes en pedidos maestros
 * - Botones de generar facturas en pedidos maestros
 * - Acciones de PDF masivas en pedidos maestros
 * 
 * @package SchoolManagement\Orders
 * @since 1.0.0
 */

namespace SchoolManagement\Orders;

if (!defined('ABSPATH')) {
    exit;
}

class TeacherMasterOrderPdfRestrictions
{
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
        // Solo aplicar restricciones si el usuario es profesor
        if ($this->isCurrentUserTeacher()) {
            // Filtrar acciones de fila específicamente en pedidos maestros
            add_filter('woocommerce_admin_order_actions', [$this, 'filterMasterOrderPdfActions'], 20, 2);
            
            // Interceptar y bloquear acceso directo a URLs de PDF de pedidos maestros
            add_action('admin_init', [$this, 'blockMasterOrderPdfAccess']);
            
            // Agregar notice explicativo para profesores
            add_action('admin_notices', [$this, 'showTeacherPdfRestrictionNotice']);
        }
    }

    /**
     * Verificar si el usuario actual es profesor
     * 
     * @return bool
     */
    private function isCurrentUserTeacher(): bool
    {
        if (!is_user_logged_in()) {
            return false;
        }

        $current_user = wp_get_current_user();
        
        // Verificar si tiene el rol de profesor
        return in_array('teacher', $current_user->roles);
    }

    /**
     * Verificar si un pedido es un pedido maestro
     * 
     * @param int|\WC_Order $order
     * @return bool
     */
    private function isMasterOrder($order): bool
    {
        if (is_numeric($order)) {
            $order = wc_get_order($order);
        }

        if (!$order) {
            return false;
        }

        // Verificar si tiene meta de pedido maestro
        $is_master = $order->get_meta('_is_master_order');
        
        return !empty($is_master) && $is_master === 'yes';
    }

    /**
     * Filtrar acciones de fila para eliminar botones de PDF en pedidos maestros
     * 
     * @param array $actions
     * @param \WC_Order $order
     * @return array
     */
    public function filterMasterOrderPdfActions(array $actions, \WC_Order $order): array
    {
        // Solo filtrar en pedidos maestros
        if (!$this->isMasterOrder($order)) {
            return $actions;
        }

        // Lista de acciones específicas de pedidos maestros que deben ser removidas para profesores
        // NOTA: Los profesores SÍ pueden generar packing slips individuales de pedidos maestros
        // Solo bloqueamos facturas y acciones masivas de múltiples albaranes
        $master_order_pdf_actions_to_remove = [
            // Acciones de facturas (siempre bloqueadas para profesores)
            'master_order_invoice', 
            'master_invoice',
            
            // Acciones masivas de múltiples albaranes (bloqueadas)
            'master_order_packing_slip',  // Solo si genera múltiples PDFs
            'master_packing_slip',        // Solo si genera múltiples PDFs
            'generate_master_pdf',
            'download_master_pdf', 
            'master_pdf_download',
            'bulk_master_pdf'
        ];

        foreach ($master_order_pdf_actions_to_remove as $action_key) {
            if (isset($actions[$action_key])) {
                unset($actions[$action_key]);
            }
        }

        return $actions;
    }

    /**
     * Filtrar bulk actions para remover acciones de PDF masivas en pedidos maestros
     * 
     * @param array $bulk_actions
     * @return array
     */
    public function filterMasterOrderBulkPdfActions(array $bulk_actions): array
    {
        // Solo en la página de pedidos
        if (!$this->isOrdersAdminPage()) {
            return $bulk_actions;
        }

        // Lista de bulk actions de PDF que deben ser removidas para profesores
        // NOTA: packing_slips y delivery_notes están permitidos para profesores
        $pdf_bulk_actions_to_remove = [
            'pdf_invoices',
            'pdf_receipts',
            'pdf_proformas',
            'pdf_credit_notes',
            'mark_printed_invoice'
        ];

        foreach ($pdf_bulk_actions_to_remove as $action_key) {
            if (isset($bulk_actions[$action_key])) {
                unset($bulk_actions[$action_key]);
            }
        }

        return $bulk_actions;
    }

    /**
     * Verificar si estamos en la página de administración de pedidos
     * 
     * @return bool
     */
    private function isOrdersAdminPage(): bool
    {
        global $pagenow, $typenow;
        
        return $pagenow === 'edit.php' && $typenow === 'shop_order';
    }

    /**
     * Bloquear acceso directo a URLs de PDF en pedidos maestros
     */
    public function blockMasterOrderPdfAccess(): void
    {
        // Verificar si se está intentando acceder a una acción de PDF
        if (!isset($_GET['action']) || !isset($_GET['order_ids'])) {
            return;
        }

        $action = sanitize_text_field($_GET['action']);
        $order_ids = array_map('intval', explode(',', sanitize_text_field($_GET['order_ids'])));

        // Verificar si alguno de los pedidos es un pedido maestro
        $has_master_order = false;
        foreach ($order_ids as $order_id) {
            if ($this->isMasterOrder($order_id)) {
                $has_master_order = true;
                break;
            }
        }

        // Si no hay pedidos maestros, permitir todas las acciones
        if (!$has_master_order) {
            return;
        }

        // Para pedidos maestros, verificar el tipo de documento solicitado
        $document_type = $this->getDocumentTypeFromRequest();
        
        // Lista de tipos de documentos bloqueados para pedidos maestros
        // NOTA: Los profesores SÍ pueden generar packing-slip individuales de pedidos maestros
        $blocked_document_types = [
            'invoice',
            'simplified-invoice',
            'credit-note',
            'simplified-credit-note',
            'receipt',
            'proforma'
        ];

        if (in_array($document_type, $blocked_document_types)) {
            // Bloquear acceso y mostrar error
            wp_die(
                sprintf(
                    esc_html__('Access denied: Teachers cannot generate %s documents for master orders.', 'neve-child'),
                    $document_type
                ),
                esc_html__('Access Denied', 'neve-child'),
                [
                    'response' => 403,
                    'back_link' => true
                ]
            );
        }

        // Si es packing-slip u otro documento permitido, continuar normalmente
    }

    /**
     * Extraer el tipo de documento de la petición actual
     * 
     * @return string|null
     */
    private function getDocumentTypeFromRequest(): ?string
    {
        // Intentar obtener de parámetro directo
        if (isset($_GET['document_type'])) {
            return sanitize_text_field($_GET['document_type']);
        }

        // Intentar obtener del action
        $action = sanitize_text_field($_GET['action'] ?? '');
        
        // Mapeo de acciones a tipos de documento
        $action_to_document_type = [
            'pdf_invoice' => 'invoice',
            'pdf_packing_slip' => 'packing-slip',
            'pdf_delivery_note' => 'delivery-note',
            'pdf_receipt' => 'receipt',
            'pdf_credit_note' => 'credit-note',
            'generate_wpo_wcpdf' => sanitize_text_field($_GET['document_type'] ?? 'unknown'),
            'wpo_wcpdf' => sanitize_text_field($_GET['document_type'] ?? 'unknown')
        ];

        return $action_to_document_type[$action] ?? null;
    }

    /**
     * Mostrar notice explicativo para profesores sobre las restricciones de PDF
     */
    public function showTeacherPdfRestrictionNotice(): void
    {
        // Solo mostrar en páginas de pedidos
        if (!$this->isOrdersAdminPage()) {
            return;
        }

        // Solo mostrar una vez por sesión
        if (get_transient('teacher_pdf_restriction_notice_shown_' . get_current_user_id())) {
            return;
        }

        echo '<div class="notice notice-info is-dismissible teacher-pdf-restriction-notice">';
        echo '<h4>' . esc_html__('PDF Document Restrictions', 'neve-child') . '</h4>';
        echo '<p>';
        echo esc_html__('As a teacher, you have limited access to PDF document generation for master orders. You can still:', 'neve-child');
        echo '</p>';
        echo '<ul style="margin-left: 20px;">';
        echo '<li>• ' . esc_html__('Use bulk actions for order status changes', 'neve-child') . '</li>';
        echo '<li>• ' . esc_html__('Generate PDFs for individual student orders', 'neve-child') . '</li>';
        echo '<li>• ' . esc_html__('View and manage orders within your school', 'neve-child') . '</li>';
        echo '</ul>';
        echo '<p>';
        echo esc_html__('For master order PDF generation, please contact an administrator.', 'neve-child');
        echo '</p>';
        echo '</div>';

        // JavaScript para ocultar el notice al hacer clic en dismiss
        echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            const notice = document.querySelector(".teacher-pdf-restriction-notice");
            if (notice) {
                const dismissButton = notice.querySelector(".notice-dismiss");
                if (dismissButton) {
                    dismissButton.addEventListener("click", function() {
                        fetch(ajaxurl, {
                            method: "POST",
                            headers: {
                                "Content-Type": "application/x-www-form-urlencoded",
                            },
                            body: "action=dismiss_teacher_pdf_notice&nonce=' . wp_create_nonce('dismiss_teacher_pdf_notice') . '"
                        });
                    });
                }
            }
        });
        </script>';

        // Marcar como mostrado por esta sesión
        set_transient('teacher_pdf_restriction_notice_shown_' . get_current_user_id(), true, HOUR_IN_SECONDS);
    }

    /**
     * Manejar AJAX para dismiss del notice
     */
    public function handleDismissNotice(): void
    {
        if (!wp_verify_nonce($_POST['nonce'], 'dismiss_teacher_pdf_notice')) {
            wp_die();
        }

        // Marcar como dismissido permanentemente para esta sesión
        set_transient('teacher_pdf_restriction_notice_dismissed_' . get_current_user_id(), true, DAY_IN_SECONDS);
        
        wp_die();
    }

    /**
     * Agregar estilos CSS para el notice
     */
    public function addNoticeStyles(): void
    {
        if (!$this->isOrdersAdminPage()) {
            return;
        }

        echo '<style>
        .teacher-pdf-restriction-notice ul {
            list-style-type: none;
            padding-left: 0;
        }
        
        .teacher-pdf-restriction-notice ul li {
            margin-bottom: 5px;
            color: #0073aa;
        }
        
        .teacher-pdf-restriction-notice h4 {
            margin-top: 0;
            margin-bottom: 10px;
            color: #23282d;
        }
        </style>';
    }
}

// Inicializar automáticamente
if (!isset($GLOBALS['teacher_master_order_pdf_restrictions'])) {
    $GLOBALS['teacher_master_order_pdf_restrictions'] = new TeacherMasterOrderPdfRestrictions();
    
    // Agregar hook para AJAX
    add_action('wp_ajax_dismiss_teacher_pdf_notice', [$GLOBALS['teacher_master_order_pdf_restrictions'], 'handleDismissNotice']);
    
    // Agregar estilos
    add_action('admin_head', [$GLOBALS['teacher_master_order_pdf_restrictions'], 'addNoticeStyles']);
}

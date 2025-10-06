<?php
/**
 * Refund Restrictions - Bloquea refunds para órdenes no pagadas
 * 
 * FUNCIONALIDAD:
 * - Bloquea botón de refund si la orden no está marcada como pagada
 * - Usa el sistema personalizado de validación de pagos
 * - Compatible con sistema clásico y HPOS
 * 
 * @package SchoolManagement\Payments
 * @since 1.0.0
 */

namespace SchoolManagement\Payments;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para controlar restricciones de refunds basadas en estado de pago
 */
class RefundRestrictions
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
        // Hook para sistema clásico (posts)
        add_action('admin_footer', [$this, 'hideRefundButtonForUnpaidOrders']);
        
        // Hook para HPOS (High-Performance Order Storage)
        add_action('admin_head', [$this, 'addRefundRestrictionCSS']);
        
        // Hook adicional para interceptar refunds via AJAX
        add_action('wp_ajax_woocommerce_refund_line_items', [$this, 'interceptRefundAjax'], 1);
        
        // Hook para verificar antes de procesar refunds
        add_filter('woocommerce_order_fully_refunded_status', [$this, 'preventRefundForUnpaidOrders'], 10, 3);
    }

    /**
     * Ocultar botón de refund para órdenes no pagadas (JavaScript)
     */
    public function hideRefundButtonForUnpaidOrders(): void
    {
        global $post;
        
        // Solo en páginas de orden individual
        if (!isset($post->post_type) || $post->post_type !== 'shop_order') {
            return;
        }
        
        $order = wc_get_order($post->ID);
        if (!$order) {
            return;
        }
        
        // Si la orden no está pagada según nuestro sistema, ocultar botón
        if (!$this->isOrderReallyPaid($order)) {
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function($){
                    // Ocultar botón de refund
                    $('.button.refund-items').remove();
                    
                    // También ocultar enlaces relacionados con refunds
                    $('a[href*="refund"]').remove();
                    
                    // Mostrar mensaje informativo
                    if ($('.refund-items').length > 0) {
                        $('.refund-items').parent().append(
                            '<p style="color: #dc3232; font-style: italic; margin-top: 10px;">' +
                            '⚠️ <?php echo esc_js(__("Refunds not available: Order is not marked as paid", "neve-child")); ?>' +
                            '</p>'
                        );
                    }
                });
            </script>
            <?php
        }
    }

    /**
     * Añadir CSS para ocultar botones de refund en órdenes no pagadas
     */
    public function addRefundRestrictionCSS(): void
    {
        $screen = get_current_screen();
        
        // Solo en páginas de orden (tanto clásico como HPOS)
        if (!$screen || !in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'])) {
            return;
        }
        
        // Para HPOS, necesitamos obtener el ID de la orden desde la URL
        $order_id = $this->getCurrentOrderId();
        if (!$order_id) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Si la orden no está pagada, añadir CSS para ocultar botones
        if (!$this->isOrderReallyPaid($order)) {
            echo '<style>
                .button.refund-items,
                .wc-order-refund-items,
                a[href*="refund"] {
                    display: none !important;
                }
                .refund-restriction-notice {
                    color: #dc3232;
                    font-style: italic;
                    margin: 10px 0;
                }
            </style>';
        }
    }

    /**
     * Interceptar refunds via AJAX antes de que se procesen
     */
    public function interceptRefundAjax(): void
    {
        // Verificar nonce de seguridad
        if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'order-item')) {
            return;
        }
        
        $order_id = absint($_POST['order_id'] ?? 0);
        if (!$order_id) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Si la orden no está pagada, bloquear el refund
        if (!$this->isOrderReallyPaid($order)) {
            wp_die(
                __('⚠️ Refund blocked: This order is not marked as paid in the system.', 'neve-child'),
                __('Refund Not Allowed', 'neve-child'),
                ['response' => 403, 'back_link' => true]
            );
        }
    }

    /**
     * Prevenir refunds completos para órdenes no pagadas
     * 
     * @param string $status Estado propuesto para orden totalmente reembolsada
     * @param int $order_id ID de la orden
     * @param int $refund_id ID del refund
     * @return string Estado modificado
     */
    public function preventRefundForUnpaidOrders(string $status, int $order_id, int $refund_id): string
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return $status;
        }
        
        // Si la orden no está pagada, no cambiar el estado
        if (!$this->isOrderReallyPaid($order)) {
            // Mantener el estado original
            return $order->get_status();
        }
        
        return $status;
    }

    /**
     * Verificar si una orden está realmente pagada
     * Replica la MISMA lógica de CheckoutBlocker para consistencia
     * 
     * @param \WC_Order $order Objeto de orden
     * @return bool Si la orden está pagada
     */
    private function isOrderReallyPaid(\WC_Order $order): bool
    {
        // LÓGICA IDÉNTICA A CheckoutBlocker::orderNeedsPaymentAdvanced()
        // Pero invertida (si NO necesita pago = está pagada)
        
        // 1. Verificar el meta _order_marked_as_paid (marcado manualmente)
        $marked_as_paid = $order->get_meta('_order_marked_as_paid');
        if ($marked_as_paid === 'yes' || $marked_as_paid === '1' || $marked_as_paid === 1) {
            return true; // Marcado como pagado manualmente
        }
        
        // 2. Verificar indicadores confiables de pago
        $payment_date = $order->get_meta('payment_date');
        $deferred_payment_date = $order->get_meta('_dm_pay_later_card_payment_date');
        
        // 3. Verificar transaction_id solo si no es auto-generado
        $transaction_id = $order->get_transaction_id();
        $reliable_transaction = !empty($transaction_id) && stripos($transaction_id, 'order') === false;
        
        // Si tiene indicadores confiables de pago, SÍ está pagado
        if (!empty($payment_date) || !empty($deferred_payment_date) || $reliable_transaction) {
            return true;
        }
        
        // 4. Verificación específica para transferencias bancarias
        $payment_method = $order->get_payment_method();
        if ($payment_method === 'bacs') {
            // Para transferencias, usar la misma lógica que CheckoutBlocker
            return !$this->bankTransferNeedsPayment($order);
        }
        
        // 5. Para otros métodos, sin indicadores confiables = NO pagado
        return false;
    }

    /**
     * Verificación específica para transferencias bancarias
     * COPIADA EXACTAMENTE de CheckoutBlocker para mantener consistencia
     * 
     * @param \WC_Order $order Objeto de pedido de transferencia bancaria
     * @return bool True si necesita pago, false en caso contrario
     */
    private function bankTransferNeedsPayment(\WC_Order $order): bool
    {
        $order_status = $order->get_status();
        
        // Verificar el meta _order_marked_as_paid primero
        $marked_as_paid = $order->get_meta('_order_marked_as_paid');
        if ($marked_as_paid === 'yes' || $marked_as_paid === '1' || $marked_as_paid === 1) {
            return false; // Marcado como pagado manualmente
        }
        
        // LÓGICA IDÉNTICA A CheckoutBlocker:
        // Para transferencias bancarias completadas SIN indicadores de pago manual,
        // SÍ necesita confirmación manual - NO se puede asumir que están pagadas
        // INCLUIR ESTADOS PERSONALIZADOS: 'completed' y 'mast-complete'
        if (in_array($order_status, ['completed', 'mast-complete'])) {
            return true; // BLOQUEAR: transferencia completada sin confirmar pago
        }

        // Para estados que claramente indican pago pendiente
        if (in_array($order_status, ['pending', 'on-hold', 'failed'])) {
            return true;
        }

        // Para processing, usar needs_payment estándar de WooCommerce
        return $order->needs_payment();
    }

    /**
     * Obtener el ID de la orden actual desde la URL (para HPOS)
     * 
     * @return int|null ID de la orden
     */
    private function getCurrentOrderId(): ?int
    {
        // Para sistema HPOS
        if (isset($_GET['id']) && is_numeric($_GET['id'])) {
            return (int) $_GET['id'];
        }
        
        // Para sistema clásico
        global $post;
        if ($post && $post->post_type === 'shop_order') {
            return $post->ID;
        }
        
        return null;
    }

    /**
     * Verificar si estamos en una página de orden individual
     * 
     * @return bool Si estamos en página de orden
     */
    private function isOrderEditPage(): bool
    {
        $screen = get_current_screen();
        
        if (!$screen) {
            return false;
        }
        
        // Sistema clásico
        if ($screen->id === 'shop_order') {
            return true;
        }
        
        // Sistema HPOS
        if ($screen->id === 'woocommerce_page_wc-orders' && isset($_GET['action']) && $_GET['action'] === 'edit') {
            return true;
        }
        
        return false;
    }
}
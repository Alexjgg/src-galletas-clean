<?php
/**
 * Packing Slip Customizer
 * 
 * Configuración universal para personalizar TODOS los albaranes (individuales y en masa)
 * Funciona con WC PDF Invoices & Packing Slips
 * 
 * FUNCIONALIDADES:
 * 1. Mostrar número del alumno en todos los albaranes
 * 2. Personalizar datos del destinatario (usar nombre del alumno en lugar del comprador)
 * 3. Personalizar templates y datos de albaranes
 * 4. Aplicar modificaciones tanto a albaranes individuales como masivos
 * 
 * @package SchoolManagement\Orders
 * @since 1.0.0
 */

namespace SchoolManagement\Orders;

if (!defined('ABSPATH')) {
    exit;
}

class PackingSlipCustomizer
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
        // Solo cargar si el plugin WC PDF está activo
        add_action('plugins_loaded', [$this, 'checkPluginCompatibility']);
        
        // Hooks para mostrar número de alumno en TODOS los albaranes
        add_action('wpo_wcpdf_before_order_details', [$this, 'displayStudentNumberInPdf'], 1, 2);
        
        // Hook más específico para personalizar solo el nombre en las direcciones
        add_filter('woocommerce_order_formatted_billing_address', [$this, 'customizeOrderBillingAddress'], 10, 2);
        add_filter('woocommerce_order_formatted_shipping_address', [$this, 'customizeOrderShippingAddress'], 10, 2);
        
        // Hooks adicionales para personalización de albaranes
        add_filter('wpo_wcpdf_document_title', [$this, 'customizeDocumentTitle'], 10, 2);
        add_action('wpo_wcpdf_after_order_details', [$this, 'addCustomFooterInfo'], 10, 2);
    }

    /**
     * Verificar compatibilidad con el plugin WC PDF
     */
    public function checkPluginCompatibility(): void
    {
        if (!function_exists('wcpdf_get_document') && !class_exists('WPO\WC\PDF_Invoices\Main')) {
            add_action('admin_notices', [$this, 'showPluginRequiredNotice']);
            return;
        }
    }

    /**
     * Mostrar aviso si el plugin requerido no está activo
     */
    public function showPluginRequiredNotice(): void
    {
        echo '<div class="notice notice-warning">';
        echo '<p><strong>' . __('Packing Slip Customizer:', 'neve-child-dm-woo') . '</strong> ' . __('Requires the "WooCommerce PDF Invoices & Packing Slips" plugin to work.', 'neve-child-dm-woo') . '</p>';
        echo '</div>';
    }

    /**
     * Personalizar dirección de facturación para mostrar datos del alumno
     * SOLO APLICA A PACKING SLIPS (albaranes), NO a facturas ni otros documentos
     */
    public function customizeOrderBillingAddress(array $address, \WC_Order $order): array
    {
        // Solo aplicar durante la generación de PDFs
        if (!$this->isGeneratingPdf()) {
            return $address;
        }

        // CRÍTICO: Solo aplicar a packing slips, NO a facturas ni otros documentos
        if (!$this->isGeneratingPackingSlip()) {
            return $address;
        }

        // No modificar para pedidos maestros
        if ($order->get_meta('_is_master_order') === 'yes') {
            return $address;
        }

        $student_name_parts = $this->getStudentNameParts($order);
        if (!$student_name_parts) {
            return $address; // Si no hay datos del alumno, usar dirección original
        }

        // Modificar solo el nombre y apellidos, mantener el resto
        $address['first_name'] = $student_name_parts['first_name'];
        $address['last_name'] = $student_name_parts['last_name'];

        return $address;
    }

    /**
     * Personalizar dirección de envío para mostrar datos del alumno
     * SOLO APLICA A PACKING SLIPS (albaranes), NO a facturas ni otros documentos
     */
    public function customizeOrderShippingAddress(array $address, \WC_Order $order): array
    {
        // Solo aplicar durante la generación de PDFs
        if (!$this->isGeneratingPdf()) {
            return $address;
        }

        // CRÍTICO: Solo aplicar a packing slips, NO a facturas ni otros documentos
        if (!$this->isGeneratingPackingSlip()) {
            return $address;
        }

        // No modificar para pedidos maestros
        if ($order->get_meta('_is_master_order') === 'yes') {
            return $address;
        }

        $student_name_parts = $this->getStudentNameParts($order);
        if (!$student_name_parts) {
            return $address; // Si no hay datos del alumno, usar dirección original
        }

        // Modificar solo el nombre y apellidos, mantener el resto
        $address['first_name'] = $student_name_parts['first_name'];
        $address['last_name'] = $student_name_parts['last_name'];

        return $address;
    }

    /**
     * Verificar si se está generando un PDF
     */
    private function isGeneratingPdf(): bool
    {
        // Verificar si estamos en el contexto de generación de PDF
        return (
            (isset($_GET['action']) && $_GET['action'] === 'generate_wpo_wcpdf') ||
            (defined('DOING_AJAX') && DOING_AJAX && isset($_POST['action']) && $_POST['action'] === 'generate_wpo_wcpdf') ||
            (function_exists('wcpdf_get_document') && !empty($GLOBALS['wpo_wcpdf_current_document'])) ||
            (class_exists('WPO\WC\PDF_Invoices\Main') && !empty($GLOBALS['wc_pdf_invoice_processing']))
        );
    }

    /**
     * Verificar si se está generando específicamente un PACKING SLIP
     * NO una factura ni otro tipo de documento
     */
    private function isGeneratingPackingSlip(): bool
    {
        // Verificar parámetros GET para generación individual
        if (isset($_GET['document_type']) && $_GET['document_type'] === 'packing-slip') {
            return true;
        }

        // Verificar parámetros POST para generación AJAX/masiva
        if (defined('DOING_AJAX') && DOING_AJAX) {
            if (isset($_POST['document_type']) && $_POST['document_type'] === 'packing-slip') {
                return true;
            }
        }

        // Verificar el documento actual en contexto global
        if (!empty($GLOBALS['wpo_wcpdf_current_document'])) {
            $document = $GLOBALS['wpo_wcpdf_current_document'];
            if (is_object($document) && method_exists($document, 'get_type')) {
                return $document->get_type() === 'packing-slip';
            }
        }

        // Verificar por URL patterns específicos de packing slips
        if (isset($_SERVER['REQUEST_URI'])) {
            $request_uri = $_SERVER['REQUEST_URI'];
            if (strpos($request_uri, 'packing-slip') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Obtener las partes del nombre del alumno desde los campos ACF
     */
    private function getStudentNameParts(\WC_Order $order): ?array
    {
        $customer_user_id = $order->get_customer_id();
        
        if (!$customer_user_id) {
            return null;
        }

        // Obtener datos del alumno desde ACF
        $user_name = '';
        $user_first_surname = '';
        $user_second_surname = '';

        if (function_exists('get_field')) {
            $user_name = get_field('user_name', 'user_' . $customer_user_id);
            $user_first_surname = get_field('user_first_surname', 'user_' . $customer_user_id);
            $user_second_surname = get_field('user_second_surname', 'user_' . $customer_user_id);
        }

        // Fallback: obtener desde user meta directo
        if (empty($user_name)) {
            $user_name = get_user_meta($customer_user_id, 'user_name', true);
        }
        if (empty($user_first_surname)) {
            $user_first_surname = get_user_meta($customer_user_id, 'user_first_surname', true);
        }
        if (empty($user_second_surname)) {
            $user_second_surname = get_user_meta($customer_user_id, 'user_second_surname', true);
        }

        // Verificar que tenemos al menos nombre y primer apellido
        if (empty($user_name) || empty($user_first_surname)) {
            return null;
        }

        // Combinar apellidos
        $last_name = trim($user_first_surname);
        if (!empty($user_second_surname)) {
            $last_name .= ' ' . $user_second_surname;
        }

        return [
            'first_name' => $user_name,
            'last_name' => $last_name
        ];
    }

    /**
     * Mostrar número del alumno en TODOS los PDFs usando el hook correcto
     * Aplica tanto a albaranes individuales como masivos
     */
    public function displayStudentNumberInPdf(string $document_type, $order): void
    {        
        // Solo aplicar a packing slips
        if ($document_type !== 'packing-slip') {
            return;
        }
        
        // Asegurar que tenemos un objeto order válido
        if (!$order instanceof \WC_Order) {
            return;
        }
        
        $order_id = $order->get_id();
        
        // Crear clave única para este pedido específico para evitar duplicados por pedido
        static $displayed_for_orders = [];
        
        // Si ya se mostró para este pedido específico, no volver a mostrar
        if (isset($displayed_for_orders[$order_id])) {
            return;
        }

        // Verificar si es pedido maestro
        $is_master_order = ($order->get_meta('_is_master_order') === 'yes');
        
        // Calcular total de productos (cantidad total de cajas) - siempre necesario
        $total_boxes = 0;
        foreach ($order->get_items() as $item) {
            $total_boxes += $item->get_quantity();
        }

        // Marcar que ya se mostró para este pedido específico
        $displayed_for_orders[$order_id] = true;

        if ($is_master_order) {
            // Para pedidos maestros: solo mostrar total de cajas
            ?>
            <div style="margin:20px 0;padding:15px;background-color:#ffffff;color:#000000;text-align:center;border:4px solid #000000;border-radius:8px;page-break-inside:avoid;width:120px;display:flex;align-items:center;justify-content:center;">
                <div style="font-size:16px;font-weight:bold;color:#000000;line-height:1;">
                    <?php echo sprintf(__('Total boxes: %s', 'neve-child-dm-woo'), esc_html($total_boxes)); ?>
                </div>
            </div>
            <?php
        } else {
            // Para pedidos normales: intentar mostrar número de alumno + total de cajas
            // PERO SIEMPRE mostrar al menos el total de cajas
            
            // Obtener el ID del usuario que hizo el pedido (cliente)
            $customer_user_id = $order->get_customer_id();
            
            $user_number = '';
            
            // Solo intentar obtener número de alumno si hay usuario
            if ($customer_user_id && get_userdata($customer_user_id)) {
                // Obtener el número del alumno desde ACF del cliente
                if (function_exists('get_field')) {
                    $user_number = get_field('user_number', 'user_' . $customer_user_id);
                }
                
                // Fallback: obtener desde user meta directo
                if (empty($user_number)) {
                    $user_number = get_user_meta($customer_user_id, 'user_number', true);
                }
            }

            // MOSTRAR SIEMPRE: con número de alumno si lo tiene, o solo total si no lo tiene
            if (!empty($user_number)) {
                // CON número de alumno
                ?>
                <div style="margin:20px 0;padding:15px;background-color:#ffffff;color:#000000;text-align:center;border:4px solid #000000;border-radius:8px;page-break-inside:avoid;width:120px;display:flex;flex-direction:column;align-items:center;justify-content:center;">
                    <h1 style="margin:0;font-size:28px;font-weight:bold;color:#000000;line-height:1;">
                        <?php echo sprintf(__('No. %s', 'neve-child-dm-woo'), esc_html($user_number)); ?>
                    </h1>
                    <div style="margin-top:8px;font-size:14px;font-weight:normal;color:#000000;line-height:1;">
                        <?php echo sprintf(__('Total boxes: %s', 'neve-child-dm-woo'), esc_html($total_boxes)); ?>
                    </div>
                </div>
                <?php
            } else {
                // SIN número de alumno - SOLO mostrar total de cajas
                ?>
                <div style="margin:20px 0;padding:15px;background-color:#ffffff;color:#000000;text-align:center;border:4px solid #000000;border-radius:8px;page-break-inside:avoid;width:120px;display:flex;align-items:center;justify-content:center;">
                    <div style="font-size:16px;font-weight:bold;color:#000000;line-height:1;">
                        <?php echo sprintf(__('Total boxes: %s', 'neve-child-dm-woo'), esc_html($total_boxes)); ?>
                    </div>
                </div>
                <?php
            }
        }
    }

    /**
     * Personalizar título del documento
     */
    public function customizeDocumentTitle(string $title, $document): string
    {
        // Solo aplicar a packing slips
        if (!is_object($document) || $document->get_type() !== 'packing-slip') {
            return $title;
        }

        // Aquí puedes personalizar el título según tus necesidades
        // Por ejemplo, agregar información de la escuela
        return $title;
    }

    /**
     * Agregar información personalizada al final del albarán
     */
    public function addCustomFooterInfo(string $document_type, $order): void
    {
        // Solo aplicar a packing slips
        if ($document_type !== 'packing-slip') {
            return;
        }
        
        // Asegurar que tenemos un objeto order válido
        if (!$order instanceof \WC_Order) {
            return;
        }

        // Aquí puedes agregar información adicional al final del albarán
        // Por ejemplo, instrucciones especiales, información de la escuela, etc.
        
        // Ejemplo: Mostrar información de la escuela si existe
        $school_info = $order->get_meta('_school_info');
        if (!empty($school_info)) {
            ?>
            <div style="margin-top: 20px; padding: 10px; border-top: 1px solid #ddd; font-size: 12px; color: #666;">
                <strong><?php _e('School information:', 'neve-child-dm-woo'); ?></strong> <?php echo esc_html($school_info); ?>
            </div>
            <?php
        }
    }
}

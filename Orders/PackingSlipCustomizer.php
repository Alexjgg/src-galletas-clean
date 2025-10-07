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
     * Flag para prevenir múltiples ejecuciones
     */
    private static $instance_running = false;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Prevenir múltiples instancias
        if (self::$instance_running) {
            return;
        }
        self::$instance_running = true;
        
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
        // DESHABILITADO: Para evitar duplicados con las direcciones
        // add_action('wpo_wcpdf_before_order_details', [$this, 'displayStudentNumberInPdf'], 1, 2);

        // Hook para mostrar información del estudiante después del área de direcciones de envío
        add_action('wpo_wcpdf_after_shipping_address', [$this, 'displayStudentInfoAfterAddresses'], 10, 2);

        // Hooks para personalizar direcciones y mostrar solo información del estudiante
        add_filter('woocommerce_order_formatted_billing_address', [$this, 'customizeOrderBillingAddress'], 10, 2);
        add_filter('woocommerce_order_formatted_shipping_address', [$this, 'customizeOrderShippingAddress'], 10, 2);
        
        // 🎯 HOOKS PARA TAX ID EN PACKING SLIPS - Igual que en facturas
        add_filter('wpo_wcpdf_shop_coc_number', [$this, 'filterPackingSlipTaxId'], 5, 2);
        add_filter('wpo_wcpdf_formatted_shop_coc_number', [$this, 'filterPackingSlipTaxId'], 5, 2);
        add_filter('wpo_wcpdf_shop_vat_number', [$this, 'filterPackingSlipTaxId'], 5, 2);
        add_filter('wpo_wcpdf_formatted_shop_vat_number', [$this, 'filterPackingSlipTaxId'], 5, 2);
        add_filter('wpo_wcpdf_coc_number_settings_text', [$this, 'filterPackingSlipTaxId'], 5, 2);
        add_filter('wpo_wcpdf_vat_number_settings_text', [$this, 'filterPackingSlipTaxId'], 5, 2);
        
        // 🎯 HOOKS PARA OCULTAR MÉTODO DE PAGO EN PACKING SLIPS
        add_filter('woocommerce_order_get_payment_method_title', [$this, 'hidePaymentMethodInPdf'], 10, 2);
        add_filter('wpo_wcpdf_order_payment_method', [$this, 'hidePaymentMethodInPdf'], 10, 2);
        add_action('wpo_wcpdf_before_order_details', [$this, 'hidePaymentMethodSection'], 10, 2);
        
        // 🎯 HOOKS PARA OCULTAR DIRECCIÓN DE LA EMPRESA EN PACKING SLIPS
        add_filter('wpo_wcpdf_shop_address', [$this, 'hideCompanyAddressInPackingSlip'], 10, 2);
        add_filter('wpo_wcpdf_formatted_shop_address', [$this, 'hideCompanyAddressInPackingSlip'], 10, 2);
        add_action('wpo_wcpdf_before_order_details', [$this, 'hideCompanyAddressSection'], 5, 2);
        
        // 🎯 HOOKS PARA OPTIMIZAR TABLA Y QUITAR FOOTER
        add_action('wpo_wcpdf_before_order_details', [$this, 'optimizeTableForSpace'], 1, 2);
        
        // 🎯 HOOKS PARA ELIMINAR COMPLETAMENTE EL FOOTER
        add_filter('wpo_wcpdf_footer', [$this, 'hideRgpdFooterInPackingSlip'], 10, 2);
        add_action('wpo_wcpdf_after_order_details', [$this, 'removeFooterContent'], 999, 2);
        add_action('wpo_wcpdf_after_document', [$this, 'removeFooterContent'], 999, 2);
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
        echo '<p>' . __('"WooCommerce PDF Invoices & Packing Slips" plugin is required to work.', 'neve-child') . '</p>';
        echo '</div>';
    }

    /**
     * Mostrar información del estudiante después del área de direcciones
     * Se ejecuta después de las direcciones de envío, ideal para mostrar las cajas de información
     */
    public function displayStudentInfoAfterAddresses(string $document_type, \WC_Order $order): void
    {
        // Solo aplicar a packing slips
        if ($document_type !== 'packing-slip') {
            return;
        }

        // Asegurar que tenemos un objeto order válido
        if (!$order instanceof \WC_Order) {
            return;
        }

        // Verificar si es pedido maestro
        $is_master_order = $order->get_meta('_is_master_order') === 'yes';

        // Obtener datos del estudiante/maestro
        $student_name_parts = $this->getStudentNameParts($order);
        $student_number = $this->getStudentNumber($order);
        $total_boxes = $this->getTotalBoxes($order);
        $school_name = $this->getSchoolName($order);

        // 🔍 DEBUG: Mostrar información para debugging
        // echo "<!-- DEBUG: is_master_order={$is_master_order}, student_number={$student_number}, total_boxes={$total_boxes} -->";

        ?>
        <?php if ($is_master_order): ?>
            <!-- 🎯 PEDIDOS MAESTROS -->
            <div class="master-order-info-box" style="margin: 10px 0; background-color: #ffffff; border: 4px solid #000000; border-radius: 8px; text-align: center; width: 120px; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 15px; color: #000000; font-family: Arial, sans-serif; outline: 4px solid #000000;">
                <div class="master-order-label" style="font-size: 16px; font-weight: bold; color: #000000; line-height: 1; margin: 0;">
                    <?php echo __('MASTER ORDER', 'neve-child'); ?>
                </div>
                <div class="total-boxes" style="margin-top: 8px; font-size: 14px; font-weight: normal; color: #000000; line-height: 1;">
                    <?php echo sprintf(__('Nº boxes: %s', 'neve-child'), esc_html($total_boxes)); ?>
                </div>
            </div>
        <?php elseif (!empty($student_number)): ?>
            <!-- 🎯 ESTUDIANTES CON NÚMERO -->
            <div class="student-info-box with-student-number" style="margin: 10px 0; background-color: #ffffff; border: 4px solid #000000; border-radius: 8px; text-align: center; width: 120px; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 15px; color: #000000; font-family: Arial, sans-serif; outline: 4px solid #000000;">
                <div class="student-number" style="font-size: 28px; font-weight: bold; color: #000000; line-height: 1; margin: 0;">
                    <?php echo sprintf(__('Nº %s', 'neve-child'), esc_html($student_number)); ?>
                </div>
                <div class="total-boxes" style="margin-top: 8px; font-size: 14px; font-weight: normal; color: #000000; line-height: 1;">
                    <?php echo sprintf(__('Nº boxes: %s', 'neve-child'), esc_html($total_boxes)); ?>
                </div>
            </div>
        <?php else: ?>
            <!-- 🎯 OTROS CASOS (solo número de cajas) -->
            <div class="student-info-box total-boxes-only" style="margin: 20px 0; background-color: #ffffff; border: 4px solid #000000; border-radius: 8px; text-align: center; width: 120px; display: flex; align-items: center; justify-content: center; padding: 15px; font-size: 16px; font-weight: bold; color: #000000; font-family: Arial, sans-serif; outline: 4px solid #000000;">
                <?php echo sprintf(__('Nº boxes: %s', 'neve-child'), esc_html($total_boxes)); ?>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Personalizar dirección de facturación para mostrar solo datos del alumno
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

        // Para pedidos maestros, mostrar solo el nombre del colegio en company
        if ($order->get_meta('_is_master_order') === 'yes') {
            $school_name = $this->getSchoolName($order);
            return [
                'first_name' => '',
                'last_name' => '',
                'company' => $school_name, // Mantener nombre del colegio
                'address_1' => '',
                'address_2' => '',
                'city' => '',
                'state' => '',
                'postcode' => '',
                'country' => '',
            ];
        }

        $student_name_parts = $this->getStudentNameParts($order);
        if (!$student_name_parts) {
            return $address; // Si no hay datos del alumno, usar dirección original
        }

        // Obtener nombre del colegio
        $school_name = $this->getSchoolName($order);

        // Modificar para mostrar solo nombre del alumno y colegio, sin direcciones
        $address = [
            'first_name' => $student_name_parts['first_name'],
            'last_name' => $student_name_parts['last_name'],
            'company' => $school_name, // Mostrar nombre del colegio en empresa
            'address_1' => '', // Ocultar dirección
            'address_2' => '', // Ocultar dirección 2
            'city' => '', // Ocultar ciudad
            'state' => '', // Ocultar estado/provincia
            'postcode' => '', // Ocultar código postal
            'country' => '', // Ocultar país
        ];

        return $address;
    }

    /**
     * Personalizar dirección de envío para mostrar solo datos del alumno
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

        // Para pedidos maestros, mostrar solo el nombre del colegio en company
        if ($order->get_meta('_is_master_order') === 'yes') {
            $school_name = $this->getSchoolName($order);
            return [
                'first_name' => '',
                'last_name' => '',
                'company' => $school_name, // Mantener nombre del colegio
                'address_1' => '',
                'address_2' => '',
                'city' => '',
                'state' => '',
                'postcode' => '',
                'country' => '',
            ];
        }

        $student_name_parts = $this->getStudentNameParts($order);
        if (!$student_name_parts) {
            return $address; // Si no hay datos del alumno, usar dirección original
        }

        // Obtener nombre del colegio
        $school_name = $this->getSchoolName($order);

        // Modificar para mostrar solo nombre del alumno y colegio, sin direcciones
        $address = [
            'first_name' => $student_name_parts['first_name'],
            'last_name' => $student_name_parts['last_name'],
            'company' => $school_name, // Mostrar nombre del colegio en empresa
            'address_1' => '', // Ocultar dirección
            'address_2' => '', // Ocultar dirección 2
            'city' => '', // Ocultar ciudad
            'state' => '', // Ocultar estado/provincia
            'postcode' => '', // Ocultar código postal
            'country' => '', // Ocultar país
        ];

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
     * Obtener el nombre del colegio desde los meta del pedido
     */
    private function getSchoolName(\WC_Order $order): string
    {
        // Intentar obtener el nombre del colegio desde los meta del pedido
        $school_name = $order->get_meta('_school_name');
        
        // Si no está disponible, intentar obtenerlo desde el ID del colegio
        if (empty($school_name)) {
            $school_id = $order->get_meta('_school_id');
            if (!empty($school_id)) {
                $school_post = get_post($school_id);
                if ($school_post && $school_post->post_status === 'publish') {
                    $school_name = $school_post->post_title;
                }
            }
        }
        
        // Si aún no tenemos nombre, intentar desde ACF del pedido
        if (empty($school_name) && function_exists('get_field')) {
            $school_name = get_field('school_name', $order->get_id());
        }
        
        // Fallback: usar texto por defecto si no hay información del colegio
        if (empty($school_name)) {
            $school_name = __('School', 'neve-child');
        }
        
        return $school_name;
    }

    /**
     * Obtener el número del alumno desde los campos ACF
     */
    private function getStudentNumber(\WC_Order $order): string
    {
        $customer_user_id = $order->get_customer_id();
        
        if (!$customer_user_id) {
            return '';
        }

        // Obtener el número del alumno desde ACF del cliente
        $user_number = '';
        if (function_exists('get_field')) {
            $user_number = get_field('user_number', 'user_' . $customer_user_id);
        }
        
        // Fallback: obtener desde user meta directo
        if (empty($user_number)) {
            $user_number = get_user_meta($customer_user_id, 'user_number', true);
        }

        return (string) $user_number;
    }

    /**
     * Calcular el total de cajas (cantidad total de productos) del pedido
     */
    private function getTotalBoxes(\WC_Order $order): int
    {
        $total_boxes = 0;
        foreach ($order->get_items() as $item) {
            $total_boxes += $item->get_quantity();
        }
        return $total_boxes;
    }

    /**
     * 🎯 Filtrar Tax ID del vendor para packing slips
     * Solo se aplica cuando se está generando un packing slip
     */
    public function filterPackingSlipTaxId($tax_id, $document): string
    {
        // Solo aplicar a packing slips, NO a facturas ni otros documentos
        if (!$this->isGeneratingPackingSlip()) {
            return $tax_id;
        }

        if (!is_object($document)) {
            return $tax_id;
        }

        $order_id = $this->getOrderIdFromDocument($document);
        if ($order_id) {
            $vendor_id = $this->getVendorId($order_id);
            if ($vendor_id) {
                $vendor_tax_id = get_field('_taxIdentificationNumber', $vendor_id) ?: '';
                $cleaned_tax_id = trim(str_replace(':', '', $vendor_tax_id));
                
                if ($cleaned_tax_id) {
                    return $cleaned_tax_id;
                }
            }
        }
        
        return $tax_id;
    }

    /**
     * Obtener order_id de un documento
     */
    private function getOrderIdFromDocument($document): ?int
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
     * Obtener vendor_id desde un order_id
     */
    private function getVendorId($order_id): ?int
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return null;
        }

        // Primero, verificar si la orden ya tiene vendor_id directamente asignado
        $vendor_id = $order->get_meta('_vendor_id');
        if ($vendor_id) {
            return (int) $vendor_id;
        }

        // Fallback: buscar vendor a través del school_id
        $school_id = $this->getSchoolIdFromOrder($order);
        if (!$school_id) {
            return null;
        }

        return get_field('vendor', $school_id);
    }

    /**
     * Obtener school_id del order
     */
    private function getSchoolIdFromOrder($order): ?int
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
     * Ocultar método de pago en PDFs (solo para packing slips)
     * 
     * @param string $payment_method_title Título del método de pago
     * @param \WC_Order $order Objeto de pedido
     * @return string Título modificado (vacío para ocultar)
     */
    public function hidePaymentMethodInPdf($payment_method_title, $order = null): string
    {
        // Solo aplicar durante la generación de PDFs
        if (!$this->isGeneratingPdf()) {
            return $payment_method_title;
        }

        // CRÍTICO: Solo aplicar a packing slips, NO a facturas ni otros documentos
        if (!$this->isGeneratingPackingSlip()) {
            return $payment_method_title;
        }

        // Ocultar método de pago devolviendo string vacío
        return '';
    }

    /**
     * Ocultar sección completa del método de pago usando CSS
     * 
     * @param string $document_type Tipo de documento
     * @param \WC_Order $order Objeto de pedido
     * @return void
     */
    public function hidePaymentMethodSection(string $document_type, \WC_Order $order): void
    {
        // Solo aplicar a packing slips
        if ($document_type !== 'packing-slip') {
            return;
        }

        // Agregar CSS inline para ocultar elementos relacionados con método de pago
        ?>
        <style>
        /* Ocultar método de pago en packing slips */
        .payment-method,
        .payment_method,
        .order-payment-method,
        .wc-order-payment-method,
        .payment-info,
        .payment_info,
        tr.payment-method,
        tr.payment_method,
        .document-notes .payment,
        .order-data .payment,
        .order-meta .payment,
        td.payment-method,
        td.payment_method,
        th.payment-method,
        th.payment_method,
        .wpo-wcpdf-payment-method,
        .wcpdf-payment-method {
            display: none !important;
        }
        
        /* También ocultar filas de tabla que contengan "Payment" o "Pago" */
        tr:has(.payment-method),
        tr:has(.payment_method),
        tr:contains("Payment Method"),
        tr:contains("Método de pago"),
        tr:contains("Payment"),
        tr:contains("Pago") {
            display: none !important;
        }
        </style>
        <?php
    }

    /**
     * Ocultar dirección de la empresa en packing slips
     * 
     * @param string $shop_address Dirección de la empresa
     * @param object $document Objeto de documento
     * @return string Dirección modificada (vacía para ocultar)
     */
    public function hideCompanyAddressInPackingSlip($shop_address, $document = null): string
    {
        // Solo aplicar durante la generación de PDFs
        if (!$this->isGeneratingPdf()) {
            return $shop_address;
        }

        // CRÍTICO: Solo aplicar a packing slips, NO a facturas ni otros documentos
        if (!$this->isGeneratingPackingSlip()) {
            return $shop_address;
        }

        // Verificar si es un packing slip por el tipo de documento
        if ($document && is_object($document) && method_exists($document, 'get_type')) {
            if ($document->get_type() !== 'packing-slip') {
                return $shop_address;
            }
        }

        // Ocultar dirección de la empresa devolviendo string vacío
        return '';
    }

    /**
     * Ocultar sección completa de la dirección de empresa usando CSS
     * 
     * @param string $document_type Tipo de documento
     * @param \WC_Order $order Objeto de pedido
     * @return void
     */
    public function hideCompanyAddressSection(string $document_type, \WC_Order $order): void
    {
        // Solo aplicar a packing slips
        if ($document_type !== 'packing-slip') {
            return;
        }

        // Agregar CSS inline para ocultar elementos relacionados con dirección de empresa
        ?>
        <style>
        /* Ocultar dirección de empresa en packing slips */
        .shop-address,
        .company-address,
        .shop-details .address,
        .document-header .address,
        .wpo-wcpdf-shop-address,
        .wcpdf-shop-address,
        .shop-info .address,
        .header-shop-address,
        .pdf-shop-address,
        .from-address,
        .sender-address,
        .company-info .address,
        .shop-details address,
        .document-header address,
        .header address,
        .shop-name + address,
        .shop-name + .address,
        address.shop-address,
        .wpo_wcpdf_shop_address,
        .wcpdf_shop_address,
        .document-header-left address,
        .document-header-right address,
        .shop-column address,
        .from-column address {
            display: none !important;
        }
        
        /* También ocultar elementos que contengan dirección específica */
        .document-header p:has(address),
        .shop-details p:has(address),
        .header p:has(address) {
            display: none !important;
        }
        </style>
        <?php
    }

    /**
     * Optimizar tabla de productos para que entren 36 elementos
     */
    public function optimizeTableForSpace(string $document_type, \WC_Order $order): void
    {
        // Solo aplicar a packing slips
        if ($document_type !== 'packing-slip') {
            return;
        }

        ?>
        <style>
        /* Optimización de tabla para que entren 36 elementos */
        .order-details table,
        .order-items table,
        #order_details_table,
        table.order-details,
        table.order-items {
            font-size: 8px !important;
        }
        
        .order-details table td,
        .order-details table th,
        .order-items table td,
        .order-items table th,
        #order_details_table td,
        #order_details_table th,
        table.order-details td,
        table.order-details th,
        table.order-items td,
        table.order-items th {
            font-size: 10px !important;
            padding: 0px 1px !important;
            line-height: 1 !important;
            margin: 0 !important;
            border-spacing: 0 !important;
        }
        
        .order-details tbody tr,
        .order-items tbody tr,
        #order_details_table tbody tr,
        table.order-details tbody tr,
        table.order-items tbody tr {
            height: auto !important;
            min-height: 10px !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        
        .order-details table,
        .order-items table,
        #order_details_table,
        table.order-details,
        table.order-items {
            border-spacing: 0 !important;
            border-collapse: collapse !important;
            margin: 0 !important;
        }
        </style>
        <?php
    }

    /**
     * Quitar footer RGPD de los packing slips
     */
    public function hideRgpdFooterInPackingSlip($footer, $document = null): string
    {
        // Solo aplicar a packing slips
        if ($document && method_exists($document, 'get_type') && $document->get_type() === 'packing-slip') {
            return '';
        }
        
        return $footer;
    }

    /**
     * Eliminar completamente cualquier contenido de footer en packing slips
     */
    public function removeFooterContent(string $document_type, \WC_Order $order): void
    {
        // Solo aplicar a packing slips
        if ($document_type !== 'packing-slip') {
            return;
        }

        // Agregar CSS para eliminar completamente cualquier footer o contenido extra
        ?>
        <style>
        /* Eliminar completamente cualquier footer, pie de página o contenido RGPD */
        .document-footer,
        .footer,
        .wpo-wcpdf-footer,
        .wcpdf-footer,
        .footer-content,
        .document-notes,
        .footer-notes,
        .rgpd-footer,
        .privacy-footer,
        .terms-footer,
        .legal-footer,
        .page-break,
        .break-page,
        .wpo_wcpdf_footer,
        .wcpdf_footer {
            display: none !important;
            height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
            visibility: hidden !important;
        }
        
        /* Eliminar saltos de página automáticos */
        @page {
            margin-bottom: 0 !important;
        }
        
        /* Asegurar que no hay espacio extra al final */
        body, html {
            margin-bottom: 0 !important;
            padding-bottom: 0 !important;
        }
        </style>
        <?php
        
        // COMENTADO: Puede interferir con la generación del PDF
        // ob_start();
        // ob_clean();
    }


}

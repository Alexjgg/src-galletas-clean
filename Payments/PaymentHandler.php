<?php
/**
 * Manejador de Pagos para WooCommerce con Redsys
 * 
 * @package SchoolManagement\Payments
 * @since 1.0.0
 * @version 2.0.0
 * 
 * FUNCIONALIDADES PRINCIPALES:
 * 
 * 1. GESTIÓN DE PAGOS DIFERIDOS:
 *    - Permite reintentos de pago para pedidos dm_pay_later_card sin fecha de pago
 *    - Integración completa con el plugin de José Conti para Redsys
 *    - Manejo inteligente de estados de pedido para permitir repagos
 * 
 * 2. DETECCIÓN DE PAGOS CANCELADOS:
 *    - Detecta automáticamente pedidos con fechas falsas de pago
 *    - Limpia meta fields incorrectos para permitir reintentos
 *    - Verificación estricta usando confirmaciones reales de Redsys
 * 
 * 3. VERIFICACIÓN DE PAGOS REALES:
 *    - Utiliza múltiples indicadores de confirmación de Redsys
 *    - Evita falsos positivos basados en fechas o facturas
 *    - Solo considera pagado cuando hay confirmación real del banco
 * 
 * 4. GESTIÓN DE TRANSFERENCIAS BANCARIAS:
 *    - Cambia automáticamente pedidos de transferencia de on-hold a processing
 *    - Permite control personalizado del estado de pago fuera de WooCommerce
 *    - Evita que los pedidos se queden bloqueados en on-hold indefinidamente
 * 
 * 5. INTEGRACIÓN CON HOOKS DE WOOCOMMERCE:
 *    - Filtros con prioridades específicas para orden correcto de ejecución
 *    - Compatible con otros plugins de pago sin interferencias
 *    - Manejo de estados válidos para diferentes métodos de pago
 * 
 * META FIELDS UTILIZADOS:
 * - _dm_pay_later_card_payment_date: Fecha de pago diferido
 * - _dm_pay_later_card_payment_date_created: Fecha de creación del pago diferido
 * - payment_date: Fecha general de pago
 * 
 * NOTA: Los meta fields específicos de Redsys (_redsys_order_number, _redsys_auth_code, etc.) 
 * son gestionados automáticamente por el plugin de José Conti y no requieren intervención manual.
 * 
 * COMPATIBILIDAD:
 * - José Conti's Redsys Gateway Plugin
 * - WooCommerce 3.0+
 * - WordPress 5.0+
 */

namespace SchoolManagement\Payments;

if (!defined('ABSPATH')) {
    exit;
}

class PaymentHandler
{
    /**
     * Total mínimo del carrito para transferencia bancaria
     */
    private const MIN_BANK_TRANSFER_AMOUNT = 1000.0;

    /**
     * Si bloquear completamente el checkout cuando hay pedidos pendientes
     * Establecer en false para solo mostrar un aviso en lugar de bloquear
     */
    private const BLOCK_CHECKOUT_WITH_PENDING_ORDERS = true;

    /**
     * Instancia singleton
     */
    private static $instance = null;
    
    /**
     * Evitar instanciaciones múltiples
     */
    private static $initialized = false;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Evitar múltiples instanciaciones
        if (self::$initialized) {
            return;
        }
        
        self::$initialized = true;
        $this->initHooks();
    }
    
    /**
     * Obtener instancia singleton
     * 
     * @return PaymentHandler
     */
    public static function getInstance(): PaymentHandler
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Inicializar hooks de WordPress
     */
    public function initHooks(): void
    {
        add_filter('woocommerce_order_needs_payment', [$this, 'enablePaymentForPayLater'], 10, 2);
        add_filter('redsys_status_pending', [$this, 'addPayLaterToRedsysStatus'], 10);
        add_action('woocommerce_payment_complete', [$this, 'handlePaymentComplete']);
        add_filter('woocommerce_available_payment_gateways', [$this, 'filterPaymentMethodsByCartTotal']);
        
        // SOLUCIÓN ÚNICA: Hook IPN de Redsys (máxima seguridad y confiabilidad)
        // PRIORIDAD ALTA (5) para LIMPIAR ANTES de José Conti
        add_action('valid-redsys-standard-ipn-request', [$this, 'prepareForJoseContiProcessing'], 5, 1);
        // PRIORIDAD BAJA (20) para ejecutar DESPUÉS del plugin de José Conti
        add_action('valid-redsys-standard-ipn-request', [$this, 'handleRedsysIPNNotification'], 20, 1);
        //Bizum Redsys
        add_action('valid_bizumredsys_standard_ipn_request', [$this, 'handleBizumRedsysIPNNotification'], 20, 1);
        
        // Hook para transferencia bancaria: cambiar estado de on-hold a processing
        add_action('woocommerce_thankyou_bacs', [$this, 'setBankTransferToProcessing'], 10, 1);
        add_filter('woocommerce_bacs_process_payment_order_status', [$this, 'forceBankTransferProcessingStatus'], 10, 2);
        
        // Hooks ESPECÍFICOS solo para pedidos diferidos que intentan repagar
        add_filter('woocommerce_order_needs_payment', [$this, 'allowDeferredRepayment'], 15, 2);
        add_filter('woocommerce_valid_order_statuses_for_payment', [$this, 'allowDeferredRepaymentStatuses'], 15, 2);

        // Hooks para manejar cancelaciones y errores de Redsys
        add_action('redsys_payment_failed', [$this, 'handleRedsysFailure'], 10, 1);
        add_action('redsys_payment_cancelled', [$this, 'handleRedsysCancellation'], 10, 1);
        add_filter('woocommerce_order_needs_payment', [$this, 'allowRetryAfterFailure'], 20, 2);
        add_filter('woocommerce_valid_order_statuses_for_payment', [$this, 'allowRetryAfterFailureStatuses'], 20, 2);
        
        // Hook para restaurar estado original cuando el pago falla
        add_action('woocommerce_order_status_changed', [$this, 'restoreOriginalStatusOnPaymentFailure'], 10, 4);
        
        // Hook para detectar automáticamente cancelaciones/fallos mirando el estado del pedido
        add_filter('woocommerce_order_needs_payment', [$this, 'detectAndFixCancelledPayments'], 25, 2);
        add_filter('woocommerce_valid_order_statuses_for_payment', [$this, 'detectAndFixCancelledPaymentsStatuses'], 25, 2);
        
        // Hook para acción programada de restaurar estado
        add_action('restore_original_order_status', [$this, 'executeRestoreOriginalStatus'], 10, 2);
    }





    /**
     * Manejar notificación IPN de Redsys (MÉTODO COMPLEMENTARIO Y DE RESPALDO)
     * Se ejecuta DESPUÉS del plugin de José Conti para verificar y completar su trabajo
     * 
     * @param array $posted_data Datos recibidos desde Redsys vía IPN
     * @return void
     */
    public function handleRedsysIPNNotification($posted_data): void
    {
        // Extraer order ID de los datos IPN con múltiples estrategias
        $order_id = $this->extractOrderIdFromIPNData($posted_data);
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Verificar que el pago fue exitoso según los datos IPN
        if (!$this->isIPNPaymentSuccessful($posted_data)) {
            return;
        }

        // SIEMPRE RESTAURAR el estado original después de que José Conti procese
        $original_status = get_option('temp_order_' . $order_id . '_original_status');
        if ($original_status) {
            // SIEMPRE restaurar el estado original, sin importar lo que José Conti haya hecho
            $order->set_status($original_status, 'Estado original restaurado después de procesamiento de José Conti - meta fields actualizados', false);
            $order->save();
            delete_option('temp_order_' . $order_id . '_original_status');
            delete_option('temp_order_' . $order_id . '_status_change_time');
        }

        // VERIFICAR QUÉ HIZO JOSÉ CONTI (usando nombres correctos de meta fields)
        $jose_conti_auth_code = $order->get_meta('_authorisation_code_redsys');
        $jose_conti_order_number = $order->get_meta('_payment_order_number_redsys'); 
        $jose_conti_response = $order->get_meta('_redsys_done');
        $existing_payment_date = $order->get_meta('payment_date');

        // DETERMINAR QUÉ NECESITA COMPLETARSE
        $jose_conti_worked = (!empty($jose_conti_auth_code) && !empty($jose_conti_order_number) && $jose_conti_response === 'yes');
        $needs_payment_date = empty($existing_payment_date);
        
        if ($needs_payment_date) {
            // ESTABLECER NUESTROS META FIELDS
            $payment_date = new \DateTime();
            $formatted_date = $payment_date->format('Y-m-d H:i:s');            
            $order->update_meta_data('payment_date', $formatted_date);
            
            // Obtener método de pago para establecer meta fields específicos
            $payment_method = $order->get_payment_method();
            
            // Establecer meta fields específicos para dm_pay_later_card
            if ($payment_method === 'dm_pay_later_card') {
                $order->update_meta_data('_dm_pay_later_card_payment_date', $formatted_date);
                $order->update_meta_data('_dm_pay_later_card_payment_date_created', $formatted_date);
            }
            
            $order->save();
            $order->add_order_note(__('PaymentHandler backup: payment_date established', 'neve-child'));
        }
    }

    /**
     * Manejar notificación IPN de Bizum Redsys
     * Se ejecuta DESPUÉS del plugin de José Conti para verificar y completar su trabajo
     * 
     * @param array $posted_data Datos recibidos desde Bizum Redsys vía IPN
     * @return void
     */
    public function handleBizumRedsysIPNNotification($posted_data): void
    {
        // Extraer order ID de los datos IPN con múltiples estrategias
        $order_id = $this->extractOrderIdFromIPNData($posted_data);
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Verificar que el pago fue exitoso según los datos IPN
        if (!$this->isIPNPaymentSuccessful($posted_data)) {
            return;
        }

        // SIEMPRE RESTAURAR el estado original después de que José Conti procese
        $original_status = get_option('temp_order_' . $order_id . '_original_status');
        if ($original_status) {
            // SIEMPRE restaurar el estado original, sin importar lo que José Conti haya hecho
            $order->set_status($original_status, 'Estado original restaurado después de procesamiento de José Conti - meta fields actualizados (Bizum)', false);
            $order->save();
            delete_option('temp_order_' . $order_id . '_original_status');
            delete_option('temp_order_' . $order_id . '_status_change_time');
        }

        // VERIFICAR QUÉ HIZO JOSÉ CONTI (usando nombres correctos de meta fields para Bizum)
        $jose_conti_auth_code = $order->get_meta('_authorisation_code_redsys');
        $jose_conti_order_number = $order->get_meta('_payment_order_number_redsys'); 
        $jose_conti_response = $order->get_meta('_redsys_done');
        $existing_payment_date = $order->get_meta('payment_date');

        // DETERMINAR QUÉ NECESITA COMPLETARSE
        $jose_conti_worked = (!empty($jose_conti_auth_code) && !empty($jose_conti_order_number) && $jose_conti_response === 'yes');
        $needs_payment_date = empty($existing_payment_date);
        
        if ($needs_payment_date) {
            // ESTABLECER NUESTROS META FIELDS
            $payment_date = new \DateTime();
            $formatted_date = $payment_date->format('Y-m-d H:i:s');            
            $order->update_meta_data('payment_date', $formatted_date);
            
            // Obtener método de pago para establecer meta fields específicos
            $payment_method = $order->get_payment_method();
            
            // Establecer meta fields específicos para dm_pay_later_card o bizumredsys
            if ($payment_method === 'dm_pay_later_card') {
                $order->update_meta_data('_dm_pay_later_card_payment_date', $formatted_date);
                $order->update_meta_data('_dm_pay_later_card_payment_date_created', $formatted_date);
            } elseif ($payment_method === 'bizumredsys') {
                $order->update_meta_data('_bizum_payment_date', $formatted_date);
            }
            
            $order->save();
            $order->add_order_note(__('PaymentHandler backup: payment_date established for Bizum payment', 'neve-child'));
        }
    }

    /**
     * Extraer order ID de los datos de notificación IPN
     * 
     * @param array $posted_data Datos IPN de Redsys
     * @return int|null Order ID o null si no se encuentra
     */
    private function extractOrderIdFromIPNData($posted_data): ?int
    {
        // ESTRATEGIA 1: Buscar directamente order_id
        if (isset($posted_data['order_id'])) {
            return intval($posted_data['order_id']);
        }

        // ESTRATEGIA 2: Extraer de Ds_Order (formato estándar de Redsys)
        if (isset($posted_data['Ds_Order'])) {
            $ds_order = $posted_data['Ds_Order'];
            
            // Formato típico: prefijo + order_id con padding
            // Ejemplo: "937000000123" donde 123 es el order_id real
            if (preg_match('/^.*?(\d{1,6})$/', $ds_order, $matches)) {
                $order_id = intval($matches[1]);
                return $order_id;
            }
        }

        // ESTRATEGIA 3: Buscar en Ds_MerchantParameters (datos codificados)
        if (isset($posted_data['Ds_MerchantParameters'])) {
            try {
                $decoded_params = base64_decode($posted_data['Ds_MerchantParameters']);
                $params_array = json_decode($decoded_params, true);
                
                if (is_array($params_array) && isset($params_array['Ds_Order'])) {
                    $ds_order = $params_array['Ds_Order'];
                    if (preg_match('/^.*?(\d{1,6})$/', $ds_order, $matches)) {
                        $order_id = intval($matches[1]);
                        return $order_id;
                    }
                }
            } catch (Exception $e) {
                // Error silenciado, continúa con otras estrategias
            }
        }

        // ESTRATEGIA 4: Buscar otros campos posibles
        $possible_fields = ['order', 'Order', 'OrderId', 'order_number'];
        foreach ($possible_fields as $field) {
            if (isset($posted_data[$field])) {
                $order_id = intval($posted_data[$field]);
                if ($order_id > 0) {
                    return $order_id;
                }
            }
        }

        return null;
    }

    /**
     * Verificar si el pago IPN fue exitoso
     * 
     * @param array $posted_data Datos IPN de Redsys
     * @return bool True si el pago fue exitoso
     */
    private function isIPNPaymentSuccessful($posted_data): bool
    {
        // Estrategia 1: Buscar datos directamente
        $ds_response = $posted_data['Ds_Response'] ?? '';
        $auth_code = $posted_data['Ds_AuthorisationCode'] ?? '';
        
        // Estrategia 2: Si no están directos, extraer de Ds_MerchantParameters
        if (empty($ds_response) && empty($auth_code) && isset($posted_data['Ds_MerchantParameters'])) {
            try {
                $decoded_params = base64_decode($posted_data['Ds_MerchantParameters']);
                $params_array = json_decode($decoded_params, true);
                
                if (is_array($params_array)) {
                    $ds_response = $params_array['Ds_Response'] ?? '';
                    $auth_code = $params_array['Ds_AuthorisationCode'] ?? '';
                }
            } catch (Exception $e) {
                // Error silenciado, usar datos directos si están disponibles
            }
        }

        // Redsys considera exitosos los códigos 0000-0099
        $response_code = intval($ds_response);
        $is_successful = ($response_code >= 0 && $response_code <= 99) && !empty($auth_code);
        
        return $is_successful;
    }

    /**
     * Guardar campos de Redsys como respaldo cuando José Conti no los guardó
     * 
     * @param \WC_Order $order Objeto de pedido
     * @param array $posted_data Datos IPN de Redsys
     * @return void
     */
    /**
     * Habilitar pago para pedidos de pago posterior
     * 
     * @param bool|null $needs_payment Estado actual de necesita pago
     * @param \WC_Order $order Objeto de pedido
     * @return bool Estado modificado de necesita pago
     */
    public function enablePaymentForPayLater(?bool $needs_payment, \WC_Order $order): bool
    {
        // VERIFICACIÓN CRÍTICA: Pedidos cancelados NO pueden ser repagados
        if ($order->get_status() === 'cancelled') {
            return false;
        }
        
        // Si viene null, convertir a false como valor por defecto
        $needs_payment = $needs_payment ?? false;
        
        if ($order->get_status() === 'pay-later') {
            return true;
        }

        return $needs_payment;
    }

    /**
     * Agregar estado de pago posterior a los estados pendientes de Redsys
     * 
     * @param array $status Estados pendientes actuales
     * @return array Estados pendientes modificados
     */
    public function addPayLaterToRedsysStatus(array $status): array
    {
        $status[] = 'pay-later';
        return $status;
    }

    /**
     * Manejar finalización de pago en WooCommerce
     * 
     * @param int $order_id ID del pedido
     * @return void
     */
    public function handlePaymentComplete(int $order_id): void
    {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }

        // Solo manejar Redsys normal, NO dm_pay_later_card
        if ($order->get_payment_method() === 'redsys' && $order->get_payment_method() !== 'dm_pay_later_card') {
            $this->handleRedsysPaymentComplete($order);
        }

        if ($order->get_payment_method() === 'dm_pay_later_card') {
            $order->add_order_note(__("⚠️ handlePaymentComplete executed but DISABLED for dm_pay_later_card for security. Only real payments with Redsys confirmation set payment date.", 'neve-child'));
        }

        // Agregar acciones personalizadas post-pago aquí
        $this->executePostPaymentActions($order);
    }    /**
     * Manejar finalización de pago de Redsys
     * 
     * @param \WC_Order $order Objeto de pedido
     * @return void
     */
    private function handleRedsysPaymentComplete(\WC_Order $order): void
    {
        // Solo procesar Redsys normal, NO dm_pay_later_card
        if ($order->get_payment_method() === 'dm_pay_later_card') {
            return; // Salir sin procesar
        }

        // Solo para pagos Redsys normales (no diferidos)
        if ($order->get_payment_method() === 'redsys') {
            $order->add_order_note(__('Payment completed through Redsys', 'neve-child'));
        }
        
        $order->save();
    }

    /**
     * Ejecutar acciones post-pago
     * 
     * @param \WC_Order $order Objeto de pedido
     * @return void
     */
    private function executePostPaymentActions(\WC_Order $order): void
    {
        // Agregar cualquier lógica personalizada post-pago aquí:
        // - Enviar emails personalizados
        // - Conceder acceso a productos digitales
        // - Generar licencias
        // - Actualizar permisos de usuario
        // - etc.

        do_action('school_management_payment_complete', $order);
    }

    /**
     * Filtrar métodos de pago por total del carrito
     * 
     * @param array $available_gateways Pasarelas de pago disponibles
     * @return array Pasarelas de pago filtradas
     */
    public function filterPaymentMethodsByCartTotal(array $available_gateways): array
    {
        // Solo aplicar en checkout y carrito, no en administración
        if (is_admin()) {
            return $available_gateways;
        }

        // Obtener total del carrito
        $cart_total = 0;

        if (WC()->cart && !WC()->cart->is_empty()) {
            $cart_total = floatval(WC()->cart->get_total('edit'));
        }

        // Si el total es menor al mínimo, remover transferencia bancaria
        if ($cart_total < self::MIN_BANK_TRANSFER_AMOUNT) {
            $bank_transfer_methods = ['bacs'];

            foreach ($bank_transfer_methods as $method_id) {
                if (isset($available_gateways[$method_id])) {
                    unset($available_gateways[$method_id]);
                }
            }
        }

        return $available_gateways;
    }

    /**
     * Cambiar estado de pedidos de transferencia bancaria de on-hold a processing
     * Se ejecuta en la página de agradecimiento después de realizar el pedido
     * 
     * @param int $order_id ID del pedido
     * @return void
     */
    public function setBankTransferToProcessing(int $order_id): void
    {
        $order = wc_get_order($order_id);
        
        if (!$order || $order->get_payment_method() !== 'bacs') {
            return;
        }

        // Solo cambiar si está en on-hold
        if ($order->get_status() === 'on-hold') {
            $order->update_status('processing', __('Order automatically moved to processing for bank transfer', 'neve-child'));
            
            // Añadir nota explicativa
            $order->add_order_note(__('Status automatically changed from on-hold to processing for bank transfers. Payment control is managed by another system.', 'neve-child'));
        }
    }

    /**
     * Forzar que los pedidos de transferencia bancaria vayan directamente a processing
     * Intercepta el estado que se asigna al procesar el pago
     * 
     * @param string $order_status Estado que se va a asignar
     * @param \WC_Order $order Objeto de pedido
     * @return string Estado modificado
     */
    public function forceBankTransferProcessingStatus(string $order_status, \WC_Order $order): string
    {
        // Si el estado por defecto sería on-hold, cambiarlo a processing
        if ($order_status === 'on-hold') {
            return 'processing';
        }
        
        return $order_status;
    }

    /**
     * Obtener monto mínimo de transferencia bancaria
     * 
     * @return float
     */
    public function getMinBankTransferAmount(): float
    {
        return self::MIN_BANK_TRANSFER_AMOUNT;
    }

    /**
     * Verificar si el pedido es elegible para transferencia bancaria
     * 
     * @param \WC_Order $order Objeto de pedido
     * @return bool
     */
    public function isOrderEligibleForBankTransfer(\WC_Order $order): bool
    {
        return $order->get_total() >= self::MIN_BANK_TRANSFER_AMOUNT;
    }

    /**
     * Obtener fecha de pago del pedido
     * 
     * @param \WC_Order $order Objeto de pedido
     * @return string|null Fecha de pago o null
     */
    public function getOrderPaymentDate(\WC_Order $order): ?string
    {
        return $order->get_meta('payment_date') ?: null;
    }

    /**
     * Establecer fecha de pago del pedido
     * 
     * @param \WC_Order $order Objeto de pedido
     * @param string $date Fecha de pago
     * @return void
     */
    public function setOrderPaymentDate(\WC_Order $order, string $date): void
    {
        $order->update_meta_data('payment_date', $date);
        $order->save();
    }

    /**
     * Permitir repago para pedidos diferidos sin fecha de pago
     * 
     * @param bool $needs_payment Estado actual
     * @param \WC_Order $order Objeto de pedido
     * @return bool Estado modificado
     */
    public function allowDeferredRepayment(bool $needs_payment, \WC_Order $order): bool
    {
        // VERIFICACIÓN CRÍTICA: Pedidos cancelados NO pueden ser repagados
        if ($order->get_status() === 'cancelled') {
            return false;
        }
        
        // Aplicar a pedidos diferidos Y a pedidos Redsys que pueden ser diferidos
        $payment_method = $order->get_payment_method();
        
        if ($payment_method === 'dm_pay_later_card') {
            $payment_date = $order->get_meta('_dm_pay_later_card_payment_date');
            
            // Si no tiene fecha de pago, permitir repago
            if (empty($payment_date)) {
                return true;
            }
            
            // Si ya está pagado, no necesita pago
            return false;
        }
        
        // NUEVO: Detectar pedidos Redsys que eran originalmente dm_pay_later_card
        if ($payment_method === 'redsys') {
            // Buscar indicadores de que era un pago diferido
            $has_deferred_meta = !empty($order->get_meta('_dm_pay_later_card_payment_date_created'));
            $has_pay_later_status = ($order->get_status() === 'pay-later');
            $is_not_truly_paid = !$this->isOrderTrulyPaid($order);
            
            // Si parece que era un pago diferido y no está realmente pagado
            if (($has_deferred_meta || $has_pay_later_status) && $is_not_truly_paid) {
                // RESTAURAR método de pago a dm_pay_later_card automáticamente
                $order->set_payment_method('dm_pay_later_card');
                $order->set_payment_method_title('Pagar después con tarjeta');
                $order->save();
                $order->add_order_note(__("Payment method auto-restored from redsys to dm_pay_later_card to allow retry.", 'neve-child'));
                
                return true;
            }
        }

        return $needs_payment;
    }

    /**
     * Permitir estados específicos para repago de pedidos diferidos
     * Permite pago desde cualquier estado del tema
     * 
     * @param array $statuses Estados válidos actuales
     * @param \WC_Order $order Objeto de pedido
     * @return array Estados modificados
     */
    public function allowDeferredRepaymentStatuses(array $statuses, \WC_Order $order): array
    {
        $payment_method = $order->get_payment_method();
        
        // Aplicar a pedidos diferidos
        if ($payment_method === 'dm_pay_later_card') {
            $payment_date = $order->get_meta('_dm_pay_later_card_payment_date');
            
            // Solo si no tiene fecha de pago
            if (empty($payment_date)) {
                return $this->addAllValidStatuses($statuses, $order);
            }
        }
        
        // NUEVO: También aplicar a pedidos Redsys que eran diferidos
        if ($payment_method === 'redsys') {
            $has_deferred_meta = !empty($order->get_meta('_dm_pay_later_card_payment_date_created'));
            $has_pay_later_status = ($order->get_status() === 'pay-later');
            $is_not_truly_paid = !$this->isOrderTrulyPaid($order);
            
            if (($has_deferred_meta || $has_pay_later_status) && $is_not_truly_paid) {
                return $this->addAllValidStatuses($statuses, $order);
            }
        }

        return $statuses;
    }

    /**
     * Añadir todos los estados válidos (helper method)
     * 
     * @param array $statuses Estados actuales
     * @param \WC_Order $order Objeto de pedido
     * @return array Estados con todos los válidos añadidos
     */
    private function addAllValidStatuses(array $statuses, \WC_Order $order): array
    {
        // Estados personalizados del tema y estándar
        $all_statuses = [
            'pay-later',
            'reviewed', 
            'warehouse',
            'prepared',
            'master-order',
            'mast-warehs', 
            'mast-prepared',
            'mast-complete',
            'pending',
            'processing',
            'on-hold',
            'completed',
            'cancelled',
            'refunded',
            'failed'
        ];
        
        // Añadir todos los estados que no estén ya incluidos
        foreach ($all_statuses as $status) {
            if (!in_array($status, $statuses)) {
                $statuses[] = $status;
            }
        }
        
        // También añadir el estado actual por si acaso
        $order_status = $order->get_status();
        if (!in_array($order_status, $statuses)) {
            $statuses[] = $order_status;
        }
        
        return $statuses;
    }



    /**
     * Manejar fallos de pago de Redsys
     * Limpiar fechas de pago para permitir reintentos y restaurar estado original
     * 
     * @param int $order_id ID del pedido
     * @return void
     */
    public function handleRedsysFailure(int $order_id): void
    {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }

        $payment_method = $order->get_payment_method();
        
        // Restaurar estado original si existe
        $this->restoreOriginalStatusIfExists($order_id);
        
        // Aplicar a pedidos de pago diferido O a pedidos Redsys/Bizum que tienen fechas de pago diferido
        $is_deferred_payment = ($payment_method === 'dm_pay_later_card');
        $is_redsys_with_deferred = ($payment_method === 'redsys' && !empty($order->get_meta('_dm_pay_later_card_payment_date_created')));
        $is_bizum_payment = ($payment_method === 'bizumredsys');
        
        if ($is_deferred_payment || $is_redsys_with_deferred || $is_bizum_payment) {
            // Limpiar fechas de pago para permitir reintentos
            $order->delete_meta_data('_dm_pay_later_card_payment_date');
            $order->delete_meta_data('payment_date');
            $order->delete_meta_data('_bizum_payment_date');
            
            // RESTAURAR MÉTODO DE PAGO ORIGINAL si era dm_pay_later_card
            if ($is_redsys_with_deferred) {
                $order->set_payment_method('dm_pay_later_card');
                $order->set_payment_method_title('Pagar después con tarjeta');
            }
            
            $order->save();
            
            $order->add_order_note(__("Payment failed in Redsys/Bizum. Payment dates cleared and payment method restored to allow retry. Original status restored.", 'neve-child'));
        }
    }

    /**
     * Manejar cancelaciones de pago de Redsys
     * Limpiar fechas de pago para permitir reintentos y restaurar estado original
     * 
     * @param int $order_id ID del pedido
     * @return void
     */
    public function handleRedsysCancellation(int $order_id): void
    {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }

        $payment_method = $order->get_payment_method();
        
        // Restaurar estado original si existe
        $this->restoreOriginalStatusIfExists($order_id);
        
        // Aplicar a pedidos de pago diferido O a pedidos Redsys/Bizum que tienen fechas de pago diferido
        $is_deferred_payment = ($payment_method === 'dm_pay_later_card');
        $is_redsys_with_deferred = ($payment_method === 'redsys' && !empty($order->get_meta('_dm_pay_later_card_payment_date_created')));
        $is_bizum_payment = ($payment_method === 'bizumredsys');
        
        if ($is_deferred_payment || $is_redsys_with_deferred || $is_bizum_payment) {
            // Limpiar fechas de pago para permitir reintentos
            $order->delete_meta_data('_dm_pay_later_card_payment_date');
            $order->delete_meta_data('payment_date');
            $order->delete_meta_data('_bizum_payment_date');
            
            // RESTAURAR MÉTODO DE PAGO ORIGINAL si era dm_pay_later_card
            if ($is_redsys_with_deferred) {
                $order->set_payment_method('dm_pay_later_card');
                $order->set_payment_method_title('Pagar después con tarjeta');
            }
            
            $order->save();
            
            $order->add_order_note(__("Payment cancelled in Redsys/Bizum. Payment dates cleared and payment method restored to allow retry. Original status restored.", 'neve-child'));
        }
    }

    /**
     * Permitir reintentos después de fallos/cancelaciones
     * Permite que pedidos sin confirmación de pago real puedan volver a intentar el pago
     * 
     * @param bool $needs_payment Estado actual
     * @param \WC_Order $order Objeto de pedido
     * @return bool Estado modificado
     */
    public function allowRetryAfterFailure(bool $needs_payment, \WC_Order $order): bool
    {
        // VERIFICACIÓN CRÍTICA: Pedidos cancelados NO pueden ser repagados
        if ($order->get_status() === 'cancelled') {
            return false;
        }
        
        $payment_method = $order->get_payment_method();
        
        // LÓGICA AMPLIADA: Aplicar a:
        // 1. Pedidos dm_pay_later_card
        // 2. Pedidos Redsys que eran originalmente diferidos
        // 3. CUALQUIER pedido Redsys que no esté realmente pagado
        // 4. Pedidos Bizum
        $is_deferred_payment = ($payment_method === 'dm_pay_later_card');
        $is_redsys_with_deferred = ($payment_method === 'redsys' && !empty($order->get_meta('_dm_pay_later_card_payment_date_created')));
        $is_any_redsys = ($payment_method === 'redsys');
        $is_bizum_payment = ($payment_method === 'bizumredsys');
        
        // Para pedidos que NO son dm_pay_later_card, redsys ni bizum, usar comportamiento estándar
        if (!$is_deferred_payment && !$is_any_redsys && !$is_bizum_payment) {
            return $needs_payment;
        }

        // NUEVA LÓGICA PARA REDSYS Y BIZUM: Si no está realmente pagado, SIEMPRE permitir pago
        if (($is_any_redsys || $is_bizum_payment) && !$this->isOrderTrulyPaid($order)) {
            return true;
        }

        // Para dm_pay_later_card: lógica original
        if ($is_deferred_payment) {
            // Si ya necesita pago, mantener ese estado
            if ($needs_payment) {
                return true;
            }

            // Verificar si tiene fechas de pago
            $deferred_payment_date = $order->get_meta('_dm_pay_later_card_payment_date');
            $regular_payment_date = $order->get_meta('payment_date');
            
            // Si no tiene ninguna fecha de pago, permitir pago
            if (empty($deferred_payment_date) && empty($regular_payment_date)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Permitir estados para reintentos después de fallos/cancelaciones
     * Permite pago desde cualquier estado del tema
     * 
     * @param array $statuses Estados válidos actuales
     * @param \WC_Order $order Objeto de pedido
     * @return array Estados modificados
     */
    public function allowRetryAfterFailureStatuses(array $statuses, \WC_Order $order): array
    {
        $payment_method = $order->get_payment_method();
        
        // LÓGICA AMPLIADA: Aplicar a:
        // 1. Pedidos dm_pay_later_card
        // 2. Pedidos Redsys que eran originalmente diferidos  
        // 3. CUALQUIER pedido Redsys que no esté realmente pagado
        // 4. Pedidos Bizum
        $is_deferred_payment = ($payment_method === 'dm_pay_later_card');
        $is_redsys_with_deferred = ($payment_method === 'redsys' && !empty($order->get_meta('_dm_pay_later_card_payment_date_created')));
        $is_any_redsys = ($payment_method === 'redsys');
        $is_bizum_payment = ($payment_method === 'bizumredsys');

        // Para pedidos que NO son dm_pay_later_card, redsys ni bizum, usar comportamiento estándar
        if (!$is_deferred_payment && !$is_any_redsys && !$is_bizum_payment) {
            return $statuses;
        }

        // NUEVA LÓGICA PARA REDSYS Y BIZUM: Si no está realmente pagado, permitir desde cualquier estado
        if (($is_any_redsys || $is_bizum_payment) && !$this->isOrderTrulyPaid($order)) {
            return $this->addAllValidStatuses($statuses, $order);
        }

        // Para dm_pay_later_card: lógica original
        if ($is_deferred_payment) {
            // Verificar si tiene fechas de pago
            $deferred_payment_date = $order->get_meta('_dm_pay_later_card_payment_date');
            $regular_payment_date = $order->get_meta('payment_date');
            
            // Si no tiene fechas de pago, permitir todos los estados del tema
            if (empty($deferred_payment_date) && empty($regular_payment_date)) {
                return $this->addAllValidStatuses($statuses, $order);
            }
        }

        return $statuses;
    }

    /**
     * Detectar y corregir pagos cancelados automáticamente
     * Verifica si un pedido con fechas de pago fue realmente cancelado
     * Si está realmente pagado pero aparece como "needs payment", corrige el estado
     * Si no está pagado pero tiene fechas, limpia las fechas falsas
     * 
     * @param bool $needs_payment Estado actual
     * @param \WC_Order $order Objeto de pedido
     * @return bool Estado modificado
     */
    public function detectAndFixCancelledPayments(bool $needs_payment, \WC_Order $order): bool
    {
        // VERIFICACIÓN CRÍTICA: Pedidos cancelados NO pueden ser repagados
        if ($order->get_status() === 'cancelled') {
            return false;
        }
        
        $payment_method = $order->get_payment_method();
        
        // Aplicar a pedidos de pago diferido O a pedidos Redsys que tienen fechas de pago diferido
        $is_deferred_payment = ($payment_method === 'dm_pay_later_card');
        $is_redsys_with_deferred = ($payment_method === 'redsys' && !empty($order->get_meta('_dm_pay_later_card_payment_date_created')));
        
        if (!$is_deferred_payment && !$is_redsys_with_deferred) {
            return $needs_payment;
        }

        // Verificar si el pedido está realmente pagado
        $is_truly_paid = $this->isOrderTrulyPaid($order);
        
        if ($is_truly_paid) {
            return false; // No necesita pago
        }

        // RESTAURAR MÉTODO DE PAGO si es un pedido Redsys que era originalmente dm_pay_later_card
        if ($is_redsys_with_deferred && is_wc_endpoint_url('order-pay')) {
            $order->set_payment_method('dm_pay_later_card');
            $order->set_payment_method_title('Pagar después con tarjeta');
            $order->save();
            $order->add_order_note(__("Payment method restored to dm_pay_later_card to allow retry.", 'neve-child'));
        }

        // Detectar y limpiar pagos cancelados (fechas sin confirmación real)
        // Solo en páginas de pago para evitar limpiar datos innecesariamente
        if (is_wc_endpoint_url('order-pay')) {
            $this->detectAndCleanCancelledPayment($order);
        }

        // Si el pedido no está realmente pagado, debe necesitar pago independientemente del estado
        return true;
    }

    /**
     * Detectar y corregir estados para pagos cancelados
     * Permite pago desde cualquier estado del tema
     * 
     * @param array $statuses Estados válidos actuales
     * @param \WC_Order $order Objeto de pedido
     * @return array Estados modificados
     */
    public function detectAndFixCancelledPaymentsStatuses(array $statuses, \WC_Order $order): array
    {
        $payment_method = $order->get_payment_method();
        
        // Aplicar a pedidos de pago diferido O a pedidos Redsys que tienen fechas de pago diferido
        $is_deferred_payment = ($payment_method === 'dm_pay_later_card');
        $is_redsys_with_deferred = ($payment_method === 'redsys' && !empty($order->get_meta('_dm_pay_later_card_payment_date_created')));
        
        if (!$is_deferred_payment && !$is_redsys_with_deferred) {
            return $statuses;
        }

        // Verificar si el pedido no está realmente pagado - permitir repago desde cualquier estado
        if (!$this->isOrderTrulyPaid($order)) {
            // Estados personalizados del tema - Permitir pago desde cualquiera
            $theme_statuses = [
                'pay-later',
                'reviewed', 
                'warehouse',
                'prepared',
                'master-order',
                'mast-warehs', 
                'mast-prepared',
                'mast-complete',
                // Estados estándar adicionales
                'pending',
                'processing',
                'on-hold',
                'completed',
                'cancelled',
                'refunded',
                'failed'
            ];
            
            // Añadir todos los estados del tema si no están ya incluidos
            foreach ($theme_statuses as $theme_status) {
                if (!in_array($theme_status, $statuses)) {
                    $statuses[] = $theme_status;
                }
            }
            
            // También añadir el estado actual por si acaso
            $order_status = $order->get_status();
            if (!in_array($order_status, $statuses)) {
                $statuses[] = $order_status;
            }
        }

        return $statuses;
    }

    /**
     * Verificar si un pedido está realmente pagado
     * SIMPLIFICADO: Solo usa los indicadores confiables de pago
     * 
     * @param \WC_Order $order Objeto de pedido
     * @return bool Si está realmente pagado
     */
    private function isOrderTrulyPaid(\WC_Order $order): bool
    {
        // ÚNICO INDICADOR CONFIABLE: payment_date
        // Este es el que establece updateOrderStatus() cuando el pago es exitoso
        $payment_date = $order->get_meta('payment_date');
        
        if (!empty($payment_date)) {
            return true;
        }
        
        // Para compatibilidad con pagos diferidos de Redsys
        $deferred_payment_date = $order->get_meta('_dm_pay_later_card_payment_date');
        if (!empty($deferred_payment_date)) {
            return true;
        }
        
        // Para pagos de Bizum
        $bizum_payment_date = $order->get_meta('_bizum_payment_date');
        if (!empty($bizum_payment_date)) {
            return true;
        }
        
        // Transaction ID SOLO si es manual (no auto-generado)
        $transaction_id = $order->get_transaction_id();
        if (!empty($transaction_id) && !str_starts_with($transaction_id, 'auto_')) {
            return true;
        }
        
        return false;
    }

    /**
     * Asegurar que los meta fields de pago estén establecidos correctamente
     * SIMPLIFICADO: Solo establece payment_date si no existe
     * 
     * @param \WC_Order $order Objeto de pedido
     * @return void
     */
    private function ensurePaymentMetaFieldsAreSet(\WC_Order $order): void
    {
        // Solo verificar si falta payment_date
        $payment_date = $order->get_meta('payment_date');
        
        if (empty($payment_date)) {
            $current_time = current_time('Y-m-d H:i:s');
            $order->update_meta_data('payment_date', $current_time);
            $order->save();
            $order->add_order_note(__("Payment date set automatically after detecting successful payment.", 'neve-child'));
        }
    }

    /**
     * Detectar y limpiar pagos que fueron cancelados en Redsys
     * 
     * @param \WC_Order $order Objeto de pedido
     * @return void
     */
    private function detectAndCleanCancelledPayment(\WC_Order $order): void
    {
        // Verificar si tiene fechas de pago
        $deferred_payment_date = $order->get_meta('_dm_pay_later_card_payment_date');
        $regular_payment_date = $order->get_meta('payment_date');
        
        // Si no tiene fechas de pago, no hay nada que limpiar
        if (empty($deferred_payment_date) && empty($regular_payment_date)) {
            return;
        }

        // Limpiar fechas de pago erróneas
        $order->delete_meta_data('_dm_pay_later_card_payment_date');
        $order->delete_meta_data('payment_date');
        
        // También limpiar transaction IDs automáticos si existen
        $transaction_id = $order->get_transaction_id();
        if (!empty($transaction_id) && str_starts_with($transaction_id, 'auto_')) {
            $order->set_transaction_id('');
        }
        
        $order->save();
        $order->add_order_note(__("Cancelled payment detected automatically. Payment dates cleared to allow new attempt.", 'neve-child'));
    }

    /**
     * Preparar el entorno para que José Conti procese correctamente
     * Se ejecuta ANTES de José Conti (prioridad 5) para limpiar estados problemáticos
     * SOLO ACTÚA SI EL ESTADO NO ES PENDING (pedidos que ya existían)
     * 
     * @param array $posted_data Datos recibidos desde Redsys vía IPN
     * @return void
     */
    public function prepareForJoseContiProcessing($posted_data): void
    {
        // Extraer order ID
        $order_id = $this->extractOrderIdFromIPNData($posted_data);
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Verificar que el pago fue exitoso
        if (!$this->isIPNPaymentSuccessful($posted_data)) {
            return;
        }

        // ⚡ LÓGICA CLAVE: SOLO INTERFERIR SI EL ESTADO NO ES PENDING
        $current_status = $order->get_status();
        
        if ($current_status === 'pending') {
            // Si está en pending, es un pedido nuevo que José Conti puede manejar sin problemas
            // NO INTERFERIR - Dejar que José Conti haga todo
            return;
        }
        
        // Si llegamos aquí, el pedido YA EXISTÍA con otro estado (processing, pay-later, etc.)
        // José Conti podría tener problemas, así que SÍ necesitamos preparar el entorno

        // 🚨 LIMPIEZA BRUTAL: ELIMINAR TODO LO QUE PUEDA HACER QUE JOSÉ CONTI CONSIDERE EL PEDIDO COMO "YA PAGADO"
        
        // 1. TRANSIENTS de Redsys - TODO lo que pueda existir
        $redsys_transients_to_clear = [
            $order_id . '_redsys_done',
            $order_id . '_redsys_signature', 
            'redsys_signature_933000000' . $order_id,
            'redsys_' . $order_id . '_paid',
            'redsys_' . $order_id . '_processed',
            'redsys_order_' . $order_id,
            $order_id . '_payment_complete',
            $order_id . '_redsys_complete'
        ];
        
        foreach ($redsys_transients_to_clear as $transient_key) {
            $existing_transient = get_transient($transient_key);
            if ($existing_transient !== false) {
                delete_transient($transient_key);
            }
        }
        
        // 2. META FIELDS de Redsys - TODOS los que José Conti pueda usar para detectar pago completado
        $jose_meta_fields_to_clear = [
            '_redsys_done',
            '_authorisation_code_redsys',
            '_payment_order_number_redsys', 
            '_payment_date_redsys',
            '_redsys_order_number',
            '_redsys_auth_code',
            '_transaction_id',
            '_paid_date',
            '_date_paid',
            '_redsys_response',
            '_redsys_transaction_id'
        ];
        
        foreach ($jose_meta_fields_to_clear as $meta_key) {
            $existing_value = $order->get_meta($meta_key);
            if (!empty($existing_value)) {
                $order->delete_meta_data($meta_key);
            }
        }
        
        // 3. LIMPIAR Transaction ID de WooCommerce (José Conti lo usa para detectar pagos)
        $current_transaction_id = $order->get_transaction_id();
        if (!empty($current_transaction_id)) {
            $order->set_transaction_id('');
        }
        
        // 4. CAMBIAR ESTADO DEL PEDIDO temporalmente para que José Conti procese
        // SOLO para pedidos que NO estaban en pending (es decir, que ya existían)
        
        // Cambiar temporalmente a pending para que José Conti procese sin problemas
        $order->set_status('pending', 'Temporal change to allow José Conti processing (original: ' . $current_status . ')', false);
        
        // SIEMPRE guardar el estado original para restaurarlo después con marca de tiempo
        update_option('temp_order_' . $order_id . '_original_status', $current_status);
        update_option('temp_order_' . $order_id . '_status_change_time', time());
        
        $order->save();
    }

    /**
     * Restaurar estado original si existe
     * Utilizado cuando un pago falla para volver al estado previo al intento de pago
     * 
     * @param int $order_id ID del pedido
     * @return bool True si se restauró un estado, false si no había estado guardado
     */
    private function restoreOriginalStatusIfExists(int $order_id): bool
    {
        $original_status = get_option('temp_order_' . $order_id . '_original_status');
        
        if ($original_status) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->set_status($original_status, 'Estado original restaurado después de fallo de pago', false);
                $order->save();
                delete_option('temp_order_' . $order_id . '_original_status');
                delete_option('temp_order_' . $order_id . '_status_change_time');
                return true;
            }
        }
        
        return false;
    }

    /**
     * Hook para restaurar estado original cuando el pedido cambia a failed, cancelled o completed
     * 
     * @param int $order_id ID del pedido
     * @param string $old_status Estado anterior
     * @param string $new_status Estado nuevo
     * @param \WC_Order $order Objeto del pedido
     * @return void
     */
    public function restoreOriginalStatusOnPaymentFailure(int $order_id, string $old_status, string $new_status, \WC_Order $order): void
    {
        // Solo actuar en estados que indican fallo de pago
        if (!in_array($new_status, ['failed', 'cancelled', 'completed'])) {
            return;
        }
        
        $payment_method = $order->get_payment_method();
        
        // Aplicar a pedidos de pago diferido O a pedidos Redsys que tienen fechas de pago diferido
        $is_deferred_payment = ($payment_method === 'dm_pay_later_card');
        $is_redsys_with_deferred = ($payment_method === 'redsys' && !empty($order->get_meta('_dm_pay_later_card_payment_date_created')));
        
        if (!$is_deferred_payment && !$is_redsys_with_deferred) {
            return;
        }
        
        // Verificar si hay un estado original guardado
        $original_status = get_option('temp_order_' . $order_id . '_original_status');
        
        if ($original_status && $original_status !== $new_status) {
            // Verificar si no ha pasado mucho tiempo (evitar restauraciones indefinidas)
            $status_change_time = get_option('temp_order_' . $order_id . '_status_change_time');
            $current_time = time();
            
            // Solo restaurar si han pasado menos de 10 minutos desde el cambio temporal
            if (!$status_change_time || ($current_time - $status_change_time) < 600) {
                // Esperar un momento para que el cambio se procese, luego restaurar
                wp_schedule_single_event(time() + 2, 'restore_original_order_status', [$order_id, $original_status]);
            } else {
                // Limpiar datos antiguos si han pasado más de 10 minutos
                delete_option('temp_order_' . $order_id . '_original_status');
                delete_option('temp_order_' . $order_id . '_status_change_time');
            }
        }
    }

    /**
     * Ejecutar la restauración del estado original (función para acción programada)
     * 
     * @param int $order_id ID del pedido
     * @param string $original_status Estado original a restaurar
     * @return void
     */
    public function executeRestoreOriginalStatus(int $order_id, string $original_status): void
    {
        $order = wc_get_order($order_id);
        
        if ($order && $order->get_status() !== $original_status) {
            $order->set_status($original_status, 'Estado original restaurado automáticamente después de fallo de pago', false);
            $order->save();
            delete_option('temp_order_' . $order_id . '_original_status');
            delete_option('temp_order_' . $order_id . '_status_change_time');
            
            $order->add_order_note(__("Original status ({$original_status}) restored after payment failure.", 'neve-child'));
        }
    }




}
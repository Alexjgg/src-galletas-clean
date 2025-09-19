<?php
/**
 * Vendor Payment Gateway Selector for Redsys
 * 
 * Modifica los datos de la pasarela Redsys según el vendor asociado
 * al alumno a través de su escuela
 * 
 * @package SchoolManagement\Payments
 * @since 1.0.0
 */

namespace SchoolManagement\Payments;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Selector de configuración de pasarela de pago según vendor
 * 
 * Esta clase intercepta los filtros de Redsys para modificar 
 * la configuración de pago según el vendor asociado al alumno.
 * 
 * Flujo:
 * 1. Usuario/alumno tiene school_id en meta
 * 2. Escuela tiene vendor asociado en ACF
 * 3. Vendor tiene configuración Redsys en repeater ACF
 * 4. Sistema aplica configuración del vendor al pago
 * 5. El modo TEST/PRODUCCIÓN se detecta automáticamente desde la configuración del plugin Redsys
 * 
 * @since 1.0.0
 */
class VendorPaymentGatewaySelector
{
    /**
     * Tipo de post para vendors
     */
    private const VENDOR_POST_TYPE = 'coo_vendor';

    /**
     * Campo ACF repeater para configuración de payment gateways del vendor
     */
    private const VENDOR_PAYMENT_GATEWAY_REPEATER = 'payment_gateway';
    
    /**
     * Determinar si usar claves de test basándose en la configuración del plugin Redsys de José Conti
     * Se obtiene dinámicamente desde 'woocommerce_redsys_settings'['testmode']
     * true = Usar campo 'secret_sha256_test' del ACF (modo test)
     * false = Usar campo 'secret_sha256' del ACF (modo producción)
     */
    private function isTestEnvironment(): bool
    {
        $redsys_settings = get_option('woocommerce_redsys_settings', []);
        return ($redsys_settings['testmode'] ?? 'no') === 'yes';
    }
    
    /**
     * Mapeo de campos ACF para configuración de Redsys
     * Estos campos corresponden al repeater 'payment_gateway' en los vendors
     */
    private const REDSYS_GATEWAY_FIELDS = [
        'customer' => 'ds_merchant_merchantcode',
        'terminal' => 'ds_merchant_terminal',
        'currency' => 'ds_merchant_currency',
        'secret_prod' => 'secret_sha256',
        'secret_test' => 'secret_sha256_test',
        'commerce_name' => 'commerce_name'
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->initHooks();
        
            $mode = $this->isTestEnvironment() ? 'TEST (detectado desde plugin Redsys)' : 'PRODUCCIÓN (detectado desde plugin Redsys)';
    }

    /**
     * Inicializar hooks de WordPress
     */
    private function initHooks(): void
    {
        // Hook para Redsys normal
        add_filter('redsys_modify_data_to_send', [$this, 'modifyRedsysDataByVendor'], 10, 1);
        
        // Hook para Bizum (según documentación del plugin de José Conti)
        add_filter('bizum_modify_data_to_send', [$this, 'modifyRedsysDataByVendor'], 10, 1);
    }

    /**
     * Modificar datos de Redsys según vendor del alumno
     * 
     * @param array $redsys_data_send Datos originales de Redsys
     * @return array Datos modificados con configuración del vendor, o datos originales si no aplica
     */
    public function modifyRedsysDataByVendor(array $redsys_data_send): array
    {
        try {
                        // Extraer Order ID desde los datos de Redsys
            $order_id = $this->extractOrderId($redsys_data_send);
            if (!$order_id) {
                return $redsys_data_send;
            }

            // Obtener pedido
            $order = wc_get_order($order_id);
            if (!$order) {
                return $redsys_data_send;
            }            // Obtener vendor del usuario del pedido
            $vendor_result = $this->getOrderUserVendorWithReason($order);
            if (!$vendor_result['vendor_id']) {
                return $redsys_data_send; // Usar configuración por defecto de Redsys
            }

            $vendor_id = $vendor_result['vendor_id'];

            // Obtener configuración de Redsys del vendor
            
            $vendor_config = $this->getVendorRedsysConfig($vendor_id);
            if (empty($vendor_config)) {
                return $redsys_data_send; // Usar configuración por defecto
            }

            // Aplicar configuración del vendor
            $modified_data = $this->applyVendorConfig($redsys_data_send, $vendor_config);

            return $modified_data;        } catch (\Exception $e) {
            // En caso de error, retornar datos originales
            return $redsys_data_send; // Usar configuración por defecto
        }
    }

    /**
     * Extraer ID del pedido de los datos de Redsys
     * 
     * Según la documentación del plugin de José Conti, el campo principal 
     * para identificar el pedido es transaction_id2
     * 
     * @param array $redsys_data_send Datos de Redsys
     * @return int|null ID del pedido o null si no se encuentra
     */
    private function extractOrderId(array $redsys_data_send): ?int
    {
        // Según la documentación, el campo principal es transaction_id2
        if (isset($redsys_data_send['transaction_id2'])) {
            $order_id = \WCRed()->clean_order_number($redsys_data_send['transaction_id2']);
            if ($order_id) {
                return (int) $order_id;
            }
        }

        // Fallback a otros campos posibles
        $possible_fields = ['order_id', 'transaction_id', 'Ds_Order'];
        
        foreach ($possible_fields as $field) {
            if (isset($redsys_data_send[$field])) {
                $order_id = \WCRed()->clean_order_number($redsys_data_send[$field]);
                if ($order_id) {
                    return (int) $order_id;
                }
            }
        }
        
        return null;
    }

    /**
     * Obtener vendor asociado al usuario del pedido con información de por qué falló
     * 
     * @param \WC_Order $order Pedido de WooCommerce
     * @return array Array con vendor_id y reason
     */
    private function getOrderUserVendorWithReason(\WC_Order $order): array
    {
        // Obtener usuario del pedido
        $user_id = $order->get_customer_id();
        if (!$user_id) {
            return [
                'vendor_id' => null,
                'reason' => 'Pedido sin usuario asociado (compra como invitado)'
            ];
        }

        // Obtener escuela del usuario
        $school_id = get_user_meta($user_id, 'school_id', true);
        if (!$school_id) {
            return [
                'vendor_id' => null,
                'reason' => "Usuario ID {$user_id} no tiene escuela asociada (school_id vacío)"
            ];
        }

        
        // Obtener vendor de la escuela
        $vendor_id = get_field('vendor', $school_id);
        if (!$vendor_id) {
            return [
                'vendor_id' => null,
                'reason' => "Escuela ID {$school_id} no tiene vendor asociado"
            ];
        }

        // Verificar que el vendor existe y está publicado
        $vendor_post = get_post($vendor_id);
        if (!$vendor_post || $vendor_post->post_type !== self::VENDOR_POST_TYPE || $vendor_post->post_status !== 'publish') {
            return [
                'vendor_id' => null,
                'reason' => "Vendor ID {$vendor_id} no existe o no está publicado"
            ];
        }

        return [
            'vendor_id' => (int) $vendor_id,
            'reason' => "Vendor encontrado: {$vendor_post->post_title}"
        ];
    }

    /**
     * Obtener vendor asociado al usuario del pedido (método original mantenido por compatibilidad)
     * 
     * @param \WC_Order $order Pedido de WooCommerce
     * @return int|null ID del vendor o null si no se encuentra
     */
    private function getOrderUserVendor(\WC_Order $order): ?int
    {
        $result = $this->getOrderUserVendorWithReason($order);
        return $result['vendor_id'];
    }

    /**
     * Obtener configuración de Redsys del vendor desde el repeater ACF
     * 
     * @param int $vendor_id ID del vendor
     * @return array Configuración de Redsys del vendor
     */
    private function getVendorRedsysConfig(int $vendor_id): array
    {        $config = [];

        // Obtener el repeater de payment gateways
        $payment_gateways = get_field(self::VENDOR_PAYMENT_GATEWAY_REPEATER, $vendor_id);
        
        if (!$payment_gateways || !is_array($payment_gateways)) {
            return $config;
        }

        // Buscar configuración de Redsys en el repeater
        foreach ($payment_gateways as $index => $gateway) {
            
            // Verificar que tiene los campos mínimos necesarios para Redsys
            $customer_field = self::REDSYS_GATEWAY_FIELDS['customer'];
            $terminal_field = self::REDSYS_GATEWAY_FIELDS['terminal'];
            
            if (!empty($gateway[$customer_field]) && !empty($gateway[$terminal_field])) {
                
                $config = [
                    'customer' => sanitize_text_field($gateway[$customer_field]),
                    'terminal' => sanitize_text_field($gateway[$terminal_field]),
                    'currency' => sanitize_text_field($gateway[self::REDSYS_GATEWAY_FIELDS['currency']] ?? '978'), // Default EUR
                    'commerce_name' => sanitize_text_field($gateway[self::REDSYS_GATEWAY_FIELDS['commerce_name']] ?? '')
                ];

                // Determinar qué secret usar según la configuración del plugin Redsys de José Conti
                $secret_prod_field = self::REDSYS_GATEWAY_FIELDS['secret_prod'];
                $secret_test_field = self::REDSYS_GATEWAY_FIELDS['secret_test'];
                
                if ($this->isTestEnvironment()) {
                    // Plugin en modo TEST: usar siempre clave de test
                    if (!empty($gateway[$secret_test_field])) {
                        $config['secret'] = sanitize_text_field($gateway[$secret_test_field]);
                    } else {
                        // Sin clave test: retornar configuración vacía para usar DEFAULT
                        return [];
                    }
                } else {
                    // Plugin en modo PRODUCCIÓN: usar clave real si existe, sino usar configuración DEFAULT
                    if (!empty($gateway[$secret_prod_field])) {
                        $config['secret'] = sanitize_text_field($gateway[$secret_prod_field]);
                    } else {
                        // Sin clave producción: retornar configuración vacía para usar DEFAULT
                        return [];
                    }
                }

                // Retornar la primera configuración válida encontrada
                break;
            }
        }

        return $config;
    }

    /**
     * Aplicar configuración del vendor a los datos de Redsys
     * 
     * Según la documentación del plugin de José Conti, los campos principales son:
     * - customer: FUC (Código de comercio)
     * - DSMerchantTerminal: Terminal
     * - secretsha256: Clave SHA256
     * - currency: Moneda (se mantiene la original)
     * - merchant_name: Nombre del comercio (opcional)
     * 
     * @param array $redsys_data_send Datos originales de Redsys
     * @param array $vendor_config Configuración del vendor
     * @return array Datos modificados
     */
    private function applyVendorConfig(array $redsys_data_send, array $vendor_config): array
    {
        // IMPORTANTE: Preservar todos los campos originales como indica la documentación
        $original_data = $redsys_data_send;
        
        // Aplicar configuración del vendor solo a los campos específicos
        if (isset($vendor_config['customer'])) {
            $redsys_data_send['customer'] = $vendor_config['customer'];
        }
        
        if (isset($vendor_config['terminal'])) {
            $redsys_data_send['DSMerchantTerminal'] = $vendor_config['terminal'];
        }
        
        if (isset($vendor_config['secret'])) {
            $redsys_data_send['secretsha256'] = $vendor_config['secret'];
        }
        
        // Opcional: Actualizar nombre del comercio si está configurado
        if (isset($vendor_config['commerce_name']) && !empty($vendor_config['commerce_name'])) {
            $redsys_data_send['merchant_name'] = $vendor_config['commerce_name'];
        }
        
        // NOTA: currency se mantiene como viene originalmente
        // (no se modifica desde la configuración del vendor)

        return $redsys_data_send;
    }

    /**
     * Validar si un vendor tiene configuración válida de Redsys
     * 
     * @param int $vendor_id ID del vendor
     * @return bool True si tiene configuración válida
     */
    public function hasValidRedsysConfig(int $vendor_id): bool
    {
        $payment_gateways = get_field(self::VENDOR_PAYMENT_GATEWAY_REPEATER, $vendor_id);
        
        if (!$payment_gateways || !is_array($payment_gateways)) {
            return false;
        }

        foreach ($payment_gateways as $gateway) {
            $has_customer = !empty($gateway[self::REDSYS_GATEWAY_FIELDS['customer']]);
            $has_terminal = !empty($gateway[self::REDSYS_GATEWAY_FIELDS['terminal']]);
            $has_secret = !empty($gateway[self::REDSYS_GATEWAY_FIELDS['secret_prod']]) || 
                         !empty($gateway[self::REDSYS_GATEWAY_FIELDS['secret_test']]);

            if ($has_customer && $has_terminal && $has_secret) {
                return true;
            }
        }

        return false;
    }

    /**
     * Log cuando se usa configuración por defecto de Redsys (fallback)
     * 
     * @param int|string $order_id ID del pedido
     * @param string $reason Razón por la que se usa fallback
     */
    public function getVendorConfigurationInfo(int $vendor_id): array
    {
        $payment_gateways = get_field(self::VENDOR_PAYMENT_GATEWAY_REPEATER, $vendor_id);
        
        if (!$payment_gateways || !is_array($payment_gateways)) {
            return [
                'vendor_id' => $vendor_id,
                'vendor_name' => get_the_title($vendor_id),
                'has_payment_config' => false,
                'gateways' => []
            ];
        }

        $gateways_info = [];
        foreach ($payment_gateways as $index => $gateway) {
            $has_prod_secret = !empty($gateway[self::REDSYS_GATEWAY_FIELDS['secret_prod']]);
            $has_test_secret = !empty($gateway[self::REDSYS_GATEWAY_FIELDS['secret_test']]);
            
            $gateways_info[] = [
                'index' => $index,
                'commerce_number' => $gateway[self::REDSYS_GATEWAY_FIELDS['customer']] ?? '',
                'commerce_name' => $gateway[self::REDSYS_GATEWAY_FIELDS['commerce_name']] ?? '',
                'terminal' => $gateway[self::REDSYS_GATEWAY_FIELDS['terminal']] ?? '',
                'currency' => $gateway[self::REDSYS_GATEWAY_FIELDS['currency']] ?? '',
                'has_production_secret' => $has_prod_secret,
                'has_test_secret' => $has_test_secret,
                'secret_strategy' => [
                    'test_mode' => $has_test_secret ? 'test_secret' : 'none',
                    'prod_mode' => $has_prod_secret ? 'prod_secret' : ($has_test_secret ? 'test_secret_fallback' : 'none')
                ],
                'is_valid' => !empty($gateway[self::REDSYS_GATEWAY_FIELDS['customer']]) && 
                             !empty($gateway[self::REDSYS_GATEWAY_FIELDS['terminal']]) &&
                             ($has_prod_secret || $has_test_secret)
            ];
        }

        return [
            'vendor_id' => $vendor_id,
            'vendor_name' => get_the_title($vendor_id),
            'has_payment_config' => !empty($gateways_info),
            'has_valid_config' => $this->hasValidRedsysConfig($vendor_id),
            'gateways' => $gateways_info
        ];
    }

    /**
     * Obtener todos los vendors con configuración de Redsys
     * 
     * @return array Lista de vendors con configuración
     */
    public function getVendorsWithRedsysConfig(): array
    {
        $vendors = get_posts([
            'post_type' => self::VENDOR_POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => -1
        ]);

        $configured_vendors = [];
        foreach ($vendors as $vendor) {
            if ($this->hasValidRedsysConfig($vendor->ID)) {
                $configured_vendors[] = [
                    'id' => $vendor->ID,
                    'name' => $vendor->post_title,
                    'config_info' => $this->getVendorConfigurationInfo($vendor->ID)
                ];
            }
        }

        return $configured_vendors;
    }

    /**
     * Verificar si un vendor tiene configuración completa de Redsys
     * 
     * @param int $vendor_id ID del vendor
     * @return bool True si tiene configuración completa
     */
    public function hasCompleteRedsysConfig(int $vendor_id): bool
    {
        return $this->hasValidRedsysConfig($vendor_id);
    }

    /**
     * Obtener información detallada de payment gateway de un vendor
     * 
     * @param int $vendor_id ID del vendor
     * @return array Información detallada
     */
    public function getVendorPaymentGatewayInfo(int $vendor_id): array
    {
        return $this->getVendorConfigurationInfo($vendor_id);
    }
}
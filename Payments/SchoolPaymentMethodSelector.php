<?php
/**
 * Payment Method Selector for School-based Users
 * 
 * Gestiona qué métodos de pago se muestran en el checkout basándose
 * en la configuración del centro escolar del usuario
 * 
 * @package SchoolManagement\Payments
 * @since 1.0.0
 */

namespace SchoolManagement\Payments;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Selector de métodos de pago basado en configuración del centro escolar
 * 
 * Esta clase determina qué métodos de pago están disponibles para cada usuario
 * basándose en la configuración del centro escolar al que pertenece.
 * 
 * FUNCIONALIDADES PRINCIPALES:
 * - Verifica la configuración del centro escolar del usuario
 * - Filtra métodos de pago según la configuración del centro
 * - Soporte para el método de pago de estudiantes
 * - Fallback a métodos estándar cuando no hay configuración específica
 * 
 * CONFIGURACIÓN DEL CENTRO:
 * - Campo ACF 'the_billing_by_the_school': Define si el centro paga por los estudiantes
 * 
 * SEMÁNTICA CORRECTA:
 * - Campo 'the_billing_by_the_school' = true: el Centro paga por los estudiantes.
 * - Campo 'the_billing_by_the_school' = false: NO paga el centro (paga el usuario en checkout).
 *
 * LÓGICA DE MÉTODOS (alineada con la semántica real del gateway student_payment = "Pago del Centro"):
 * - billing_by_school = true  → Centro PAGA → mostrar SOLO 'student_payment' (pago del centro)
 * - billing_by_school = false → Centro NO paga → ocultar 'student_payment' y dejar métodos estándar (redsys, bizum, bacs, etc.)
 * 
 * IMPORTANTE: 
 * - Cuando el centro NO paga (valor 0), el estudiante es quien debe pagar
 * - Solo en este caso se muestra el método de pago especial para estudiantes
 * - Cuando el centro SÍ paga (valor 1), se usan métodos normales porque el centro factura después
 * 
 * @since 1.0.0
 */
class SchoolPaymentMethodSelector
{
    /**
     * Nombre del campo ACF que controla el método de pago del centro
     */
    private const SCHOOL_BILLING_FIELD = 'the_billing_by_the_school';

    /**
     * ID del método de pago para estudiantes
     */
    private const STUDENT_PAYMENT_METHOD = 'student_payment';

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
        // Filtrar métodos de pago disponibles en el checkout
        add_filter('woocommerce_available_payment_gateways', [$this, 'filterPaymentMethods'], 20);
    }

    /**
     * Filtrar métodos de pago basándose en la configuración del centro escolar
     * 
     * @param array $available_gateways Métodos de pago disponibles
     * @return array Métodos de pago filtrados
     */
    public function filterPaymentMethods(array $available_gateways): array
    {
        // Solo aplicar el filtro en el checkout
        if (!is_checkout()) {
            return $available_gateways;
        }

        // VALIDACIÓN ESPECIAL: Si estamos pagando después (order-pay), solo mostrar Redsys
        if ($this->isPayingLater()) {
            return $this->filterToRedsysOnly($available_gateways);
        }

        // Solo para usuarios logueados
        if (!is_user_logged_in()) {
            // Para usuarios no logueados, excluir método de estudiantes
            return $this->removeStudentPaymentMethod($available_gateways);
        }

        $user_id = get_current_user_id();
        
        // TEMPORALMENTE DESACTIVADO: Permitir múltiples pedidos con "pagar más tarde"
        // if ($this->hasPendingDeferredOrders($user_id)) {
        //     $available_gateways = $this->removeDeferredRedsysMethod($available_gateways);
        // }
        
        // LÓGICA ADICIONAL: Si estamos en checkout de un pedido que cambió de dm_pay_later_card a redsys
        if ($this->isPayingFormerDeferredOrder()) {

            $available_gateways = $this->removeDeferredRedsysMethod($available_gateways);
        }
        
        $school_config = $this->getUserSchoolPaymentConfig($user_id);

        // Aplicar filtro según configuración del centro
        return $this->applySchoolPaymentFilter($available_gateways, $school_config);
    }

    /**
     * Obtener configuración de pago del centro escolar del usuario
     * 
     * @param int $user_id ID del usuario
     * @return array Configuración del centro escolar
     */
    private function getUserSchoolPaymentConfig(int $user_id): array
    {
        // Obtener el centro escolar del usuario
        $school_id = get_user_meta($user_id, 'school_id', true);
        
        if (empty($school_id)) {
            return [
                'has_school' => false,
                'school_id' => null,
                'school_name' => null,
                'billing_by_school' => false,
                'reason' => 'Usuario sin centro escolar asignado'
            ];
        }

        // Verificar que el centro existe
        $school = get_post($school_id);
        if (!$school || $school->post_type !== 'coo_school') {
            return [
                'has_school' => false,
                'school_id' => $school_id,
                'school_name' => null,
                'billing_by_school' => false,
                'reason' => 'Centro escolar no encontrado o tipo incorrecto'
            ];
        }

        // Obtener configuración de facturación del centro
        $billing_by_school = $this->getSchoolBillingConfig($school_id);
        
        return [
            'has_school' => true,
            'school_id' => $school_id,
            'school_name' => $school->post_title,
            'billing_by_school' => $billing_by_school,
            'reason' => $billing_by_school ? __('School pays for students → standard methods', 'neve-child') : __('School does NOT pay → student pays → student method', 'neve-child')
        ];
    }

    /**
     * Obtener configuración de facturación del centro escolar
     * 
     * @param int $school_id ID del centro escolar
     * @return bool True si el centro paga por los estudiantes
     */
    private function getSchoolBillingConfig(int $school_id): bool
    {
        // Intentar obtener con ACF primero
        if (function_exists('get_field')) {
            $value = get_field(self::SCHOOL_BILLING_FIELD, $school_id);
            
            // ACF puede devolver diferentes formatos
            if (is_bool($value)) {
                return $value;
            }
            
            if (is_string($value)) {
                return in_array(strtolower($value), ['yes', '1', 'true', 'on']);
            }
            
            if (is_numeric($value)) {
                return (bool) $value;
            }
        }

        // Fallback a meta tradicional
        $meta_value = get_post_meta($school_id, self::SCHOOL_BILLING_FIELD, true);
        
        if (is_string($meta_value)) {
            return in_array(strtolower($meta_value), ['yes', '1', 'true', 'on']);
        }
        
        return (bool) $meta_value;
    }

    /**
     * Aplicar filtro de métodos de pago según configuración del centro
     * 
     * @param array $available_gateways Métodos de pago disponibles
     * @param array $school_config Configuración del centro escolar
     * @return array Métodos de pago filtrados
     */
    private function applySchoolPaymentFilter(array $available_gateways, array $school_config): array
    {
        // Si no tiene centro, mostrar métodos estándar
        if (!$school_config['has_school']) {
            return $this->removeStudentPaymentMethod($available_gateways);
        }

        // Aplicación alineada con la semántica real del gateway 'student_payment'
        // - billing_by_school = true  → Centro paga → solo 'student_payment'
        // - billing_by_school = false → Centro NO paga → métodos estándar (ocultar 'student_payment')

        if ($school_config['billing_by_school']) {
            // Centro paga por los estudiantes - solo método del centro
            return $this->filterToStudentPaymentOnly($available_gateways);
        } else {
            // Centro NO paga - el usuario paga en checkout - usar métodos estándar
            return $this->removeStudentPaymentMethod($available_gateways);
        }
    }

    /**
     * Filtrar para mostrar solo el método de pago de estudiantes
     * 
     * @param array $available_gateways Métodos de pago disponibles
     * @return array Solo el método de pago de estudiantes
     */
    private function filterToStudentPaymentOnly(array $available_gateways): array
    {
        $student_gateway = [];
        
        if (isset($available_gateways[self::STUDENT_PAYMENT_METHOD])) {
            $student_gateway[self::STUDENT_PAYMENT_METHOD] = $available_gateways[self::STUDENT_PAYMENT_METHOD];
        }

        return $student_gateway;
    }

    /**
     * Remover el método de pago de estudiantes
     * 
     * @param array $available_gateways Métodos de pago disponibles
     * @return array Métodos de pago sin el método de estudiantes
     */
    private function removeStudentPaymentMethod(array $available_gateways): array
    {
        unset($available_gateways[self::STUDENT_PAYMENT_METHOD]);
        return $available_gateways;
    }

    // Métodos de logging/elaboración de diagnósticos eliminados: no se realizan logs en producción

    /**
     * Verificar si un usuario debe usar el método de pago de estudiantes
     * 
    * LÓGICA CORRECTA:
    * - Centro paga (billing_by_school = true)  → usar método del centro ('student_payment')
    * - Centro NO paga (billing_by_school = false) → no usar 'student_payment'
     * 
     * @param int $user_id ID del usuario (opcional, usa el usuario actual si no se proporciona)
     * @return bool True si debe usar método de estudiantes
     */
    public function shouldUseStudentPayment(int $user_id = 0): bool
    {
        if ($user_id === 0) {
            if (!is_user_logged_in()) {
                return false;
            }
            $user_id = get_current_user_id();
        }

        $school_config = $this->getUserSchoolPaymentConfig($user_id);
        
    // Usar método del centro SOLO si tiene centro Y el centro paga
    return $school_config['has_school'] && $school_config['billing_by_school'];
    }

    /**
     * Obtener información detallada de configuración para un usuario
     * 
     * @param int $user_id ID del usuario
     * @return array Información completa de configuración
     */
    public function getUserPaymentInfo(int $user_id): array
    {
        $school_config = $this->getUserSchoolPaymentConfig($user_id);
        $should_use_student_payment = $this->shouldUseStudentPayment($user_id);
        $has_pending_deferred = $this->hasPendingDeferredOrders($user_id);
        
        // Determinar métodos recomendados (SIN dm_pay_later_card - desactivado permanentemente)
        $recommended_methods = $should_use_student_payment
            ? [self::STUDENT_PAYMENT_METHOD]
            : ['bacs', 'redsys', 'bizum'];
            
        // Nota: dm_pay_later_card eliminado permanentemente por solicitud del usuario
        
        return [
            'user_id' => $user_id,
            'should_use_student_payment' => $should_use_student_payment,
            'has_pending_deferred_orders' => $has_pending_deferred,
            'school_configuration' => $school_config,
            'recommended_payment_methods' => array_values($recommended_methods), // Reindexar array
            'logic_explanation' => $this->buildLogicExplanation($school_config, $has_pending_deferred)
        ];
    }
    
    /**
     * Construir explicación de la lógica aplicada
     * 
     * @param array $school_config Configuración del centro escolar
     * @param bool $has_pending_deferred Si tiene pedidos pendientes con pagar más tarde
     * @return string Explicación de la lógica
     */
    private function buildLogicExplanation(array $school_config, bool $has_pending_deferred): string
    {
        $explanations = [];
        
        if ($has_pending_deferred) {
            $explanations[] = __('Has pending "pay later" orders → dm_pay_later_card method hidden', 'neve-child');
        }
        
        // Verificar si estamos en checkout de pedido que cambió de método
        if ($this->isPayingFormerDeferredOrder()) {
            $explanations[] = __('Paying former deferred order → dm_pay_later_card method hidden', 'neve-child');
        }
        
        if ($school_config['has_school']) {
            if ($school_config['billing_by_school']) {
                $explanations[] = __('School pays → ONLY school method (student_payment)', 'neve-child');
            } else {
                $explanations[] = __('School does NOT pay → standard methods (user pays at checkout)', 'neve-child');
            }
        } else {
            $explanations[] = __('No school → standard methods', 'neve-child');
        }
        
        return implode(' | ', $explanations);
    }

    /**
     * Verificar si el usuario tiene pedidos pendientes con método "pagar más tarde"
     * 
     * @param int $user_id ID del usuario
     * @return bool True si tiene pedidos pendientes con dm_pay_later_card
     */
    private function hasPendingDeferredOrders(int $user_id): bool
    {
        // Buscar pedidos del usuario con método dm_pay_later_card y que necesiten pago
        $orders = wc_get_orders([
            'customer' => $user_id,
            'status' => ['pending', 'on-hold', 'pay-later', 'processing'], // Estados donde podría estar pendiente
            'payment_method' => 'dm_pay_later_card',
            'limit' => 5, // Limitar para rendimiento
            'meta_query' => [
                [
                    'key' => '_dm_pay_later_card_payment_date',
                    'value' => '',
                    'compare' => '='
                ]
            ]
        ]);

        return !empty($orders);
    }

    /**
     * Eliminar método "pagar más tarde" de los métodos disponibles
     * 
     * @param array $available_gateways Métodos de pago disponibles
     * @return array Métodos de pago sin dm_pay_later_card
     */
    private function removeDeferredRedsysMethod(array $available_gateways): array
    {
        if (isset($available_gateways['dm_pay_later_card'])) {
            unset($available_gateways['dm_pay_later_card']);
        }
        
        return $available_gateways;
    }

    /**
     * Verificar si estamos pagando un pedido que era dm_pay_later_card y cambió a redsys
     * 
     * @return bool True si estamos en checkout de un pedido que cambió de método
     */
    private function isPayingFormerDeferredOrder(): bool
    {
        // Verificar si estamos en la página de pago de un pedido específico
        if (!is_wc_endpoint_url('order-pay')) {
            return false;
        }
        
        // Obtener ID del pedido desde la URL
        global $wp;
        $order_id = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
        
        if (!$order_id) {
            return false;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        
        // Verificar si el pedido actual tiene método redsys pero tenía _original_payment_method dm_pay_later_card
        $current_method = $order->get_payment_method();
        $original_method = $order->get_meta('_original_payment_method');
        
        // Si el método actual es redsys y el original era dm_pay_later_card, entonces cambió
        return ($current_method === 'redsys' && $original_method === 'dm_pay_later_card');
    }

    /**
     * Verificar si estamos en una página de pago posterior (order-pay)
     * 
     * @return bool True si estamos pagando un pedido después
     */
    private function isPayingLater(): bool
    {
        return is_wc_endpoint_url('order-pay');
    }

    /**
     * Filtrar para mostrar solo el método de pago Redsys
     * 
     * @param array $available_gateways Métodos de pago disponibles
     * @return array Solo el método de pago Redsys
     */
    private function filterToRedsysOnly(array $available_gateways): array
    {
        $redsys_gateway = [];
        
        if (isset($available_gateways['redsys'])) {
            $redsys_gateway['redsys'] = $available_gateways['redsys'];
        }

        return $redsys_gateway;
    }

   
}

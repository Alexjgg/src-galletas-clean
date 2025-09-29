<?php
/**
 * Bootstrap file for the School Management System
 * 
 * Sistema modular para gestión de centros escolares con autoloader PSR-4
 * y inicialización controlada de dependencias.
 * 
 * @package SchoolManagement
 * @since 1.0.0
 * @author Alexis Galletas System
 */

if (!defined('ABSPATH')) {
    exit;
}

// Guard para evitar ejecución múltiple
if (defined('SCHOOL_MANAGEMENT_BOOTSTRAP_LOADED')) {
    return;
}
define('SCHOOL_MANAGEMENT_BOOTSTRAP_LOADED', true);
define('FACTUPRESS_DISALLOW_FACTURAE', true);
/**
 * Clase principal del sistema School Management
 * Maneja autoloading, inicialización y gestión de dependencias
 */
class SchoolManagementBootstrap {
    
    /** @var string Namespace base del sistema */
    const NAMESPACE_PREFIX = 'SchoolManagement';
    
    /** @var string Directorio base del sistema */
    private string $basePath;
    
    /** @var array Instancias de servicios cargados */
    private static array $services = [];
    
    /** @var array Configuración de módulos y sus dependencias */
    private array $moduleConfig = [];
    
    /** @var bool Si el sistema ya fue inicializado */
    private static bool $initialized = false;

    public function __construct() {
        $this->basePath = __DIR__;
        $this->setupConstants();
        $this->registerAutoloader();
        $this->setupModuleConfiguration();
    }

    /**
     * Configura las constantes del sistema
     */
    private function setupConstants(): void {
        if (!defined('SCHOOL_MANAGEMENT_NAMESPACE')) {
            define('SCHOOL_MANAGEMENT_NAMESPACE', self::NAMESPACE_PREFIX);
        }
        if (!defined('SCHOOL_MANAGEMENT_PATH')) {
            define('SCHOOL_MANAGEMENT_PATH', $this->basePath);
        }
    }

    /**
     * Autoloader PSR-4 mejorado con manejo de errores y logging
     * 
     * @param string $className Nombre completo de la clase
     * @return bool True si la clase fue cargada exitosamente
     */
    public function autoload(string $className): bool {
        // Verificar que pertenece a nuestro namespace
        if (strpos($className, self::NAMESPACE_PREFIX . '\\') !== 0) {
            return false;
        }
        
        try {
            // Remover el namespace base
            $relativeClassName = substr($className, strlen(self::NAMESPACE_PREFIX . '\\'));
            
            // Convertir namespace a ruta del archivo
            $filePath = $this->basePath . DIRECTORY_SEPARATOR 
                      . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClassName) 
                      . '.php';
            
            // Verificar que el archivo existe y es legible
            if (!is_file($filePath) || !is_readable($filePath)) {
                return false;
            }
            
            // Incluir el archivo
            require_once $filePath;
            
            // Verificar que la clase se cargó correctamente
            if (!class_exists($className, false) && !interface_exists($className, false) && !trait_exists($className, false)) {
                return false;
            }
            
            return true;
            
        } catch (Throwable $e) {
            return false;
        }
    }
    
    /**
     * Registra el autoloader
     */
    private function registerAutoloader(): void {
        spl_autoload_register([$this, 'autoload'], true, true);
    }
    
    /**
     * Configura los módulos del sistema y sus dependencias
     */
    private function setupModuleConfiguration(): void {
        $this->moduleConfig = [
            // MÓDULOS BÁSICOS (sin dependencias)
            'core' => [
                'SchoolManagement\Shared\Constants' => [],
            ],
            
            // MÓDULOS DE USUARIOS
            'users' => [
                'SchoolManagement\Users\TeacherRoleManager' => [],
                'SchoolManagement\Users\OrderManagerRoleManager' => [],
                'SchoolManagement\Users\ShopManagerRoleManager' => [],
                'SchoolManagement\Users\SchoolUserManager' => [],
                'SchoolManagement\Users\UserAdminColumnsManager' => [
                    'dependencies' => ['SchoolManagement\Users\SchoolUserManager']
                ],
            ],
            
            // MÓDULOS DE CENTROS ESCOLARES
            'schools' => [
                'SchoolManagement\Schools\SchoolManager' => [],
                'SchoolManagement\Schools\QRCodeManager' => [
                    'dependencies' => ['SchoolManagement\Schools\SchoolManager']
                ],
                'SchoolManagement\Schools\VendorRelationship' => [
                    'dependencies' => ['SchoolManagement\Schools\SchoolManager']
                 ],
                'SchoolManagement\Schools\OrderStatsManager' => [
                    'dependencies' => ['SchoolManagement\Schools\SchoolManager']
                ],
                'SchoolManagement\Schools\FormValidator' => [
                    'dependencies' => ['SchoolManagement\Schools\SchoolManager'],
                    'args' => [138], // Post ID específico
                    'store_global' => 'school_form_validator_instance'
                ],
            ],
            
            // MÓDULOS DE PEDIDOS
            'orders' => [
                'SchoolManagement\Orders\OrderManager' => [],
                'SchoolManagement\Orders\StatusManager' => [],
                'SchoolManagement\Orders\StatusCleaner' => [],
                'SchoolManagement\Orders\MasterOrderManager' => [],
                'SchoolManagement\Orders\InvoiceButtonFilter' => [],
                'SchoolManagement\Orders\MasterOrderPackingSlips' => [],
                'SchoolManagement\Orders\PackingSlipCustomizer' => [],
                'SchoolManagement\Orders\AdvancedOrderFilters' => [],
                'SchoolManagement\Orders\TeacherOrderCountFilter' => [],
                'SchoolManagement\Orders\TeacherPdfRestrictionsManager' => [],
                'SchoolManagement\Orders\TeacherMasterOrderPdfRestrictions' => [],
            ],
            
            // MÓDULOS DE PAGOS
            'payments' => [
                'SchoolManagement\Payments\PaymentHandler' => [
                    'store_global' => 'payment_handler_instance'
                ],
                'SchoolManagement\Payments\CheckoutBlocker' => [
                    'store_global' => 'checkout_blocker_instance'
                ],
                'SchoolManagement\Payments\PaymentStatusColumn' => [
                    'dependencies' => ['SchoolManagement\Payments\PaymentHandler'],
                    'store_global' => 'payment_status_column_instance'
                ],
                'SchoolManagement\Payments\BankTransferBulkActions' => [
                    'dependencies' => ['SchoolManagement\Payments\PaymentStatusColumn'],
                    'store_global' => 'bank_transfer_bulk_actions_instance'
                ],
                'SchoolManagement\Payments\RefundRestrictions' => [
                    'dependencies' => ['SchoolManagement\Payments\PaymentStatusColumn'],
                    'store_global' => 'refund_restrictions_instance'
                ],
                'SchoolManagement\Payments\VendorPaymentGatewaySelector' => [
                    'store_global' => 'vendor_payment_gateway_selector_instance'
                ],
                'SchoolManagement\Payments\SchoolPaymentMethodSelector' => [
                    'store_global' => 'school_payment_method_selector_instance'
                ],
            
            ],
            
            // MÓDULOS DE INTEGRACIÓN
            'integration' => [
                // Componentes especializados (se cargan primero)
                // 'SchoolManagement\Integration\VendorAEATIntegration' => [
                //     // Maneja integración con AEAT: hooks factupress_before_generate_register
                //     'description' => 'Integración con AEAT para datos del vendor'
                // ],
                'SchoolManagement\Integration\VendorPDFManager' => [
                    // Maneja PDFs y numeración: hooks wpo_wcpdf_*
                    'description' => 'Manejo de datos del vendor en PDFs y numeración'
                ],
                
                // Orquestador principal (depende de los componentes especializados)
                'SchoolManagement\Integration\VendorDataManager' => [
                    'dependencies' => [
                        'SchoolManagement\Integration\VendorAEATIntegration',
                        'SchoolManagement\Integration\VendorPDFManager'
                    ],
                    'description' => 'Orquestador principal - coordina AEAT y PDF'
                ],
                
                // Validador de campos (independiente)
                'SchoolManagement\Integration\VendorFieldValidator' => [
                    'description' => 'Validación de campos de vendor'
                ],
            ],

            'vendors' => [
                'SchoolManagement\Vendors\VendorAdminColumns' => [],
            ],
            // MÓDULOS DE INFORMES
            'reports' => [
                'SchoolManagement\Reports\ReportsManager' => [
                    'dependencies' => ['SchoolManagement\Schools\SchoolManager']
                ],
            ],
        ];
    }
    
    /**
     * Inicializa todos los módulos del sistema
     */
    public function initialize(): void {
        if (self::$initialized) {
            return;
        }
        
        try {
            // Inicializar módulos en orden de dependencias
            foreach ($this->moduleConfig as $groupName => $modules) {
                $this->initializeModuleGroup($modules);
            }
            
            self::$initialized = true;
            
        } catch (Throwable $e) {
            throw $e;
        }
    }
    
    /**
     * Inicializa un grupo de módulos
     * 
     * @param array $modules Configuración de módulos
     */
    private function initializeModuleGroup(array $modules): void {
        foreach ($modules as $className => $config) {
            $this->initializeModule($className, $config);
        }
    }
    
    /**
     * Inicializa un módulo específico
     * 
     * @param string $className Nombre de la clase
     * @param array $config Configuración del módulo
     */
    private function initializeModule(string $className, array $config): void {
        try {
            // Verificar dependencias
            if (!empty($config['dependencies'])) {
                foreach ($config['dependencies'] as $dependency) {
                    if (!isset(self::$services[$dependency])) {
                        throw new Exception("Dependencia no encontrada: {$dependency} para {$className}");
                    }
                }
            }
            
            // Preparar argumentos del constructor
            $args = $config['args'] ?? [];
            
            // Resolver dependencias como argumentos
            $dependencyInstances = [];
            if (!empty($config['dependencies'])) {
                foreach ($config['dependencies'] as $dependency) {
                    if (!isset(self::$services[$dependency])) {
                        throw new Exception("Dependencia no encontrada: {$dependency} para {$className}");
                    }
                    $dependencyInstances[] = self::$services[$dependency];
                }
            }
            
            // Combinar argumentos configurados con dependencias
            $allArgs = array_merge($args, $dependencyInstances);
            
            // Crear instancia
            if ($className === 'SchoolManagement\Payments\PaymentHandler') {
                // Usar singleton para PaymentHandler
                $instance = $className::getInstance();
                file_put_contents(ABSPATH . 'hook-test.log', date('Y-m-d H:i:s') . " - Bootstrap: PaymentHandler obtenido via Singleton\n", FILE_APPEND);
            } else {
                $instance = empty($allArgs) 
                    ? new $className() 
                    : new $className(...$allArgs);
            }
            
            // Almacenar en servicios
            self::$services[$className] = $instance;
            
            // Almacenar en GLOBALS si se especifica
            if (!empty($config['store_global'])) {
                $GLOBALS[$config['store_global']] = $instance;
                
                // Log adicional para PaymentHandler
                if ($className === 'SchoolManagement\Payments\PaymentHandler') {
                    file_put_contents(ABSPATH . 'hook-test.log', date('Y-m-d H:i:s') . " - Bootstrap: PaymentHandler guardado en GLOBALS como 'payment_handler_instance'\n", FILE_APPEND);
                }
            }
            
        } catch (Throwable $e) {
            throw $e;
        }
    }
    
    /**
     * Obtiene una instancia de servicio
     * 
     * @param string $className Nombre de la clase
     * @return object|null La instancia del servicio o null si no existe
     */
    public static function getService(string $className): ?object {
        return self::$services[$className] ?? null;
    }
    
    /**
     * Verifica si un servicio está cargado
     * 
     * @param string $className Nombre de la clase
     * @return bool True si el servicio está cargado
     */
    public static function hasService(string $className): bool {
        return isset(self::$services[$className]);
    }
    
    /**
     * Obtiene todos los servicios cargados
     * 
     * @return array Array con todos los servicios
     */
    public static function getAllServices(): array {
        return self::$services;
    }
}

// Inicializar el sistema
$schoolManagementBootstrap = new SchoolManagementBootstrap();
$schoolManagementBootstrap->initialize();
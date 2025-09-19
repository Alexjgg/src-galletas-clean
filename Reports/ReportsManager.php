<?php
/**
 * ReportsManager
 * 
 * Coordinador principal para los diferentes tipos de informes.
 * Inicializa y coordina SchoolReport y ProductReport
 * Crea el menú principal "Informes" con submenús organizados
 * 
 * @package SchoolManagement\Reports
 * @since 1.0.0
 */

namespace SchoolManagement\Reports;

if (!defined('ABSPATH')) {
    exit;
}

class ReportsManager 
{
    /**
     * Instancia del informe de escuelas
     */
    private $school_report;
    
    /**
     * Instancia del informe de productos
     */
    private $product_report;

    /**
     * Constructor - Inicializa los diferentes tipos de informes
     */
    public function __construct() {
        $this->setupMainMenu();
        $this->initializeReports();
    }

    /**
     * Configurar el menú principal de Informes
     */
    private function setupMainMenu() {
        add_action('admin_menu', [$this, 'addMainReportsMenu'], 5);
    }

    /**
     * Agregar menú principal de Informes
     */
    public function addMainReportsMenu(): void
    {
        add_menu_page(
            __('Reports', 'neve-child'),
            __('Reports', 'neve-child'),
            'manage_woocommerce',
            'informes',
            [$this, 'renderMainReportsPage'],
            'dashicons-chart-area',
            29
        );
    }

    /**
     * Renderizar página principal de informes
     */
    public function renderMainReportsPage(): void
    {
        // Obtener estadísticas básicas
        $stats = $this->getBasicStats();
        
        ?>
        <div class="wrap informes-main-wrap">
            <div class="informes-header">
                <h1 class="wp-heading-inline">
                    <span class="dashicons dashicons-chart-area"></span>
                    <?php _e('Reports Center', 'neve-child'); ?>
                </h1>
                <hr class="wp-header-end">
            </div>
            
            <div class="informes-description">
                <p class="description">
                    <?php _e('Welcome to the reports center. Here you can access different types of analysis and statistics about schools and products.', 'neve-child'); ?>
                </p>
            </div>
            
            <div class="informes-cards-container">
                <div class="informe-card">
                    <div class="card-icon">
                        <span class="dashicons dashicons-groups"></span>
                    </div>
                    <div class="card-content">
                        <h3><?php _e('Schools Report', 'neve-child'); ?></h3>
                        <p><?php _e('Analyze products by school, zone filters and detailed order statistics.', 'neve-child'); ?></p>
                        <a href="<?php echo admin_url('admin.php?page=informe-escuelas'); ?>" class="button button-primary">
                            <?php _e('View Report', 'neve-child'); ?>
                        </a>
                    </div>
                </div>
                
                <div class="informe-card">
                    <div class="card-icon">
                        <span class="dashicons dashicons-chart-bar"></span>
                    </div>
                    <div class="card-content">
                        <h3><?php _e('Products Report', 'neve-child'); ?></h3>
                        <p><?php _e('Product rankings, popularity analysis and distribution statistics between schools.', 'neve-child'); ?></p>
                        <a href="<?php echo admin_url('admin.php?page=informe-productos'); ?>" class="button button-primary">
                            <?php _e('View Report', 'neve-child'); ?>
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="informes-stats-summary">
                                <h2><?php _e('General Summary', 'neve-child'); ?></h2>
                <div class="stats-grid">
                    <div class="stat-item">
                        <span class="stat-label"><?php _e('Active Schools', 'neve-child'); ?></span>
                        <span class="stat-value"><?php echo $stats['schools']; ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label"><?php _e('Available Products', 'neve-child'); ?></span>
                        <span class="stat-value"><?php echo $stats['products']; ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label"><?php _e('Configured Zones', 'neve-child'); ?></span>
                        <span class="stat-value"><?php echo $stats['zones']; ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
        .informes-main-wrap {
            max-width: 1200px;
        }
        
        .informes-header h1 {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.8em;
            color: #0073aa;
        }
        
        .informes-description {
            margin: 20px 0 30px 0;
            padding: 15px;
            background: #f1f1f1;
            border-left: 4px solid #0073aa;
        }
        
        .informes-cards-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        
        .informe-card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .informe-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .card-icon {
            text-align: center;
            margin-bottom: 15px;
        }
        
        .card-icon .dashicons {
            font-size: 48px;
            width: 48px;
            height: 48px;
            color: #0073aa;
        }
        
        .card-content h3 {
            margin: 0 0 10px 0;
            color: #23282d;
            text-align: center;
        }
        
        .card-content p {
            color: #666;
            line-height: 1.5;
            text-align: center;
            margin-bottom: 20px;
        }
        
        .card-content .button {
            display: block;
            text-align: center;
            width: 100%;
        }
        
        .informes-stats-summary {
            margin-top: 40px;
            padding: 20px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
        }
        
        .informes-stats-summary h2 {
            margin-top: 0;
            color: #23282d;
            border-bottom: 2px solid #0073aa;
            padding-bottom: 10px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .stat-item {
            text-align: center;
            padding: 20px;
            background: #f9f9f9;
            border-radius: 6px;
        }
        
        .stat-number {
            display: block;
            font-size: 2.5em;
            font-weight: bold;
            color: #0073aa;
            line-height: 1;
        }
        
        .stat-label {
            display: block;
            margin-top: 8px;
            color: #666;
            font-weight: 500;
        }
        </style>
        <?php
    }

    /**
     * Inicializar todos los tipos de informes
     */
    private function initializeReports() {
        // Inicializar informe de escuelas
        $this->school_report = new SchoolReport();
        
        // Inicializar informe de productos
        $this->product_report = new ProductReport();
    }

    /**
     * Obtener instancia del informe de escuelas
     */
    public function getSchoolReport(): SchoolReport {
        return $this->school_report;
    }

    /**
     * Obtener instancia del informe de productos
     */
    public function getProductReport(): ProductReport {
        return $this->product_report;
    }

    /**
     * Obtener estadísticas básicas para la página principal
     */
    private function getBasicStats(): array {
        $schools_count = wp_count_posts('school');
        $products_count = wp_count_posts('product');
        
        // Contar zonas (si tienes configuradas)
        $zones_count = 0;
        if (function_exists('WC')) {
            $zones = \WC_Shipping_Zones::get_zones();
            $zones_count = count($zones);
        }
        
        return [
            'schools' => $schools_count->publish ?? 0,
            'products' => $products_count->publish ?? 0,
            'zones' => $zones_count
        ];
    }

    /**
     * Método estático para obtener todas las instancias de informes
     * Útil para casos donde se necesite acceso directo a los informes
     */
    public static function getAllReports(): array {
        static $instance = null;
        
        if ($instance === null) {
            $instance = new self();
        }
        
        return [
            'school_report' => $instance->getSchoolReport(),
            'product_report' => $instance->getProductReport()
        ];
    }
}

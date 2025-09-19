<?php
/**
 * School User System Initializer
 * 
 * Inicializa y coordina todos los componentes del sistema de gestión
 * de usuarios y escuelas
 * 
 * @package SchoolManagement\Users
 * @since 1.0.0
 */

namespace SchoolManagement\Users;

if (!defined('ABSPATH')) {
    exit;
}

// Cargar las clases requeridas
require_once __DIR__ . '/SchoolUserManager.php';
require_once __DIR__ . '/UserAdminColumnsManager.php';

/**
 * Class for initializing the complete school user management system
 */
class SchoolUserSystemInitializer
{
    /**
     * @var SchoolUserManager
     */
    private $userManager;

    /**
     * @var UserAdminColumnsManager
     */
    private $columnsManager;

    /**
     * @var bool
     */
    private $initialized = false;

    /**
     * Constructor
     */
    public function __construct()
    {
        add_action('init', [$this, 'initialize'], 10);
        add_action('admin_notices', [$this, 'displaySystemStatus']);
    }

    /**
     * Initialize the complete system
     */
    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        try {
            // Verificar dependencias
            $this->checkDependencies();

            // Inicializar SchoolUserManager (funcionalidad principal)
            $this->userManager = new SchoolUserManager();

            // Inicializar UserAdminColumnsManager (columnas del admin)
            $this->columnsManager = new UserAdminColumnsManager();

            $this->initialized = true;

            // Log de inicialización exitosa
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('School User System: Successfully initialized');
            }

        } catch (\Exception $e) {
            // Log del error
            error_log('School User System Initialization Error: ' . $e->getMessage());
            
            // Mostrar mensaje en admin si es posible
            if (is_admin()) {
                add_action('admin_notices', function() use ($e) {
                    echo '<div class="notice notice-error"><p>';
                    echo '<strong>School User System Error:</strong> ' . esc_html($e->getMessage());
                    echo '</p></div>';
                });
            }
        }
    }

    /**
     * Check system dependencies
     * 
     * @throws \Exception If dependencies are not met
     */
    private function checkDependencies(): void
    {
        $errors = [];

        // Verificar ACF
        if (!function_exists('acf_add_local_field_group')) {
            $errors[] = 'Advanced Custom Fields (ACF) no está activo o instalado';
        }

        // Verificar WooCommerce
        if (!class_exists('WooCommerce')) {
            $errors[] = 'WooCommerce no está activo o instalado';
        }

        // Verificar post type 'school'
        if (!post_type_exists('school') && !post_type_exists('coo_school')) {
            $errors[] = 'El post type de escuelas no está registrado (school o coo_school)';
        }

        // Verificar capacidades del usuario actual
        if (is_admin() && !current_user_can('manage_options')) {
            // No es un error crítico, solo una advertencia
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('School User System: Current user lacks manage_options capability');
            }
        }

        if (!empty($errors)) {
            throw new \Exception('Dependencias faltantes: ' . implode(', ', $errors));
        }
    }

    /**
     * Display system status in admin
     */
    public function displaySystemStatus(): void
    {
        // Solo mostrar en páginas relevantes
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['users', 'user-edit', 'profile'])) {
            return;
        }

        if (!$this->initialized) {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>School User System:</strong> Sistema no inicializado correctamente. ';
            echo 'Revisa los logs para más detalles.';
            echo '</p></div>';
            return;
        }

        // Mostrar información útil solo si estamos en debug
        if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options')) {
            $stats = $this->getSystemStats();
            
            echo '<div class="notice notice-info is-dismissible"><p>';
            echo '<strong>School User System Activo:</strong> ';
            echo sprintf(
                '%d escuelas, %d estudiantes registrados',
                $stats['schools_count'],
                $stats['students_count']
            );
            if ($stats['duplicates_count'] > 0) {
                echo sprintf(' - ⚠️ %d números duplicados detectados', $stats['duplicates_count']);
            }
            echo '</p></div>';
        }
    }

    /**
     * Get system statistics
     * 
     * @return array
     */
    private function getSystemStats(): array
    {
        // Contar escuelas
        $schools_count = wp_count_posts('school');
        if (!$schools_count) {
            $schools_count = wp_count_posts('coo_school');
        }
        $schools_total = $schools_count ? $schools_count->publish : 0;

        // Contar estudiantes con school_id
        $students = get_users([
            'meta_query' => [
                [
                    'key' => 'school_id',
                    'compare' => 'EXISTS'
                ]
            ],
            'fields' => 'ids',
            'count_total' => true
        ]);
        $students_count = count($students);

        // Detectar duplicados en números de usuario
        $duplicates_count = $this->detectDuplicateNumbers();

        return [
            'schools_count' => $schools_total,
            'students_count' => $students_count,
            'duplicates_count' => $duplicates_count,
            'initialized' => $this->initialized
        ];
    }

    /**
     * Detect duplicate user numbers within schools
     * 
     * @return int Number of duplicate cases
     */
    private function detectDuplicateNumbers(): int
    {
        global $wpdb;

        $query = "
            SELECT school_meta.meta_value as school_id, number_meta.meta_value as user_number, COUNT(*) as count
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->usermeta} school_meta ON u.ID = school_meta.user_id AND school_meta.meta_key = 'school_id'
            INNER JOIN {$wpdb->usermeta} number_meta ON u.ID = number_meta.user_id AND number_meta.meta_key = 'user_number'
            WHERE school_meta.meta_value != '' AND number_meta.meta_value != ''
            GROUP BY school_meta.meta_value, number_meta.meta_value
            HAVING COUNT(*) > 1
        ";

        $results = $wpdb->get_results($query);
        return count($results);
    }

    /**
     * Get system health report
     * 
     * @return array
     */
    public function getHealthReport(): array
    {
        $report = [
            'status' => 'healthy',
            'issues' => [],
            'stats' => $this->getSystemStats(),
            'dependencies' => []
        ];

        // Verificar dependencias
        $report['dependencies']['acf'] = function_exists('acf_add_local_field_group');
        $report['dependencies']['woocommerce'] = class_exists('WooCommerce');
        $report['dependencies']['school_post_type'] = post_type_exists('school') || post_type_exists('coo_school');

        // Detectar problemas
        if (!$this->initialized) {
            $report['status'] = 'error';
            $report['issues'][] = 'Sistema no inicializado';
        }

        if ($report['stats']['duplicates_count'] > 0) {
            $report['status'] = 'warning';
            $report['issues'][] = sprintf('%d números de usuario duplicados', $report['stats']['duplicates_count']);
        }

        if (!$report['dependencies']['acf']) {
            $report['status'] = 'error';
            $report['issues'][] = 'ACF no disponible';
        }

        if (!$report['dependencies']['woocommerce']) {
            $report['status'] = 'error';
            $report['issues'][] = 'WooCommerce no disponible';
        }

        return $report;
    }

    /**
     * Get the user manager instance
     * 
     * @return SchoolUserManager|null
     */
    public function getUserManager(): ?SchoolUserManager
    {
        return $this->userManager;
    }

    /**
     * Get the columns manager instance
     * 
     * @return UserAdminColumnsManager|null
     */
    public function getColumnsManager(): ?UserAdminColumnsManager
    {
        return $this->columnsManager;
    }

    /**
     * Check if system is initialized
     * 
     * @return bool
     */
    public function isInitialized(): bool
    {
        return $this->initialized;
    }
}

// Inicializar el sistema automáticamente
new SchoolUserSystemInitializer();

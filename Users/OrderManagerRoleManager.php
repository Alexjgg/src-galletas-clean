<?php
/**
 * Shop Manager Role Manager - COPIA EXACTA DE TeacherRoleManager
 * 
 * Gestiona roles de shop_manager y sus permisos - IDÉNTICO AL PROFESOR
 * COPIA EXACTA del funcionamiento de TeacherRoleManager PERO SIN filtros de escuela
 * 
 * @package SchoolManagement\Users
 * @since 1.0.0
 */

namespace SchoolManagement\Users;

if (!defined('ABSPATH')) {
    exit;
}

class OrderManagerRoleManager
{
    /**
     * Constructor - IDÉNTICO A TeacherRoleManager
     */ 
    public function __construct()
    {
        add_action('init', array($this, 'dm_registrar_roles_shop_manager'));
        add_filter('woocommerce_prevent_admin_access', array($this, 'shop_manager_allow_admin_to_role'));
        add_filter('woocommerce_disable_admin_bar', array($this, 'shop_manager_allow_admin_to_role'));
        add_action('wp_dashboard_setup', array($this, 'shop_manager_dashboard_widgets'), 999);

        // 🎯 NUEVO: Filtrar pedidos - Order Managers SOLO ven Master Orders
        add_filter('woocommerce_order_list_table_prepare_items_query_args', array($this, 'filter_orders_to_master_only'), 10);
        add_filter('woocommerce_reports_get_order_report_query', array($this, 'filter_order_reports_to_master_only'));

        // Restringir acceso a pedidos individuales
        add_action('admin_init', array($this, 'restrict_shop_manager_shop_order_access'));

        // Restringir acceso a páginas específicas de plugins
        add_action('admin_init', array($this, 'restrict_shop_manager_plugin_pages'));

        // Ocultar elementos del menú admin para order managers
        add_action('admin_menu', array($this, 'remove_admin_menu_items_for_shop_managers'), 999);

        // Remover específicamente menús de plugins (prioridad más alta)
        add_action('admin_menu', array($this, 'remove_plugin_menu_items_for_shop_managers'), 9999);

        // Hook adicional para capturar menús registrados muy tarde
        add_action('admin_head', array($this, 'final_menu_cleanup_for_shop_managers'));

        // CSS adicional para ocultar elementos como último recurso
        add_action('admin_head', array($this, 'hide_restricted_elements_css'));

        // Mostrar mensaje de acceso restringido si es necesario
        add_action('admin_notices', array($this, 'show_restricted_access_notice'));

        // Remover enlaces del perfil de la barra de admin para order managers
        add_action('admin_bar_menu', array($this, 'remove_shop_manager_profile_admin_bar'), 999);

        // FILTRAR BULK ACTIONS ESPECÍFICAS - Order Manager NO puede usar pagos ni PDFs
        add_filter('bulk_actions-edit-shop_order', array($this, 'filter_shop_manager_bulk_actions'), 999, 1);
        add_filter('bulk_actions-woocommerce_page_wc-orders', array($this, 'filter_shop_manager_bulk_actions'), 999, 1);
        
        // INTERCEPTAR BULK ACTIONS PROHIBIDAS - Bloquear pagos y PDFs
        add_filter('handle_bulk_actions-edit-shop_order', array($this, 'intercept_shop_manager_bulk_actions'), 10, 3);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', array($this, 'intercept_shop_manager_bulk_actions'), 10, 3);
        
        // PROTEGER CONTRA BULK ACTIONS PROHIBIDAS - Bloquear pagos y PDFs
        add_action('admin_init', array($this, 'block_shop_manager_bulk_action_processing'), 5);
        
        // PROTEGER CONTRA ACCESOS DIRECTOS POR URL - Interceptar cualquier intento
        add_action('wp_loaded', array($this, 'block_shop_manager_direct_url_access'), 1);
        add_action('admin_init', array($this, 'block_shop_manager_url_parameters'), 1);
        
        // PROTEGER CONTRA ACCESOS AJAX PROHIBIDOS - Bloquear llamadas AJAX
        add_action('wp_ajax_wpo_wcpdf_generate_pdf', array($this, 'block_shop_manager_ajax_actions'), 1);
        add_action('wp_ajax_mark_bank_transfer_paid', array($this, 'block_shop_manager_ajax_actions'), 1);
        add_action('wp_ajax_mark_bank_transfer_unpaid', array($this, 'block_shop_manager_ajax_actions'), 1);
    }

    /**
     * COPIA EXACTA de teacher_allow_admin_to_role() del TeacherRoleManager
     * Permitimos a order_manager acceder al Admin - IDÉNTICO A TEACHER
     */
    function shop_manager_allow_admin_to_role($prevent_access)
    {
        // Si no es order_manager, devolvemos el valor original
        if (!$this->is_user('order_manager')) {
            return $prevent_access;
        }
        // Si es order_manager, permitimos el acceso al admin
        return false;
    }

    /**
     * COPIA EXACTA de dm_registrar_roles_alumno_profesor() del TeacherRoleManager
     * Registra el rol shop_manager - IDÉNTICO A TEACHER
     */
    function dm_registrar_roles_shop_manager()
    {
        // FORZAR RECREACIÓN DEL ROL (igual que teacher)
        // Primero eliminamos el rol si existe para forzar actualización
        if (get_role('order_manager')) {
            remove_role('order_manager');
        }

        // Crear el rol order_manager con capacidades IDÉNTICAS a teacher
        add_role(
            'order_manager',
            __('Order Manager', 'neve-child'),
            array(
                'read' => true,
                'level_0' => true,
                'access_woocommerce_admin' => true,
                'manage_woocommerce' => true,
                'view_woocommerce_reports' => true,

                // Capacidades para ACCEDER al admin de pedidos (listado)
                'edit_shop_orders' => true,              // Necesario para ver el menú de pedidos
                'read_shop_orders' => true,              // Ver listado de pedidos
                'read_private_shop_orders' => true,      // Ver pedidos privados
                'edit_others_shop_orders' => true,       // Necesario para el listado completo
                
                // CAPACIDADES PARA REPORTES PERSONALIZADOS
                'manage_options' => true,                // Necesario para acceder al menú principal de Informes
                'view_reports' => true,                  // Capacidad personalizada para reportes
                'read_products' => true,                 // Necesario para reportes de productos
                
                // SIN CAPACIDADES DE USUARIOS - Order Manager NO puede acceder a usuarios
                'list_users' => false,                   // NO ver listado de usuarios
                'edit_users' => false,                   // NO acceder al menú de usuarios
                'create_users' => false,                 // NO crear usuarios nuevos
                'delete_users' => false,                 // NO eliminar usuarios
                'promote_users' => false,                // NO cambiar roles de usuarios
                
                // NOTA: Order Manager tiene acceso a CASI TODAS las bulk actions EXCEPTO pagos y PDFs
                // DIFERENCIA CON TEACHER: 
                // - SIN filtros de escuela - ve TODOS los pedidos
                // - SIN acceso a usuarios - NO puede ver ni gestionar usuarios
                // - SIN acceso a pagos y PDFs - NO puede marcar como pagado/no pagado ni generar PDFs
                // + ACCESO COMPLETO A REPORTES - Puede ver todos los reportes personalizados (SchoolReport, ProductReport)
                // - CON todas las demás bulk actions - puede usar cualquier otra acción masiva en pedidos
            )
        );
    }

    /**
     * 🎯 NUEVO: Filtra los pedidos para mostrar SOLO Master Orders a los Order Managers
     * OPUESTO al TeacherRoleManager - Los Order Managers SOLO ven pedidos maestros
     */
    function filter_orders_to_master_only($query_args)
    {
        // Solo aplicamos filtro para order managers
        if (!$this->is_user('order_manager') || is_super_admin(get_current_user_id())) {
            return $query_args;
        }

        // Inicializar meta_query si no existe
        if (!isset($query_args['meta_query'])) {
            $query_args['meta_query'] = [];
        }

        // Configurar relación AND para múltiples condiciones meta si hay más de una
        if (count($query_args['meta_query']) > 0) {
            $query_args['meta_query']['relation'] = 'AND';
        }

        // 🎯 MOSTRAR SOLO Master Orders - Los Order Managers solo ven pedidos maestros
        $query_args['meta_query'][] = [
            'key' => '_is_master_order',
            'value' => 'yes',
            'compare' => '='
        ];

        return $query_args;
    }

    /**
     * 🎯 NUEVO: Filtra los reportes para mostrar SOLO Master Orders a los Order Managers
     * OPUESTO al TeacherRoleManager - Los reportes solo incluyen pedidos maestros
     */
    function filter_order_reports_to_master_only($query)
    {
        // Solo aplicamos filtro para order managers
        if (!$this->is_user('order_manager') || is_super_admin(get_current_user_id())) {
            return $query;
        }

        // Añadimos condición para filtrar SOLO master orders en reportes
        global $wpdb;

        if (isset($query['where']) && is_string($query['where'])) {
            $query['where'] .= " AND meta__master_order.meta_key = '_is_master_order' AND meta__master_order.meta_value = 'yes'";

            if (!strstr($query['join'], 'meta__master_order')) {
                $query['join'] .= " LEFT JOIN {$wpdb->prefix}postmeta meta__master_order ON posts.ID = meta__master_order.post_id";
            }
        }

        return $query;
    }

    /**
     * Restringe el acceso a TODOS los pedidos (maestros e individuales) para order managers
     * Order managers solo pueden ver el listado y usar bulk actions, NO editar pedidos
     */
    function restrict_shop_manager_shop_order_access()
    {
        global $pagenow;

        // Solo aplicar a order managers
        if (!$this->is_user('order_manager')) {
            return;
        }

        // Verificar acceso a pedidos individuales en sistema legacy (post.php/post-new.php)
        if (is_admin() && ($pagenow == 'post.php' || $pagenow == 'post-new.php')) {
            // Verificar si estamos accediendo a un pedido individual específico
            $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
            $action = isset($_GET['action']) ? $_GET['action'] : '';
            $post_type = isset($_GET['post_type']) ? $_GET['post_type'] : '';

            // BLOQUEAR: Crear nuevo pedido
            if ($pagenow == 'post-new.php' && $post_type == 'shop_order') {
                wp_redirect(admin_url('edit.php?post_type=shop_order&individual_access_denied=1'));
                exit;
            }

            // BLOQUEAR: Editar/ver CUALQUIER pedido (individual O maestro)
            if ($pagenow == 'post.php' && $post_id > 0) {
                $post = get_post($post_id);
                if ($post && $post->post_type === 'shop_order') {
                    // 🚫 BLOQUEAR TODOS LOS PEDIDOS - Order managers NO pueden editar ningún pedido
                    wp_redirect(admin_url('edit.php?post_type=shop_order&order_edit_access_denied=1'));
                    exit;
                }
            }
        }

        // Verificar acceso a pedidos individuales en sistema HPOS (admin.php?page=wc-orders)
        if (is_admin() && $pagenow == 'admin.php') {
            $page = isset($_GET['page']) ? $_GET['page'] : '';
            $action = isset($_GET['action']) ? $_GET['action'] : '';
            $order_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

            // BLOQUEAR: Acceso a CUALQUIER pedido en HPOS (individual O maestro)
            if ($page === 'wc-orders' && $action === 'edit' && $order_id > 0) {
                // 🚫 BLOQUEAR TODOS LOS PEDIDOS - Order managers NO pueden editar ningún pedido
                wp_redirect(admin_url('admin.php?page=wc-orders&order_edit_access_denied=1'));
                exit;
            }

            // BLOQUEAR: Crear nuevo pedido en HPOS
            if ($page === 'wc-orders' && $action === 'new') {
                wp_redirect(admin_url('admin.php?page=wc-orders&individual_access_denied=1'));
                exit;
            }
        }
    }

    /**
     * COPIA EXACTA de teacher_dashboard_widgets() del TeacherRoleManager
     * Limpiamos widgets del order_manager dashboard - IDÉNTICO A TEACHER
     */    /**
     * COPIA EXACTA de teacher_dashboard_widgets() del TeacherRoleManager
     * Limpiamos widgets del order_manager dashboard - IDÉNTICO A TEACHER
     */
    function shop_manager_dashboard_widgets()
    {
        if ($this->is_user('order_manager')) {
            global $wp_meta_boxes;

            unset($wp_meta_boxes['dashboard']['side']['core']['dashboard_primary']);
            unset($wp_meta_boxes['dashboard']['side']['core']['dashboard_secondary']);
            unset($wp_meta_boxes['dashboard']['side']['core']['dashboard_quick_press']);
            unset($wp_meta_boxes['dashboard']['normal']['core']['dashboard_activity']);
            unset($wp_meta_boxes['dashboard']['normal']['core']['dashboard_incoming_links']);
            unset($wp_meta_boxes['dashboard']['normal']['core']['dashboard_right_now']);
            unset($wp_meta_boxes['dashboard']['normal']['core']['dashboard_plugins']);
            unset($wp_meta_boxes['dashboard']['normal']['core']['dashboard_recent_drafts']);
            unset($wp_meta_boxes['dashboard']['normal']['core']['dashboard_recent_comments']);
            unset($wp_meta_boxes['dashboard']['normal']['core']['redsys_link_widget']);
            unset($wp_meta_boxes['dashboard']['normal']['core']['redsys_link_posts_widget']);

            // Añadir un widget personalizado para el order manager
            add_meta_box(
                'order_manager_info',
                __('Order Manager Information', 'neve-child'),
                [$this, 'display_shop_manager_widget'],
                'dashboard',
                'normal',
                'high'
            );
        }
    }

    /**
     * COPIA EXACTA de display_teacher_school_widget() del TeacherRoleManager
     * Muestra el widget con información del shop manager - SIN filtros de escuela
     */
    function display_shop_manager_widget()
    {
        $manager_id = get_current_user_id();

        // Contar pedidos totales (SIN filtro de escuela)
        global $wpdb;
        $count_query = "SELECT COUNT(*) FROM {$wpdb->posts} p 
                       WHERE p.post_type = 'shop_order' 
                       AND p.post_status IN ('wc-processing', 'wc-completed')";
        $orders_count = $wpdb->get_var($count_query);

        echo '<div class="shop-manager-info">';
        echo '<h3>Order Manager Dashboard</h3>';
        echo '<p><strong>Welcome Order Manager!</strong></p>';
        echo '<p>You have full access to all orders and most bulk actions in the system.</p>';
        echo '<p><em>Note: Payment actions and PDF generation are restricted.</em></p>';
        echo '<p><strong>' . __('Total managed orders:', 'neve-child') . '</strong> ' . intval($orders_count) . '</p>';

        // Enlace a pedidos
        echo '<p><a href="' . esc_url(admin_url('edit.php?post_type=shop_order')) . '" class="button button-primary">';
        echo __('View and manage all orders', 'neve-child');
        echo '</a></p>';
        echo '</div>';
    }

    /**
     * COPIA EXACTA de restrict_teacher_plugin_pages() del TeacherRoleManager
     * Restringe el acceso a páginas específicas de plugins para order managers
     */
    function restrict_shop_manager_plugin_pages()
    {
        // Solo aplicar restricciones a order managers
        if (!$this->is_user('order_manager') || is_super_admin()) {
            return;
        }

        // Obtener la página actual
        $current_page = isset($_GET['page']) ? $_GET['page'] : '';

        // Lista de páginas de plugins bloqueadas para order managers
        $blocked_pages = array(
            'wpo_wcpdf_options_page',  // WooCommerce PDF Invoices & Packing Slips
            'wc-settings',             // Configuración de WooCommerce
            'wc-status',               // Estado del sistema WooCommerce
            'wc-addons',               // Complementos WooCommerce
            'woocommerce_importer',    // Importador WooCommerce
            'woocommerce_exporter',    // Exportador WooCommerce
        );

        // Si está intentando acceder a una página bloqueada
        if (in_array($current_page, $blocked_pages)) {
            // Redirigir al dashboard con mensaje de error
            wp_redirect(admin_url('index.php?restricted=1'));
            exit;
        }
    }

    /**
     * COPIA EXACTA de remove_admin_menu_items_for_teachers() del TeacherRoleManager
     * Remueve elementos del menú admin - IDÉNTICO A TEACHER
     */
    function remove_admin_menu_items_for_shop_managers()
    {
        // Solo para order managers, no para super admins
        if (!$this->is_user('order_manager') || is_super_admin()) {
            return;
        }

        global $menu, $submenu;

        // Remover TODOS los menús principales de WordPress EXCEPTO shop_order E INFORMES
        remove_menu_page('edit.php');                          // Posts
        remove_menu_page('upload.php');                        // Media
        remove_menu_page('edit.php?post_type=page');           // Páginas
        remove_menu_page('edit-comments.php');                 // Comentarios
        remove_menu_page('themes.php');                        // Apariencia
        remove_menu_page('plugins.php');                       // Plugins
        remove_menu_page('tools.php');                         // Herramientas
        remove_menu_page('options-general.php');               // Ajustes
        remove_menu_page('profile.php');                       // Perfil - NO necesario para shop managers
        remove_menu_page('users.php');                         // Usuarios - NO acceso para order managers
        
        // MANTENER ACCESO A INFORMES - Los reportes personalizados SÍ son relevantes para order_manager
        // NO remover 'informes' - permitir acceso completo al menú de reportes

        // MANTENER WooCommerce principal pero remover sus submenús excepto pedidos
        // Eliminar todos los submenús de WooCommerce excepto pedidos
        if (isset($submenu['woocommerce'])) {
            foreach ($submenu['woocommerce'] as $key => $item) {
                // Mantener solo el submenú de pedidos
                if (
                    !isset($item[2]) ||
                    (strpos($item[2], 'shop_order') === false &&
                        strpos($item[2], 'orders') === false)
                ) {
                    remove_submenu_page('woocommerce', $item[2]);
                }
            }
        }

        // Remover específicamente páginas de plugins
        remove_submenu_page('woocommerce', 'wpo_wcpdf_options_page');  // PDF Invoices options
        remove_submenu_page('woocommerce', 'wc-settings');             // WooCommerce Settings
        remove_submenu_page('woocommerce', 'wc-status');               // WooCommerce Status
        remove_submenu_page('woocommerce', 'wc-addons');               // WooCommerce Add-ons

        // Remover productos, cupones, etc.
        remove_menu_page('edit.php?post_type=product');
        remove_menu_page('edit.php?post_type=shop_coupon');

        // Remover todos los otros CPTs EXCEPTO shop_order
        remove_menu_page('edit.php?post_type=elementor_library');
        remove_menu_page('edit.php?post_type=neve_custom_layouts');
        remove_menu_page('edit.php?post_type=acf-field-group');
        remove_menu_page('edit.php?post_type=custom_css');
        remove_menu_page('edit.php?post_type=customize_changeset');

        // Remover cualquier otro menú no deseado pero mantener WooCommerce, pedidos E INFORMES
        foreach ($menu as $key => $item) {
            if (isset($item[2])) {
                $menu_slug = $item[2];
                // Mantener Dashboard, WooCommerce, Pedidos E INFORMES (NO usuarios)
                if (
                    !in_array($menu_slug, [
                        'index.php',                        // Dashboard
                        'woocommerce',                      // WooCommerce principal
                        'edit.php?post_type=shop_order',   // Pedidos
                        'informes'                          // REPORTES PERSONALIZADOS - ESENCIAL
                    ])
                ) {
                    remove_menu_page($menu_slug);
                }
            }
        }
    }

    /**
     * COPIA EXACTA de remove_plugin_menu_items_for_teachers() del TeacherRoleManager
     * Remover específicamente elementos de menú de plugins (con prioridad alta)
     */
    function remove_plugin_menu_items_for_shop_managers()
    {
        // Solo para order managers, no para super admins
        if (!$this->is_user('order_manager') || is_super_admin()) {
            return;
        }

        global $submenu;

        // Remover específicamente submenús de plugins que se registran tarde
        remove_submenu_page('woocommerce', 'wpo_wcpdf_options_page');  // PDF Invoices & Packing Slips

        // También intentar remover cualquier variación del nombre
        $wc_submenus_to_remove = array(
            'wpo_wcpdf_options_page',
            'wc-pdf-invoices',
            'pdf-invoices-packing-slips',
            'woocommerce-pdf-invoices-packing-slips'
        );

        // Remover múltiples posibles slugs del plugin PDF
        foreach ($wc_submenus_to_remove as $submenu_slug) {
            remove_submenu_page('woocommerce', $submenu_slug);
        }

        // Verificar y remover manualmente del array $submenu si aún existe
        if (isset($submenu['woocommerce'])) {
            foreach ($submenu['woocommerce'] as $key => $item) {
                if (isset($item[2])) {
                    // Buscar cualquier referencia a PDF invoices
                    if (
                        strpos($item[2], 'wpo_wcpdf') !== false ||
                        strpos($item[2], 'pdf-invoice') !== false ||
                        strpos($item[2], 'pdf_invoice') !== false ||
                        (isset($item[0]) && strpos(strtolower($item[0]), 'pdf') !== false && strpos(strtolower($item[0]), 'invoice') !== false)
                    ) {
                        unset($submenu['woocommerce'][$key]);
                    }
                }
            }
        }
    }

    /**
     * COPIA EXACTA de final_menu_cleanup_for_teachers() del TeacherRoleManager
     * Limpieza final de menús para shop managers (se ejecuta en admin_head)
     */
    function final_menu_cleanup_for_shop_managers()
    {
        // Solo para order managers, no para super admins
        if (!$this->is_user('order_manager') || is_super_admin()) {
            return;
        }

        // Solo en páginas de admin
        if (!is_admin()) {
            return;
        }

        global $submenu;

        // Última verificación y limpieza del submenú de WooCommerce
        if (isset($submenu['woocommerce'])) {
            foreach ($submenu['woocommerce'] as $key => $item) {
                if (isset($item[2])) {
                    // Lista de slugs a remover
                    $blocked_slugs = array(
                        'wpo_wcpdf_options_page',
                        'wc-settings',
                        'wc-status', 
                        'wc-addons',
                        'woocommerce_importer',
                        'woocommerce_exporter'
                    );

                    if (in_array($item[2], $blocked_slugs)) {
                        unset($submenu['woocommerce'][$key]);
                        continue;
                    }

                    // Verificación adicional por texto del menú para PDF invoices
                    if (isset($item[0])) {
                        $menu_text = strtolower($item[0]);
                        if (
                            strpos($menu_text, 'pdf') !== false ||
                            strpos($menu_text, 'invoice') !== false ||
                            strpos($menu_text, 'packing') !== false ||
                            strpos($menu_text, 'settings') !== false ||
                            strpos($menu_text, 'status') !== false ||
                            strpos($menu_text, 'extensions') !== false ||
                            strpos($menu_text, 'add-ons') !== false
                        ) {
                            // Preservar solo menús de pedidos
                            if (
                                strpos($menu_text, 'order') === false &&
                                strpos($menu_text, 'pedido') === false
                            ) {
                                unset($submenu['woocommerce'][$key]);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * COPIA EXACTA de hide_restricted_elements_css() del TeacherRoleManager
     * Ocultar elementos restringidos usando CSS como último recurso
     */
    function hide_restricted_elements_css()
    {
        // Solo para order managers, no para super admins
        if (!$this->is_user('order_manager') || is_super_admin()) {
            return;
        }

        // Solo en páginas de admin
        if (!is_admin()) {
            return;
        }

        ?>
        <style type="text/css">
        /* Ocultar enlaces específicos de PDF Invoices en el menú de WooCommerce */
        #adminmenu .wp-submenu a[href*="wpo_wcpdf_options_page"],
        #adminmenu .wp-submenu a[href*="pdf-invoice"],
        #adminmenu .wp-submenu a[href*="pdf_invoice"] {
            display: none !important;
        }

        /* Ocultar otros enlaces restringidos */
        #adminmenu .wp-submenu a[href*="wc-settings"],
        #adminmenu .wp-submenu a[href*="wc-status"],
        #adminmenu .wp-submenu a[href*="wc-addons"],
        #adminmenu .wp-submenu a[href*="woocommerce_importer"],
        #adminmenu .wp-submenu a[href*="woocommerce_exporter"] {
            display: none !important;
        }

        /* OCULTAR BOTONES PDF EN COLUMNA DE ACCIONES DE PEDIDOS */
        /* OCULTAR SOLO botones de facturas PDF, PERMITIR albaranes (packing-slip) */
        .wc_actions .wpo_wcpdf.invoice,
        .wc_actions a[href*="document_type=invoice"],
        .wc_actions a[href*="document_type=receipt"],
        .wc_actions a[href*="document_type=credit-note"],
        .wc_actions a[href*="document_type=proforma"],
        .wc_actions a[href*="document_type=delivery-note"],
        .wc_actions a[href*="document_type=simplified-invoice"],
        .wc_actions a[href*="document_type=exchange-invoice"],
        .wc_actions a[href*="document_type=simplified-credit-note"],
        .column-wc_actions a[class*="invoice"],
        .column-wc_actions a[class*="simplified-invoice"],
        .column-wc_actions a[class*="exchange-invoice"],
        .column-wc_actions a[class*="simplified-credit-note"],
        .column-wc_actions a[alt*="Invoice"],
        .column-wc_actions a[alt*="Factura"],
        .column-wc_actions a[alt*="Simplified Credit Note"],
        .column-wc_actions a[alt*="Credit Note"] {
            display: none !important;
        }

        /* PERMITIR explícitamente botones de albaranes (packing-slip) para order managers */
        .wc_actions .wpo_wcpdf.packing-slip,
        .wc_actions a[href*="document_type=packing-slip"],
        .column-wc_actions a[class*="packing-slip"],
        .column-wc_actions a[alt*="Packing"],
        .column-wc_actions a[alt*="Albarán"] {
            display: inline-block !important;
        }

        /* Ocultar también cualquier botón PDF de facturación específico */
        .button.wpo_wcpdf.invoice.pdf,
        .button.wpo_wcpdf.receipt.pdf,
        .button.wpo_wcpdf.credit-note.pdf,
        .button.wpo_wcpdf.proforma.pdf,
        .button.wpo_wcpdf.delivery-note.pdf,
        .button.wpo_wcpdf.simplified-invoice.pdf,
        .button.wpo_wcpdf.exchange-invoice.pdf,
        .button.wpo_wcpdf.simplified-credit-note.pdf {
            display: none !important;
        }

        /* Ocultar enlace del perfil en la barra de admin */
        #wp-admin-bar-user-info,
        #wp-admin-bar-edit-profile,
        #wpadminbar #wp-admin-bar-user-actions #wp-admin-bar-user-info,
        #wpadminbar #wp-admin-bar-user-actions #wp-admin-bar-edit-profile,
        #wpadminbar .ab-top-menu #wp-admin-bar-user-info,
        #wpadminbar .ab-top-menu #wp-admin-bar-edit-profile {
            display: none !important;
        }

        /* Ocultar específicamente el enlace "Editar mi perfil" del menú de usuario */
        #wpadminbar #wp-admin-bar-user-actions .ab-submenu #wp-admin-bar-edit-profile {
            display: none !important;
        }
        </style>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // JavaScript adicional para remover elementos que no se pudieron ocultar con CSS
            
            // Buscar y ocultar enlaces que contengan palabras clave restringidas
            $('#adminmenu .wp-submenu a').each(function() {
                var href = $(this).attr('href') || '';
                var text = $(this).text().toLowerCase();
                
                // Lista de patrones a ocultar
                var restrictedPatterns = [
                    'wpo_wcpdf_options_page',
                    'pdf-invoice',
                    'pdf_invoice',
                    'wc-settings',
                    'wc-status',
                    'wc-addons'
                ];
                
                // Lista de textos a ocultar (excepto si contienen "order" o "pedido")
                var restrictedTexts = ['pdf', 'invoice', 'packing', 'settings', 'status', 'extensions', 'add-ons'];
                
                // Verificar patrones en href
                for (var i = 0; i < restrictedPatterns.length; i++) {
                    if (href.indexOf(restrictedPatterns[i]) !== -1) {
                        $(this).parent().hide();
                        return;
                    }
                }
                
                // Verificar textos restrictivos (excepto si es relacionado con pedidos)
                if (text.indexOf('order') === -1 && text.indexOf('pedido') === -1) {
                    for (var j = 0; j < restrictedTexts.length; j++) {
                        if (text.indexOf(restrictedTexts[j]) !== -1) {
                            $(this).parent().hide();
                            return;
                        }
                    }
                }
            });

            // REMOVER SOLO BOTONES PDF DE FACTURAS, PERMITIR ALBARANES
            // Ocultar solo botones de facturas PDF, mantener albaranes (packing-slip)
            $('.wc_actions .wpo_wcpdf.invoice').remove();
            $('.column-wc_actions a[href*="document_type=invoice"]').remove();
            $('.column-wc_actions a[href*="document_type=receipt"]').remove();
            $('.column-wc_actions a[href*="document_type=credit-note"]').remove();
            $('.column-wc_actions a[href*="document_type=proforma"]').remove();
            $('.column-wc_actions a[href*="document_type=delivery-note"]').remove();
            $('.column-wc_actions a[href*="document_type=simplified-invoice"]').remove();
            $('.column-wc_actions a[href*="document_type=exchange-invoice"]').remove();
            $('.column-wc_actions a[href*="document_type=simplified-credit-note"]').remove();
            $('.column-wc_actions a[alt*="Invoice"]').remove();
            $('.column-wc_actions a[alt*="Factura"]').remove();
            $('.column-wc_actions a[alt*="Simplified Credit Note"]').remove();
            $('.column-wc_actions a[alt*="Credit Note"]').remove();
            $('.button.wpo_wcpdf.invoice.pdf').remove();
            $('.button.wpo_wcpdf.receipt.pdf').remove();
            $('.button.wpo_wcpdf.credit-note.pdf').remove();
            $('.button.wpo_wcpdf.proforma.pdf').remove();
            $('.button.wpo_wcpdf.delivery-note.pdf').remove();
            $('.button.wpo_wcpdf.simplified-invoice.pdf').remove();
            $('.button.wpo_wcpdf.exchange-invoice.pdf').remove();
            $('.button.wpo_wcpdf.simplified-credit-note.pdf').remove();
            
            // Verificación adicional para elementos PDF de facturas que se carguen dinámicamente
            setInterval(function() {
                $('.wc_actions .wpo_wcpdf.invoice').remove();
                $('.column-wc_actions a[href*="document_type=invoice"]').remove();
                $('.column-wc_actions a[href*="document_type=receipt"]').remove();
                $('.column-wc_actions a[href*="document_type=credit-note"]').remove();
                $('.column-wc_actions a[href*="document_type=proforma"]').remove();
                $('.column-wc_actions a[href*="document_type=delivery-note"]').remove();
                $('.column-wc_actions a[href*="document_type=simplified-invoice"]').remove();
                $('.column-wc_actions a[href*="document_type=exchange-invoice"]').remove();
                $('.column-wc_actions a[href*="document_type=simplified-credit-note"]').remove();
                $('.column-wc_actions a[alt*="Invoice"]').remove();
                $('.column-wc_actions a[alt*="Factura"]').remove();
                $('.column-wc_actions a[alt*="Simplified Credit Note"]').remove();
                $('.column-wc_actions a[alt*="Credit Note"]').remove();
                // NOTA: NO remover packing-slip - Los order managers SÍ pueden usar albaranes
            }, 1000); // Verificar cada segundo

            // Remover enlaces del perfil de la barra de admin
            $('#wp-admin-bar-user-info').remove();
            $('#wp-admin-bar-edit-profile').remove();
            $('#wpadminbar #wp-admin-bar-user-actions #wp-admin-bar-user-info').remove();
            $('#wpadminbar #wp-admin-bar-user-actions #wp-admin-bar-edit-profile').remove();
            
            // Remover cualquier enlace que vaya a profile.php
            $('a[href*="profile.php"]').each(function() {
                $(this).remove();
            });
        });
        </script>
        <?php
    }

    /**
     * COPIA EXACTA de show_restricted_access_notice() del TeacherRoleManager
     * Muestra mensaje de acceso restringido para shop managers
     */
    function show_restricted_access_notice()
    {
        // Solo para order managers y solo si hay parámetro de restricción
        if (!$this->is_user('order_manager')) {
            return;
        }

        // Mensaje para acceso a páginas de plugins restringidas
        if (isset($_GET['restricted'])) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>' . __('Access Denied', 'neve-child') . '</strong></p>';
            echo '<p>' . __('You do not have permission to access this page. Order managers can access and manage all orders with full bulk actions.', 'neve-child') . '</p>';
            echo '</div>';
        }

        // Mensaje para acceso a pedidos individuales (legacy y HPOS)
        if (isset($_GET['individual_access_denied'])) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>' . __('Individual Order Access Denied', 'neve-child') . '</strong></p>';
            echo '<p>' . __('Order managers cannot access individual order details. You can only view the orders list and use bulk actions.', 'neve-child') . '</p>';
            echo '</div>';
        }

        // 🎯 NUEVO: Mensaje específico para pedidos individuales (no master orders) bloqueados
        if (isset($_GET['individual_order_access_denied'])) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>' . __('Individual Order Access Denied', 'neve-child') . '</strong></p>';
            echo '<p>' . __('Order managers can only access Master Orders. Individual orders are not accessible for your role. You can only view and manage Master Orders through the orders list.', 'neve-child') . '</p>';
            echo '</div>';
        }

        // 🚫 NUEVO: Mensaje para acceso denegado a edición de pedidos (maestros e individuales)
        if (isset($_GET['order_edit_access_denied'])) {
        }

        // Mensaje para bulk actions denegadas (específicamente pagos y PDFs)
        if (isset($_GET['bulk_action_denied'])) {
            $denied_action = isset($_GET['denied_action']) ? sanitize_text_field($_GET['denied_action']) : 'unknown';
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>' . __('Bulk Action Denied', 'neve-child') . '</strong></p>';
            echo '<p>' . sprintf(__('Order managers cannot use payment or PDF actions. The action "%s" is not permitted for your role.', 'neve-child'), $denied_action) . '</p>';
            echo '</div>';
        }
    }

    /**
     * COPIA EXACTA de remove_teacher_profile_admin_bar() del TeacherRoleManager
     * Remover enlaces del perfil de la barra de admin para shop managers
     */
    public function remove_shop_manager_profile_admin_bar($wp_admin_bar)
    {
        // Solo aplicar a order managers
        if (!$this->is_user('order_manager')) {
            return;
        }

        // Remover elementos específicos del perfil de usuario
        $wp_admin_bar->remove_node('user-info');       // Información del usuario
        $wp_admin_bar->remove_node('edit-profile');    // Editar mi perfil
        $wp_admin_bar->remove_node('user-actions');    // Todo el menú de acciones de usuario (si queremos ser más agresivos)
    }

    /**
     * Filtra las acciones en lote para order managers - PROHIBE solo pagos y facturas PDF
     * PERMITE albaranes (packing-slip) pero NO facturas (invoice) ni pagos
     */
    public function filter_shop_manager_bulk_actions($actions)
    {
        // Solo aplicar el filtro si el usuario actual es order manager
        if (!$this->is_user('order_manager')) {
            return $actions;
        }

        // Acciones específicamente PROHIBIDAS para order managers (facturas, pagos Y cambios de estado individuales)
        $forbidden_actions = array(
            'mark_bank_transfers_paid',   // 💰 Mark orders as paid
            'mark_bank_transfers_unpaid', // ❌ Mark orders as unpaid
            'invoice',                    // PDF Factura (prohibida)
            'receipt',                    // PDF Recibo
            'credit-note',                // PDF Nota de crédito
            'proforma',                   // PDF Proforma
            'delivery-note',              // PDF Nota de entrega
            'simplified-invoice',         // 🚫 PDF Factura Simplificada
            'exchange-invoice',           // 🚫 PDF Factura de Canje
            'simplified-credit-note',     // 🚫 PDF Factura Rectificativa Simplificada
            
            // 🚫 ESTADOS INDIVIDUALES - No aplicables a Master Orders
            'mark_processing',            // 🚫 Cambiar a Processing (individual)
            'mark_on-hold',               // 🚫 Cambiar a En Espera (individual)
            'mark_completed',             // 🚫 Cambiar a Completed (individual)
            'mark_warehouse',             // 🚫 Cambiar a Warehouse/Almacén (individual)
            'mark_prepared',              // 🚫 Cambiar a Prepared/Preparado (individual)
            'mark_cancelled',             // 🚫 Cambiar a Cancelled (individual)
            'mark_refunded',              // 🚫 Cambiar a Refunded (individual)
            'mark_failed',                // 🚫 Cambiar a Failed (individual)
            'mark_shipped',               // 🚫 Cambiar estado a Preparado (individual)
            'mark_reviewed',              // 🚫 Marcar como revisado (individual)
            'trash',                      // 🚫 Mover a papelera (no aplicable a master orders)
            // NOTA: 'packing-slip' NO está aquí - Los order managers SÍ pueden generar albaranes
            // NOTA: Los estados MASTER (mast-warehs, mast-prepared, etc.) SÍ están permitidos
        );

        // Remover solo las acciones prohibidas, mantener todas las demás incluyendo packing-slip
        foreach ($forbidden_actions as $forbidden_action) {
            unset($actions[$forbidden_action]);
        }

        return $actions;
    }

    /**
     * Intercepta el procesamiento de bulk actions para order managers - BLOQUEA pagos, facturas PDF Y cambios de estado
     * PERMITE albaranes (packing-slip) pero NO facturas (invoice), pagos ni cambios a processing/on-hold
     */
    public function intercept_shop_manager_bulk_actions($redirect_url, $action, $post_ids)
    {
        // Solo aplicar a order managers
        if (!$this->is_user('order_manager')) {
            return $redirect_url;
        }

        // Acciones específicamente PROHIBIDAS para order managers (facturas, pagos Y cambios de estado individuales)
        $forbidden_actions = array(
            'mark_bank_transfers_paid',   // 💰 Mark orders as paid
            'mark_bank_transfers_unpaid', // ❌ Mark orders as unpaid
            'invoice',                    // PDF Factura (prohibida)
            'receipt',                    // PDF Recibo
            'credit-note',                // PDF Nota de crédito
            'proforma',                   // PDF Proforma
            'delivery-note',              // PDF Nota de entrega
            'simplified-invoice',         // 🚫 PDF Factura Simplificada
            'exchange-invoice',           // 🚫 PDF Factura de Canje
            'simplified-credit-note',     // 🚫 PDF Factura Rectificativa Simplificada
            
            // 🚫 ESTADOS INDIVIDUALES - No aplicables a Master Orders
            'mark_processing',            // 🚫 Cambiar a Processing (individual)
            'mark_on-hold',               // 🚫 Cambiar a En Espera (individual)
            'mark_completed',             // 🚫 Cambiar a Completed (individual)
            'mark_warehouse',             // 🚫 Cambiar a Warehouse/Almacén (individual)
            'mark_prepared',              // 🚫 Cambiar a Prepared/Preparado (individual)
            'mark_cancelled',             // 🚫 Cambiar a Cancelled (individual)
            'mark_refunded',              // 🚫 Cambiar a Refunded (individual)
            'mark_failed',                // 🚫 Cambiar a Failed (individual)
            'mark_shipped',               // 🚫 Cambiar estado a Preparado (individual)
            'mark_reviewed',              // 🚫 Marcar como revisado (individual)
            'trash',                      // 🚫 Mover a papelera (no aplicable a master orders)
            // NOTA: 'packing-slip' NO está aquí - Los order managers SÍ pueden generar albaranes
            // NOTA: Los estados MASTER (mast-warehs, mast-prepared, etc.) SÍ están permitidos
        );

        // Solo bloquear las acciones prohibidas
        if (in_array($action, $forbidden_actions)) {
            // Bloquear la acción y redirigir con error
            $redirect_url = add_query_arg(
                array(
                    'bulk_action_denied' => 1,
                    'denied_action' => $action
                ),
                $redirect_url
            );
            
            // Importante: No procesar la acción, solo redirigir
            wp_redirect($redirect_url);
            exit;
        }

        // Si no está en la lista prohibida, permitir que continúe normalmente
        return $redirect_url;
    }

    /**
     * Bloquea el procesamiento de bulk actions prohibidas a nivel de POST - SOLO pagos y facturas PDF
     * PERMITE albaranes (packing-slip) pero NO facturas (invoice) ni pagos
     */
    public function block_shop_manager_bulk_action_processing()
    {
        // Solo aplicar a order managers
        if (!$this->is_user('order_manager')) {
            return;
        }

        // Verificar si se está enviando una bulk action
        $action = '';
        if (isset($_POST['action']) && $_POST['action'] !== '-1') {
            $action = $_POST['action'];
        } elseif (isset($_POST['action2']) && $_POST['action2'] !== '-1') {
            $action = $_POST['action2'];
        }

        // Acciones específicamente PROHIBIDAS para order managers (facturas, pagos Y estados individuales)
        $forbidden_actions = array(
            'mark_bank_transfers_paid',   // 💰 Mark orders as paid
            'mark_bank_transfers_unpaid', // ❌ Mark orders as unpaid
            'invoice',                    // PDF Factura (prohibida)
            'receipt',                    // PDF Recibo
            'credit-note',                // PDF Nota de crédito
            'proforma',                   // PDF Proforma
            'delivery-note',              // PDF Nota de entrega
            
            // 🚫 ESTADOS INDIVIDUALES - No aplicables a Master Orders
            'mark_processing',            // 🚫 Cambiar a Processing (individual)
            'mark_on-hold',               // 🚫 Cambiar a En Espera (individual)
            'mark_completed',             // 🚫 Cambiar a Completed (individual)
            'mark_warehouse',             // 🚫 Cambiar a Warehouse/Almacén (individual)
            'mark_prepared',              // 🚫 Cambiar a Prepared/Preparado (individual)
            'mark_cancelled',             // 🚫 Cambiar a Cancelled (individual)
            'mark_refunded',              // 🚫 Cambiar a Refunded (individual)
            'mark_failed',                // 🚫 Cambiar a Failed (individual)
            'mark_shipped',               // 🚫 Cambiar estado a Preparado (individual)
            'mark_reviewed',              // 🚫 Marcar como revisado (individual)
            'trash',                      // 🚫 Mover a papelera (no aplicable a master orders)
            // NOTA: 'packing-slip' NO está aquí - Los order managers SÍ pueden generar albaranes
        );

        // Si hay una acción y está en la lista prohibida
        if (!empty($action) && in_array($action, $forbidden_actions)) {
            // Redirigir inmediatamente con mensaje de error
            $redirect_url = remove_query_arg(array('action', 'action2'));
            $redirect_url = add_query_arg(
                array(
                    'bulk_action_denied' => 1,
                    'denied_action' => $action
                ),
                $redirect_url
            );
            
            wp_redirect($redirect_url);
            exit;
        }
    }

    /**
     * Bloquea llamadas AJAX prohibidas para order managers - PROTECCIÓN AJAX
     */
    public function block_shop_manager_ajax_actions()
    {
        // Solo aplicar a order managers
        if (!$this->is_user('order_manager')) {
            return;
        }

        // Enviar respuesta de error y terminar
        wp_send_json_error(array(
            'message' => __('Access denied: Order managers cannot use payment or PDF functions.', 'neve-child'),
            'code' => 'order_manager_forbidden_action'
        ));
    }

    /**
     * Bloquea accesos directos por URL a funcionalidades prohibidas - PROTECCIÓN COMPLETA
     * PERMITE albaranes (packing-slip) pero NO facturas (invoice) ni pagos
     */
    public function block_shop_manager_direct_url_access()
    {
        // Solo aplicar a order managers
        if (!$this->is_user('order_manager')) {
            return;
        }

        // Verificar parámetros en la URL que indiquen acciones prohibidas (facturas, pagos Y estados individuales)
        $forbidden_url_params = array(
            'mark_bank_transfers_paid',
            'mark_bank_transfers_unpaid', 
            'invoice',                    // PDF Factura (prohibida)
            'receipt',                    // PDF Recibo
            'credit-note',                // PDF Nota de crédito
            'proforma',                   // PDF Proforma
            'delivery-note',              // PDF Nota de entrega
            
            // 🚫 ESTADOS INDIVIDUALES - No aplicables a Master Orders
            'mark_processing',            // 🚫 Cambiar a Processing (individual)
            'mark_on-hold',               // 🚫 Cambiar a En Espera (individual)
            'mark_completed',             // 🚫 Cambiar a Completed (individual)
            'mark_warehouse',             // 🚫 Cambiar a Warehouse/Almacén (individual)
            'mark_prepared',              // 🚫 Cambiar a Prepared/Preparado (individual)
            'mark_cancelled',             // 🚫 Cambiar a Cancelled (individual)
            'mark_refunded',              // 🚫 Cambiar a Refunded (individual)
            'mark_failed',                // 🚫 Cambiar a Failed (individual)
            'mark_shipped',               // 🚫 Marcar como enviado (individual)
            'mark_reviewed',              // 🚫 Marcar como revisado (individual)
            'trash'                       // 🚫 Mover a papelera (individual)
            // NOTA: 'packing-slip' NO está aquí - Los order managers SÍ pueden usar albaranes
            // NOTA: 'wpo_wcpdf_generate_pdf' se evalúa caso por caso abajo
        );

        // Verificar todos los parámetros GET
        foreach ($_GET as $param => $value) {
            // Si el parámetro contiene alguna acción prohibida
            foreach ($forbidden_url_params as $forbidden) {
                if (is_string($param) && is_string($value) && 
                    (strpos($param, $forbidden) !== false || strpos($value, $forbidden) !== false)) {
                    // Bloquear inmediatamente con mensaje de error
                    wp_redirect(admin_url('edit.php?post_type=shop_order&bulk_action_denied=1&denied_action=' . urlencode($forbidden)));
                    exit;
                }
            }
        }

        // Verificación especial para wpo_wcpdf_generate_pdf - solo bloquear si es para facturas
        if (isset($_GET['wpo_wcpdf_generate_pdf']) || isset($_GET['action']) && $_GET['action'] === 'generate_wpo_wcpdf') {
            $document_type = isset($_GET['document_type']) ? $_GET['document_type'] : '';
            
            // Solo bloquear si es para generar facturas, permitir albaranes
            if (in_array($document_type, ['invoice', 'receipt', 'credit-note', 'proforma', 'delivery-note', 'simplified-invoice', 'exchange-invoice', 'simplified-credit-note'])) {
                wp_redirect(admin_url('edit.php?post_type=shop_order&bulk_action_denied=1&denied_action=pdf_invoice_blocked'));
                exit;
            }
        }

        // Verificar URLs específicas de plugins PDF
        if (is_admin() && isset($_GET['page'])) {
            $blocked_pages = array(
                'wpo_wcpdf_options_page',
                'wc-pdf-invoices'
            );
            
            if (in_array($_GET['page'], $blocked_pages)) {
                wp_redirect(admin_url('index.php?restricted=1'));
                exit;
            }
        }
    }

    /**
     * Protección adicional contra parámetros URL maliciosos - VERIFICACIÓN EN ADMIN_INIT
     * PERMITE albaranes (packing-slip) pero NO facturas (invoice) ni pagos
     */
    public function block_shop_manager_url_parameters()
    {
        // Solo aplicar a order managers
        if (!$this->is_user('order_manager')) {
            return;
        }

        // Verificar si están intentando acceder a funcionalidades de PDF por URL
        if (isset($_GET['action']) || isset($_GET['bulk_action']) || isset($_GET['action2'])) {
            $actions_to_check = array();
            
            if (isset($_GET['action'])) $actions_to_check[] = $_GET['action'];
            if (isset($_GET['bulk_action'])) $actions_to_check[] = $_GET['bulk_action'];
            if (isset($_GET['action2'])) $actions_to_check[] = $_GET['action2'];

            // Acciones específicamente PROHIBIDAS para order managers (facturas, pagos Y estados individuales)
            $forbidden_actions = array(
                'mark_bank_transfers_paid',
                'mark_bank_transfers_unpaid',
                'invoice',                    // PDF Factura (prohibida)
                'receipt',                    // PDF Recibo
                'credit-note',                // PDF Nota de crédito
                'proforma',                   // PDF Proforma
                'delivery-note',              // PDF Nota de entrega
                
                // 🚫 ESTADOS INDIVIDUALES - No aplicables a Master Orders
                'mark_processing',            // 🚫 Cambiar a Processing (individual)
                'mark_on-hold',               // 🚫 Cambiar a En Espera (individual)
                'mark_completed',             // 🚫 Cambiar a Completed (individual)
                'mark_warehouse',             // 🚫 Cambiar a Warehouse/Almacén (individual)
                'mark_prepared',              // 🚫 Cambiar a Prepared/Preparado (individual)
                'mark_cancelled',             // 🚫 Cambiar a Cancelled (individual)
                'mark_refunded',              // 🚫 Cambiar a Refunded (individual)
                'mark_failed',                // 🚫 Cambiar a Failed (individual)
                'mark_shipped',               // 🚫 Marcar como enviado (individual)
                'mark_reviewed',              // 🚫 Marcar como revisado (individual)
                'trash'                       // 🚫 Mover a papelera (individual)
                // NOTA: 'packing-slip' NO está aquí - Los order managers SÍ pueden usar albaranes
            );

            foreach ($actions_to_check as $action) {
                if (in_array($action, $forbidden_actions)) {
                    wp_redirect(admin_url('edit.php?post_type=shop_order&bulk_action_denied=1&denied_action=' . urlencode($action)));
                    exit;
                }
            }

            // Verificación especial para wpo_wcpdf_generate_pdf - solo bloquear facturas
            if (in_array('wpo_wcpdf_generate_pdf', $actions_to_check) || isset($_GET['action']) && $_GET['action'] === 'generate_wpo_wcpdf') {
                $document_type = isset($_GET['document_type']) ? $_GET['document_type'] : '';
                
                // Solo bloquear si es para generar facturas, permitir albaranes
                if (in_array($document_type, ['invoice', 'receipt', 'credit-note', 'proforma', 'delivery-note', 'simplified-invoice', 'exchange-invoice', 'simplified-credit-note'])) {
                    wp_redirect(admin_url('edit.php?post_type=shop_order&bulk_action_denied=1&denied_action=pdf_invoice_blocked'));
                    exit;
                }
            }
        }

        // Verificar acceso directo a archivos PDF generados - solo bloquear facturas
        if (isset($_GET['download_pdf']) || isset($_GET['pdf']) || isset($_GET['generate_pdf'])) {
            $document_type = isset($_GET['document_type']) ? $_GET['document_type'] : '';
            
            // Solo bloquear descargas de facturas, permitir albaranes
            if (empty($document_type) || in_array($document_type, ['invoice', 'receipt', 'credit-note', 'proforma', 'delivery-note', 'simplified-invoice', 'exchange-invoice', 'simplified-credit-note'])) {
                wp_redirect(admin_url('edit.php?post_type=shop_order&bulk_action_denied=1&denied_action=pdf_access'));
                exit;
            }
        }
    }

    /**
     * Verifica si el usuario actual tiene un rol específico - FUNCIÓN HELPER
     */
    private function is_user($role)
    {
        $user = wp_get_current_user();
        return in_array($role, (array) $user->roles);
    }
}
 
<?php

namespace SchoolManagement\Users;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Teacher Role Manager - EXACTA RÉPLICA DE COO_ROLE.PHP
 * 
 * Gestiona roles de profesor y sus permisos en el sistema escolar
 * REPLICA EXACTA del funcionamiento de coo_role.php
 * 
 * @package SchoolManagement\Users
 * @since 1.0.0
 */
class TeacherRoleManager
{
    /**
     * Constructor - IDÉNTICO A COO_ROLE.PHP
     */
    public function __construct()
    {
        add_action('init', array($this, 'dm_registrar_roles_alumno_profesor'));
        add_filter('woocommerce_prevent_admin_access', array($this, 'teacher_allow_admin_to_role'));
        add_filter('woocommerce_disable_admin_bar', array($this, 'teacher_allow_admin_to_role'));
        add_action('wp_dashboard_setup', array($this, 'teacher_dashboard_widgets'), 999);

        // Filtrar los pedidos que ve un profesor en el panel de administración
        add_filter('woocommerce_order_list_table_prepare_items_query_args', array($this, 'filter_orders_by_teacher_school'), 10);
        add_filter('woocommerce_reports_get_order_report_query', array($this, 'filter_order_reports_by_teacher_school'));

        // Filtrar usuarios visibles en el admin para profesores
        add_action('pre_get_users', array($this, 'filter_users_list_for_teacher'));

        // Filtrar los enlaces de roles en el admin de usuarios para profesores
        add_filter('views_users', array($this, 'filter_user_role_links_for_teacher'));

        // Restringir acceso a pedidos individuales
        add_action('admin_init', array($this, 'restrict_teacher_shop_order_access'));

        // Restringir acceso a usuarios individuales
        add_action('admin_init', array($this, 'restrict_teacher_user_access'));

        // Restringir acceso a páginas específicas de plugins
        add_action('admin_init', array($this, 'restrict_teacher_plugin_pages'));

        // Ocultar elementos del menú admin para profesores
        add_action('admin_menu', array($this, 'remove_admin_menu_items_for_teachers'), 999);

        // Remover específicamente menús de plugins (prioridad más alta)
        add_action('admin_menu', array($this, 'remove_plugin_menu_items_for_teachers'), 9999);

        // Hook adicional para capturar menús registrados muy tarde
        add_action('admin_head', array($this, 'final_menu_cleanup_for_teachers'));

        // CSS adicional para ocultar elementos como último recurso
        add_action('admin_head', array($this, 'hide_restricted_elements_css'));

        // Mostrar mensaje de acceso restringido si es necesario
        add_action('admin_notices', array($this, 'show_restricted_access_notice'));

        // Remover enlaces del perfil de la barra de admin para profesores
        add_action('admin_bar_menu', array($this, 'remove_teacher_profile_admin_bar'), 999);

        // Agregar página personalizada para editar datos ACF de usuarios
        add_action('admin_menu', array($this, 'add_teacher_user_acf_edit_page'));
        
        // Manejar el guardado de datos ACF de usuarios
        add_action('admin_init', array($this, 'handle_teacher_user_acf_save'));
        
        // Modificar acciones de fila en lista de usuarios para profesores
        add_filter('user_row_actions', array($this, 'modify_user_row_actions_for_teachers'), 10, 2);

        // NUEVO: Filtrar acciones individuales de fila en órdenes para profesores
        add_filter('woocommerce_admin_order_actions', array($this, 'filter_teacher_order_actions'), 10, 2);

        // Filtrar bulk actions para profesores (detectar página automáticamente)
        add_action('current_screen', array($this, 'register_teacher_bulk_action_filters'), 999);
        
        // Solo registrar interceptores para usuarios teacher
        if ($this->is_user('teacher')) {
            // Interceptar el procesamiento de bulk actions antes de que se ejecuten
            add_filter('handle_bulk_actions-edit-shop_order', array($this, 'intercept_teacher_bulk_actions'), 10, 3);
            add_filter('handle_bulk_actions-woocommerce_page_wc-orders', array($this, 'intercept_teacher_bulk_actions'), 10, 3);
            
            // Proteger contra POST directo de bulk actions
            add_action('admin_init', array($this, 'block_teacher_bulk_action_processing'), 5);
            
            // Cargar JavaScript para limpiar duplicados en bulk actions
            add_action('admin_enqueue_scripts', array($this, 'enqueue_teacher_bulk_actions_cleanup_script'));
        }
        
        // PROTEGER CONTRA ACCESOS DIRECTOS POR URL - Interceptar cualquier intento
        add_action('wp_loaded', array($this, 'block_teacher_direct_url_access'), 1);
        add_action('admin_init', array($this, 'block_teacher_url_parameters'), 1);
        
        // PROTEGER CONTRA ACCESOS AJAX PROHIBIDOS - Bloquear llamadas AJAX
        add_action('wp_ajax_wpo_wcpdf_generate_pdf', array($this, 'block_teacher_ajax_actions'), 1);
        add_action('wp_ajax_mark_bank_transfer_paid', array($this, 'block_teacher_ajax_actions'), 1);
        add_action('wp_ajax_mark_bank_transfer_unpaid', array($this, 'block_teacher_ajax_actions'), 1);
    }

    /**
     * Permitimos a ciertos roles acceder al Admin - IDÉNTICO A COO_ROLE.PHP
     */
    function teacher_allow_admin_to_role($prevent_access)
    {
        // Si no es profesor, devolvemos el valor original
        if (!$this->is_user('teacher')) {
            return $prevent_access;
        }
        // Si es profesor, permitimos el acceso al admin
        return false;
    }

    /**
     * Filtra el listado de usuarios para profesores: solo ven estudiantes y profesores de su centro
     */
    public function filter_users_list_for_teacher($query)
    {
        // Solo en admin, solo para profesores, no super admins
        if (!is_admin() || !$this->is_user('teacher') || is_super_admin(get_current_user_id())) {
            return;
        }

        // Solo en la pantalla de usuarios
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->id !== 'users') {
            return;
        }

        // Mostrar usuarios con rol student y teacher
        $query->set('role__in', array('student', 'teacher'));

        // Filtrar por school_id igual al del profesor
        $teacher_school_id = get_user_meta(get_current_user_id(), 'school_id', true);
        if ($teacher_school_id) {
            $meta_query = $query->get('meta_query') ?: array();
            $meta_query[] = array(
                'key' => 'school_id',
                'value' => $teacher_school_id,
                'compare' => '='
            );
            $query->set('meta_query', $meta_query);
        } else {
            // Si el profesor no tiene centro, no mostrar nada
            $query->set('meta_query', array(array(
                'key' => '_non_existent_key',
                'compare' => 'NOT EXISTS'
            )));
        }
    }

    /**
     * Filtra los enlaces de roles en el admin de usuarios para profesores
     * Solo muestra "Todos", "Student" y "Teacher" con conteos de su centro
     */
    public function filter_user_role_links_for_teacher($views)
    {
        // Solo aplicar a profesores, no super admins
        if (!$this->is_user('teacher') || is_super_admin(get_current_user_id())) {
            return $views;
        }

        // Solo en la pantalla de usuarios
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'users') {
            return $views;
        }

        // Obtener el school_id del profesor
        $teacher_school_id = get_user_meta(get_current_user_id(), 'school_id', true);
        if (!$teacher_school_id) {
            // Si no tiene centro, mostrar enlaces vacíos
            return array(
                'all' => '<a href="users.php" class="current" aria-current="page">' . __('All', 'neve-child') . ' <span class="count">(0)</span></a>',
            );
        }

        // Crear nuevos enlaces filtrados
        $filtered_views = array();

        // Contar usuarios de su centro por rol
        $students_count = $this->count_users_by_role_and_school('student', $teacher_school_id);
        $teachers_count = $this->count_users_by_role_and_school('teacher', $teacher_school_id);
        $total_count = $students_count + $teachers_count;

        // Obtener el rol actual seleccionado
        $current_role = isset($_GET['role']) ? $_GET['role'] : '';

        // Enlace "Todos" - siempre presente
        $all_class = empty($current_role) ? 'current' : '';
        $all_aria = empty($current_role) ? ' aria-current="page"' : '';
        $filtered_views['all'] = '<a href="users.php" class="' . $all_class . '"' . $all_aria . '>' . 
                                __('All', 'neve-child') . ' <span class="count">(' . $total_count . ')</span></a>';

        // Enlace "Student" - solo si hay estudiantes
        if ($students_count > 0) {
            $student_class = ($current_role === 'student') ? 'current' : '';
            $student_aria = ($current_role === 'student') ? ' aria-current="page"' : '';
            $filtered_views['student'] = '<a href="users.php?role=student" class="' . $student_class . '"' . $student_aria . '>' . 
                                        __('Student', 'neve-child') . ' <span class="count">(' . $students_count . ')</span></a>';
        }

        // Enlace "Teacher" - solo si hay profesores (además del actual)
        if ($teachers_count > 0) {
            $teacher_class = ($current_role === 'teacher') ? 'current' : '';
            $teacher_aria = ($current_role === 'teacher') ? ' aria-current="page"' : '';
            $filtered_views['teacher'] = '<a href="users.php?role=teacher" class="' . $teacher_class . '"' . $teacher_aria . '>' . 
                                        __('Teacher', 'neve-child') . ' <span class="count">(' . $teachers_count . ')</span></a>';
        }

        return $filtered_views;
    }

    /**
     * Cuenta usuarios por rol y centro escolar
     */
    private function count_users_by_role_and_school($role, $school_id)
    {
        $users = get_users(array(
            'role' => $role,
            'meta_key' => 'school_id',
            'meta_value' => $school_id,
            'fields' => 'ID'
        ));
        
        return count($users);
    }

    /**
     * Registra los roles "Alumno" y "Profesor" - IDÉNTICO A COO_ROLE.PHP
     */
    function dm_registrar_roles_alumno_profesor()
    {
        // 1) Alumno: clonar capacidades de "customer"
        $customer = get_role('customer');
        
        //    if (get_role('student')) {
        //     remove_role('student');
        // }

        if ($customer && !get_role('student')) {
            add_role(
                'student',                              // slug interno
                __('Student', 'neve-child'),           // nombre traducible
                $customer->capabilities
            );
        }

        // 2) Profesor: FORZAR RECREACIÓN DEL ROL
        // Primero eliminamos el rol si existe para forzar actualización
     
        // Crear el rol teacher con capacidades para LISTADO pero NO edición individual
        add_role(
            'teacher',
            __('Teacher', 'neve-child'),
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
                
                // Capacidades para ACCEDER al admin de usuarios (listado)
                'list_users' => true,                    // Ver listado de usuarios
                'edit_users' => true,                    // Necesario para acceder al menú de usuarios
                'create_users' => false,                 // NO crear usuarios nuevos
                'delete_users' => false,                 // NO eliminar usuarios
                'promote_users' => false,                // NO cambiar roles de usuarios
                
                // NOTA: La restricción de edición individual se maneja vía filtros personalizados
                // Estas capacidades permiten acceso al admin pero los filtros limitan qué ven
                
                // REMOVIDO: Capacidades de gestión/creación de pedidos
                // 'publish_shop_orders' => false,        // NO publicar pedidos nuevos
                // 'delete_shop_orders' => false,         // NO eliminar pedidos
                // 'delete_shop_order' => false,          // NO eliminar pedido individual
                // 'delete_others_shop_orders' => false,  // NO eliminar pedidos de otros
                // 'manage_shop_orders' => false,         // NO gestionar (crear nuevos)
            )
        );
    }

    /**
     * Filtra los pedidos en la lista de WooCommerce - IDÉNTICO A COO_ROLE.PHP
     */
    function filter_orders_by_teacher_school($query_args)
    {
        // Solo aplicamos filtro para profesores
        if (!$this->is_user('teacher') || is_super_admin(get_current_user_id())) {
            return $query_args;
        }

        // Obtenemos el ID del centro del profesor
        $teacher_school_id = get_user_meta(get_current_user_id(), 'school_id', true);
        if (!$teacher_school_id) {
            // Si no hay centro, no mostramos ningún pedido
            $query_args['meta_query'][] = [
                'key' => '_non_existent_key',
                'compare' => 'NOT EXISTS'
            ];
            return $query_args;
        }

        // Inicializar meta_query si no existe
        if (!isset($query_args['meta_query'])) {
            $query_args['meta_query'] = [];
        }

        // Establecer relación AND para los meta queries
        $query_args['meta_query']['relation'] = 'AND';

        // Filtrar por centro escolar
        $query_args['meta_query'][] = [
            'key' => '_school_id',
            'value' => $teacher_school_id,
            'compare' => '='
        ];

        // Excluir master orders - Los profesores solo ven pedidos individuales
        $query_args['meta_query'][] = [
            'relation' => 'OR',
            [
                'key' => '_is_master_order',
                'compare' => 'NOT EXISTS'
            ],
            [
                'key' => '_is_master_order',
                'value' => 'yes',
                'compare' => '!='
            ]
        ];

        // PERMITIR TODOS LOS ESTADOS - Los profesores pueden ver todos los estados de pedidos de su colegio
        // REMOVIDO: $query_args['post_status'] = 'wc-on-hold';

        return $query_args;
    }

    /**
     * Filtra los reportes de pedidos - IDÉNTICO A COO_ROLE.PHP
     */
    function filter_order_reports_by_teacher_school($query)
    {
        // Solo aplicamos filtro para profesores
        if (!$this->is_user('teacher') || is_super_admin(get_current_user_id())) {
            return $query;
        }

        // Obtenemos el ID del centro del profesor
        $teacher_school_id = get_user_meta(get_current_user_id(), 'school_id', true);
        if (!$teacher_school_id) {
            return $query;
        }

        // Añadimos condición para filtrar por centro escolar en reportes
        global $wpdb;

        if (isset($query['where']) && is_string($query['where'])) {
            $query['where'] .= $wpdb->prepare(
                " AND meta__school_id.meta_key = '_school_id' AND meta__school_id.meta_value = %s",
                $teacher_school_id
            );

            if (!strstr($query['join'], 'meta__school_id')) {
                $query['join'] .= " LEFT JOIN {$wpdb->prefix}postmeta meta__school_id ON posts.ID = meta__school_id.post_id";
            }
        }

        return $query;
    }

    /**
     * Restringe SOLO el acceso a pedidos individuales, NO al admin/listado
     * Incluye tanto sistema legacy (post.php) como HPOS (admin.php?page=wc-orders&action=edit)
     */
    function restrict_teacher_shop_order_access()
    {
        global $pagenow;

        // Solo aplicar a profesores
        if (!$this->is_user('teacher')) {
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

            // BLOQUEAR: Editar/ver pedido individual existente
            if ($pagenow == 'post.php' && $post_id > 0) {
                $post = get_post($post_id);
                if ($post && $post->post_type === 'shop_order') {
                    // Es un pedido individual - BLOQUEAR
                    wp_redirect(admin_url('edit.php?post_type=shop_order&individual_access_denied=1'));
                    exit;
                }
            }
        }

        // Verificar acceso a pedidos individuales en sistema HPOS (admin.php?page=wc-orders)
        if (is_admin() && $pagenow == 'admin.php') {
            $page = isset($_GET['page']) ? $_GET['page'] : '';
            $action = isset($_GET['action']) ? $_GET['action'] : '';
            $order_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

            // BLOQUEAR: Acceso a pedido individual en HPOS
            if ($page === 'wc-orders' && $action === 'edit' && $order_id > 0) {
                // Es un pedido individual en sistema HPOS - BLOQUEAR
                wp_redirect(admin_url('admin.php?page=wc-orders&individual_access_denied=1'));
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
     * Restringe COMPLETAMENTE el acceso a usuarios individuales
     * Los profesores NO pueden editar ningún perfil (ni propio ni ajeno)
     */
    function restrict_teacher_user_access()
    {
        global $pagenow;

        // Solo aplicar a profesores
        if (!$this->is_user('teacher')) {
            return;
        }

        // BLOQUEAR COMPLETAMENTE: Acceso a usuarios individuales (user-edit.php y user-new.php)
        if (is_admin() && ($pagenow == 'user-edit.php' || $pagenow == 'user-new.php')) {
            // BLOQUEAR: Crear nuevo usuario
            if ($pagenow == 'user-new.php') {
                wp_redirect(admin_url('users.php?individual_user_access_denied=1'));
                exit;
            }

            // BLOQUEAR: Editar CUALQUIER usuario individual (incluyendo el propio)
            if ($pagenow == 'user-edit.php') {
                wp_redirect(admin_url('users.php?individual_user_access_denied=1'));
                exit;
            }
        }

        // PERMITIR acceso al perfil propio (profile.php) - Los profesores necesitan acceso a su perfil
        // Código de bloqueo removido para permitir acceso al perfil
    }

    /**
     * Limpiamos widgets del teacher dashboard - IDÉNTICO A COO_ROLE.PHP
     */
    function teacher_dashboard_widgets()
    {
        if ($this->is_user('teacher')) {
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

            // Añadir un widget personalizado para el profesor
            add_meta_box(
                'teacher_school_info',
                __('School Information', 'neve-child'),
                [$this, 'display_teacher_school_widget'],
                'dashboard',
                'normal',
                'high'
            );
        }
    }

    /**
     * Muestra el widget con información del centro del profesor - IDÉNTICO A COO_ROLE.PHP
     */
    function display_teacher_school_widget()
    {
        $teacher_id = get_current_user_id();
        $school_id = get_user_meta($teacher_id, 'school_id', true);

        if (!$school_id) {
            echo '<p>' . __('You do not have an assigned school. Please contact the administrator.', 'neve-child') . '</p>';
            return;
        }

        $school = get_post($school_id);
        if (!$school) {
            echo '<p>' . __('Could not find your school information.', 'neve-child') . '</p>';
            return;
        }

        // Contar pedidos del centro
        global $wpdb;
        $count_query = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p 
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
            WHERE p.post_type = 'shop_order' 
            AND p.post_status IN ('wc-processing', 'wc-completed') 
            AND pm.meta_key = '_school_id' 
            AND pm.meta_value = %s",
            $school_id
        );
        $orders_count = $wpdb->get_var($count_query);

        echo '<div class="teacher-school-info">';
        echo '<h3>' . esc_html($school->post_title) . '</h3>';
        echo '<p><strong>' . __('School key:', 'neve-child') . '</strong> ' . esc_html(get_post_meta($school_id, 'school_key', true)) . '</p>';
        echo '<p><strong>' . __('Managed orders:', 'neve-child') . '</strong> ' . intval($orders_count) . '</p>';

        // Enlace a pedidos del centro
        echo '<p><a href="' . esc_url(admin_url('edit.php?post_type=shop_order')) . '" class="button button-primary">';
        echo __('View school orders', 'neve-child');
        echo '</a></p>';
        echo '</div>';
    }

    /**
     * Restringe el acceso a páginas específicas de plugins para profesores
     */
    function restrict_teacher_plugin_pages()
    {
        // Solo aplicar restricciones a profesores
        if (!$this->is_user('teacher') || is_super_admin()) {
            return;
        }

        // Obtener la página actual
        $current_page = isset($_GET['page']) ? $_GET['page'] : '';

        // Lista de páginas de plugins bloqueadas para profesores
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
     * Remueve elementos del menú admin - IDÉNTICO A COO_ROLE.PHP
     */
    function remove_admin_menu_items_for_teachers()
    {
        // Solo para profesores, no para super admins
        if (!$this->is_user('teacher') || is_super_admin()) {
            return;
        }

        global $menu, $submenu;

        // Remover TODOS los menús principales de WordPress EXCEPTO shop_order y usuarios
        remove_menu_page('edit.php');                          // Posts
        remove_menu_page('upload.php');                        // Media
        remove_menu_page('edit.php?post_type=page');           // Páginas
        remove_menu_page('edit-comments.php');                 // Comentarios
        remove_menu_page('themes.php');                        // Apariencia
        remove_menu_page('plugins.php');                       // Plugins
        remove_menu_page('tools.php');                         // Herramientas
        remove_menu_page('options-general.php');               // Ajustes
        // MANTENER profile.php - Los profesores necesitan acceso a su perfil

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

        // Remover cualquier otro menú no deseado pero mantener WooCommerce, pedidos y usuarios
        foreach ($menu as $key => $item) {
            if (isset($item[2])) {
                $menu_slug = $item[2];
                // Solo mantener Dashboard, WooCommerce, Pedidos y Usuarios (NO perfil)
                if (
                    !in_array($menu_slug, [
                        'index.php',                        // Dashboard
                        'woocommerce',                      // WooCommerce principal
                        'edit.php?post_type=shop_order',   // Pedidos
                        'users.php'                         // Usuarios (admin de usuarios)
                    ])
                ) {
                    remove_menu_page($menu_slug);
                }
            }
        }
    }

    /**
     * Remover específicamente elementos de menú de plugins (con prioridad alta)
     */
    function remove_plugin_menu_items_for_teachers()
    {
        // Solo para profesores, no para super admins
        if (!$this->is_user('teacher') || is_super_admin()) {
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
     * Limpieza final de menús para profesores (se ejecuta en admin_head)
     */
    function final_menu_cleanup_for_teachers()
    {
        // Solo para profesores, no para super admins
        if (!$this->is_user('teacher') || is_super_admin()) {
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
     * Ocultar elementos restringidos usando CSS como último recurso
     */
    function hide_restricted_elements_css()
    {
        // Solo para profesores, no para super admins
        if (!$this->is_user('teacher') || is_super_admin()) {
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

        /* Ocultar por texto si contiene palabras clave específicas */
        #adminmenu .wp-submenu li:has(a):not(:has(a[href*="order"])):not(:has(a[href*="pedido"])) {
            /* CSS moderno para navegadores compatibles */
        }

        /* Fallback para navegadores más antiguos */
        #adminmenu .wp-submenu a:not([href*="order"]):not([href*="pedido"]) {
            /* Mantener solo enlaces relacionados con pedidos */
        }
        
        /* Ocultar específicamente elementos de PDF si aparecen */
        #adminmenu .wp-submenu li a:contains("PDF"),
        #adminmenu .wp-submenu li a:contains("Invoice"),
        #adminmenu .wp-submenu li a:contains("Packing"),
        #adminmenu .wp-submenu li a:contains("Settings"):not(:contains("Order")),
        #adminmenu .wp-submenu li a:contains("Status"):not(:contains("Order")) {
            display: none !important;
        }

        /* Ocultar enlaces de edición de usuarios NATIVOS de WordPress (reemplazados por enlaces ACF) */
        .wp-list-table.users .row-actions .edit:not(.edit_acf_data),
        .wp-list-table.users .row-actions .view,
        .wp-list-table.users .column-username a[href*="user-edit.php"],
        .wp-list-table.users .column-name a[href*="user-edit.php"] {
            display: none !important;
        }

        /* Hacer que los nombres de usuarios no sean clicables (mantener como texto) */
        .wp-list-table.users .column-username strong a,
        .wp-list-table.users .column-name strong a {
            pointer-events: none !important;
            color: inherit !important;
            text-decoration: none !important;
        }

        /* Mostrar y estilizar enlaces ACF personalizados */
        .wp-list-table.users .row-actions .edit_acf_data {
            display: inline !important;
        }

        .wp-list-table.users .row-actions .edit_acf_data a {
            color: #2271b1 !important;
            text-decoration: none !important;
        }

        .wp-list-table.users .row-actions .edit_acf_data a:hover {
            color: #135e96 !important;
            text-decoration: underline !important;
        }

        /* MANTENER enlaces del perfil en la barra de admin - Los profesores necesitan acceso */
        /* Reglas CSS removidas para permitir acceso al perfil */

        /* OCULTAR BOTONES PDF EN COLUMNA DE ACCIONES - SOLO MOSTRAR ALBARÁN */
        /* Profesores SOLO pueden ver botón de albarán (packing-slip), NO facturas ni otros PDFs */
        .wc_actions .wpo_wcpdf.invoice,
        .wc_actions .wpo_wcpdf.receipt,
        .wc_actions .wpo_wcpdf.credit-note,
        .wc_actions .wpo_wcpdf.proforma,
        .wc_actions .wpo_wcpdf.delivery-note,
        .wc_actions a[href*="document_type=invoice"],
        .wc_actions a[href*="document_type=receipt"],
        .wc_actions a[href*="document_type=credit-note"],
        .wc_actions a[href*="document_type=proforma"],
        .wc_actions a[href*="document_type=delivery-note"],
        .column-wc_actions .wpo_wcpdf.invoice,
        .column-wc_actions .wpo_wcpdf.receipt,
        .column-wc_actions .wpo_wcpdf.credit-note,
        .column-wc_actions .wpo_wcpdf.proforma,
        .column-wc_actions .wpo_wcpdf.delivery-note,
        .column-wc_actions a[alt*="PDF Factura"],
        .column-wc_actions a[alt*="PDF Borrador"],
        .column-wc_actions a[alt*="PDF Invoice"],
        .column-wc_actions a[alt*="PDF Receipt"],
        .column-wc_actions a[class*="invoice"],
        .column-wc_actions a[class*="receipt"] {
            display: none !important;
        }

        /* PERMITIR explícitamente solo el botón de albarán (packing-slip) */
        .wc_actions .wpo_wcpdf.packing-slip,
        .wc_actions a[href*="document_type=packing-slip"],
        .column-wc_actions .wpo_wcpdf.packing-slip,
        .column-wc_actions a[alt*="PDF Albarán"],
        .column-wc_actions a[alt*="Packing Slip"],
        .column-wc_actions a[class*="packing-slip"] {
            display: inline-block !important;
        }
        /* Ocultar TODOS los botones PDF de facturas y recibos, PERMITIR solo albaranes */
        .wc_actions .wpo_wcpdf.invoice,
        .wc_actions .wpo_wcpdf.receipt,
        .wc_actions a[href*="document_type=invoice"],
        .wc_actions a[href*="document_type=receipt"],
        .wc_actions a[href*="document_type=credit-note"],
        .wc_actions a[href*="document_type=proforma"],
        .wc_actions a[href*="document_type=delivery-note"],
        .column-wc_actions .wpo_wcpdf.invoice,
        .column-wc_actions .wpo_wcpdf.receipt,
        .column-wc_actions a[alt*="PDF Factura"],
        .column-wc_actions a[alt*="PDF Borrador"],
        .column-wc_actions a[alt*="Invoice"],
        .column-wc_actions a[alt*="Receipt"] {
            display: none !important;
        }

        /* PERMITIR explícitamente solo el botón de albarán (packing-slip) */
        .wc_actions .wpo_wcpdf.packing-slip,
        .wc_actions a[href*="document_type=packing-slip"],
        .column-wc_actions .wpo_wcpdf.packing-slip,
        .column-wc_actions a[alt*="PDF Albarán"],
        .column-wc_actions a[alt*="Albarán"] {
            display: inline-block !important;
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

            // Remover enlaces de edición de usuarios NATIVOS en la página de usuarios
            if (window.location.href.indexOf('users.php') !== -1) {
                // Remover enlaces "Editar" y "Ver" NATIVOS de WordPress (preservar nuestros enlaces ACF)
                $('.wp-list-table.users .row-actions .edit').not('.edit_acf_data').remove();
                $('.wp-list-table.users .row-actions .view').remove();
                
                // Hacer que los nombres de usuario no sean clicables
                $('.wp-list-table.users .column-username strong a').each(function() {
                    var text = $(this).text();
                    $(this).replaceWith('<span>' + text + '</span>');
                });
                
                // También para la columna de nombre si existe
                $('.wp-list-table.users .column-name strong a').each(function() {
                    var text = $(this).text();
                    $(this).replaceWith('<span>' + text + '</span>');
                });

                // Remover cualquier enlace que lleve a user-edit.php
                $('a[href*="user-edit.php"]').each(function() {
                    var text = $(this).text();
                    $(this).replaceWith('<span>' + text + '</span>');
                });
            }

            // MANTENER enlaces del perfil de la barra de admin - Los profesores necesitan acceso
            // JavaScript removido para permitir acceso al perfil
            
            // REMOVER BOTONES PDF NO DESEADOS - SOLO MANTENER ALBARÁN
            // Remover botones de factura y borrador, mantener solo albarán
            $('.wc_actions .wpo_wcpdf.invoice').remove();
            $('.wc_actions .wpo_wcpdf.receipt').remove();
            $('.column-wc_actions a[href*="document_type=invoice"]').remove();
            $('.column-wc_actions a[href*="document_type=receipt"]').remove();
            $('.column-wc_actions a[href*="document_type=credit-note"]').remove();
            $('.column-wc_actions a[href*="document_type=proforma"]').remove();
            $('.column-wc_actions a[href*="document_type=delivery-note"]').remove();
            $('.column-wc_actions a[alt*="PDF Factura"]').remove();
            $('.column-wc_actions a[alt*="PDF Borrador"]').remove();
            $('.column-wc_actions a[alt*="Invoice"]').remove();
            $('.column-wc_actions a[alt*="Receipt"]').remove();
            
            // Verificación periódica para elementos que se cargan dinámicamente
            setInterval(function() {
                $('.wc_actions .wpo_wcpdf.invoice').remove();
                $('.wc_actions .wpo_wcpdf.receipt').remove();
                $('.column-wc_actions a[href*="document_type=invoice"]').remove();
                $('.column-wc_actions a[href*="document_type=receipt"]').remove();
                $('.column-wc_actions a[alt*="PDF Factura"]').remove();
                $('.column-wc_actions a[alt*="PDF Borrador"]').remove();
                // NOTA: NO remover packing-slip - Los profesores SÍ pueden usar albaranes
            }, 1000);
            
            // MANTENER enlaces de perfil - Los profesores necesitan acceso a su perfil
            // Código JavaScript removido para permitir acceso al perfil
        });
        </script>
        <?php
    }

    /**
     * Muestra mensaje de acceso restringido para profesores
     */
    function show_restricted_access_notice()
    {
        // Solo para profesores y solo si hay parámetro de restricción
        if (!$this->is_user('teacher')) {
            return;
        }

        // Mensaje para acceso a páginas de plugins restringidas
        if (isset($_GET['restricted'])) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>' . __('Access Denied', 'neve-child') . '</strong></p>';
            echo '<p>' . __('You do not have permission to access this page. Teachers can only access orders and reports for their assigned school.', 'neve-child') . '</p>';
            echo '</div>';
        }

        // Mensaje para acceso a pedidos individuales (legacy y HPOS)
        if (isset($_GET['individual_access_denied'])) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>' . __('Individual Order Access Denied', 'neve-child') . '</strong></p>';
            echo '<p>' . __('Teachers cannot access individual order details. You can only view the orders list and filter by status for your assigned school.', 'neve-child') . '</p>';
            echo '</div>';
        }

        // Mensaje para acceso a usuarios individuales denegado
        if (isset($_GET['individual_user_access_denied'])) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>' . __('User Profile Access Completely Denied', 'neve-child') . '</strong></p>';
            echo '<p>' . __('Teachers cannot access any individual user profiles (including their own) or create new users. You can only view the users list filtered by your assigned school.', 'neve-child') . '</p>';
            echo '</div>';
        }

        // Mensaje para bulk actions denegadas
        if (isset($_GET['bulk_action_denied'])) {
            $denied_action = isset($_GET['denied_action']) ? sanitize_text_field($_GET['denied_action']) : 'unknown';
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>' . __('Bulk Action Denied', 'neve-child') . '</strong></p>';
            echo '<p>' . sprintf(__('Teachers can only use "Mark as reviewed" action. The action "%s" is not permitted for your role.', 'neve-child'), $denied_action) . '</p>';
            echo '</div>';
        }
    }

    /**
     * Remover enlaces del perfil de la barra de admin para profesores
     */
    public function remove_teacher_profile_admin_bar($wp_admin_bar)
    {
        // Solo aplicar a profesores
        if (!$this->is_user('teacher')) {
            return;
        }

        // MANTENER acceso al perfil - Los profesores necesitan editar su perfil
        // Nodos removidos para permitir acceso completo al perfil:
        // $wp_admin_bar->remove_node('user-info');       // Información del usuario
        // $wp_admin_bar->remove_node('edit-profile');    // Editar mi perfil
        // $wp_admin_bar->remove_node('user-actions');    // Todo el menú de acciones de usuario
    }

    /**
     * Agregar página personalizada para editar datos ACF de usuarios
     */
    public function add_teacher_user_acf_edit_page()
    {
        // Solo para profesores
        if (!$this->is_user('teacher')) {
            return;
        }

        add_submenu_page(
            null, // No mostrar en menú, solo acceso directo
            __('Edit Student Data', 'neve-child'),
            __('Edit Student Data', 'neve-child'),
            'edit_users',
            'teacher-edit-student-acf',
            array($this, 'render_teacher_user_acf_edit_page')
        );
    }

    /**
     * Modificar acciones de fila en lista de usuarios para profesores
     */
    public function modify_user_row_actions_for_teachers($actions, $user_object)
    {
        // Solo aplicar a profesores
        if (!$this->is_user('teacher')) {
            return $actions;
        }

        // Verificar que el usuario pertenezca al mismo centro
        $teacher_school_id = get_user_meta(get_current_user_id(), 'school_id', true);
        $user_school_id = get_user_meta($user_object->ID, 'school_id', true);
        
        if ($teacher_school_id && $user_school_id && $teacher_school_id == $user_school_id) {
            // Verificar que sea estudiante o profesor
            if (in_array('student', $user_object->roles) || in_array('teacher', $user_object->roles)) {
                // Preservar enlace al perfil - NO remover 'edit' que puede ser el enlace al perfil
                // Solo remover 'view' si existe
                unset($actions['view']);
                
                // Modificar la acción 'edit' para que apunte a los datos ACF en lugar de removerla
                $actions['edit'] = '<a href="' . 
                    esc_url(admin_url('admin.php?page=teacher-edit-student-acf&user_id=' . $user_object->ID)) . '">' . 
                    __('Edit Data', 'neve-child') . '</a>';
            }
        } else {
            // Si no es del mismo centro, solo remover 'view' pero mantener enlace al perfil
            unset($actions['view']);
            // Mantener $actions['edit'] para preservar acceso al perfil
        }

        return $actions;
    }

    /**
     * Filtrar acciones individuales de fila en órdenes para profesores
     * Los profesores NO pueden cambiar estados de pedidos, solo pueden generar packing slips
     */
    public function filter_teacher_order_actions($actions, $order)
    {
        // Solo aplicar a profesores
        if (!$this->is_user('teacher')) {
            return $actions;
        }

        // Lista de acciones de cambio de estado que deben ser bloqueadas para profesores
        $blocked_status_actions = [
            'processing',      // Mark as Processing
            'complete',        // Mark as Complete  
            'on-hold',         // Mark as On Hold
            'cancelled',       // Mark as Cancelled
            'trash',           // Move to Trash
            'view',            // View Order (acceso individual)
            'edit',            // Edit Order (acceso individual)
        ];

        // Remover acciones bloqueadas
        foreach ($blocked_status_actions as $blocked_action) {
            if (isset($actions[$blocked_action])) {
                unset($actions[$blocked_action]);
            }
        }

        // Mantener solo acciones permitidas (packing slips, etc.)
        // Las acciones de PDF que SÍ están permitidas se mantienen automáticamente
        // ya que no están en la lista de bloqueadas

        return $actions;
    }

    /**
     * Renderizar página de edición de datos ACF de usuario
     */
    public function render_teacher_user_acf_edit_page()
    {
        // Verificar permisos
        if (!$this->is_user('teacher')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        if (!$user_id) {
            wp_die(__('Invalid user ID.'));
        }

        $user = get_userdata($user_id);
        if (!$user) {
            wp_die(__('User not found.'));
        }

        // Verificar que el usuario pertenezca al mismo centro
        $teacher_school_id = get_user_meta(get_current_user_id(), 'school_id', true);
        $user_school_id = get_user_meta($user_id, 'school_id', true);
        
        if (!$teacher_school_id || !$user_school_id || $teacher_school_id != $user_school_id) {
            wp_die(__('You can only edit users from your assigned school.'));
        }

        // Verificar que sea estudiante o profesor
        if (!in_array('student', $user->roles) && !in_array('teacher', $user->roles)) {
            wp_die(__('You can only edit student or teacher data.'));
        }

        // Obtener campos ACF del grupo coo_users
        $field_group = acf_get_field_group('group_68ac0c520bf69');
        if (!$field_group) {
            wp_die(__('ACF field group not found.'));
        }

        $fields = acf_get_fields($field_group);
        
        ?>
        <div class="wrap">
            <h1>
                <?php echo sprintf(__('Edit %s Data', 'neve-child'), in_array('student', $user->roles) ? 'Student' : 'Teacher'); ?>
                - <?php echo esc_html($user->display_name); ?>
            </h1>
            
            <?php if (isset($_GET['updated']) && $_GET['updated'] == '1'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('User data updated successfully.', 'neve-child'); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('teacher_edit_user_acf_' . $user_id, 'teacher_edit_user_acf_nonce'); ?>
                <input type="hidden" name="user_id" value="<?php echo $user_id; ?>" />
                <input type="hidden" name="action" value="teacher_save_user_acf" />

                <table class="form-table" role="presentation">
                    <tbody>
                        <?php foreach ($fields as $field): ?>
                            <?php 
                            $value = get_field($field['name'], 'user_' . $user_id);
                            $field['value'] = $value;
                            ?>
                            <tr>
                                <th scope="row">
                                    <label for="<?php echo esc_attr($field['name']); ?>">
                                        <?php echo esc_html($field['label']); ?>
                                        <?php if ($field['required']): ?>
                                            <span class="description">(required)</span>
                                        <?php endif; ?>
                                    </label>
                                </th>
                                <td>
                                    <?php $this->render_acf_field($field, $user_id); ?>
                                    <?php if (!empty($field['instructions'])): ?>
                                        <p class="description"><?php echo esc_html($field['instructions']); ?></p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php submit_button(__('Update User Data', 'neve-child')); ?>
            </form>

            <p>
                <a href="<?php echo admin_url('users.php'); ?>" class="button">
                    <?php _e('Back to Users List', 'neve-child'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Renderizar campo ACF simplificado
     */
    private function render_acf_field($field, $user_id)
    {
        $value = $field['value'] ?? '';
        $name = 'acf_' . $field['name'];
        $id = $field['name'];
        
        switch ($field['type']) {
            case 'text':
                echo '<input type="text" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="regular-text"';
                if ($field['required']) echo ' required';
                echo ' />';
                break;
                
            case 'number':
                echo '<input type="number" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="small-text"';
                if ($field['required']) echo ' required';
                if (isset($field['min'])) echo ' min="' . esc_attr($field['min']) . '"';
                if (isset($field['max'])) echo ' max="' . esc_attr($field['max']) . '"';
                echo ' />';
                break;
                
            case 'textarea':
                echo '<textarea id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" rows="5" cols="50"';
                if ($field['required']) echo ' required';
                echo '>' . esc_textarea($value) . '</textarea>';
                break;
                
            default:
                echo '<input type="text" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="regular-text"';
                if ($field['required']) echo ' required';
                echo ' />';
        }
    }

    /**
     * Manejar guardado de datos ACF de usuario
     */
    public function handle_teacher_user_acf_save()
    {
        // Verificar que sea el action correcto
        if (!isset($_POST['action']) || $_POST['action'] !== 'teacher_save_user_acf') {
            return;
        }

        // Verificar permisos
        if (!$this->is_user('teacher')) {
            wp_die(__('You do not have sufficient permissions to perform this action.'));
        }

        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        if (!$user_id) {
            wp_die(__('Invalid user ID.'));
        }

        // Verificar nonce
        if (!wp_verify_nonce($_POST['teacher_edit_user_acf_nonce'], 'teacher_edit_user_acf_' . $user_id)) {
            wp_die(__('Security check failed.'));
        }

        $user = get_userdata($user_id);
        if (!$user) {
            wp_die(__('User not found.'));
        }

        // Verificar que el usuario pertenezca al mismo centro
        $teacher_school_id = get_user_meta(get_current_user_id(), 'school_id', true);
        $user_school_id = get_user_meta($user_id, 'school_id', true);
        
        if (!$teacher_school_id || !$user_school_id || $teacher_school_id != $user_school_id) {
            wp_die(__('You can only edit users from your assigned school.'));
        }

        // Obtener campos ACF y guardar
        $field_group = acf_get_field_group('group_68ac0c520bf69');
        if ($field_group) {
            $fields = acf_get_fields($field_group);
            
            foreach ($fields as $field) {
                $field_name = 'acf_' . $field['name'];
                if (isset($_POST[$field_name])) {
                    $value = sanitize_text_field($_POST[$field_name]);
                    
                    // Validación adicional para números
                    if ($field['type'] === 'number') {
                        $value = intval($value);
                        if (isset($field['min']) && $value < $field['min']) {
                            $value = $field['min'];
                        }
                    }
                    
                    // Guardar usando ACF
                    if (function_exists('update_field')) {
                        update_field($field['name'], $value, 'user_' . $user_id);
                    }
                }
            }
        }

        // Redirigir con mensaje de éxito
        wp_redirect(admin_url('admin.php?page=teacher-edit-student-acf&user_id=' . $user_id . '&updated=1'));
        exit;
    }

    /**
     * Filtra las acciones en lote para profesores
     * Solo permite la acción "Mark as reviewed"
     */
    public function filter_teacher_bulk_actions($actions)
    {
        // Solo aplicar el filtro si el usuario actual es profesor
        if (!$this->is_user('teacher')) {
            return $actions;
        }



        // NUEVA ESTRATEGIA: Filtrar las acciones existentes en lugar de reemplazar todo
        $filtered_actions = array();
        
        // Preservar la opción por defecto existente (sin duplicar)
        if (isset($actions['-1'])) {
            $filtered_actions['-1'] = $actions['-1'];
        } elseif (isset($actions[''])) {
            $filtered_actions[''] = $actions[''];
        }
        
        // Solo añadir las acciones permitidas para profesores
        // Permitir: mark_reviewed y packing-slip (pero NO invoice, credit-note, etc.)
        $allowed_actions = ['mark_reviewed', 'packing-slip'];
        
        foreach ($actions as $key => $value) {
            // Permitir acciones específicamente listadas
            if (in_array($key, $allowed_actions) || $key === '-1' || $key === '') {
                $filtered_actions[$key] = $value;
            }
            // Permitir acciones de packing slip con diferentes variantes de nombres
            elseif (strpos($key, 'packing') !== false || strpos($key, 'delivery') !== false) {
                $filtered_actions[$key] = $value;
            }
        }
        


        return $filtered_actions;
    }

    /**
     * Intercepta el procesamiento de bulk actions para profesores
     * Permite 'mark_reviewed' y acciones de packing-slip, bloquea facturas y notas de crédito
     */
    public function intercept_teacher_bulk_actions($redirect_url, $action, $post_ids)
    {
        // Solo aplicar a profesores
        if (!$this->is_user('teacher')) {
            return $redirect_url;
        }

        // Acciones permitidas para profesores
        $allowed_actions = ['mark_reviewed', 'packing-slip'];
        
        // Verificar si la acción está permitida o es relacionada con packing slips
        $is_allowed = in_array($action, $allowed_actions) || 
                     strpos($action, 'packing') !== false || 
                     strpos($action, 'delivery') !== false;

        if (!$is_allowed) {
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

        // Si es 'mark_reviewed', permitir que continúe normalmente
        return $redirect_url;
    }

    /**
     * Filtrar bulk actions de usuarios para profesores
     * Elimina la acción "Solicitar token de suscripción" para profesores
     */
    public function filter_teacher_user_bulk_actions($actions)
    {
        // Solo aplicar el filtro si el usuario actual es profesor
        if (!$this->is_user('teacher')) {
            return $actions;
        }

        // Remover la acción de solicitar token de suscripción para profesores
        if (isset($actions['request_redsys_subscription_token'])) {
            unset($actions['request_redsys_subscription_token']);
        }

        return $actions;
    }

    /**
     * Registrar filtros de bulk actions de manera inteligente para evitar duplicados
     */
    public function register_teacher_bulk_action_filters()
    {
        static $filters_registered = false;
        
        if ($filters_registered || !is_admin() || !$this->is_user('teacher')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        // Solo registrar el filtro para la página actual con prioridad extremadamente alta
        if ($screen->id === 'edit-shop_order') {
            add_filter('bulk_actions-edit-shop_order', array($this, 'filter_teacher_bulk_actions'), PHP_INT_MAX, 1);
            $filters_registered = true;
        } elseif ($screen->id === 'woocommerce_page_wc-orders') {
            add_filter('bulk_actions-woocommerce_page_wc-orders', array($this, 'filter_teacher_bulk_actions'), PHP_INT_MAX, 1);
            $filters_registered = true;
        } elseif ($screen->id === 'users') {
            add_filter('bulk_actions-users', array($this, 'filter_teacher_user_bulk_actions'), PHP_INT_MAX, 1);
            $filters_registered = true;
        }
    }

    /**
     * Bloquea el procesamiento de bulk actions no permitidas a nivel de POST
     * Protege contra manipulación directa del formulario
     */
    public function block_teacher_bulk_action_processing()
    {
        // Solo aplicar a profesores
        if (!$this->is_user('teacher')) {
            return;
        }

        // Verificar si se está enviando una bulk action
        $action = '';
        if (isset($_POST['action']) && $_POST['action'] !== '-1') {
            $action = $_POST['action'];
        } elseif (isset($_POST['action2']) && $_POST['action2'] !== '-1') {
            $action = $_POST['action2'];
        }

        // Si hay una acción y no es la permitida
        if (!empty($action) && $action !== 'mark_reviewed') {
            // Lista de acciones específicamente bloqueadas
            $blocked_actions = array(
                'mark_master_order',
                'mark_master_preparing', 
                'mark_master_complete',
                'mark_bank_transfers_paid',
                'mark_bank_transfers_unpaid',
                'invoice',
                'mark_processing',
                'mark_on-hold',
                'mark_completed',
                'mark_cancelled',
                'trash',
                'mark_warehouse',
                'mark_prepared',
                'mark_customized',
                'mark_pickup',
                'mark_estimate'
            );

            if (in_array($action, $blocked_actions)) {
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
    }

    /**
     * Bloquea llamadas AJAX prohibidas para profesores - PROTECCIÓN AJAX
     */
    public function block_teacher_ajax_actions()
    {
        // Solo aplicar a profesores
        if (!$this->is_user('teacher')) {
            return;
        }

        // Enviar respuesta de error y terminar
        wp_send_json_error(array(
            'message' => __('Access denied: Teachers can only use review actions.', 'neve-child'),
            'code' => 'teacher_forbidden_action'
        ));
    }

    /**
     * Bloquea accesos directos por URL a funcionalidades prohibidas - PROTECCIÓN COMPLETA
     */
    public function block_teacher_direct_url_access()
    {
        // Solo aplicar a profesores
        if (!$this->is_user('teacher')) {
            return;
        }

        // Verificar parámetros en la URL que indiquen acciones prohibidas
        $forbidden_url_params = array(
            'mark_master_order',
            'mark_master_preparing', 
            'mark_master_complete',
            'mark_bank_transfers_paid',
            'mark_bank_transfers_unpaid',
            'invoice',
            'mark_processing',
            'mark_on-hold',
            'mark_completed',
            'mark_cancelled',
            'trash',
            'mark_warehouse',
            'mark_prepared',
            'mark_customized',
            'mark_pickup',
            'mark_estimate',
            'wpo_wcpdf_generate_pdf'  // Plugin PDF directo
        );

        // Verificar todos los parámetros GET
        foreach ($_GET as $param => $value) {
            // Si el parámetro contiene alguna acción prohibida
            foreach ($forbidden_url_params as $forbidden) {
                // Verificar el nombre del parámetro
                if (strpos($param, $forbidden) !== false) {
                    // Bloquear inmediatamente con mensaje de error
                    wp_redirect(admin_url('edit.php?post_type=shop_order&bulk_action_denied=1&denied_action=' . urlencode($forbidden)));
                    exit;
                }
                
                // Verificar el valor del parámetro (puede ser string o array)
                if (is_array($value)) {
                    // Si es array, verificar cada elemento
                    foreach ($value as $val) {
                        if (is_string($val) && strpos($val, $forbidden) !== false) {
                            wp_redirect(admin_url('edit.php?post_type=shop_order&bulk_action_denied=1&denied_action=' . urlencode($forbidden)));
                            exit;
                        }
                    }
                } elseif (is_string($value) && strpos($value, $forbidden) !== false) {
                    // Si es string, verificar directamente
                    wp_redirect(admin_url('edit.php?post_type=shop_order&bulk_action_denied=1&denied_action=' . urlencode($forbidden)));
                    exit;
                }
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
     */
    public function block_teacher_url_parameters()
    {
        // Solo aplicar a profesores
        if (!$this->is_user('teacher')) {
            return;
        }

        // Verificar si están intentando acceder a funcionalidades prohibidas por URL
        if (isset($_GET['action']) || isset($_GET['bulk_action']) || isset($_GET['action2'])) {
            $actions_to_check = array();
            
            if (isset($_GET['action'])) $actions_to_check[] = $_GET['action'];
            if (isset($_GET['bulk_action'])) $actions_to_check[] = $_GET['bulk_action'];
            if (isset($_GET['action2'])) $actions_to_check[] = $_GET['action2'];

            $forbidden_actions = array(
                'mark_master_order',
                'mark_master_preparing', 
                'mark_master_complete',
                'mark_bank_transfers_paid',
                'mark_bank_transfers_unpaid',
                'invoice',
                'mark_processing',
                'mark_on-hold',
                'mark_completed',
                'mark_cancelled',
                'trash',
                'mark_warehouse',
                'mark_prepared',
                'mark_customized',
                'mark_pickup',
                'mark_estimate',
                'wpo_wcpdf_generate_pdf'
            );

            foreach ($actions_to_check as $action) {
                if (in_array($action, $forbidden_actions)) {
                    wp_redirect(admin_url('edit.php?post_type=shop_order&bulk_action_denied=1&denied_action=' . urlencode($action)));
                    exit;
                }
            }
        }

        // Verificar acceso directo a archivos PDF generados
        if (isset($_GET['download_pdf']) || isset($_GET['pdf']) || isset($_GET['generate_pdf'])) {
            wp_redirect(admin_url('edit.php?post_type=shop_order&bulk_action_denied=1&denied_action=pdf_access'));
            exit;
        }
    }

    /**
     * Cargar JavaScript para limpiar duplicados en bulk actions para profesores
     */
    public function enqueue_teacher_bulk_actions_cleanup_script()
    {
        $screen = get_current_screen();
        
        // Solo cargar en páginas de pedidos
        if (!$screen || !in_array($screen->id, ['edit-shop_order', 'woocommerce_page_wc-orders'])) {
            return;
        }
        
        // Solo para profesores
        if (!$this->is_user('teacher')) {
            return;
        }
        
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Función para limpiar duplicados en bulk actions - MUY AGRESIVA
            function cleanBulkActionsDuplicates() {
                // Seleccionar todos los selects de bulk actions
                $('select[name="action"], select[name="action2"]').each(function() {
                    var $select = $(this);
                    var selectId = $select.attr('id') || 'unknown';
                    
                    // Encontrar todas las opciones actuales
                    var allOptions = $select.find('option');
                    
                    // Crear array con opciones limpias para profesores
                    var cleanOptions = [];
                    var hasDefaultOption = false;
                    var hasMarkReviewed = false;
                    
                    // Recorrer opciones y mantener solo las necesarias
                    allOptions.each(function() {
                        var $option = $(this);
                        var value = $option.val();
                        var text = $option.text().trim();
                        
                        // Para la opción por defecto (-1 o vacío), mantener solo una
                        if ((value === '-1' || value === '') && !hasDefaultOption) {
                            cleanOptions.push({value: '-1', text: text});
                            hasDefaultOption = true;
                        }
                        // Para mark_reviewed, mantener solo una
                        else if (value === 'mark_reviewed' && !hasMarkReviewed) {
                            cleanOptions.push({value: value, text: text});
                            hasMarkReviewed = true;
                        }
                    });
                    
                    // Si no encontramos la opción por defecto, añadirla
                    if (!hasDefaultOption) {
                        cleanOptions.unshift({value: '-1', text: 'Acciones en lote'});
                    }
                    
                    // Si no encontramos mark_reviewed, añadirla
                    if (!hasMarkReviewed) {
                        cleanOptions.push({value: 'mark_reviewed', text: 'Marcar como revisado'});
                    }
                    
                    // Limpiar completamente el select
                    $select.empty();
                    
                    // Reconstruir con opciones limpias
                    $.each(cleanOptions, function(index, option) {
                        $select.append('<option value="' + option.value + '">' + option.text + '</option>');
                    });
                });
            }
            
            // Ejecutar limpieza inmediatamente
            cleanBulkActionsDuplicates();
            
            // Ejecutar limpieza después de un pequeño delay para asegurar que todo esté cargado
            setTimeout(function() {
                cleanBulkActionsDuplicates();
            }, 100);
            
            // Ejecutar limpieza después de llamadas AJAX
            $(document).ajaxComplete(function() {
                setTimeout(function() {
                    cleanBulkActionsDuplicates();
                }, 50);
            });
            
            // Usar MutationObserver para detectar cambios en el DOM
            if (window.MutationObserver) {
                var observer = new MutationObserver(function(mutations) {
                    var shouldClean = false;
                    mutations.forEach(function(mutation) {
                        if (mutation.type === 'childList' || mutation.type === 'subtree') {
                            // Verificar si se han añadido select elements
                            var $target = $(mutation.target);
                            if ($target.find('select[name="action"]').length > 0 || 
                                $target.is('select[name="action"]') || 
                                $target.is('option')) {
                                shouldClean = true;
                            }
                        }
                    });
                    
                    if (shouldClean) {
                        setTimeout(function() {
                            cleanBulkActionsDuplicates();
                        }, 10);
                    }
                });
                
                // Observar cambios en el documento
                observer.observe(document.body, {
                    childList: true,
                    subtree: true
                });
            }
        });
        </script>
        <?php
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

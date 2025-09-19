<?php
/**
 * QR Code Manager for schools
 * 
 * @package SchoolManagement\Schools
 * @since 1.0.0
 */

namespace SchoolManagement\Schools;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for managing QR codes for schools
 */
class QRCodeManager
{
    /**
     * School post type
     */
    private const POST_TYPE = 'coo_school';

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->initHooks();
    }

    /**
     * Initialize WordPress hooks
     */
    public function initHooks(): void
    {
        // Generate school key when creating a new school
        add_action('save_post_' . self::POST_TYPE, [$this, 'generateSchoolKey'], 10, 3);
        
        // QR column and display
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'addQRColumn']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'displayQRColumn'], 10, 2);
        
        // Admin scripts and download
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminScripts']);
        add_action('admin_init', [$this, 'handleQRDownload']);
        add_action('admin_footer', [$this, 'addDownloadScript']);
        
        // AJAX for manual key generation
        add_action('wp_ajax_generate_school_key', [$this, 'ajaxGenerateSchoolKey']);
    }

    /**
     * Generate school key when creating a new school
     * 
     * @param int $post_id Post ID
     * @param \WP_Post $post Post object
     * @param bool $update Whether this is an update
     * @return void
     */
    public function generateSchoolKey(int $post_id, \WP_Post $post, bool $update): void
    {
        // Only generate key for new schools (not updates)
        if ($update) {
            return;
        }

        // Check if key already exists
        $existing_key = get_post_meta($post_id, 'school_key', true);
        if (!empty($existing_key)) {
            return;
        }

        // Generate a unique key
        $key = wp_generate_password(12, false, false); // 12 alphanumeric characters
        
        // Save the key using ACF if available, or post meta as fallback
        if (function_exists('update_field')) {
            update_field('school_key', $key, $post_id);
        } else {
            update_post_meta($post_id, 'school_key', $key);
        }
    }

    /**
     * Add QR column to schools list
     * 
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public function addQRColumn(array $columns): array
    {
        $new_columns = [];

        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            if ($key === 'title') {
                $new_columns['school_qr'] = __('QR Code', 'neve-child');
            }
        }

        if (!isset($new_columns['school_qr'])) {
            $new_columns['school_qr'] = __('QR Code', 'neve-child');
        }

        return $new_columns;
    }

    /**
     * Display QR code in the column
     * 
     * @param string $column_name Column name
     * @param int $post_id Post ID
     * @return void
     */
    public function displayQRColumn(string $column_name, int $post_id): void
    {
        if ($column_name !== 'school_qr') {
            return;
        }

        $key = get_post_meta($post_id, 'school_key', true);

        if (empty($key)) {
            echo '<div class="qr-no-key">';
            echo '<span class="dashicons dashicons-warning" style="color: #d63638;"></span> ';
            echo __('No Key', 'neve-child');
            echo '<br><a href="#" class="button button-small generate-key" data-post-id="' . esc_attr($post_id) . '">' . __('Generate Key', 'neve-child') . '</a>';
            echo '</div>';
            return;
        }

        $register_url = $this->getRegisterUrlWithKey($post_id);

        if (empty($register_url)) {
            echo '<span class="dashicons dashicons-warning" style="color: #d63638;"></span> ' . __('Error: My Account page not configured', 'neve-child');
            return;
        }

        echo '<div class="qr-container">';
        echo '<div class="qr-preview-container" data-url="' . esc_attr($register_url) . '" id="qr-' . esc_attr($post_id) . '"></div>';
        echo '<br><a href="#" class="button button-small qr-show" data-postid="' . esc_attr($post_id) . '" data-url="' . esc_url($register_url) . '">' . __('View Complete QR', 'neve-child') . '</a>';
        echo '</div>';
    }

    /**
     * Enqueue admin scripts for QR generation
     * 
     * @param string $hook Current admin page hook
     * @return void
     */
    public function enqueueAdminScripts(string $hook): void
    {
        $screen = get_current_screen();

        // Solo en la lista de coo_school
        if ($screen->post_type !== self::POST_TYPE || $hook !== 'edit.php') {
            return;
        }

        // Registrar el script de qrcode.js local
        wp_enqueue_script(
            'qrcode-js',
            get_stylesheet_directory_uri() . '/custom/assets/js/qrcode.js',
            [],
            '1.0.0',
            true
        );

        // Registrar estilos con rutas correctas
        wp_enqueue_style(
            'dm-school-qr-style',
            get_stylesheet_directory_uri() . '/custom/assets/css/admin_school_qr.css',
            [],
            '1.0.0' . time() // Add time() to force reload in development
        );

        // Registrar script con rutas correctas
        wp_enqueue_script(
            'dm-school-qr-script',
            get_stylesheet_directory_uri() . '/custom/assets/js/admin_school_qr.js',
            ['qrcode-js'], // Ahora depende de qrcode.js
            '1.0.0' . time(), // Add time() to force reload in development
            true
        );
    }

    /**
     * Handle QR code display in new window
     * 
     * @return void
     */
    public function handleQRDownload(): void
    {
        if (!isset($_GET['action']) || $_GET['action'] !== 'show_school_qr') {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'show_school_qr')) {
            wp_die(__('Nonce verification failed', 'neve-child'));
        }

        if (!current_user_can('edit_posts')) {
            wp_die(__('Insufficient permissions', 'neve-child'));
        }

        if (!isset($_GET['post_id']) || !is_numeric($_GET['post_id'])) {
            wp_die(__('Invalid post ID', 'neve-child'));
        }

        $post_id = intval($_GET['post_id']);
        $post = get_post($post_id);

        if (!$post || $post->post_type !== self::POST_TYPE) {
            wp_die(__('Invalid school', 'neve-child'));
        }

        $register_url = $this->getRegisterUrlWithKey($post_id);

        if (empty($register_url)) {
            wp_die(__('Cannot generate register URL', 'neve-child'));
        }

        $school_name = $post->post_title;

        // Generate HTML page for QR display
        $html = $this->generateQRDisplayPage($register_url, $school_name);
        
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        echo $html;
        exit;
    }

    /**
     * Add download script to admin footer
     * 
     * @return void
     */
    public function addDownloadScript(): void
    {
        $screen = get_current_screen();
        
        if ($screen->post_type !== self::POST_TYPE) {
            return;
        }
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Simple timeout to ensure all scripts are loaded
            setTimeout(function() {
                
                // Generate QR codes for all preview containers
                $('.qr-preview-container').each(function() {
                    var container = $(this);
                    var url = container.data('url');
                    
                    if (url && typeof qrcode !== 'undefined') {
                        try {
                            // Use your local qrcode library syntax
                            var qr = qrcode(0, 'M');
                            qr.addData(url);
                            qr.make();
                            
                            // Create image and add to container
                            container.html(qr.createImgTag(3, 5));
                        } catch (error) {
                            container.html('<span style="font-size:10px; color:#d63638;">Error QR</span>');
                        }
                    } else if (!url) {
                        container.html('<span style="font-size:10px; color:#666;">Sin URL</span>');
                    } else {
                        container.html('<span style="font-size:10px; color:#d63638;">Library not available</span>');
                    }
                });
            }, 1000); // Esperamos 1 segundo para que se carguen todos los scripts
            
            // Handle show QR clicks
            $('.qr-show').on('click', function(e) {
                e.preventDefault();
                
                var postId = $(this).data('postid');
                var nonce = '<?php echo wp_create_nonce('show_school_qr'); ?>';
                
                var showUrl = '<?php echo admin_url('admin.php'); ?>?action=show_school_qr&post_id=' + postId + '&_wpnonce=' + nonce;
                
                // Open in new window with specific dimensions
                var newWindow = window.open(showUrl, 'qr-window-' + postId, 'width=700,height=800,scrollbars=yes,resizable=yes,toolbar=no,menubar=no,location=no');
                
                // Focus new window if opened correctly
                if (newWindow) {
                    newWindow.focus();
                }
            });

            // Handle generate key clicks
            $('.generate-key').on('click', function(e) {
                e.preventDefault();
                
                var button = $(this);
                var postId = button.data('post-id');
                var originalText = button.text();
                
                button.text('<?php echo esc_js(__('Generating...', 'neve-child')); ?>').prop('disabled', true);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'generate_school_key',
                        post_id: postId,
                        nonce: '<?php echo wp_create_nonce('generate_school_key_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Reload the page to show the new QR code
                            location.reload();
                        } else {
                            alert('<?php echo esc_js(__('Error generating key', 'neve-child')); ?>');
                            button.text(originalText).prop('disabled', false);
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('Connection error', 'neve-child')); ?>');
                        button.text(originalText).prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Get register URL with school key
     * 
     * @param int $school_id School ID
     * @return string|null
     */
    private function getRegisterUrlWithKey(int $school_id): ?string
    {
        $key = get_post_meta($school_id, 'school_key', true);
        if (empty($key)) {
            return null;
        }

        $my_account_page = get_option('woocommerce_myaccount_page_id');
        if (!$my_account_page) {
            return null;
        }

        return add_query_arg(
            ['school_key' => $key],
            get_permalink($my_account_page)
        );
    }

    /**
     * Generate HTML page for QR display
     * 
     * @param string $url URL to encode in QR
     * @param string $school_name School name
     * @return string HTML content
     */
    private function generateQRDisplayPage(string $url, string $school_name): string
    {
        $qrcode_js_url = get_stylesheet_directory_uri() . '/custom/assets/js/qrcode.js';
        
        $title = sprintf(__('QR Code - %s', 'neve-child'), $school_name);
        $educational_center_label = __('Educational Center:', 'neve-child');
        $registration_url_label = __('Registration URL:', 'neve-child');
        $scan_instruction = __('Students can scan this QR code to automatically register at your educational center.', 'neve-child');
        $download_button = __('Download Image', 'neve-child');
        $print_button = __('Print', 'neve-child');
        $close_button = __('Close', 'neve-child');
        $generating_text = __('Generating QR Code...', 'neve-child');
        
        return sprintf(
            '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>%s</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            text-align: center; 
            padding: 40px; 
            background: #f5f5f5;
            margin: 0;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { 
            color: #333; 
            margin-bottom: 30px; 
            font-size: 28px;
        }
        #qrcode { 
            margin: 30px auto; 
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 300px;
            border: 2px dashed #ddd;
            border-radius: 8px;
            background: #fafafa;
        }
        .info { 
            margin: 30px 0; 
            padding: 20px;
            background: #f8f9fa;
            border-radius: 5px;
            border-left: 4px solid #007cba;
        }
        .info p { 
            margin: 10px 0; 
            color: #555;
        }
        .url-text {
            background: #fff;
            padding: 10px;
            border-radius: 3px;
            font-family: monospace;
            font-size: 14px;
            word-break: break-all;
            border: 1px solid #ddd;
        }
        .loading {
            color: #666;
            font-size: 16px;
        }
        .actions {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            margin: 0 10px;
            background: #007cba;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            transition: background 0.3s;
            border: none;
            cursor: pointer;
        }
        .btn:hover {
            background: #005a87;
        }
        .btn-secondary {
            background: #666;
        }
        .btn-secondary:hover {
            background: #444;
        }
        .error {
            color: #d63638;
            padding: 20px;
            border: 1px solid #d63638;
            border-radius: 5px;
            background: #fff0f0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>%s</h1>
        <div id="qrcode">
            <div class="loading">%s</div>
        </div>
        <div class="info">
            <p><strong>%s</strong> %s</p>
            <p><strong>%s</strong></p>
            <div class="url-text">%s</div>
            <p style="margin-top: 15px;"><em>%s</em></p>
        </div>
        <div class="actions">
            <button class="btn" onclick="downloadQR()">%s</button>
            <button class="btn btn-secondary" onclick="window.print()">%s</button>
            <button class="btn btn-secondary" onclick="window.close()">%s</button>
        </div>
    </div>
    
    <script src="%s"></script>
    <script>
        const qrContainer = document.getElementById("qrcode");
        const url = "%s";
        
        // Function to generate QR
        function generateQR() {
            // Verify that local library is loaded
            if (typeof qrcode === "undefined") {
                qrContainer.innerHTML = `<div class="error">
                    <strong>Error: Local QR library not loaded</strong><br>
                    <small>Verify that qrcode.js file is available</small>
                </div>`;
                return;
            }
            
            try {
                qrContainer.innerHTML = "";
                
                var qr = qrcode(0, "M");
                qr.addData(url);
                qr.make();
                
                var qrImage = qr.createImgTag(4, 10);
                qrContainer.innerHTML = qrImage;
                
            } catch (error) {
                qrContainer.innerHTML = `<div class="error">
                    <strong>Error generating QR code</strong><br>
                    <small>Error: ` + error.message + `</small><br>
                    <button class="btn" onclick="generateQR()" style="margin-top: 10px;">Retry</button>
                </div>`;
            }
        }
        
        function downloadQR() {
            const qrImage = document.querySelector("#qrcode img");
            if (qrImage) {
                const canvas = document.createElement("canvas");
                const ctx = canvas.getContext("2d");
                
                canvas.width = qrImage.width;
                canvas.height = qrImage.height;
                
                ctx.drawImage(qrImage, 0, 0);
                
                const link = document.createElement("a");
                link.download = "qr-%s.png";
                link.href = canvas.toDataURL("image/png");
                link.click();
            } else {
                alert("Could not generate QR image. Try regenerating the code.");
            }
        }
        
        function waitForQRCode() {
            if (typeof qrcode !== "undefined") {
                generateQR();
            } else {
                setTimeout(waitForQRCode, 100);
            }
        }
        
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", waitForQRCode);
        } else {
            waitForQRCode();
        }
        
        window.addEventListener("beforeprint", function() {
            const actions = document.querySelector(".actions");
            if (actions) actions.style.display = "none";
        });
        
        window.addEventListener("afterprint", function() {
            const actions = document.querySelector(".actions");
            if (actions) actions.style.display = "block";
        });
        
        setTimeout(function() {
            if (qrContainer.innerHTML.includes("%s")) {
                qrContainer.innerHTML = `<div class="error">
                    <strong>Timeout: QR code took too long to generate</strong><br>
                    <small>May be a problem with the local library</small><br>
                    <button class="btn" onclick="generateQR()" style="margin-top: 10px;">Retry</button>
                </div>`;
            }
        }, 5000);
    </script>
</body>
</html>',
            esc_html($title),
            esc_html($title),
            esc_html($generating_text),
            esc_html($educational_center_label),
            esc_html($school_name),
            esc_html($registration_url_label),
            esc_html($url),
            esc_html($scan_instruction),
            esc_html($download_button),
            esc_html($print_button),
            esc_html($close_button),
            esc_url($qrcode_js_url),
            esc_js($url),
            sanitize_title($school_name),
            esc_js($generating_text)
        );
    }

    /**
     * AJAX handler for generating school key manually
     * 
     * @return void
     */
    public function ajaxGenerateSchoolKey(): void
    {
        // Check nonce
        if (!check_ajax_referer('generate_school_key_nonce', 'nonce', false)) {
            wp_die(__('Security: Invalid nonce', 'neve-child'));
        }

        // Check permissions
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to perform this action', 'neve-child'));
        }

        $post_id = intval($_POST['post_id']);
        if (!$post_id) {
            wp_die(__('Invalid post ID', 'neve-child'));
        }

        // Verify it's a school post
        $post = get_post($post_id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            wp_die(__('Invalid post', 'neve-child'));
        }

        // Generate key
        $key = wp_generate_password(12, false, false);
        
        // Save the key
        if (function_exists('update_field')) {
            update_field('school_key', $key, $post_id);
        } else {
            update_post_meta($post_id, 'school_key', $key);
        }

        // Return success response
        wp_send_json_success([
            'message' => __('Key generated successfully', 'neve-child'),
            'key' => $key,
            'post_id' => $post_id
        ]);
    }
}

<?php
/**
 * Quick Order Sorter - Ordenar productos en pedidos maestros desde el admin
 * 
 * Agrega botones de acción rápida en el admin de pedidos para:
 * - Reordenar productos por product_id
 * - Recalcular totales desde pedidos hijo
 * - Reconstruir master order completa
 * 
 * @package SchoolManagement\Orders
 * @since 1.0.0
 */

namespace SchoolManagement\Orders;

if (!defined('ABSPATH')) {
    exit;
}

class QuickOrderSorter
{
    public function __construct()
    {
        $this->initHooks();
    }

    /**
     * Inicializar hooks
     */
    private function initHooks(): void
    {
        // Agregar columna de acciones en el listado de pedidos
        add_filter('manage_shop_order_posts_columns', [$this, 'addQuickActionsColumn'], 20);
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'addQuickActionsColumn'], 20);
        
        // Renderizar contenido de la columna
        add_action('manage_shop_order_posts_custom_column', [$this, 'renderQuickActionsColumn'], 20, 2);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'renderQuickActionsColumnHPOS'], 20, 2);
        
        // Agregar metabox en la página de edición de pedido
        add_action('add_meta_boxes', [$this, 'addQuickActionsMetabox']);
        
        // Procesar acciones AJAX
        add_action('wp_ajax_quick_sort_order_items', [$this, 'ajaxSortOrderItems']);
        add_action('wp_ajax_quick_rebuild_master_order', [$this, 'ajaxRebuildMasterOrder']);
        
        // Agregar scripts y estilos en admin
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        
        // Agregar bulk action para ordenar múltiples pedidos
        add_filter('bulk_actions-edit-shop_order', [$this, 'addBulkSortAction']);
        add_filter('bulk_actions-woocommerce_page_wc-orders', [$this, 'addBulkSortAction']);
        add_filter('handle_bulk_actions-edit-shop_order', [$this, 'handleBulkSortAction'], 10, 3);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [$this, 'handleBulkSortAction'], 10, 3);
        add_action('admin_notices', [$this, 'showBulkActionNotices']);
    }

    /**
     * Agregar columna de acciones rápidas
     */
    public function addQuickActionsColumn(array $columns): array
    {
        // Insertar antes de la columna de acciones
        $new_columns = [];
        foreach ($columns as $key => $value) {
            if ($key === 'order_actions' || $key === 'wc_actions') {
                $new_columns['quick_sort'] = '<span class="dashicons dashicons-sort" title="' . __('Quick Sort', 'neve-child') . '"></span>';
            }
            $new_columns[$key] = $value;
        }
        return $new_columns;
    }

    /**
     * Renderizar columna de acciones rápidas (legacy)
     */
    public function renderQuickActionsColumn(string $column, int $post_id): void
    {
        if ($column !== 'quick_sort') {
            return;
        }

        $order = wc_get_order($post_id);
        if (!$order) {
            return;
        }

        $this->renderQuickButtons($order);
    }

    /**
     * Renderizar columna de acciones rápidas (HPOS)
     */
    public function renderQuickActionsColumnHPOS(string $column, $order): void
    {
        if ($column !== 'quick_sort') {
            return;
        }

        if (!is_a($order, 'WC_Order')) {
            return;
        }

        $this->renderQuickButtons($order);
    }

    /**
     * Renderizar botones de acciones rápidas
     */
    private function renderQuickButtons(\WC_Order $order): void
    {
        $order_id = $order->get_id();
        $is_master = $order->get_meta('_is_master_order') === 'yes';
        
        echo '<div class="quick-sort-actions" data-order-id="' . esc_attr($order_id) . '">';
        
        if ($is_master) {
            // Botón para reordenar productos
            echo '<button type="button" class="button button-small quick-sort-btn" title="' . esc_attr__('Sort products by ID', 'neve-child') . '">';
            echo '<span class="dashicons dashicons-sort"></span>';
            echo '</button>';
            
            // Botón para reconstruir master order
            echo '<button type="button" class="button button-small quick-rebuild-btn" title="' . esc_attr__('Rebuild master order', 'neve-child') . '">';
            echo '<span class="dashicons dashicons-update"></span>';
            echo '</button>';
        } else {
            // Para pedidos normales, solo mostrar ordenar si tiene items
            $items = $order->get_items();
            if (count($items) > 1) {
                echo '<button type="button" class="button button-small quick-sort-btn" title="' . esc_attr__('Sort products by ID', 'neve-child') . '">';
                echo '<span class="dashicons dashicons-sort"></span>';
                echo '</button>';
            } else {
                echo '<span style="color: #999;">-</span>';
            }
        }
        
        echo '</div>';
    }

    /**
     * Agregar metabox de acciones rápidas en la página de edición
     */
    public function addQuickActionsMetabox(): void
    {
        add_meta_box(
            'quick-order-actions',
            '<span class="dashicons dashicons-admin-tools"></span> ' . __('Quick Order Actions', 'neve-child'),
            [$this, 'renderQuickActionsMetabox'],
            'shop_order',
            'side',
            'high'
        );
        
        // Para HPOS
        add_meta_box(
            'quick-order-actions',
            '<span class="dashicons dashicons-admin-tools"></span> ' . __('Quick Order Actions', 'neve-child'),
            [$this, 'renderQuickActionsMetabox'],
            'woocommerce_page_wc-orders',
            'side',
            'high'
        );
    }

    /**
     * Renderizar metabox de acciones rápidas
     */
    public function renderQuickActionsMetabox($post_or_order): void
    {
        $order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);
        
        if (!$order) {
            return;
        }

        $order_id = $order->get_id();
        $is_master = $order->get_meta('_is_master_order') === 'yes';
        $items_count = count($order->get_items());
        
        ?>
        <div class="quick-order-actions-metabox">
            <style>
                .quick-order-actions-metabox .action-button {
                    width: 100%;
                    margin-bottom: 10px;
                    text-align: center;
                    padding: 10px;
                    border: none;
                    cursor: pointer;
                    border-radius: 3px;
                    font-weight: 600;
                    transition: all 0.3s;
                }
                .quick-order-actions-metabox .action-button:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
                }
                .quick-order-actions-metabox .action-button.sort {
                    background: #0073aa;
                    color: white;
                }
                .quick-order-actions-metabox .action-button.sort:hover {
                    background: #005177;
                }
                .quick-order-actions-metabox .action-button.rebuild {
                    background: #00a32a;
                    color: white;
                }
                .quick-order-actions-metabox .action-button.rebuild:hover {
                    background: #008a20;
                }
                .quick-order-actions-metabox .info {
                    background: #f0f0f1;
                    padding: 10px;
                    border-radius: 3px;
                    margin-bottom: 10px;
                    font-size: 12px;
                }
                .quick-order-actions-metabox .spinner {
                    float: none;
                    margin: 0 auto;
                    display: block;
                }
            </style>

            <?php if ($is_master): ?>
                <div class="info">
                    <strong><?php _e('Master Order', 'neve-child'); ?></strong><br>
                    <?php
                    $included_orders = $order->get_meta('_included_orders') ?: [];
                    printf(__('%d child orders', 'neve-child'), count($included_orders));
                    echo '<br>';
                    printf(__('%d products', 'neve-child'), $items_count);
                    ?>
                </div>

                <button type="button" 
                        class="action-button sort quick-sort-btn-metabox" 
                        data-order-id="<?php echo esc_attr($order_id); ?>">
                    <span class="dashicons dashicons-sort"></span>
                    <?php _e('Sort Products by ID', 'neve-child'); ?>
                </button>

                <button type="button" 
                        class="action-button rebuild quick-rebuild-btn-metabox" 
                        data-order-id="<?php echo esc_attr($order_id); ?>">
                    <span class="dashicons dashicons-update"></span>
                    <?php _e('Rebuild from Child Orders', 'neve-child'); ?>
                </button>

                <p style="font-size: 11px; color: #666; margin-top: 10px;">
                    <strong><?php _e('Sort:', 'neve-child'); ?></strong> <?php _e('Reorders products by product ID', 'neve-child'); ?><br>
                    <strong><?php _e('Rebuild:', 'neve-child'); ?></strong> <?php _e('Recalculates everything from child orders', 'neve-child'); ?>
                </p>

            <?php else: ?>
                <div class="info">
                    <?php printf(__('%d products', 'neve-child'), $items_count); ?>
                </div>

                <?php if ($items_count > 1): ?>
                    <button type="button" 
                            class="action-button sort quick-sort-btn-metabox" 
                            data-order-id="<?php echo esc_attr($order_id); ?>">
                        <span class="dashicons dashicons-sort"></span>
                        <?php _e('Sort Products by ID', 'neve-child'); ?>
                    </button>
                <?php else: ?>
                    <p style="color: #666; text-align: center;">
                        <?php _e('Order has only one product', 'neve-child'); ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>

            <div class="action-result" style="margin-top: 10px; display: none;"></div>
        </div>
        <?php
    }

    /**
     * Cargar scripts y estilos en admin
     */
    public function enqueueAdminAssets($hook): void
    {
        // Solo cargar en páginas de pedidos
        if ($hook !== 'edit.php' && $hook !== 'post.php' && strpos($hook, 'wc-orders') === false) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || ($screen->post_type !== 'shop_order' && strpos($screen->id, 'wc-orders') === false)) {
            return;
        }

        ?>
        <style>
            .quick-sort-actions {
                display: flex;
                gap: 5px;
            }
            .quick-sort-actions button {
                padding: 3px 8px !important;
                min-height: 0 !important;
                line-height: 1 !important;
            }
            .quick-sort-actions .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
            }
            .quick-sort-btn {
                background: #0073aa !important;
                border-color: #0073aa !important;
                color: white !important;
            }
            .quick-sort-btn:hover {
                background: #005177 !important;
                border-color: #005177 !important;
            }
            .quick-rebuild-btn {
                background: #00a32a !important;
                border-color: #00a32a !important;
                color: white !important;
            }
            .quick-rebuild-btn:hover {
                background: #008a20 !important;
                border-color: #008a20 !important;
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Handler para botón de ordenar en listado
            $(document).on('click', '.quick-sort-btn', function(e) {
                e.preventDefault();
                var button = $(this);
                var orderId = button.closest('.quick-sort-actions').data('order-id') || button.data('order-id');
                
                if (!confirm('<?php echo esc_js(__('Sort products by product ID?', 'neve-child')); ?>')) {
                    return;
                }
                
                button.prop('disabled', true).html('<span class="dashicons dashicons-update"></span>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'quick_sort_order_items',
                        order_id: orderId,
                        nonce: '<?php echo wp_create_nonce('quick_sort_order'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            button.html('<span class="dashicons dashicons-yes"></span>');
                            $('.action-result').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>').show();
                            
                            // Recargar la página después de 1 segundo
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            button.html('<span class="dashicons dashicons-no"></span>');
                            $('.action-result').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>').show();
                            button.prop('disabled', false).html('<span class="dashicons dashicons-sort"></span>');
                        }
                    },
                    error: function() {
                        button.html('<span class="dashicons dashicons-no"></span>');
                        $('.action-result').html('<div class="notice notice-error"><p><?php echo esc_js(__('Error processing request', 'neve-child')); ?></p></div>').show();
                        setTimeout(function() {
                            button.prop('disabled', false).html('<span class="dashicons dashicons-sort"></span>');
                        }, 2000);
                    }
                });
            });

            // Handler para botón de reconstruir
            $(document).on('click', '.quick-rebuild-btn, .quick-rebuild-btn-metabox', function(e) {
                e.preventDefault();
                var button = $(this);
                var orderId = button.closest('.quick-sort-actions').data('order-id') || button.data('order-id');
                
                if (!confirm('<?php echo esc_js(__('Rebuild master order from child orders? This will recalculate all products and totals.', 'neve-child')); ?>')) {
                    return;
                }
                
                var originalHtml = button.html();
                button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> <?php echo esc_js(__('Rebuilding...', 'neve-child')); ?>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'quick_rebuild_master_order',
                        order_id: orderId,
                        nonce: '<?php echo wp_create_nonce('quick_rebuild_order'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            button.html('<span class="dashicons dashicons-yes"></span> <?php echo esc_js(__('Done!', 'neve-child')); ?>');
                            $('.action-result').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>').show();
                            
                            // Recargar la página después de 1 segundo
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            button.html('<span class="dashicons dashicons-no"></span> <?php echo esc_js(__('Error', 'neve-child')); ?>');
                            $('.action-result').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>').show();
                            setTimeout(function() {
                                button.prop('disabled', false).html(originalHtml);
                            }, 2000);
                        }
                    },
                    error: function() {
                        button.html('<span class="dashicons dashicons-no"></span> <?php echo esc_js(__('Error', 'neve-child')); ?>');
                        $('.action-result').html('<div class="notice notice-error"><p><?php echo esc_js(__('Error processing request', 'neve-child')); ?></p></div>').show();
                        setTimeout(function() {
                            button.prop('disabled', false).html(originalHtml);
                        }, 2000);
                    }
                });
            });

            // Handler para botón de ordenar en metabox
            $(document).on('click', '.quick-sort-btn-metabox', function(e) {
                $('.quick-sort-btn').first().trigger('click');
            });

            // Animación de spin
            $('<style>.spin { animation: spin 1s linear infinite; } @keyframes spin { 100% { transform: rotate(360deg); } }</style>').appendTo('head');
        });
        </script>
        <?php
    }

    /**
     * AJAX: Ordenar productos de un pedido
     */
    public function ajaxSortOrderItems(): void
    {
        check_ajax_referer('quick_sort_order', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'neve-child')]);
        }

        $order_id = intval($_POST['order_id'] ?? 0);
        if (!$order_id) {
            wp_send_json_error(['message' => __('Invalid order ID', 'neve-child')]);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => __('Order not found', 'neve-child')]);
        }

        // Llamar a la función de ordenamiento
        $result = $this->sortOrderItems($order);

        if ($result['success']) {
            wp_send_json_success([
                'message' => sprintf(
                    __('Sorted %d products by product ID', 'neve-child'),
                    $result['sorted_count']
                )
            ]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }

    /**
     * AJAX: Reconstruir master order desde pedidos hijo
     */
    public function ajaxRebuildMasterOrder(): void
    {
        check_ajax_referer('quick_rebuild_order', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'neve-child')]);
        }

        $order_id = intval($_POST['order_id'] ?? 0);
        if (!$order_id) {
            wp_send_json_error(['message' => __('Invalid order ID', 'neve-child')]);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => __('Order not found', 'neve-child')]);
        }

        if ($order->get_meta('_is_master_order') !== 'yes') {
            wp_send_json_error(['message' => __('Not a master order', 'neve-child')]);
        }

        // Llamar a la función de reconstrucción
        $result = $this->rebuildMasterOrder($order);

        if ($result['success']) {
            wp_send_json_success([
                'message' => sprintf(
                    __('Rebuilt master order: %d products from %d child orders', 'neve-child'),
                    $result['products_count'],
                    $result['children_count']
                )
            ]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }

    /**
     * Ordenar items de un pedido por product_id
     */
    private function sortOrderItems(\WC_Order $order): array
    {
        $items = $order->get_items();
        
        if (empty($items)) {
            return [
                'success' => false,
                'message' => __('No products to sort', 'neve-child')
            ];
        }

        // Crear array con items y sus IDs de producto
        $items_with_product_id = [];
        foreach ($items as $item_id => $item) {
            if (!is_a($item, 'WC_Order_Item_Product')) {
                continue;
            }
            
            $product_id = $item->get_product_id();
            $items_with_product_id[] = [
                'item_id' => $item_id,
                'item' => $item,
                'product_id' => $product_id,
                'variation_id' => $item->get_variation_id(),
                'quantity' => $item->get_quantity(),
                'subtotal' => $item->get_subtotal(),
                'total' => $item->get_total(),
                'subtotal_tax' => $item->get_subtotal_tax(),
                'total_tax' => $item->get_total_tax(),
                'taxes' => $item->get_taxes(),
                'name' => $item->get_name()
            ];
        }

        if (empty($items_with_product_id)) {
            return [
                'success' => false,
                'message' => __('No valid products found', 'neve-child')
            ];
        }

        // Ordenar por product_id
        usort($items_with_product_id, function($a, $b) {
            return $a['product_id'] <=> $b['product_id'];
        });

        // Eliminar todos los items actuales
        foreach ($items as $item_id => $item) {
            $order->remove_item($item_id);
        }

        // Agregar items en el orden correcto
        foreach ($items_with_product_id as $item_data) {
            $product = wc_get_product($item_data['variation_id'] > 0 ? $item_data['variation_id'] : $item_data['product_id']);
            if (!$product) {
                continue;
            }

            $new_item = new \WC_Order_Item_Product();
            $new_item->set_props([
                'product' => $product,
                'quantity' => $item_data['quantity'],
                'subtotal' => $item_data['subtotal'],
                'total' => $item_data['total'],
                'name' => $item_data['name'],
                'tax_class' => $product->get_tax_class(),
            ]);
            
            $new_item->set_taxes($item_data['taxes']);
            $new_item->set_subtotal_tax($item_data['subtotal_tax']);
            $new_item->set_total_tax($item_data['total_tax']);
            
            if ($item_data['variation_id']) {
                $new_item->set_variation_id($item_data['variation_id']);
                $new_item->set_product_id($item_data['product_id']);
            } else {
                $new_item->set_product_id($item_data['product_id']);
            }
            
            $order->add_item($new_item);
        }

        $order->save();
        
        // Agregar nota
        $order->add_order_note(
            sprintf(__('Products sorted by ID (Quick Order Sorter). %d products reordered.', 'neve-child'), count($items_with_product_id))
        );

        return [
            'success' => true,
            'sorted_count' => count($items_with_product_id)
        ];
    }

    /**
     * Reconstruir master order desde pedidos hijo
     */
    private function rebuildMasterOrder(\WC_Order $master_order): array
    {
        $master_order_id = $master_order->get_id();
        $included_orders = $master_order->get_meta('_included_orders') ?: [];

        if (empty($included_orders)) {
            return [
                'success' => false,
                'message' => __('No child orders found', 'neve-child')
            ];
        }

        // Eliminar todos los items actuales
        foreach ($master_order->get_items() as $item_id => $item) {
            $master_order->remove_item($item_id);
        }

        // Agregar items desde pedidos hijo
        $products_added = [];
        
        foreach ($included_orders as $child_id) {
            $child_order = wc_get_order($child_id);
            if (!$child_order) {
                continue;
            }

            // Ignorar pedidos cancelados/trash/failed
            if (in_array($child_order->get_status(), ['cancelled', 'trash', 'failed'])) {
                continue;
            }

            foreach ($child_order->get_items() as $item) {
                if (!is_a($item, 'WC_Order_Item_Product')) {
                    continue;
                }

                $product_id = $item->get_product_id();
                $variation_id = $item->get_variation_id();
                $product_key = $product_id . '_' . $variation_id;

                if (!isset($products_added[$product_key])) {
                    $products_added[$product_key] = [
                        'product_id' => $product_id,
                        'variation_id' => $variation_id,
                        'quantity' => 0,
                        'subtotal' => 0,
                        'total' => 0,
                        'subtotal_tax' => 0,
                        'total_tax' => 0,
                        'taxes' => ['total' => [], 'subtotal' => []],
                        'name' => $item->get_name()
                    ];
                }

                $products_added[$product_key]['quantity'] += $item->get_quantity();
                $products_added[$product_key]['subtotal'] += $item->get_subtotal();
                $products_added[$product_key]['total'] += $item->get_total();
                $products_added[$product_key]['subtotal_tax'] += $item->get_subtotal_tax();
                $products_added[$product_key]['total_tax'] += $item->get_total_tax();

                // Combinar taxes
                $item_taxes = $item->get_taxes();
                if (!empty($item_taxes['total'])) {
                    foreach ($item_taxes['total'] as $tax_id => $tax_amount) {
                        if (!isset($products_added[$product_key]['taxes']['total'][$tax_id])) {
                            $products_added[$product_key]['taxes']['total'][$tax_id] = 0;
                        }
                        $products_added[$product_key]['taxes']['total'][$tax_id] += floatval($tax_amount);
                    }
                }
                if (!empty($item_taxes['subtotal'])) {
                    foreach ($item_taxes['subtotal'] as $tax_id => $tax_amount) {
                        if (!isset($products_added[$product_key]['taxes']['subtotal'][$tax_id])) {
                            $products_added[$product_key]['taxes']['subtotal'][$tax_id] = 0;
                        }
                        $products_added[$product_key]['taxes']['subtotal'][$tax_id] += floatval($tax_amount);
                    }
                }
            }
        }

        // Ordenar por product_id
        uasort($products_added, function($a, $b) {
            return $a['product_id'] <=> $b['product_id'];
        });

        // Agregar productos a la master order
        foreach ($products_added as $product_data) {
            $product = wc_get_product($product_data['variation_id'] > 0 ? $product_data['variation_id'] : $product_data['product_id']);
            if (!$product) {
                continue;
            }

            $new_item = new \WC_Order_Item_Product();
            $new_item->set_props([
                'product' => $product,
                'quantity' => $product_data['quantity'],
                'subtotal' => $product_data['subtotal'],
                'total' => $product_data['total'],
                'name' => $product_data['name'],
                'tax_class' => $product->get_tax_class(),
            ]);
            
            $new_item->set_taxes($product_data['taxes']);
            $new_item->set_subtotal_tax($product_data['subtotal_tax']);
            $new_item->set_total_tax($product_data['total_tax']);
            
            if ($product_data['variation_id']) {
                $new_item->set_variation_id($product_data['variation_id']);
                $new_item->set_product_id($product_data['product_id']);
            } else {
                $new_item->set_product_id($product_data['product_id']);
            }
            
            $master_order->add_item($new_item);
        }

        // Recalcular totales
        $master_order->calculate_totals();
        $master_order->save();

        // Agregar nota
        $master_order->add_order_note(
            sprintf(
                __('Master order rebuilt from child orders (Quick Order Sorter). %d products from %d child orders.', 'neve-child'),
                count($products_added),
                count($included_orders)
            )
        );

        return [
            'success' => true,
            'products_count' => count($products_added),
            'children_count' => count($included_orders)
        ];
    }

    /**
     * Agregar bulk action para ordenar
     */
    public function addBulkSortAction(array $actions): array
    {
        $actions['quick_sort_products'] = __('Sort Products by ID', 'neve-child');
        return $actions;
    }

    /**
     * Manejar bulk action de ordenar
     */
    public function handleBulkSortAction(string $redirect_to, string $action, array $post_ids): string
    {
        if ($action !== 'quick_sort_products') {
            return $redirect_to;
        }

        $sorted = 0;
        $errors = 0;

        foreach ($post_ids as $post_id) {
            $order = wc_get_order($post_id);
            if (!$order) {
                $errors++;
                continue;
            }

            $result = $this->sortOrderItems($order);
            if ($result['success']) {
                $sorted++;
            } else {
                $errors++;
            }
        }

        $redirect_to = add_query_arg([
            'bulk_sorted_orders' => $sorted,
            'bulk_sort_errors' => $errors
        ], $redirect_to);

        return $redirect_to;
    }

    /**
     * Mostrar notificaciones de bulk action
     */
    public function showBulkActionNotices(): void
    {
        if (!empty($_REQUEST['bulk_sorted_orders'])) {
            $sorted = intval($_REQUEST['bulk_sorted_orders']);
            $errors = intval($_REQUEST['bulk_sort_errors'] ?? 0);
            
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>' . __('Quick Order Sorter:', 'neve-child') . '</strong> ';
            printf(
                _n(
                    '%d order sorted by product ID.',
                    '%d orders sorted by product ID.',
                    $sorted,
                    'neve-child'
                ),
                $sorted
            );
            
            if ($errors > 0) {
                echo ' ';
                printf(__('%d errors.', 'neve-child'), $errors);
            }
            
            echo '</p></div>';
        }
    }
}

// Inicializar automáticamente
if (!isset($GLOBALS['quick_order_sorter'])) {
    $GLOBALS['quick_order_sorter'] = new QuickOrderSorter();
}

<?php
/**
 * MSRP Accumulator for tracking user lifetime MSRP values
 * 
 * Tracks and accumulates the total MSRP (Manufacturer's Suggested Retail Price) 
 * value for each user across all their orders, including handling refunds.
 * 
 * @package SchoolManagement\Orders
 * @since 1.0.0
 */

namespace SchoolManagement\Orders;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for accumulating MSRP values per user
 */
class MSRPAccumulator
{
    /**
     * Meta key for storing user's accumulated MSRP total
     */
    private const USER_MSRP_TOTAL_META = '_user_msrp_total';
    
    /**
     * Meta key for storing user's incoming total (Total Sales - MSRP)
     */
    private const USER_INCOMING_TOTAL_META = '_user_incoming_total';
    
    /**
     * Meta key for storing order's MSRP total
     */
    private const ORDER_MSRP_TOTAL_META = '_order_msrp_total';
    
    /**
     * ACF field name for product MSRP price
     */
    private const PRODUCT_MSRP_FIELD = 'msrp_price';
    
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->initHooks();
    }

    /**
     * Initialize WordPress hooks
     * 
     * @return void
     */
    public function initHooks(): void
    {
        // ⚠️ RESET ALL MSRP DATA - Execute once then comment out
        // $this->resetAllUserMSRPData();
        
        // ⚠️ COMMENT THE LINE ABOVE AFTER RUNNING ONCE ⚠️
        
        // Hook when order status changes to completed/processing (order paid)
        add_action('woocommerce_order_status_completed', [$this, 'addOrderMSRPToUser'], 10, 2);
        add_action('woocommerce_order_status_processing', [$this, 'addOrderMSRPToUser'], 10, 2);
        
        // Hook when refund is created
        add_action('woocommerce_order_refunded', [$this, 'subtractRefundMSRPFromUser'], 10, 2);
        
        // Hook when order status changes from completed/processing to cancelled/failed (subtract)
        add_action('woocommerce_order_status_cancelled', [$this, 'subtractOrderMSRPFromUser'], 10, 2);
        add_action('woocommerce_order_status_failed', [$this, 'subtractOrderMSRPFromUser'], 10, 2);
        
        // Hook to update incoming total when order status changes (for Total Sales recalculation)
        add_action('woocommerce_order_status_changed', [$this, 'handleOrderStatusChangeForIncoming'], 10, 4);
        
        // Hook when orders are permanently deleted or trashed
        add_action('woocommerce_delete_order', [$this, 'handleOrderDeletion'], 10, 2);
        add_action('wp_trash_post', [$this, 'handleOrderTrash']);
        add_action('before_delete_post', [$this, 'handleOrderPermanentDeletion']);
        
        // Debug hooks for manual recalculation
        add_action('wp_ajax_recalculate_user_msrp', [$this, 'ajaxRecalculateUserMSRP']);
        add_action('wp_ajax_get_user_msrp_total', [$this, 'ajaxGetUserMSRPTotal']);
        add_action('wp_ajax_recalculate_user_incoming', [$this, 'ajaxRecalculateUserIncoming']);
    }

    /**
     * Add order MSRP total to user when order is completed/processing
     * 
     * @param int $order_id Order ID
     * @param \WC_Order $order Order object
     * @return void
     */
    public function addOrderMSRPToUser(int $order_id, \WC_Order $order = null): void
    {
        if (!$order) {
            $order = wc_get_order($order_id);
        }
        
        if (!$order) {
            return;
        }

        $user_id = $order->get_user_id();
        if (!$user_id) {
            return;
        }

        // Check if we already processed this order
        $already_processed = $order->get_meta('_msrp_processed');
        if ($already_processed) {
            return;
        }

        $order_msrp_total = $this->calculateOrderMSRPTotal($order);
        
        if ($order_msrp_total <= 0) {
            return;
        }

        // Save order MSRP total in order meta
        $order->update_meta_data(self::ORDER_MSRP_TOTAL_META, $order_msrp_total);
        $order->update_meta_data('_msrp_processed', 'yes');
        $order->save();

        // Add to user's accumulated total
        $this->addToUserMSRPTotal($user_id, $order_msrp_total, $order_id, 'order_completed');
    }

    /**
     * Subtract order MSRP total from user when order is cancelled/failed
     * 
     * @param int $order_id Order ID
     * @param \WC_Order $order Order object
     * @return void
     */
    public function subtractOrderMSRPFromUser(int $order_id, \WC_Order $order = null): void
    {
        if (!$order) {
            $order = wc_get_order($order_id);
        }
        
        if (!$order) {
            return;
        }

        $user_id = $order->get_user_id();
        if (!$user_id) {
            return;
        }

        // Check if this order was previously processed
        $already_processed = $order->get_meta('_msrp_processed');
        if (!$already_processed) {
            return;
        }

        // Get the MSRP total that was previously added
        $order_msrp_total = $order->get_meta(self::ORDER_MSRP_TOTAL_META);
        
        if (!$order_msrp_total || $order_msrp_total <= 0) {
            return;
        }

        // Subtract from user's accumulated total
        $this->subtractFromUserMSRPTotal($user_id, $order_msrp_total, $order_id, 'order_cancelled');

        // Mark as not processed
        $order->update_meta_data('_msrp_processed', '');
        $order->save();
    }

    /**
     * Handle refunds by subtracting MSRP proportionally
     * 
     * @param int $order_id Order ID
     * @param int $refund_id Refund ID
     * @return void
     */
    public function subtractRefundMSRPFromUser(int $order_id, int $refund_id): void
    {
        $order = wc_get_order($order_id);
        $refund = wc_get_order($refund_id);
        
        if (!$order || !$refund) {
            return;
        }

        $user_id = $order->get_user_id();
        if (!$user_id) {
            return;
        }

        // Calculate MSRP for refunded items
        $refund_msrp_total = $this->calculateOrderMSRPTotal($refund);
        
        // For refunds, the MSRP total should be positive (we'll subtract it)
        $refund_msrp_total = abs($refund_msrp_total);
        
        if ($refund_msrp_total <= 0) {
            return;
        }

        // Subtract from user's accumulated total
        $this->subtractFromUserMSRPTotal($user_id, $refund_msrp_total, $refund_id, 'refund');

        // Store refund MSRP total for reference
        $refund->update_meta_data(self::ORDER_MSRP_TOTAL_META, $refund_msrp_total);
        $refund->save();
    }

    /**
     * Calculate total MSRP for an order
     * 
     * @param \WC_Order|\WC_Order_Refund $order Order or refund object
     * @return float Total MSRP
     */
    private function calculateOrderMSRPTotal($order): float
    {
        $total_msrp = 0.0;
        
        foreach ($order->get_items() as $item_id => $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            
            // Use variation ID if available, otherwise product ID
            $product_id_for_acf = $variation_id ? $variation_id : $product_id;
            
            // Get MSRP price from ACF field
            $msrp_price = get_field(self::PRODUCT_MSRP_FIELD, $product_id_for_acf);
            
            if (!$msrp_price || !is_numeric($msrp_price)) {
                // Try to get from parent product if variation doesn't have it
                if ($variation_id) {
                    $msrp_price = get_field(self::PRODUCT_MSRP_FIELD, $product_id);
                }
                
                // If still no MSRP, skip this item
                if (!$msrp_price || !is_numeric($msrp_price)) {
                    continue;
                }
            }

            $quantity = $item->get_quantity();
            $item_msrp_total = (float) $msrp_price * abs($quantity);
            $total_msrp += $item_msrp_total;
        }

        return $total_msrp;
    }

    /**
     * Add amount to user's MSRP total
     * 
     * @param int $user_id User ID
     * @param float $amount Amount to add
     * @param int $related_id Related order/refund ID
     * @param string $reason Reason for the change
     * @return void
     */
    private function addToUserMSRPTotal(int $user_id, float $amount, int $related_id, string $reason): void
    {
        $current_total = (float) get_user_meta($user_id, self::USER_MSRP_TOTAL_META, true);
        $new_total = $current_total + $amount;
        
        update_user_meta($user_id, self::USER_MSRP_TOTAL_META, $new_total);
        
        // Update incoming total (Total Sales - MSRP)
        $this->updateUserIncomingTotal($user_id);
        
        // Log the change for audit purposes
        $this->logMSRPChange($user_id, $amount, $new_total, $related_id, $reason, 'add');
    }

    /**
     * Subtract amount from user's MSRP total
     * 
     * @param int $user_id User ID
     * @param float $amount Amount to subtract
     * @param int $related_id Related order/refund ID
     * @param string $reason Reason for the change
     * @return void
     */
    private function subtractFromUserMSRPTotal(int $user_id, float $amount, int $related_id, string $reason): void
    {
        $current_total = (float) get_user_meta($user_id, self::USER_MSRP_TOTAL_META, true);
        $new_total = max(0, $current_total - $amount); // Don't allow negative totals
        
        update_user_meta($user_id, self::USER_MSRP_TOTAL_META, $new_total);
        
        // Update incoming total (Total Sales - MSRP)
        $this->updateUserIncomingTotal($user_id);
        
        // Log the change for audit purposes
        $this->logMSRPChange($user_id, $amount, $new_total, $related_id, $reason, 'subtract');
    }

    /**
     * Log MSRP changes for audit purposes
     * 
     * @param int $user_id User ID
     * @param float $amount Amount changed
     * @param float $new_total New total after change
     * @param int $related_id Related order/refund ID
     * @param string $reason Reason for change
     * @param string $operation Operation type (add/subtract)
     * @return void
     */
    private function logMSRPChange(int $user_id, float $amount, float $new_total, int $related_id, string $reason, string $operation): void
    {
        $log_entry = [
            'timestamp' => current_time('mysql'),
            'user_id' => $user_id,
            'amount' => $amount,
            'new_total' => $new_total,
            'related_id' => $related_id,
            'reason' => $reason,
            'operation' => $operation
        ];
        
        // Get existing log
        $msrp_log = get_user_meta($user_id, '_msrp_change_log', true);
        if (!is_array($msrp_log)) {
            $msrp_log = [];
        }
        
        // Add new entry
        $msrp_log[] = $log_entry;
        
        // Keep only last 100 entries to avoid bloating
        if (count($msrp_log) > 100) {
            $msrp_log = array_slice($msrp_log, -100);
        }
        
        update_user_meta($user_id, '_msrp_change_log', $msrp_log);
    }

    /**
     * Get user's current MSRP total
     * 
     * @param int $user_id User ID
     * @return float Current MSRP total
     */
    public function getUserMSRPTotal(int $user_id): float
    {
        return (float) get_user_meta($user_id, self::USER_MSRP_TOTAL_META, true);
    }

    /**
     * Get user's MSRP change log
     * 
     * @param int $user_id User ID
     * @param int $limit Number of entries to return (default: 50)
     * @return array Change log
     */
    public function getUserMSRPLog(int $user_id, int $limit = 50): array
    {
        $log = get_user_meta($user_id, '_msrp_change_log', true);
        if (!is_array($log)) {
            return [];
        }
        
        // Return most recent entries first
        $log = array_reverse($log);
        
        if ($limit > 0) {
            return array_slice($log, 0, $limit);
        }
        
        return $log;
    }

    /**
     * Recalculate user's MSRP total from all orders (for correction purposes)
     * 
     * @param int $user_id User ID
     * @return float Recalculated total
     */
    public function recalculateUserMSRPTotal(int $user_id): float
    {
        $total = 0.0;

        // Get all completed/processing orders for this user
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'status' => ['completed', 'processing'],
            'limit' => -1,
            'type' => 'shop_order'
        ]);

        foreach ($orders as $order) {
            $order_msrp = $this->calculateOrderMSRPTotal($order);
            $total += $order_msrp;
        }

        // Subtract refunds
        $refunds = wc_get_orders([
            'customer_id' => $user_id,
            'limit' => -1,
            'type' => 'shop_order_refund'
        ]);

        foreach ($refunds as $refund) {
            $refund_msrp = $this->calculateOrderMSRPTotal($refund);
            $refund_msrp = abs($refund_msrp); // Make sure it's positive for subtraction
            $total -= $refund_msrp;
        }

        // Ensure total is not negative
        $total = max(0, $total);

        // Update user meta
        update_user_meta($user_id, self::USER_MSRP_TOTAL_META, $total);

        return $total;
    }

    /**
     * AJAX handler for recalculating user MSRP total
     * 
     * @return void
     */
    public function ajaxRecalculateUserMSRP(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $user_id = intval($_POST['user_id'] ?? 0);
        if (!$user_id) {
            wp_send_json_error('Invalid user ID');
        }

        $total = $this->recalculateUserMSRPTotal($user_id);
        
        wp_send_json_success([
            'user_id' => $user_id,
            'new_total' => $total,
            'message' => sprintf(__('MSRP total recalculated for user #%d: $%.2f', 'neve-child'), $user_id, $total)
        ]);
    }

    /**
     * AJAX handler for getting user MSRP total
     * 
     * @return void
     */
    public function ajaxGetUserMSRPTotal(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? $_GET['_wpnonce'] ?? '', 'get_user_msrp_total')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $user_id = intval($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
        if (!$user_id) {
            wp_send_json_error('Invalid user ID');
        }

        $total = $this->getUserMSRPTotal($user_id);
        $log = $this->getUserMSRPLog($user_id, 10);
        
        wp_send_json_success([
            'user_id' => $user_id,
            'total' => $total,
            'recent_changes' => $log
        ]);
    }

    /**
     * Update user's incoming total (Total Sales - MSRP)
     * 
     * @param int $user_id User ID
     * @return void
     */
    private function updateUserIncomingTotal(int $user_id): void
    {
        // Obtener el total MSRP acumulado
        $msrp_total = (float) get_user_meta($user_id, self::USER_MSRP_TOTAL_META, true);
        
        // Obtener el total de ventas del usuario (de WooCommerce)
        $total_sales = 0.0;
        
        // Obtener todos los pedidos completados del usuario
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'status' => ['completed', 'processing'],
            'limit' => -1,
            'type' => 'shop_order'
        ]);
        
        foreach ($orders as $order) {
            $total_sales += (float) $order->get_total();
        }
        
        // Calcular incoming: Total Sales - MSRP
        $incoming_total = max(0, $total_sales - $msrp_total); // No permitir valores negativos
        
        // Guardar el valor calculado
        update_user_meta($user_id, self::USER_INCOMING_TOTAL_META, $incoming_total);
    }

    /**
     * Handle order status changes to update incoming total
     * 
     * @param int $order_id Order ID
     * @param string $old_status Old order status
     * @param string $new_status New order status
     * @param \WC_Order $order Order object
     * @return void
     */
    public function handleOrderStatusChangeForIncoming(int $order_id, string $old_status, string $new_status, \WC_Order $order): void
    {
        $user_id = $order->get_user_id();
        if (!$user_id) {
            return;
        }
        
        // Solo actualizar si el cambio afecta a las ventas totales
        // (de/hacia completed/processing que son estados que cuentan para total sales)
        $sales_statuses = ['completed', 'processing'];
        
        $old_counts = in_array($old_status, $sales_statuses);
        $new_counts = in_array($new_status, $sales_statuses);
        
        // Solo actualizar si hay un cambio en si la orden cuenta para ventas o no
        if ($old_counts !== $new_counts) {
            $this->updateUserIncomingTotal($user_id);
        }
    }

    /**
     * AJAX handler to recalculate user incoming total
     */
    public function ajaxRecalculateUserIncoming(): void
    {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? $_GET['_wpnonce'] ?? '', 'recalculate_user_incoming')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $user_id = intval($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
        if (!$user_id) {
            wp_send_json_error('Invalid user ID');
        }

        // Recalcular el incoming total
        $this->updateUserIncomingTotal($user_id);
        
        // Obtener el nuevo valor
        $incoming_total = (float) get_user_meta($user_id, self::USER_INCOMING_TOTAL_META, true);
        $msrp_total = (float) get_user_meta($user_id, self::USER_MSRP_TOTAL_META, true);
        
        // Calcular total sales para mostrar en respuesta
        $total_sales = 0.0;
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'status' => ['completed', 'processing'],
            'limit' => -1,
            'type' => 'shop_order'
        ]);
        
        foreach ($orders as $order) {
            $total_sales += (float) $order->get_total();
        }
        
        wp_send_json_success([
            'user_id' => $user_id,
            'total_sales' => $total_sales,
            'msrp_total' => $msrp_total,
            'incoming_total' => $incoming_total,
            'calculation' => "€{$total_sales} - €{$msrp_total} = €{$incoming_total}"
        ]);
    }

    /**
     * Handle order deletion via WooCommerce hook
     * 
     * @param int $order_id Order ID
     * @param \WC_Order $order Order object (if available)
     * @return void
     */
    public function handleOrderDeletion(int $order_id, $order = null): void
    {
        // If we don't have the order object, try to get it
        if (!$order) {
            $order = wc_get_order($order_id);
        }
        
        // If still no order, we can't process the deletion
        if (!$order || $order->get_type() !== 'shop_order') {
            return;
        }
        
        $customer_id = $order->get_customer_id();
        if (!$customer_id) {
            return;
        }
        
        // Log the deletion for debugging
        error_log("MSRPAccumulator: Order #{$order_id} deleted, recalculating MSRP for user #{$customer_id}");
        
        // Recalculate the user's total MSRP from scratch
        $this->recalculateUserTotalsFromOrders($customer_id);
    }

    /**
     * Handle order being moved to trash
     * 
     * @param int $post_id Post ID
     * @return void
     */
    public function handleOrderTrash(int $post_id): void
    {
        $post = get_post($post_id);
        
        // Only process shop_order post types
        if (!$post || $post->post_type !== 'shop_order') {
            return;
        }
        
        $order = wc_get_order($post_id);
        if (!$order) {
            return;
        }
        
        $customer_id = $order->get_customer_id();
        if (!$customer_id) {
            return;
        }
        
        // Log the trash action for debugging
        error_log("MSRPAccumulator: Order #{$post_id} moved to trash, recalculating MSRP for user #{$customer_id}");
        
        // Recalculate the user's total MSRP from scratch
        $this->recalculateUserTotalsFromOrders($customer_id);
    }

    /**
     * Handle order being permanently deleted
     * 
     * @param int $post_id Post ID
     * @return void
     */
    public function handleOrderPermanentDeletion(int $post_id): void
    {
        $post = get_post($post_id);
        
        // Only process shop_order post types
        if (!$post || $post->post_type !== 'shop_order') {
            return;
        }
        
        $order = wc_get_order($post_id);
        if (!$order) {
            return;
        }
        
        $customer_id = $order->get_customer_id();
        if (!$customer_id) {
            return;
        }
        
        // Log the permanent deletion for debugging
        error_log("MSRPAccumulator: Order #{$post_id} permanently deleted, recalculating MSRP for user #{$customer_id}");
        
        // Recalculate the user's total MSRP from scratch
        $this->recalculateUserTotalsFromOrders($customer_id);
    }

    /**
     * Recalculate user totals from existing orders (used after deletions)
     * 
     * @param int $user_id User ID
     * @return void
     */
    private function recalculateUserTotalsFromOrders(int $user_id): void
    {
        // Get all existing orders for the user (excluding trashed/deleted ones)
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'status' => ['completed', 'processing'], // Only count paid orders
            'limit' => -1,
            'type' => 'shop_order'
        ]);
        
        $total_msrp = 0.0;
        
        foreach ($orders as $order) {
            $order_msrp = $this->calculateOrderMSRPTotal($order);
            $total_msrp += $order_msrp;
        }
        
        // Update user meta with recalculated total
        update_user_meta($user_id, self::USER_MSRP_TOTAL_META, $total_msrp);
        
        // Also recalculate incoming total
        $this->updateUserIncomingTotal($user_id);
        
        error_log("MSRPAccumulator: Recalculated totals for user #{$user_id} - MSRP: €{$total_msrp}");
    }

    /**
     * ⚠️ RESET ALL USER MSRP DATA - Use only once for initialization ⚠️
     * 
     * Clears all MSRP and incoming totals for all users and recalculates from scratch
     * This should be run once and then commented out
     * 
     * @return void
     */
    private function resetAllUserMSRPData(): void
    {
        global $wpdb;
        
        // Delete all MSRP-related user meta
        $deleted_msrp = $wpdb->delete(
            $wpdb->usermeta,
            ['meta_key' => self::USER_MSRP_TOTAL_META],
            ['%s']
        );
        
        $deleted_incoming = $wpdb->delete(
            $wpdb->usermeta,
            ['meta_key' => self::USER_INCOMING_TOTAL_META],
            ['%s']
        );
        
        error_log("MSRPAccumulator RESET: Deleted {$deleted_msrp} MSRP totals and {$deleted_incoming} incoming totals");
        
        // Get ALL users (not just those with orders, since orders were deleted)
        $all_users = get_users([
            'fields' => 'ID',
            'number' => -1 // Get all users
        ]);
        
        if (empty($all_users)) {
            error_log("MSRPAccumulator RESET: No users found in the system");
            return;
        }
        
        $processed_users = 0;
        $total_users = count($all_users);
        
        error_log("MSRPAccumulator RESET: Starting ZERO reset for {$total_users} total users (since orders were deleted)");
        
        // Set all users to ZERO since orders don't exist
        foreach ($all_users as $user_id) {
            $user_id = (int) $user_id;
            
            // Skip invalid user IDs
            if ($user_id <= 0) {
                continue;
            }
            
            // Set both MSRP and incoming totals to ZERO
            update_user_meta($user_id, self::USER_MSRP_TOTAL_META, 0.0);
            update_user_meta($user_id, self::USER_INCOMING_TOTAL_META, 0.0);
            
            $processed_users++;
            
            // Log progress every 50 users
            if ($processed_users % 50 === 0 || $processed_users === $total_users) {
                error_log("MSRPAccumulator RESET: Set to ZERO {$processed_users}/{$total_users} users");
            }
        }
        
        error_log("MSRPAccumulator RESET: ✅ COMPLETE! Set {$processed_users} users to ZERO. REMEMBER TO COMMENT OUT THE RESET CALL!");
        error_log("MSRPAccumulator RESET: ⚠️ Go to initHooks() and comment out the resetAllUserMSRPData() call");
    }
}
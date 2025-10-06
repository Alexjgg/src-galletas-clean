<?php
/**
 * MSRP Total Recalculator
 * 
 * Recalcula todos los totales MSRP e Incoming de todos los usuarios
 * desde cero, revisando todos sus pedidos y aplicando la lógica correcta.
 * 
 * @package SchoolManagement\Orders
 * @since 1.0.0
 */

namespace SchoolManagement\Orders;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for recalculating all MSRP and Incoming totals
 */
class MSRPTotalRecalculator
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
     * Estados de pedidos que NO deben contar como ventas válidas
     */
    private const EXCLUDED_STATUSES = ['cancelled', 'failed', 'pending'];
    
    /**
     * Constructor
     */
    public function __construct()
    {
        // Añadir AJAX handler para ejecutar desde admin
        add_action('wp_ajax_recalculate_all_msrp_totals', [$this, 'ajaxRecalculateAllTotals']);
        
        // Hook para ejecutar recálculo automático si se detecta que es necesario
        // (opcional, comentado por defecto para no ejecutar automáticamente)
        // add_action('wp_loaded', [$this, 'maybeRunAutoRecalculation'], 99);
    }
    
    /**
     * Recalcular todos los totales MSRP e Incoming de todos los usuarios
     * 
     * @param int $batch_size Número de usuarios a procesar por lote
     * @param bool $silent Si es true, no muestra output
     * @return array Resultado del procesamiento
     */
    public function recalculateAllUserTotals(int $batch_size = 50, bool $silent = false): array
    {
        $start_time = microtime(true);
        $results = [
            'total_users' => 0,
            'processed_users' => 0,
            'updated_users' => 0,
            'total_orders_processed' => 0,
            'total_refunds_processed' => 0,
            'errors' => [],
            'processing_time' => 0,
            'memory_usage' => 0
        ];
        
        if (!$silent) {
            echo "🔄 INICIANDO RECÁLCULO COMPLETO DE MSRP E INCOMING...\n";
            echo "==========================================\n\n";
        }
        
        // Obtener todos los usuarios con pedidos
        $users = $this->getUsersWithOrders();
        $results['total_users'] = count($users);
        
        if (!$silent) {
            echo "👥 Usuarios encontrados con pedidos: {$results['total_users']}\n";
            
            // Debug: mostrar información del sistema WooCommerce
            if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && 
                \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
                echo "ℹ️  Sistema: HPOS (High-Performance Order Storage)\n";
            } else {
                echo "ℹ️  Sistema: Clásico (wp_posts)\n";
            }
            echo "\n";
        }
        
        // Si no hay usuarios, intentar diagnosticar el problema
        if ($results['total_users'] === 0 && !$silent) {
            $this->debugOrderSystem();
        }
        
        // Procesar usuarios en lotes
        $user_chunks = array_chunk($users, $batch_size);
        
        foreach ($user_chunks as $chunk_index => $user_chunk) {
            if (!$silent) {
                echo "📦 Procesando lote " . ($chunk_index + 1) . "/" . count($user_chunks) . " (" . count($user_chunk) . " usuarios)...\n";
            }
            
            foreach ($user_chunk as $user_id) {
                try {
                    $user_result = $this->recalculateUserTotals($user_id);
                    
                    if ($user_result['updated']) {
                        $results['updated_users']++;
                        if (!$silent) {
                            echo "  ✅ Usuario #{$user_id}: MSRP: €{$user_result['msrp_total']}, Incoming: €{$user_result['incoming_total']} ({$user_result['orders_count']} pedidos, {$user_result['refunds_count']} reembolsos)\n";
                        }
                    } else {
                        if (!$silent) {
                            $refund_info = $user_result['refunds_count'] > 0 ? ", {$user_result['refunds_count']} reembolsos" : "";
                            echo "  ⚪ Usuario #{$user_id}: Sin cambios necesarios ({$user_result['orders_count']} pedidos{$refund_info})\n";
                        }
                    }
                    
                    $results['processed_users']++;
                    $results['total_orders_processed'] += $user_result['orders_count'];
                    $results['total_refunds_processed'] += $user_result['refunds_count'];
                    
                } catch (Exception $e) {
                    $error_msg = "Error procesando usuario #{$user_id}: " . $e->getMessage();
                    $results['errors'][] = $error_msg;
                    if (!$silent) {
                        echo "  ❌ {$error_msg}\n";
                    }
                }
            }
            
            if (!$silent) {
                echo "\n";
            }
            
            // Liberar memoria
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
        }
        
        $end_time = microtime(true);
        $results['processing_time'] = round($end_time - $start_time, 2);
        $results['memory_usage'] = $this->formatBytes(memory_get_peak_usage(true));
        
        if (!$silent) {
            $this->printSummary($results);
        }
        
        return $results;
    }
    
    /**
     * Recalcular totales para un usuario específico
     * 
     * @param int $user_id User ID
     * @return array Resultado del procesamiento
     */
    public function recalculateUserTotals(int $user_id): array
    {
        $result = [
            'user_id' => $user_id,
            'updated' => false,
            'msrp_total' => 0.0,
            'incoming_total' => 0.0,
            'orders_count' => 0,
            'refunds_count' => 0,
            'total_sales' => 0.0
        ];
        
        // 1. Obtener valores actuales
        $current_msrp = (float) get_user_meta($user_id, self::USER_MSRP_TOTAL_META, true);
        $current_incoming = (float) get_user_meta($user_id, self::USER_INCOMING_TOTAL_META, true);
        
        // 2. Calcular MSRP total desde pedidos válidos
        $msrp_from_orders = $this->calculateUserMSRPFromOrders($user_id);
        $result['msrp_total'] = $msrp_from_orders['total'];
        $result['orders_count'] = $msrp_from_orders['orders_count'];
        
        // 3. Restar MSRP de reembolsos
        $msrp_from_refunds = $this->calculateUserMSRPFromRefunds($user_id);
        $result['msrp_total'] -= $msrp_from_refunds['total'];
        $result['refunds_count'] = $msrp_from_refunds['refunds_count'];
        
        // Asegurar que no sea negativo
        $result['msrp_total'] = max(0, $result['msrp_total']);
        
        // 4. Calcular total de ventas (para incoming)
        $result['total_sales'] = $this->calculateUserTotalSales($user_id);
        
        // 5. Calcular incoming: MSRP - Total Sales
        $result['incoming_total'] = max(0, $result['msrp_total'] - $result['total_sales']);
        
        // 6. Actualizar solo si hay diferencias significativas (>0.01€)
        $msrp_diff = abs($current_msrp - $result['msrp_total']);
        $incoming_diff = abs($current_incoming - $result['incoming_total']);
        
        if ($msrp_diff > 0.01 || $incoming_diff > 0.01) {
            update_user_meta($user_id, self::USER_MSRP_TOTAL_META, $result['msrp_total']);
            update_user_meta($user_id, self::USER_INCOMING_TOTAL_META, $result['incoming_total']);
            
            // Log del cambio
            $this->logRecalculationChange($user_id, [
                'old_msrp' => $current_msrp,
                'new_msrp' => $result['msrp_total'],
                'old_incoming' => $current_incoming,
                'new_incoming' => $result['incoming_total']
            ]);
            
            $result['updated'] = true;
        }
        
        return $result;
    }
    
    /**
     * Calcular MSRP total desde pedidos válidos
     * 
     * @param int $user_id User ID
     * @return array
     */
    private function calculateUserMSRPFromOrders(int $user_id): array
    {
        $total_msrp = 0.0;
        $orders_count = 0;
        
        // Obtener todos los pedidos del usuario (excluyendo estados no válidos)
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'limit' => -1,
            'type' => 'shop_order'
        ]);
        
        foreach ($orders as $order) {
            // Saltar pedidos con estados excluidos
            if (in_array($order->get_status(), self::EXCLUDED_STATUSES)) {
                continue;
            }
            
            $order_msrp = $this->calculateOrderMSRPTotal($order);
            $total_msrp += $order_msrp;
            $orders_count++;
            
            // RECÁLCULO LIMPIO: Limpiar metadata previa para evitar conflictos
            // y luego marcar como procesado para el sistema en tiempo real
            $order->delete_meta_data('_msrp_processed');
            $order->update_meta_data(self::ORDER_MSRP_TOTAL_META, $order_msrp);
            $order->update_meta_data('_msrp_processed', 'yes');
            $order->update_meta_data('_msrp_recalc_processed', current_time('mysql'));
            $order->save_meta_data();
        }
        
        return [
            'total' => $total_msrp,
            'orders_count' => $orders_count
        ];
    }
    
    /**
     * Calcular MSRP total desde reembolsos
     * Usa método híbrido: reembolsos directos + reembolsos dentro de pedidos
     * 
     * @param int $user_id User ID
     * @return array
     */
    private function calculateUserMSRPFromRefunds(int $user_id): array
    {
        $total_refund_msrp = 0.0;
        $refunds_count = 0;
        
        // MÉTODO 1: Reembolsos directos (shop_order_refund)
        $direct_refunds = wc_get_orders([
            'customer_id' => $user_id,
            'limit' => -1,
            'type' => 'shop_order_refund'
        ]);
        
        foreach ($direct_refunds as $refund) {
            $refund_msrp = abs($this->calculateOrderMSRPTotal($refund));
            $total_refund_msrp += $refund_msrp;
            $refunds_count++;
            
            // RECÁLCULO LIMPIO: Actualizar meta del reembolso para referencia
            $refund->update_meta_data(self::ORDER_MSRP_TOTAL_META, $refund_msrp);
            $refund->update_meta_data('_msrp_recalc_processed', current_time('mysql'));
            $refund->save_meta_data();
        }
        
        // MÉTODO 2: Reembolsos dentro de pedidos individuales (más común)
        $user_orders = wc_get_orders([
            'customer_id' => $user_id,
            'limit' => -1,
            'type' => 'shop_order'
        ]);
        
        foreach ($user_orders as $order) {
            // Obtener reembolsos de cada pedido
            $order_refunds = $order->get_refunds();
            
            foreach ($order_refunds as $order_refund) {
                $refund_msrp = abs($this->calculateOrderMSRPTotal($order_refund));
                if ($refund_msrp > 0) {
                    $total_refund_msrp += $refund_msrp;
                    $refunds_count++;
                    
                    // RECÁLCULO LIMPIO: Actualizar meta del reembolso para referencia
                    $order_refund->update_meta_data(self::ORDER_MSRP_TOTAL_META, $refund_msrp);
                    $order_refund->update_meta_data('_msrp_recalc_processed', current_time('mysql'));
                    $order_refund->save_meta_data();
                }
            }
        }
        
        return [
            'total' => $total_refund_msrp,
            'refunds_count' => $refunds_count
        ];
    }
    
    /**
     * Calcular total de ventas del usuario (misma lógica que woocommerce-users.php)
     * 
     * @param int $user_id User ID
     * @return float
     */
    private function calculateUserTotalSales(int $user_id): float
    {
        $total_sales = 0.0;
        
        // Obtener todos los pedidos del usuario
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'limit' => -1,
            'type' => 'shop_order'
        ]);
        
        // Filtrar pedidos válidos (excluyendo estados no deseados)
        foreach ($orders as $order) {
            if (in_array($order->get_status(), self::EXCLUDED_STATUSES)) {
                continue;
            }
            
            $order_total = (float) $order->get_total();
            
            // Restar reembolsos del pedido
            $refunds = $order->get_refunds();
            foreach ($refunds as $refund) {
                $order_total -= abs((float) $refund->get_amount());
            }
            
            $total_sales += $order_total;
        }
        
        return max(0, $total_sales);
    }
    
    /**
     * Calculate total MSRP for an order (reutilizada de MSRPAccumulator)
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
            $msrp_price_raw = get_field(self::PRODUCT_MSRP_FIELD, $product_id_for_acf);
            $msrp_price = $this->normalizeMSRPPrice($msrp_price_raw);
            
            if ($msrp_price === false) {
                // Try to get from parent product if variation doesn't have it
                if ($variation_id) {
                    $msrp_price_raw = get_field(self::PRODUCT_MSRP_FIELD, $product_id);
                    $msrp_price = $this->normalizeMSRPPrice($msrp_price_raw);
                }
                
                // If still no MSRP, skip this item
                if ($msrp_price === false) {
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
     * Normalize MSRP price value (reutilizada de MSRPAccumulator)
     * 
     * @param mixed $price_value The MSRP price value from ACF
     * @return float|false Returns normalized float value or false if invalid
     */
    private function normalizeMSRPPrice($price_value)
    {
        if (empty($price_value)) {
            return false;
        }
        
        // Convert to string for processing
        $price_str = (string) $price_value;
        
        // Replace comma with dot for decimal separator (European format support)
        $normalized_price = str_replace(',', '.', $price_str);
        
        // Check if it's numeric after normalization
        if (!is_numeric($normalized_price)) {
            return false;
        }
        
        return (float) $normalized_price;
    }
    
    /**
     * Obtener todos los usuarios que tienen pedidos
     * Compatible con HPOS (High-Performance Order Storage) y sistema clásico
     * 
     * @return array User IDs
     */
    private function getUsersWithOrders(): array
    {
        global $wpdb;
        $user_ids = [];
        
        // Verificar si WooCommerce está usando HPOS
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && 
            \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            
            // Usar tablas HPOS
            $hpos_orders_table = $wpdb->prefix . 'wc_orders';
            
            $user_ids = $wpdb->get_col($wpdb->prepare("
                SELECT DISTINCT customer_id 
                FROM {$hpos_orders_table}
                WHERE customer_id > 0 
                AND type IN ('shop_order', 'shop_order_refund')
                ORDER BY customer_id ASC
            "));
            
        } else {
            // Sistema clásico con wp_posts
            $user_ids = $wpdb->get_col($wpdb->prepare("
                SELECT DISTINCT pm.meta_value 
                FROM {$wpdb->postmeta} pm 
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id 
                WHERE pm.meta_key = '_customer_user' 
                AND pm.meta_value > 0 
                AND p.post_type IN ('shop_order', 'shop_order_refund')
                ORDER BY pm.meta_value ASC
            "));
        }
        
        return array_map('intval', array_filter($user_ids));
    }
    
    /**
     * Log cambios de recálculo
     * 
     * @param int $user_id User ID
     * @param array $changes Changes made
     * @return void
     */
    private function logRecalculationChange(int $user_id, array $changes): void
    {
        $log_entry = [
            'timestamp' => current_time('mysql'),
            'user_id' => $user_id,
            'action' => 'full_recalculation',
            'changes' => $changes
        ];
        
        // Obtener log existente
        $recalc_log = get_user_meta($user_id, '_msrp_recalc_log', true);
        if (!is_array($recalc_log)) {
            $recalc_log = [];
        }
        
        // Añadir nueva entrada
        $recalc_log[] = $log_entry;
        
        // Mantener solo las últimas 20 entradas
        if (count($recalc_log) > 20) {
            $recalc_log = array_slice($recalc_log, -20);
        }
        
        update_user_meta($user_id, '_msrp_recalc_log', $recalc_log);
    }
    
    /**
     * Formatear bytes a formato legible
     * 
     * @param int $size Size in bytes
     * @return string Formatted size
     */
    private function formatBytes(int $size): string
    {
        $units = array('B', 'KB', 'MB', 'GB');
        for ($i = 0; $size >= 1024 && $i < 3; $i++) {
            $size /= 1024;
        }
        return round($size, 2) . ' ' . $units[$i];
    }
    
    /**
     * Imprimir resumen de resultados
     * 
     * @param array $results Results array
     * @return void
     */
    private function printSummary(array $results): void
    {
        echo "==========================================\n";
        echo "🎯 RESUMEN DEL RECÁLCULO COMPLETADO\n";
        echo "==========================================\n\n";
        
        echo "👥 Usuarios totales encontrados: {$results['total_users']}\n";
        echo "✅ Usuarios procesados: {$results['processed_users']}\n";
        echo "🔄 Usuarios actualizados: {$results['updated_users']}\n";
        echo "📦 Pedidos procesados: {$results['total_orders_processed']}\n";
        echo "💰 Reembolsos procesados: {$results['total_refunds_processed']}\n";
        echo "⏱️  Tiempo de procesamiento: {$results['processing_time']} segundos\n";
        echo "💾 Memoria máxima utilizada: {$results['memory_usage']}\n";
        
        if (!empty($results['errors'])) {
            echo "\n❌ Errores encontrados (" . count($results['errors']) . "):\n";
            foreach ($results['errors'] as $error) {
                echo "  • {$error}\n";
            }
        } else {
            echo "\n✅ Sin errores durante el procesamiento\n";
        }
        
        echo "\n🎉 ¡RECÁLCULO COMPLETADO EXITOSAMENTE!\n";
    }
    
    /**
     * Método público para ejecutar recálculo completo de todos los usuarios
     * Ideal para incluir en bootstrap o ejecutar manualmente
     * 
     * @param bool $silent Si es true, no muestra output (para bootstrap)
     * @return array Resultado del procesamiento
     */
    public function executeFullRecalculation(bool $silent = false): array
    {
        // Aumentar límites para procesamiento largo
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }
        if (function_exists('ini_set')) {
            ini_set('memory_limit', '512M');
        }
        
        if (!$silent) {
            echo "🔄 Iniciando recálculo completo de MSRP para todos los usuarios...\n";
        }
        
        $results = $this->recalculateAllUserTotals(50, $silent);
        
        if (!$silent) {
            echo "✅ Recálculo completado exitosamente!\n";
        }
        
        return $results;
    }
    
    /**
     * Método estático para ejecutar recálculo rápidamente desde bootstrap
     * 
     * @return void
     */
    public static function runFullRecalculation(): void
    {
        $recalculator = new self();
        $recalculator->executeFullRecalculation(true);
    }
    
    /**
     * AJAX handler para ejecutar recálculo desde el admin
     * 
     * @return void
     */
    public function ajaxRecalculateAllTotals(): void
    {
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes']);
            return;
        }
        
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'recalculate_all_msrp')) {
            wp_send_json_error(['message' => 'Verificación de seguridad fallida']);
            return;
        }
        
        try {
            $results = $this->executeFullRecalculation(true);
            
            wp_send_json_success([
                'message' => 'Recálculo completado exitosamente',
                'results' => $results
            ]);
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => 'Error durante el recálculo: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * Ejecutar recálculo automático si se detecta que es necesario
     * (opcional, para uso automático)
     * 
     * @return void
     */
    public function maybeRunAutoRecalculation(): void
    {
        // Verificar si ya se ejecutó recientemente (evitar ejecuciones múltiples)
        $last_run = get_option('msrp_recalc_last_run', 0);
        $current_time = time();
        
        // Solo ejecutar si han pasado más de 24 horas desde la última ejecución
        if (($current_time - $last_run) < DAY_IN_SECONDS) {
            return;
        }
        
        // Verificar si hay usuarios que necesitan recálculo
        if ($this->needsRecalculation()) {
            // Ejecutar en segundo plano
            $this->executeFullRecalculation(true);
            
            // Actualizar timestamp
            update_option('msrp_recalc_last_run', $current_time);
        }
    }
    
    /**
     * Verificar si es necesario hacer recálculo
     * Compatible con HPOS (High-Performance Order Storage)
     * 
     * @return bool
     */
    private function needsRecalculation(): bool
    {
        global $wpdb;
        $users_without_msrp = 0;
        
        // Verificar si WooCommerce está usando HPOS
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && 
            \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            
            // Usar tablas HPOS
            $hpos_orders_table = $wpdb->prefix . 'wc_orders';
            
            $users_without_msrp = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(DISTINCT u.ID) 
                FROM {$wpdb->users} u 
                INNER JOIN {$hpos_orders_table} o ON o.customer_id = u.ID 
                LEFT JOIN {$wpdb->usermeta} um ON um.user_id = u.ID AND um.meta_key = %s
                WHERE o.customer_id > 0
                AND o.type = 'shop_order'
                AND o.status IN ('wc-completed', 'wc-processing')
                AND (um.meta_value IS NULL OR um.meta_value = '' OR um.meta_value = '0')
                LIMIT 1
            ", self::USER_MSRP_TOTAL_META));
            
        } else {
            // Sistema clásico
            $users_without_msrp = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(DISTINCT u.ID) 
                FROM {$wpdb->users} u 
                INNER JOIN {$wpdb->postmeta} pm ON pm.meta_value = u.ID 
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id 
                LEFT JOIN {$wpdb->usermeta} um ON um.user_id = u.ID AND um.meta_key = %s
                WHERE pm.meta_key = '_customer_user' 
                AND p.post_type = 'shop_order'
                AND p.post_status IN ('wc-completed', 'wc-processing')
                AND (um.meta_value IS NULL OR um.meta_value = '' OR um.meta_value = '0')
                LIMIT 1
            ", self::USER_MSRP_TOTAL_META));
        }
        
        return $users_without_msrp > 0;
    }
    
    /**
     * Método de debug para diagnosticar problemas con el sistema de pedidos
     * 
     * @return void
     */
    private function debugOrderSystem(): void
    {
        global $wpdb;
        
        echo "🔍 DIAGNÓSTICO DEL SISTEMA DE PEDIDOS:\n";
        echo "=====================================\n";
        
        // Verificar WooCommerce
        if (!class_exists('WooCommerce')) {
            echo "❌ WooCommerce no está activo\n";
            return;
        }
        
        echo "✅ WooCommerce activo (v" . WC()->version . ")\n";
        
        // Verificar sistema HPOS
        $is_hpos = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && 
                   \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        
        if ($is_hpos) {
            echo "📊 Sistema HPOS activo\n";
            
            // Verificar tabla HPOS
            $hpos_table = $wpdb->prefix . 'wc_orders';
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $hpos_table));
            
            if ($table_exists) {
                $total_orders = $wpdb->get_var("SELECT COUNT(*) FROM {$hpos_table} WHERE type = 'shop_order'");
                $users_with_orders = $wpdb->get_var("SELECT COUNT(DISTINCT customer_id) FROM {$hpos_table} WHERE customer_id > 0 AND type = 'shop_order'");
                
                echo "📦 Total pedidos: {$total_orders}\n";
                echo "👥 Usuarios únicos: {$users_with_orders}\n";
                
                if ($users_with_orders > 0) {
                    // Mostrar ejemplo de usuarios
                    $sample_users = $wpdb->get_col("SELECT DISTINCT customer_id FROM {$hpos_table} WHERE customer_id > 0 AND type = 'shop_order' LIMIT 5");
                    echo "🔍 Usuarios ejemplo: " . implode(', ', $sample_users) . "\n";
                }
            } else {
                echo "❌ Tabla HPOS {$hpos_table} no existe\n";
            }
        } else {
            echo "📊 Sistema clásico (wp_posts)\n";
            
            $total_orders = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order'");
            $users_with_orders = $wpdb->get_var("
                SELECT COUNT(DISTINCT pm.meta_value) 
                FROM {$wpdb->postmeta} pm 
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id 
                WHERE pm.meta_key = '_customer_user' 
                AND pm.meta_value > 0 
                AND p.post_type = 'shop_order'
            ");
            
            echo "📦 Total pedidos: {$total_orders}\n";
            echo "👥 Usuarios únicos: {$users_with_orders}\n";
            
            if ($users_with_orders > 0) {
                $sample_users = $wpdb->get_col("
                    SELECT DISTINCT pm.meta_value 
                    FROM {$wpdb->postmeta} pm 
                    INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id 
                    WHERE pm.meta_key = '_customer_user' 
                    AND pm.meta_value > 0 
                    AND p.post_type = 'shop_order'
                    LIMIT 5
                ");
                echo "🔍 Usuarios ejemplo: " . implode(', ', $sample_users) . "\n";
            }
        }
        
        echo "=====================================\n\n";
    }
}
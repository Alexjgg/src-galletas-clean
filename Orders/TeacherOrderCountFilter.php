<?php
/**
 * Filtro de contadores de pedidos para profesores
 * 
 * Modifica los contadores de estado para que los profesores vean 
 * solo los pedidos de su escuela asignada.
 */

namespace SchoolManagement\Orders;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Filtro de contadores para profesores
 */
class TeacherOrderCountFilter
{
    private ?int $teacher_school_id = null;
    private array $school_counts = [];

    public function __construct()
    {
        if (!is_admin()) {
            return;
        }

        if ($this->isCurrentUserTeacher()) {
            $current_user = wp_get_current_user();
            $this->teacher_school_id = get_user_meta($current_user->ID, 'school_id', true);

            if ($this->teacher_school_id) {
                $this->school_counts = $this->getSchoolOrderCounts($this->teacher_school_id);
                add_action('admin_footer', [$this, 'injectCounterScript'], 999);
            }
        }
    }

    private function isCurrentUserTeacher(): bool
    {
        $current_user = wp_get_current_user();
        return in_array('teacher', $current_user->roles);
    }

    private function isOrdersPage(): bool
    {
        global $pagenow;
        $page = $_GET['page'] ?? '';
        
        return ($pagenow === 'admin.php' && $page === 'wc-orders') ||
               ($pagenow === 'edit.php' && ($_GET['post_type'] ?? '') === 'shop_order');
    }

    public function injectCounterScript(): void
    {
        if (!$this->isOrdersPage() || !$this->teacher_school_id || empty($this->school_counts)) {
            return;
        }

        $counts_json = json_encode($this->school_counts);

        ?>
        <script type="text/javascript">
        function replaceOrderCounts() {
            const counts = <?php echo $counts_json; ?>;
            const stateMapping = {
                'all': 'all',
                // Estados principales de WooCommerce
                'wc-pending': 'pending',
                'wc-processing': 'processing',
                'wc-on-hold': 'on-hold',
                'wc-completed': 'completed',
                'wc-cancelled': 'cancelled',
                'wc-refunded': 'refunded',
                'wc-failed': 'failed',
                // Estados personalizados del sistema
                'wc-pay-later': 'pay-later', 
                'wc-reviewed': 'reviewed',
                'wc-warehouse': 'warehouse',
                'wc-prepared': 'prepared',
                'wc-warehouse': 'warehouse',
                // Estados de Master Orders
                'wc-master-order': 'master-order',
                'wc-mast-warehs': 'mast-warehs',
                'wc-mast-prepared': 'mast-prepared',
                'wc-mast-complete': 'mast-complete',
                // Papelera
                'trash': 'trash'
            };

            Object.keys(stateMapping).forEach(cssClass => {
                const state = stateMapping[cssClass];
                const elements = document.querySelectorAll(`li.${cssClass}`);
                
                elements.forEach(li => {
                    const countSpan = li.querySelector('span.count');
                    if (countSpan) {
                        const newCount = counts[state] || 0;
                        countSpan.textContent = `(${newCount})`;
                    }
                });
            });
        }

        document.addEventListener('DOMContentLoaded', replaceOrderCounts);
        setTimeout(replaceOrderCounts, 100);
        setTimeout(replaceOrderCounts, 500);
        setTimeout(replaceOrderCounts, 1000);

        document.addEventListener('click', function(e) {
            if (e.target.closest('ul.subsubsub')) {
                setTimeout(replaceOrderCounts, 100);
            }
        });
        </script>
        <?php
    }

    private function getValidOrderStatuses(): array
    {
        return [
            // Estados principales de WooCommerce
            'wc-pending',
            'wc-processing', 
            'wc-on-hold',
            'wc-completed',
            'wc-cancelled',
            'wc-refunded',
            'wc-failed',
            // Estados personalizados del sistema
            'wc-pay-later',
            'wc-reviewed',
            'wc-warehouse',
            'wc-prepared',
            // Estados de Master Orders
            'wc-master-order',
            'wc-mast-warehs',
            'wc-mast-prepared',
            'wc-mast-complete'
        ];
    }

    private function getSchoolOrderCounts(int $school_id): array
    {
        global $wpdb;

        $counts = ['all' => 0];

        if ($this->isHPOSEnabled()) {
            $counts = $this->getSchoolOrderCountsHPOS($school_id);
        } else {
            $counts = $this->getSchoolOrderCountsLegacy($school_id);
        }

        return $counts;
    }

    private function getSchoolOrderCountsHPOS(int $school_id): array
    {
        global $wpdb;

        $counts = ['all' => 0];
        $valid_statuses = $this->getValidOrderStatuses();
        $status_placeholders = implode(',', array_fill(0, count($valid_statuses), '%s'));

        // Consulta para "todos" - solo estados válidos y EXCLUYENDO master orders
        $total_query = "
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}wc_orders o
            INNER JOIN {$wpdb->prefix}wc_orders_meta om ON o.id = om.order_id
            LEFT JOIN {$wpdb->prefix}wc_orders_meta om_master ON o.id = om_master.order_id AND om_master.meta_key = '_is_master_order'
            WHERE om.meta_key = '_school_id' 
            AND om.meta_value = %s
            AND o.type = 'shop_order'
            AND o.status IN ($status_placeholders)
            AND (om_master.meta_value IS NULL OR om_master.meta_value != 'yes')
        ";
        
        $prepared_query = $wpdb->prepare($total_query, array_merge([$school_id], $valid_statuses));
        $counts['all'] = (int) $wpdb->get_var($prepared_query);

        $status_query = $wpdb->prepare("
            SELECT 
                CASE 
                    WHEN o.status = 'trash' THEN 'trash'
                    ELSE o.status 
                END as status, 
                COUNT(*) as count
            FROM {$wpdb->prefix}wc_orders o
            INNER JOIN {$wpdb->prefix}wc_orders_meta om ON o.id = om.order_id
            LEFT JOIN {$wpdb->prefix}wc_orders_meta om_master ON o.id = om_master.order_id AND om_master.meta_key = '_is_master_order'
            WHERE om.meta_key = '_school_id' 
            AND om.meta_value = %s
            AND o.type = 'shop_order'
            AND (om_master.meta_value IS NULL OR om_master.meta_value != 'yes')
            GROUP BY 
                CASE 
                    WHEN o.status = 'trash' THEN 'trash'
                    ELSE o.status 
                END
        ", $school_id);

        $results = $wpdb->get_results($status_query);
        
        foreach ($results as $result) {
            if ($result->status === 'trash') {
                $counts['trash'] = (int) $result->count;
            } else {
                $clean_status = str_replace('wc-', '', $result->status);
                $counts[$clean_status] = (int) $result->count;
            }
        }

        return $counts;
    }

    private function getSchoolOrderCountsLegacy(int $school_id): array
    {
        global $wpdb;

        $counts = ['all' => 0];
        $valid_statuses = $this->getValidOrderStatuses();
        $status_placeholders = implode(',', array_fill(0, count($valid_statuses), '%s'));

        // Consulta para "todos" - solo estados válidos y EXCLUYENDO master orders
        $total_query = "
            SELECT COUNT(*) 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            LEFT JOIN {$wpdb->postmeta} pm_master ON p.ID = pm_master.post_id AND pm_master.meta_key = '_is_master_order'
            WHERE p.post_type = 'shop_order'
            AND pm.meta_key = '_school_id' 
            AND pm.meta_value = %s
            AND p.post_status IN ($status_placeholders)
            AND (pm_master.meta_value IS NULL OR pm_master.meta_value != 'yes')
        ";
        
        $prepared_query = $wpdb->prepare($total_query, array_merge([$school_id], $valid_statuses));
        $counts['all'] = (int) $wpdb->get_var($prepared_query);

        $status_query = $wpdb->prepare("
            SELECT 
                CASE 
                    WHEN p.post_status = 'trash' THEN 'trash'
                    ELSE p.post_status 
                END as status, 
                COUNT(*) as count
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            LEFT JOIN {$wpdb->postmeta} pm_master ON p.ID = pm_master.post_id AND pm_master.meta_key = '_is_master_order'
            WHERE p.post_type = 'shop_order'
            AND pm.meta_key = '_school_id' 
            AND pm.meta_value = %s
            AND (pm_master.meta_value IS NULL OR pm_master.meta_value != 'yes')
            GROUP BY 
                CASE 
                    WHEN p.post_status = 'trash' THEN 'trash'
                    ELSE p.post_status 
                END
        ", $school_id);

        $results = $wpdb->get_results($status_query);
        
        foreach ($results as $result) {
            if ($result->status === 'trash') {
                $counts['trash'] = (int) $result->count;
            } else {
                $clean_status = str_replace('wc-', '', $result->status);
                $counts[$clean_status] = (int) $result->count;
            }
        }

        return $counts;
    }

    private function isHPOSEnabled(): bool
    {
        if (!class_exists('Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')) {
            return false;
        }

        try {
            return wc_get_container()
                ->get('Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')
                ->custom_orders_table_usage_is_enabled();
        } catch (\Exception $e) {
            return false;
        }
    }
}

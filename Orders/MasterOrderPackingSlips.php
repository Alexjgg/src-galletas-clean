<?php
/**
 * Master Order Packing Slips Integration
 * 
 * Integración SIMPLE Y DIRECTA con WC PDF Invoices & Packing Slips
 * para que los pedidos maestros tengan botones de albarán EN MASA.
 * 
 * FUNCIONALIDADES:
 * 1. Agregar botones de PDF específicos para pedidos maestros en admin
 * 2. Usar las URLs del plugin WC PDF para generar albaranes masivos
 * 3. Manejar cambios de estado automáticos cuando se generan PDFs
 * 4. Controlar flujo de estados de master orders y pedidos hijos
 * 
 * NOTA: 
 * - La personalización de contenido de albaranes está en PackingSlipCustomizer.php
 * - El ordenamiento de productos se maneja automáticamente en MasterOrderManager.php
 * 
 * @package SchoolManagement\Orders
 * @since 1.0.0
 */

namespace SchoolManagement\Orders;

if (!defined('ABSPATH')) {
    exit;
}

class MasterOrderPackingSlips
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->initHooks();
        // Agregar CSS una sola vez
        add_action('admin_head', [$this, 'addButtonStyles']);
    }

    /**
     * Inicializar hooks de WordPress
     */
    private function initHooks(): void
    {
        // MÉTODO DIRECTO: Agregar botones específicos para pedidos maestros
        add_filter('woocommerce_admin_order_actions', [$this, 'addMasterOrderPdfButtons'], 20, 2);
        
        // Hooks nativos del plugin WC PDF para detectar generación de PDFs masivos
        add_action('wpo_wcpdf_document_created_manually', [$this, 'handleDocumentCreatedManually'], 10, 2);
        add_action('wpo_wcpdf_pdf_created', [$this, 'handlePdfCreated'], 10, 2);
        
        // Hook para detectar cuando Master Order se completa
        add_action('woocommerce_order_status_changed', [$this, 'handleMasterOrderCompletionStatusChange'], 10, 4);
    }



    /**
     * Manejar cuando se crea un documento manualmente usando el hook nativo del plugin
     * Este es el nuevo enfoque que reemplaza la interceptación AJAX
     */
    public function handleDocumentCreatedManually($document, $order_ids): void
    {
        // Solo procesar si es packing-slip
        if (!is_object($document) || $document->get_type() !== 'packing-slip') {
            return;
        }
        
        if (!is_array($order_ids)) {
            return;
        }
        
        // Para BulkDocument, simplemente verificar que hay múltiples pedidos
        if (count($order_ids) <= 1) {
            return;
        }
        
        // Verificar si alguno de los pedidos es maestro
        $master_order_found = false;
        $master_order_id = null;
        
        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if ($order && $order->get_meta('_is_master_order') === 'yes') {
                $master_order_found = true;
                $master_order_id = $order_id;
                break;
            }
        }
        
        if (!$master_order_found) {
            return;
        }
        
        // Actualizar estados cuando se genera el PDF combinado
        $this->updateOrderStatusesAfterZipGeneration($master_order_id);
    }

    /**
     * Manejar cuando se crea un PDF usando el hook nativo del plugin
     */
    public function handlePdfCreated($pdf_data, $document): void
    {
        // Solo nos interesa si es un BulkDocument de packing-slip
        if (!is_object($document) || 
            !property_exists($document, 'is_bulk') || 
            !$document->is_bulk || 
            $document->get_type() !== 'packing-slip') {
            return;
        }
        
        // Verificar si contiene master orders
        if (property_exists($document, 'order_ids') && is_array($document->order_ids)) {
            $first_order = wc_get_order($document->order_ids[0]);
            if ($first_order && $first_order->get_meta('_is_master_order') === 'yes') {
                // Los estados ya se actualizaron en handleDocumentCreatedManually
            }
        }
    }

    /**
     * Agregar botones de PDF específicos para pedidos maestros
     * MÉTODO NATIVO: Usa el BulkDocument del plugin WC PDF para generar un PDF combinado
     */
    public function addMasterOrderPdfButtons(array $actions, \WC_Order $order): array
    {
        // Solo para pedidos maestros
        if ($order->get_meta('_is_master_order') !== 'yes') {
            return $actions;
        }

        $order_id = $order->get_id();
        
        // Verificar que el pedido maestro tenga contenido
        $included_orders = $order->get_meta('_included_orders') ?: [];
        if (empty($included_orders)) {
            return $actions; // No mostrar botones si no hay pedidos incluidos
        }

        // Crear lista completa: maestro + individuales
        $all_order_ids = array_merge([$order_id], $included_orders);
        $order_ids_string = implode('x', $all_order_ids);
        
        // URL con 'bulk' y separador 'x'
        $packing_slip_url = wp_nonce_url(
            admin_url("admin-ajax.php?action=generate_wpo_wcpdf&document_type=packing-slip&bulk&order_ids={$order_ids_string}"),
            'generate_wpo_wcpdf'
        );

        // Agregar solo botón de albarán con icono SVG
        $actions['master_packing_slip'] = [
            'url' => str_replace('&amp;', '&', $packing_slip_url),
            'name' => sprintf(__('Complete Packing Slip (%d orders)', 'neve-child'), count($all_order_ids)),
            'action' => 'master_packing_slip',
            'class' => 'master-order-packing-slip-button'
        ];

        return $actions;
    }

    /**
     * Agregar estilos CSS para los botones
     */
    public function addButtonStyles(): void
    {
        $screen = get_current_screen();
        if ($screen && ($screen->id === 'edit-shop_order' || $screen->post_type === 'shop_order')) {
            echo '<style>
            a.wc-action-button-master_packing_slip {
                display: inline-block !important;
                width: 26px !important;
                height: 26px !important;
                line-height: 26px !important;
                text-align: center !important;
                border-radius: 3px !important;
                background: #fff url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%236c45a7\' stroke-width=\'1.8\'%3E%3Cpath d=\'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z\'/%3E%3Cpolyline points=\'14,2 14,8 20,8\'/%3E%3Cline x1=\'8\' y1=\'14\' x2=\'16\' y2=\'14\'/%3E%3Cline x1=\'8\' y1=\'10\' x2=\'16\' y2=\'10\'/%3E%3Cline x1=\'8\' y1=\'18\' x2=\'16\' y2=\'18\'/%3E%3Ccircle cx=\'6\' cy=\'10\' r=\'1\' fill=\'%236c45a7\'/%3E%3Ccircle cx=\'6\' cy=\'14\' r=\'1\' fill=\'%236c45a7\'/%3E%3Ccircle cx=\'6\' cy=\'18\' r=\'1\' fill=\'%236c45a7\'/%3E%3C/svg%3E") center no-repeat !important;
                background-size: 18px 18px !important;
                color: #666 !important;
                text-decoration: none !important;
                vertical-align: middle !important;
                text-indent: -9999px !important;
                overflow: hidden !important;
            }
            a.wc-action-button-master_packing_slip:hover {
                background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%236c45a7\' stroke-width=\'1.8\'%3E%3Cpath d=\'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z\'/%3E%3Cpolyline points=\'14,2 14,8 20,8\'/%3E%3Cline x1=\'8\' y1=\'14\' x2=\'16\' y2=\'14\'/%3E%3Cline x1=\'8\' y1=\'10\' x2=\'16\' y2=\'10\'/%3E%3Cline x1=\'8\' y1=\'18\' x2=\'16\' y2=\'18\'/%3E%3Ccircle cx=\'6\' cy=\'10\' r=\'1\' fill=\'%236c45a7\'/%3E%3Ccircle cx=\'6\' cy=\'14\' r=\'1\' fill=\'%236c45a7\'/%3E%3Ccircle cx=\'6\' cy=\'18\' r=\'1\' fill=\'%236c45a7\'/%3E%3C/svg%3E") !important;
            }
            </style>';
            
            // JavaScript para manejar el clic del botón - ABRIR EN NUEVA PESTAÑA
            echo '<script>
            jQuery(document).ready(function($) {
                // Interceptar clicks para abrir en nueva pestaña
                $(document).on("click", "a.wc-action-button-master_packing_slip", function(e) {
                    e.preventDefault();
                    
                    var url = $(this).attr("href");
                    window.open(url, "_blank");
                    
                    // Recargar la página después de un delay para mostrar cambios de estado
                    setTimeout(function() {
                        window.location.reload();
                    }, 3000);
                });
            });
            </script>';
        }
    }

    /**
     * Actualizar estados de pedidos después de generar el PDF combinado de albaranes
     * Master Order → "Master Warehouse" (mast-warehs)
     * Pedidos hijos → "Warehouse" (warehouse)
     * 
     * IMPORTANTE: Solo si la master order NO está ya completada
     */
    private function updateOrderStatusesAfterZipGeneration(int $master_order_id): void
    {
        try {
            // Obtener el pedido maestro
            $master_order = wc_get_order($master_order_id);
            if (!$master_order || $master_order->get_meta('_is_master_order') !== 'yes') {
                return;
            }

            $current_master_status = $master_order->get_status();
            
            // No cambiar si ya está completada
            if ($current_master_status === 'mast-complete' || $current_master_status === 'completed') {
                $master_order->add_order_note(
                    __('Combined packing slip PDF generated. No status changes applied - master validated order is already completed', 'neve-child')
                );
                return;
            }

            // Cambiar estado del pedido maestro a "Master Warehouse" solo si no está completado
            if ($current_master_status !== 'mast-warehs') {
                $master_order->update_status('mast-warehs', 
                    __('Status automatically changed to Master Warehouse after generating combined packing slip PDF', 'neve-child')
                );
            }

            // Obtener pedidos hijos y cambiarlos a "Warehouse" solo si el master no estaba completado
            $included_orders = $master_order->get_meta('_included_orders') ?: [];
            $changed_children = 0;
            $skipped_children = 0;

            foreach ($included_orders as $child_order_id) {
                $child_order = wc_get_order($child_order_id);
                if ($child_order) {
                    $current_child_status = $child_order->get_status();
                    
                    // No cambiar pedidos hijos que ya están completados o prepared
                    if (in_array($current_child_status, ['completed', 'prepared'])) {
                        $skipped_children++;
                        continue;
                    }
                    
                    // Solo cambiar si no está ya en "warehouse"
                    if ($current_child_status !== 'warehouse') {
                        $child_order->update_status('warehouse', 
                            sprintf(__('Status automatically changed to Warehouse after master validated order #%d packing slips were generated', 'neve-child'), $master_order_id)
                        );
                        $changed_children++;
                    }
                }
            }

            // Agregar nota al pedido maestro sobre los cambios
            if ($changed_children > 0 || $skipped_children > 0) {
                $note_parts = [];
                $note_parts[] = sprintf(__('Combined packing slip PDF generated. Master validated order status changed to "Master Warehouse"', 'neve-child'));
                
                if ($changed_children > 0) {
                    $note_parts[] = sprintf(__('%d child orders changed to "Warehouse"', 'neve-child'), $changed_children);
                }
                
                if ($skipped_children > 0) {
                    $note_parts[] = sprintf(__('%d child orders skipped (already completed/prepared)', 'neve-child'), $skipped_children);
                }
                
                $master_order->add_order_note(implode('. ', $note_parts));
            } else {
                $master_order->add_order_note(
                    __('Combined packing slip PDF generated. Master validated order status changed to "Master Warehouse"', 'neve-child')
                );
            }

        } catch (\Exception $e) {
            // Error silencioso
        }
    }

    /**
     * Manejar cambio de estado cuando Master Order se completa
     * Pasar pedidos hijos de "warehouse" a "prepared" automáticamente
     */
    public function handleMasterOrderCompletionStatusChange(int $order_id, string $old_status, string $new_status, $order): void
    {
        // Solo procesar Master Orders
        if ($order->get_meta('_is_master_order') !== 'yes') {
            return;
        }

        // Solo actuar cuando cambia a estados completados
        if (!in_array($new_status, ['completed', 'mast-complete'])) {
            return;
        }

        try {
            // Obtener pedidos hijos
            $included_orders = $order->get_meta('_included_orders') ?: [];
            if (empty($included_orders)) {
                return;
            }

            $prepared_children = 0;
            $skipped_children = 0;

            foreach ($included_orders as $child_order_id) {
                $child_order = wc_get_order($child_order_id);
                if (!$child_order) {
                    continue;
                }

                $current_child_status = $child_order->get_status();
                
                // Solo cambiar pedidos que están en "warehouse"
                if ($current_child_status === 'warehouse') {
                    $child_order->update_status('prepared', 
                        sprintf(__('Status automatically changed to Prepared after master validated order #%d was completed', 'neve-child'), $order_id)
                    );
                    $prepared_children++;
                } else {
                    $skipped_children++;
                }
            }

            // Agregar nota al pedido maestro
            if ($prepared_children > 0 || $skipped_children > 0) {
                $note_parts = [];
                
                if ($prepared_children > 0) {
                    $note_parts[] = sprintf(__('%d child orders automatically changed to "Prepared"', 'neve-child'), $prepared_children);
                }
                
                if ($skipped_children > 0) {
                    $note_parts[] = sprintf(__('%d child orders skipped (not in warehouse status)', 'neve-child'), $skipped_children);
                }
                
                $final_note = sprintf(__('Master validated order completed. %s', 'neve-child'), implode('. ', $note_parts));
                $order->add_order_note($final_note);
            } else {
                $order->add_order_note(__('Master validated order completed. No child orders were in warehouse status', 'neve-child'));
            }

        } catch (\Exception $e) {
            // Error silencioso
        }
    }
}

// Inicializar automáticamente
if (!isset($GLOBALS['master_order_packing_slips'])) {
    $GLOBALS['master_order_packing_slips'] = new MasterOrderPackingSlips();
}
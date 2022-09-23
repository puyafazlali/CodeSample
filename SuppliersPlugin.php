<?php
/**
 * Suppliers Purchase Orders
 *
 * @package       SUPPLIERSP
 * @author        Puya Fazlali
 * @license       gplv2
 * @version       1.0.0
 *
 * @wordpress-plugin
 * Plugin Name:   Suppliers Purchase Orders
 * Plugin URI:    https://puyafazlali.com
 * Description:   This plugin adds the supplier feature to woocommerce.
 * Version:       1.0.0
 * Author:        Puya
 * Author URI:    https://puyafazlali.com
 * Text Domain:   suppliers-purchase-orders
 * Domain Path:   /languages
 * License:       GPLv2
 * License URI:   https://www.gnu.org/licenses/gpl-2.0.html
 *
 * You should have received a copy of the GNU General Public License
 * along with Suppliers Purchase Orders. If not, see <https://www.gnu.org/licenses/gpl-2.0.html/>.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'woocommerce_checkout_create_order_line_item', 'custom_checkout_create_order_line_item', 20, 4 );
function custom_checkout_create_order_line_item( $item, $cart_item_key, $values, $order ) {
    // Get a product custom field value
    $product_id = $item->get_product_id();
    $supplier_post = get_field('product_supplier', $product_id);
    $max_discount = get_field( 'supplier_discount', $product_id );
    $cat_supplier = get_the_title( $supplier_post->ID );
	$notification_recipients = get_field( 'purchase_order_notification_recipients', $supplier_post->ID );
    $supplier_address = get_field( 'supplier_address', $supplier_post->ID );
    $supplier_phone = get_field( 'supplier_phone', $supplier_post->ID );
    
    // Update order item meta
    $item_subtotal = $item->get_subtotal(); 
	$aquarina_price = wc_price($item_subtotal-($max_discount*($item_subtotal/100)));
    if ( ! empty( $max_discount ) ){
        $item->update_meta_data( '_supplier_name', $cat_supplier );
        $item->update_meta_data( '_aquarina_discount', $max_discount );
        $item->update_meta_data( '_aquarina_price', $aquarina_price );
        $item->update_meta_data( '_notification_recipients', $notification_recipients );
        $item->update_meta_data( '_supplier_address', $supplier_address );
        $item->update_meta_data( '_supplier_phone', $supplier_phone );
    }
}

add_action( 'woocommerce_order_status_processing', 'rele_create_purchase_order' );
function rele_create_purchase_order( $order_id ){
    //This functions creates a purchase order when order status gets to processing
    $order = wc_get_order( $order_id );
    $order_items = '';
    $item_suppliers;
    foreach ( $order->get_items() as $item_id => $item ) {
        $item_supplier = $item->get_meta( '_supplier_name', true );
        $order_items .= $item_id.',';
        $item_suppliers[] = array('product_id' => $item_id, 'supplier'=> $item_supplier);
    }

    $supplier_groups=[];
    foreach ($item_suppliers as $key => $item) {
        $supplier_groups[$item['supplier']][$key] = $item;
    }

    $new=ksort($supplier_groups, SORT_NUMERIC);
    
    foreach($supplier_groups as $group)
    {
        //print("<pre>".print_r($group,true)."</pre>");
        $order_items='';
        foreach($group as $inner){
            $item_id = $inner['product_id'];
            $supplier = $inner['supplier'];
            $order_items .= $item_id.',';
        }
        $no_whitespaces = preg_replace( '/\s*,\s*/', ',', filter_var( $order_items, FILTER_SANITIZE_STRING ) ); 
        $order_item_ids = explode( ',', $no_whitespaces );
        foreach ( $order->get_items() as $item_id => $item ) {

            if(in_array($item_id, $order_item_ids)) {
                $notification_recipients = $item->get_meta('_notification_recipients');
            }
            
        }
        $post_arr = array(
            'post_title'   => 'Purchase Order '.$order_id,
            'post_status'  => 'draft',
            'post_type'  => 'purchse_order',
            'meta_input'   => array(
                'order_id' => $order_id,
                'order_items' => $order_items,
                'supplier_name' => $supplier,
                'notification_recipients' => $notification_recipients,
            ),
        );
        $purchase_id = wp_insert_post($post_arr);
        $purchase_order_output = do_shortcode( '[purchase_order_details order_id="'.$order_id.'" purchase_order_id="'.$purchase_id.'" comma_sep_order_item_ids="'.$order_items.'" ]' );
        
        $update_arr = array(
            'ID' => $purchase_id,
            'post_title' => '#'.$purchase_id,
            'post_status' => 'publish',
            'meta_input'   => array(
                'purchase_order_output' => $purchase_order_output,
            ),
        );
        wp_update_post($update_arr);
        //Send mail
        send_email_notification($notification_recipients,$purchase_id);
    }

    
}

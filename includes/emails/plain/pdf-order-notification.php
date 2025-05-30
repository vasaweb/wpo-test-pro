<?php
/**
 * Order Notification email (plain text)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

echo $email_heading . "\n\n";

echo "****************************************************\n\n";

// Some of the default actions are disabled in this email because they may result in unexpected output.
// For example when credit notes are issued for unpaid orders/invoices, woocommerce will still display
// payment instructions!
// do_action( 'woocommerce_email_before_order_table', $order, $sent_to_admin, $plain_text );

echo sprintf( __( 'Order number: %s', 'woocommerce'), $order->get_order_number() ) . "\n";
$order_date = $order->get_date_created();
echo sprintf( __( 'Order date: %s', 'woocommerce'), $order_date->date_i18n( wc_date_format() ) ) . "\n";

echo "\n";

echo $email_body;

echo "\n";

do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text );

echo "\n";

if ( $include_customer_details == 'yes' ) {
	$items_table_args = array(
		'show_download_links'	=> $order->is_download_permitted(),
		'show_sku' 				=> true,
		'show_purchase_note'	=> $order->has_status( 'processing' ),
		'show_image'			=> false,
		'image_size'			=> array( 32, 32 ),
		'plain_text'			=> true,
	);
	echo wc_get_email_order_items( $order, $items_table_args );

	echo "----------\n\n";

	if ( $totals = $order->get_order_item_totals() ) {
		foreach ( $totals as $total ) {
			echo $total['label'] . "\t " . $total['value'] . "\n";
		}
	}
}


echo "\n****************************************************\n\n";

// do_action( 'woocommerce_email_after_order_table', $order, $sent_to_admin, $plain_text );

if ( $include_customer_details == 'yes' ) {
	echo __( 'Customer details', 'woocommerce' ) . "\n";

	if ( $email = $order->get_billing_email() ) {
		echo __( 'Email:', 'woocommerce' ); echo $email . "\n";
	}

	if ( $phone = $order->get_billing_phone() ) {
		echo __( 'Tel:', 'woocommerce' ); ?> <?php echo $phone . "\n";
	}

	wc_get_template( 'emails/plain/email-addresses.php', array( 'order' => $order, 'sent_to_admin' => $sent_to_admin ) );

	echo "\n****************************************************\n\n";
}

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );

<?php
/**
 * Order Notification email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

$order_date = $order->get_date_created();

?>

<?php do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php
// Some of the default actions are disabled in this email because they may result in unexpected output.
// For example when this email is sent for unpaid orders, woocommerce would still display payment
// instructions with the action below!
// do_action( 'woocommerce_email_before_order_table', $order, $sent_to_admin, $plain_text, $email );
?>

<h2><?php echo __( 'Order:', 'woocommerce' ) . ' ' . $order->get_order_number(); ?> (<?php printf( '<time datetime="%s">%s</time>', $order_date->date_i18n( 'c' ), $order_date->date_i18n( wc_date_format() ) ); ?>)</h2>

<p><?php echo $email_body; ?></p>

<?php if ( $include_items_table == 'yes' ) { ?>
<table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #eee;" border="1" bordercolor="#eee">
	<thead>
		<tr>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php _e( 'Product', 'woocommerce' ); ?></th>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php _e( 'Quantity', 'woocommerce' ); ?></th>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php _e( 'Price', 'woocommerce' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php
			$items_table_args = array(
				'show_download_links' => $order->is_download_permitted(),
				'show_sku'            => true,
				'show_purchase_note'  => $order->has_status( 'processing' ),
				// defaults:
				// 'show_image'       => true,
				// 'image_size'       => array( 32, 32 ),
				// 'plain_text'       => false,
			);
			echo wc_get_email_order_items( $order, $items_table_args );
		?>
	</tbody>
	<tfoot>
		<?php
			if ( $totals = $order->get_order_item_totals() ) {
				$i = 0;
				foreach ( $totals as $total ) {
					$i++;
					?><tr>
						<th scope="row" colspan="2" style="text-align:left; border: 1px solid #eee; <?php if ( $i == 1 ) echo 'border-top-width: 4px;'; ?>"><?php echo $total['label']; ?></th>
						<td style="text-align:left; border: 1px solid #eee; <?php if ( $i == 1 ) echo 'border-top-width: 4px;'; ?>"><?php echo $total['value']; ?></td>
					</tr><?php
				}
			}
		?>
	</tfoot>
</table>
<?php } // endif items_table ?>

<?php // do_action( 'woocommerce_email_after_order_table', $order, $sent_to_admin, $plain_text, $email ); ?>

<?php do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email ); ?>

<?php if ( $include_customer_details == 'yes' ) { ?>
	<h2><?php _e( 'Customer details', 'woocommerce' ); ?></h2>

	<?php if ( $email = $order->get_billing_email() ) : ?>
		<p><strong><?php _e( 'Email:', 'woocommerce' ); ?></strong> <?php echo $email; ?></p>
	<?php endif; ?>
	<?php if ( $phone = $order->get_billing_phone() ) : ?>
		<p><strong><?php _e( 'Tel:', 'woocommerce' ); ?></strong> <?php echo $phone; ?></p>
	<?php endif; ?>

	<?php wc_get_template( 'emails/email-addresses.php', array( 'order' => $order, 'sent_to_admin' => $sent_to_admin ) ); ?>
<?php } // endif customer_details ?>

<?php do_action( 'woocommerce_email_footer', $email ); ?>

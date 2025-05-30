<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<?php do_action( 'wpo_wcpdf_before_document', $this->get_type(), $this->order_ids ); ?>

<?php do_action( 'wpo_wcpdf_before_document_label', $this->get_type(), $this->order_ids ); ?>

<h1 class="document-type-label"><?php echo $this->get_title(); ?></h1>

<?php do_action( 'wpo_wcpdf_after_document_label', $this->get_type(), $this->order_ids ); ?>

<table class="summary-header">
	<tr>
		<td>
			<table>
				<?php do_action( 'wpo_wcpdf_before_summary_header', $this->get_type(), $this->order_ids ); ?>
				<tr class="summary-date">
					<th><?php echo $this->get_date_title(); ?>:</th>
					<td><?php $this->output_date(); ?></td>
				</tr>
				<tr>
					<?php if ( $this->get_export_date_type() && $this->get_export_date_interval() ) : ?>
						<th><?php $this->output_export_date_type(); ?>:</th>
						<td><?php $this->output_export_date_interval(); ?></td>
					<?php endif; ?>
				</tr>
				<?php do_action( 'wpo_wcpdf_after_summary_header', $this->get_type(), $this->order_ids ); ?>
			</table>			
		</td>
	</tr>
</table>

<?php do_action( 'wpo_wcpdf_before_summary_details', $this->get_type(), $this->order_ids ); ?>

<table class="summary-details">
	<?php $totals = array(); ?>
	<thead>
		<tr>
			<?php do_action( 'wpo_wcpdf_before_summary_details_headers', $this->get_type(), $this->order_ids ); ?>
			<th class="invoice-date"><?php _e( 'Invoice date', 'wpo_wcpdf_pro' ); ?></th>
			<?php do_action( 'wpo_wcpdf_before_summary_details_headers_invoice_number', $this->get_type(), $this->order_ids ); ?>
			<th class="invoice-number"><?php _e( 'Invoice number', 'wpo_wcpdf_pro' ); ?></th>
			<?php do_action( 'wpo_wcpdf_before_summary_details_headers_order_date', $this->get_type(), $this->order_ids ); ?>
			<th class="order-date"><?php _e( 'Order date', 'wpo_wcpdf_pro' ); ?></th>
			<?php do_action( 'wpo_wcpdf_before_summary_details_headers_order_number', $this->get_type(), $this->order_ids ); ?>
			<th class="order-number"><?php _e( 'Order number', 'wpo_wcpdf_pro' ); ?></th>
			<?php do_action( 'wpo_wcpdf_before_summary_details_headers_order_total', $this->get_type(), $this->order_ids ); ?>
			<th class="order-total last-column"><?php _e( 'Order total', 'wpo_wcpdf_pro' ); ?></th>
			<?php do_action( 'wpo_wcpdf_after_summary_details_headers', $this->get_type(), $this->order_ids ); ?>
		</tr>
	</thead>
	<tbody>
		<?php
			foreach ( $this->order_ids as $order_id ) :
				$order = wc_get_order( $order_id );
				if ( empty( $order ) ) {
					continue;
				}

				if ( isset( $totals[$order->get_currency()] ) ) {
					$totals[$order->get_currency()] += $order->get_total();
				} else {
					$totals[$order->get_currency()] = $order->get_total();
				}

				$invoice = wcpdf_get_invoice( $order );
				if ( empty( $invoice ) || $invoice->exists() === false ) {
					continue;
				}
		?>
			<tr class="<?php echo apply_filters( 'wpo_wcpdf_order_row_class', 'order-'.$order_id, $this->get_type(), $order ); ?>">
				<?php do_action( 'wpo_wcpdf_before_summary_details_data', $this->get_type(), $order ); ?>
				<td class="invoice-date"><?php $invoice->date( 'invoice' ); ?></td>
				<?php do_action( 'wpo_wcpdf_before_summary_details_data_invoice_number', $this->get_type(), $order ); ?>
				<td class="invoice-number"><?php $invoice->number( 'invoice' ); ?></td>
				<?php do_action( 'wpo_wcpdf_before_summary_details_data_order_date', $this->get_type(), $order ); ?>
				<td class="order-date"><?php echo $order->get_date_created()->date_i18n( wcpdf_date_format( $invoice, 'order_date' ) ); ?></td>
				<?php do_action( 'wpo_wcpdf_before_summary_details_data_order_number', $this->get_type(), $order ); ?>
				<td class="order-number"><?php echo $order->get_order_number(); ?></td>
				<?php do_action( 'wpo_wcpdf_before_summary_details_data_order_total', $this->get_type(), $order ); ?>
				<td class="order-total last-column"><?php echo wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ); ?></td>
				<?php do_action( 'wpo_wcpdf_after_summary_details_data', $this->get_type(), $order ); ?>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<?php do_action( 'wpo_wcpdf_after_summary_details', $this->get_type(), $this->order_ids ); ?>

<table class="summary-totals">
	<tfoot>
		<?php do_action( 'wpo_wcpdf_before_summary_totals', $this->get_type(), $this->order_ids ); ?>
		<?php foreach ( apply_filters( 'wpo_wcpdf_summary_totals', $totals ) as $currency => $total ) : ?>
			<tr>
				<th class="label">
					<?php
						printf(
							/* translators: currency */
							__( 'Total in %s', 'wpo_wcpdf_pro' ),
							$currency
						);
					?>
				</th>
				<td class="total last-column"><span class="totals-price"><?php echo wc_price( $total, array( 'currency' => $currency ) ); ?></span></td>
			</tr>
		<?php endforeach; ?>
		<?php do_action( 'wpo_wcpdf_after_summary_totals', $this->get_type(), $this->order_ids ); ?>
	</tfoot>
</table>

<div class="bottom-spacer"></div>

<?php do_action( 'wpo_wcpdf_after_document', $this->get_type(), $this->order_ids ); ?>

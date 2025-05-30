<?php
namespace WPO\WC\PDF_Invoices_Pro;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( '\\WPO\\WC\\PDF_Invoices_Pro\\Writepanels' ) ) :

class Writepanels {

	public function __construct() {
		// hide credit note button for non-refunded orders
		add_filter( 'wpo_wcpdf_meta_box_actions', array( $this, 'credit_note_button_visibility' ), 10, 2 );
		add_filter( 'wpo_wcpdf_listing_actions', array( $this, 'credit_note_button_visibility' ), 10, 2 );
		add_filter( 'wpo_wcpdf_myaccount_actions', array( $this, 'my_account_button_visibility' ), 10, 2 );

		add_action( 'wcpdf_invoice_number_column_end', array( $this, 'credit_note_number_column_data' ), 10, 1 );

		add_filter( 'wpo_wcpdf_resend_order_emails_available', array( $this, 'pro_email_order_actions' ), 99, 2 );

		add_action( 'wpo_wcpdf_meta_box_end', array( $this, 'edit_numbers_dates' ), 10, 2 );
		add_action( 'wpo_wcpdf_on_save_invoice_order_data', array( $this, 'save_numbers_dates' ), 10, 3 );

		add_action( 'wpo_wcpdf_meta_box_after_document_data', array( $this, 'regenerate_order_data_button_for_stored_pdf' ), 10, 2 );
	}

	/**
	 * Remove credit note button if order is not refunded
	 */
	public function credit_note_button_visibility( $actions, $order = '' ) {
		if ( empty( $order ) ) {
			return $actions;
		}
		
		if ( ! is_object( $order ) && is_numeric( $order ) ) {
			$order = wc_get_order( intval( $order ) );
		}
		
		$show_button = false;
		
		if ( $order ) {
			$refunds = $order->get_refunds();

			if ( ! empty( $refunds ) ) {
				// only show credit note button when there is also an invoice for this order
				$invoice = wcpdf_get_invoice( $order );
				if ( $invoice && $invoice->exists() ) {
					$show_button = true;
				}
			}
		}

		// allow overriding button visibility
		$show_button = apply_filters( 'wpo_wcpdf_show_credit_note_button', $show_button, $order );
		if ( $show_button === false ) {
			unset( $actions['credit-note'] );
		}

		return $actions;
	}

	/**
	 * Display download document buttons on My Account page.
	 *
	 * @param array $actions
	 * @param \WC_Abstract_Order $order
	 *
	 * @return array
	 */
	public function my_account_button_visibility( array $actions, \WC_Abstract_Order $order ): array {
		$documents = array(
			'proforma'     => 'no_invoice',
			'packing-slip' => 'never',
			'receipt'      => 'available',
		);

		foreach ( $documents as $document_type => $default_visibility ) {
			$document = wcpdf_get_document( $document_type, $order );
			if ( $document && $document->is_enabled() ) {
				// check my account button settings
				$button_setting   = $document->get_setting( 'my_account_buttons', $default_visibility );
				$document_allowed = false;

				switch ( $button_setting ) {
					case 'no_invoice':
						$document_allowed = ! isset( $actions['invoice'] );
						break;
					case 'available':
						$document_allowed = $document->exists();
						break;
					case 'always':
						$document_allowed = true;
						break;
					case 'custom':
						$allowed_statuses = $document->get_setting( 'my_account_restrict', array() );
						if (
							! empty( $allowed_statuses ) &&
							in_array( $order->get_status(), array_keys( $allowed_statuses ), true )
						) {
							$document_allowed = true;
						}
						break;
				}

				if ( $document_allowed ) {
					$actions[ $document_type ] = array(
						'url'  => esc_url( WPO_WCPDF()->endpoint->get_document_link( $order, $document_type, array( 'my-account' => 'true' ) ) ),
						'name' => apply_filters( 'wpo_wcpdf_myaccount_' . $document->slug . '_button', $document->get_title(), $document ),
					);
				}
			}
		}

		// Display the "Credit Note" button when both a credit note and an invoice are available.
		$refunds = $order->get_refunds();

		// If there's at least one credit note, we'll take them all.
		$show_credit_note_button = apply_filters( 'wpo_wcpdf_show_myaccount_credit_note_button', ( ! empty( $refunds ) && isset( $actions['invoice'] ) ), $order, $refunds );

		if ( $show_credit_note_button ) {
			$first_refund = current( $refunds );
			$credit_note  = wcpdf_get_document( 'credit-note', $first_refund );

			if ( $credit_note && $credit_note->exists() && $credit_note->is_enabled() ) {
				$actions['credit-note'] = array(
					'url'  => esc_url( WPO_WCPDF()->endpoint->get_document_link( $order, 'credit-note', array( 'my-account' => 'true' ) ) ),
					'name' => apply_filters( 'wpo_wcpdf_myaccount_credit_note_button', $credit_note->get_title(), $credit_note ),
				);
			}
		}

		return $actions;
	}

	/**
	 * Display Credit Note Number in Shop Order column (if available)
	 * @param  string $column column slug
	 */
	public function credit_note_number_column_data( $order ) {
		$refunds = $order->get_refunds();
		foreach ( $refunds as $key => $refund ) {
			$credit_note = wcpdf_get_document( 'credit-note', $refund );
			if ( $credit_note && is_callable( array( $credit_note, 'get_number' ) ) && $credit_note_number = $credit_note->get_number( 'credit-note' ) ) {
				$credit_note_numbers[] = $credit_note_number;
				$title = $credit_note->get_title();
			}
		}

		if ( isset( $credit_note_numbers ) ) {
			?>
			<br/><?php echo $title; ?>:<br/>
			<?php
			echo implode( ', ', $credit_note_numbers );
		}
	}

	public function edit_numbers_dates( $order, $class = null ) {
		// bail if null
		if( is_null( $class ) ) return;
		
		// Credit note
		$refunds = $order->get_refunds();
		if ( ! empty( $refunds ) ) {
			foreach ( $refunds as $key => $refund ) {
				$credit_note = wcpdf_get_document( 'credit-note', $refund );
				if ( $credit_note && $credit_note->exists() ) {
					$refund_id = $refund->get_id();

					// data
					$data = array(
						'number' => array(
							'label' => __( 'Credit Note Number:', 'wpo_wcpdf_pro' ),
							'name'  => "_wcpdf_{$credit_note->slug}_number[{$refund_id}]",
						),
						'date'   => array(
							'label' => __( 'Credit Note Date:', 'wpo_wcpdf_pro' ),
							'name'  => "_wcpdf_{$credit_note->slug}_date[{$refund_id}]",
						),
					);
					
					// output
					$class->output_number_date_edit_fields( $credit_note, $data );
				}
			}
		}

		// Proforma invoice
		$proforma = wcpdf_get_document( 'proforma', $order );
		if ( $proforma && $proforma->exists() ) {
			// data
			$data = array(
				'number' => array(
					'label'  => __( 'Proforma Invoice Number:', 'wpo_wcpdf_pro' ),
				),
				'date'   => array(
					'label'  => __( 'Proforma Invoice Date:', 'wpo_wcpdf_pro' ),
				),
			);
			// output
			$class->output_number_date_edit_fields( $proforma, $data );
		}
		
		// Receipt
		$receipt = wcpdf_get_document( 'receipt', $order );
		if ( $receipt && $receipt->exists() ) {
			// data
			$data = array(
				'number' => array(
					'label'  => __( 'Receipt Number:', 'wpo_wcpdf_pro' ),
				),
				'date'   => array(
					'label'  => __( 'Receipt Date:', 'wpo_wcpdf_pro' ),
				),
			);
			// output
			$class->output_number_date_edit_fields( $receipt, $data );
		}

		// Packing slip
		$packing_slip = wcpdf_get_document( 'packing-slip', $order );
		if ( $packing_slip ) {
			$data = array(
				'number' => array(
					'label'  => __( 'Packing Slip Number:', 'wpo_wcpdf_pro' ),
				),
				'date'   => array(
					'label'  => __( 'Packing Slip Date:', 'wpo_wcpdf_pro' ),
				),
			);
			// output
			$class->output_number_date_edit_fields( $packing_slip, $data );
		}

	}

	/**
	 * Display regenerate order data buttons when keep PDF is active
	 */
	public function regenerate_order_data_button_for_stored_pdf( $document, $order ) {

		$document_settings = $document->get_settings( true );
		if ( !isset( $document_settings['archive_pdf'] ) ) return;

		$parent_order = $archived = false;

		// If credit note
		if ( $document->get_type() == 'credit-note' ) {
			$parent_order = wc_get_order( $order->get_parent_id() );
		}

		// Get PDF file path
		$order_key = $parent_order ? $parent_order->get_order_key() : $order->get_order_key();
		$archive_path = WPO_WCPDF()->main->get_tmp_path( 'archive' );
		$filename = $order->get_meta( '_wpo_wcpdf_' . $document->slug . '_archived', true );

		// Check if PDF file exists on server
		if ( !empty( $filename ) && file_exists( $archive_path . '/' . $filename ) ) $archived = true;	
		clearstatcache();

		?>
		<div class="document-archived">
			<p class="form-field wcpdf_archived_document_data">	
				<p>
					<span><strong><?php echo $document->get_title() . ' ' . __( 'stored on server', 'wpo_wcpdf_pro' ); ?>:</strong></span>
					<span style="margin-right:10px;"><?php echo $archived ? __( 'Yes', 'wpo_wcpdf_pro' ) : __( 'No', 'wpo_wcpdf_pro' ) ; ?></span>
				</p>
			</p>
		</div>
		<?php
	}

	/**
	 * Process numbers & dates from order edit screen
	 */
	public function save_numbers_dates( $form_data, $order, $class = null ) {
		// bail if null
		if ( is_null( $class ) ) {
			return;
		}

		// Proforma
		$proforma = wcpdf_get_document( 'proforma', $order );
		if ( ! empty( $proforma ) ) {
			$document_data = $class->process_order_document_form_data( $form_data, $proforma->slug );
			$proforma->set_data( $document_data, $order );
			$proforma->save();
		}
		
		// Receipt
		$receipt = wcpdf_get_document( 'receipt', $order );
		if ( ! empty( $receipt ) ) {
			$document_data = $class->process_order_document_form_data( $form_data, $receipt->slug );
			$receipt->set_data( $document_data, $order );
			$receipt->save();
		}

		// Packing Slip
		$packing_slip = wcpdf_get_document( 'packing-slip', $order );
		if ( ! empty( $packing_slip ) ) {
			$document_data = $class->process_order_document_form_data( $form_data, $packing_slip->slug );
			$packing_slip->set_data( $document_data, $order );
			$packing_slip->save();
		}

		// Credit Note
		$credit_note_data_list   = array();
		$credit_note_field_names = array(
			'_wcpdf_credit_note_number',
			'_wcpdf_credit_note_date',
		);

		foreach ( $credit_note_field_names as $field_name ) {
			if ( isset( $_POST[ $field_name ] ) && is_array( $_POST[ $field_name ] ) ) {
				foreach ( $_POST[ $field_name ] as $refund_id => $value ) {
					$credit_note_data_list[ $refund_id ][ $field_name ] = $value;
				}
			}
		}

		foreach ( $credit_note_data_list as $refund_id => $credit_note_data ) {
			$credit_note = wcpdf_get_document( 'credit-note', $order );
			if ( $credit_note ) {
				$document_data = $class->process_order_document_form_data( $credit_note_data, $credit_note->slug );
				$credit_note->set_data( $document_data, $order );
				$credit_note->save();
			}
		}
	}
	
	/**
	 * Add credit note email to order actions list
	 */
	public function pro_email_order_actions( $available_emails, $order_id ) {
		if ( empty( $order_id ) ) {
			return $available_emails;
		}

		return array_merge( $available_emails, $this->get_pro_emails( absint( $order_id ) ) );
	}
	
	/**
	 * Get Pro emails
	 *
	 * @param  int $order_id
	 * @return array
	 */
	public function get_pro_emails( $order_id ) {
		$pro_emails = array();
		
		if ( empty( $order_id ) ) {
			return $pro_emails;
		}

		$order_notification_settings = get_option( 'woocommerce_pdf_order_notification_settings', array() );
		if ( isset( $order_notification_settings['recipient'] ) && ! empty( $order_notification_settings['recipient'] ) ) {
			// only add order notification action when a recipient is set!
			$pro_emails[] = 'pdf_order_notification';
		}
		
		$order = wc_get_order( $order_id );
		if ( ! empty( $order ) ) {
			$refunds = $order->get_refunds();
			if ( ! empty( $refunds ) ) {
				$pro_emails[] = 'customer_credit_note';
			}
		}

		return $pro_emails;
	}

} // end class

endif; // end class_exists

return new Writepanels();
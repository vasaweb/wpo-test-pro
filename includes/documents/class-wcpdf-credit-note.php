<?php
namespace WPO\WC\PDF_Invoices\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( '\\WPO\\WC\\PDF_Invoices\\Documents\\Credit_Note' ) ) :

/**
 * Credit Note Document
 * 
 * @class  \WPO\WC\PDF_Invoices\Documents\Credit_Note
 */

class Credit_Note extends Pro_Document {

	/**
	 * @var string
	 */
	public $type;

	/**
	 * @var string
	 */
	public $title;

	/**
	 * @var string
	 */
	public $icon;

	/**
	 * Init/load the order object.
	 *
	 * @param  int|object|WC_Order $order Order to init.
	 */
	public function __construct( $order = 0 ) {
		// set properties
		$this->type  = 'credit-note';
		$this->title = __( 'Credit Note', 'wpo_wcpdf_pro' );
		$this->icon  = WPO_WCPDF_Pro()->plugin_url() . '/assets/images/credit-note.svg';

		// Call parent constructor
		parent::__construct( $order );

		// Determine numbering system (main invoice number or separate document sequence)
		add_filter( 'wpo_wcpdf_document_sequential_number_store', array( $this, 'get_number_sequence' ), 1, 2 );
	}

	public function get_title() {
		// override/not using $this->title to allow for language switching!
		$title = __( 'Credit Note', 'wpo_wcpdf_pro' );
		$title = apply_filters_deprecated( "wpo_wcpdf_{$this->slug}_title", array( $title, $this ), '2.15.11', 'wpo_wcpdf_document_title' ); // deprecated
		return apply_filters( 'wpo_wcpdf_document_title', $title, $this );
	}

	public function get_number_title() {
		// override to allow for language switching!
		$title = __( 'Credit Note Number:', 'wpo_wcpdf_pro' );
		$title = apply_filters_deprecated( "wpo_wcpdf_{$this->slug}_number_title", array( $title, $this ), '2.15.11', 'wpo_wcpdf_document_number_title' ); // deprecated
		return apply_filters( 'wpo_wcpdf_document_number_title', $title, $this );
	}

	public function get_date_title() {
		// override to allow for language switching!
		$title = __( 'Credit Note Date:', 'wpo_wcpdf_pro' );
		$title = apply_filters_deprecated( "wpo_wcpdf_{$this->slug}_date_title", array( $title, $this ), '2.15.11', 'wpo_wcpdf_document_date_title' ); // deprecated
		return apply_filters( 'wpo_wcpdf_document_date_title', $title, $this );
	}
	
	/**
	 * Get the shipping address title
	 *
	 * @return string
	 */
	public function get_shipping_address_title(): string {
		// override to allow for language switching!
		return apply_filters( 'wpo_wcpdf_document_shipping_address_title', __( 'Ship To:', 'wpo_wcpdf_pro' ), $this );
	}

	public function get_filename( $context = 'download', $args = array() ) {
		$order_ids   = isset( $args['order_ids'] ) ? $args['order_ids'] : array( $this->order_id );
		$order_count = count( $order_ids );
		$name        = _n( 'credit-note', 'credit-notes', $order_count, 'wpo_wcpdf_pro' );

		if ( $order_count == 1 ) {
			if ( isset( $this->settings['display_number'] ) ) {
				$suffix = (string) $this->get_number();
			} else {
				if ( empty( $this->order ) ) {
					$order  = wc_get_order( $order_ids[0] );
					$suffix = method_exists( $order, 'get_order_number' ) ? $order->get_order_number() : intval($order_ids[0]);
				} else {
					$suffix = method_exists( $this->order, 'get_order_number' ) ? $this->order->get_order_number() : $this->order->get_id();
				}
			}
		} else {
			$suffix = date('Y-m-d'); // 2020-11-11
		}

		$filename = $name . '-' . $suffix . '.pdf';

		// Filter filename
		$filename = apply_filters( 'wpo_wcpdf_filename', $filename, $this->get_type(), $order_ids, $context, $args );

		// sanitize filename (after filters to prevent human errors)!
		return sanitize_file_name( $filename );
	}


	/**
	 * Initialise settings
	 */
	public function init_settings() {
		// Register settings.
		$page = $option_group = $option_name = 'wpo_wcpdf_documents_settings_credit-note';

		$settings_fields = array(
			array(
				'type'     => 'section',
				'id'       => 'credit_note',
				'title'    => '',
				'callback' => 'section',
			),
			array(
				'type'     => 'setting',
				'id'       => 'enabled',
				'title'    => __( 'Enable', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'enabled',
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'attach_to_email_ids',
				'title'    => __( 'Attach to:', 'wpo_wcpdf_pro' ),
				'callback' => 'multiple_checkboxes',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name'     => $option_name,
					'id'              => 'attach_to_email_ids',
					'fields_callback' => array( $this, 'get_wc_emails' ),
					'description'     => ! is_writable( WPO_WCPDF()->main->get_tmp_path( 'attachments' ) )
						? '<span class="wpo-warning">' . sprintf(
							/* translators: %s: temp folder path */
							__( 'It looks like the temp folder (%s) is not writable, check the permissions for this folder! Without having write access to this folder, the plugin will not be able to email invoices.', 'wpo_wcpdf_pro' ),
							'<code>' . WPO_WCPDF()->main->get_tmp_path( 'attachments' ) . '</code>'
						) . '</span>'
						: '',
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'display_shipping_address',
				'title'    => __( 'Display shipping address', 'wpo_wcpdf_pro' ),
				'callback' => 'select',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'display_shipping_address',
					'options'     => array(
						''               => __( 'No' , 'wpo_wcpdf_pro' ),
						'when_different' => __( 'Only when different from billing address' , 'wpo_wcpdf_pro' ),
						'always'         => __( 'Always' , 'wpo_wcpdf_pro' ),
					),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'display_email',
				'title'    => __( 'Display email address', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'display_email',
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'display_phone',
				'title'    => __( 'Display phone number', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'display_phone',
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'display_date',
				'title'    => __( 'Display credit note date', 'wpo_wcpdf_pro' ),
				'callback' => 'select',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'display_date',
					'options'     => array(
						''           => __( 'No' , 'wpo_wcpdf_pro' ),
						'1'          => __( 'Credit Note Date' , 'wpo_wcpdf_pro' ),
						'order_date' => __( 'Refund Date' , 'wpo_wcpdf_pro' ),
					),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'display_number',
				'title'    => __( 'Display credit note number', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'display_number',
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'number_sequence',
				'title'    => __( 'Number sequence', 'wpo_wcpdf_pro' ),
				'callback' => 'radio_button',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'number_sequence',
					'options'     => array(
						'invoice_number'     => __( 'Main invoice numbering' , 'wpo_wcpdf_pro' ),
						'credit_note_number' => __( 'Separate credit note numbering' , 'wpo_wcpdf_pro' ),
					),
					'default'     => 'credit_note_number',
			)
			),
			array(
				'type'     => 'setting',
				'id'       => 'original_invoice_number',
				'title'    => __( 'Show original invoice number', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'original_invoice_number',
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'next_credit_note_number',
				'title'    => __( 'Next credit note number (without prefix/suffix etc.)', 'wpo_wcpdf_pro' ),
				'callback' => 'next_number_edit',
				'section'  => 'credit_note',
				'args'     => array(
					'store_callback' => array( $this, 'get_sequential_number_store' ),
					'size'        => '10',
					'description' => __( 'This is the number that will be used for the next document. By default, numbering starts from 1 and increases for every new document. Note that if you override this and set it lower than the current/highest number, this could create duplicate numbers!', 'wpo_wcpdf_pro' ),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'number_format',
				'title'    => __( 'Number format', 'wpo_wcpdf_pro' ),
				'callback' => 'multiple_text_input',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'number_format',
					'fields'      => array(
						'prefix'  => array(
							'label'       => __( 'Prefix' , 'wpo_wcpdf_pro' ),
							'size'        => 20,
							'description' => __( 'If set, this value will be used as number prefix.' , 'wpo_wcpdf_pro' ) . ' ' . sprintf(
								/* translators: 1. document type, 2-3 placeholders */
								__( 'You can use the %1$s year and/or month with the %2$s or %3$s placeholders respectively.', 'wpo_wcpdf_pro' ),
								strtolower( __( 'Credit Note', 'wpo_wcpdf_pro' ) ), '<strong>[credit_note_year]</strong>', '<strong>[credit_note_month]</strong>'
							) . ' ' . __( 'Check the Docs article below to see all the available placeholders for prefix/suffix.', 'wpo_wcpdf_pro' ),
						),
						'suffix'  => array(
							'label'       => __( 'Suffix' , 'wpo_wcpdf_pro' ),
							'size'        => 20,
							'description' => __( 'If set, this value will be used as number suffix.' , 'wpo_wcpdf_pro' ) . ' ' . sprintf(
								/* translators: 1. document type, 2-3 placeholders */
								__( 'You can use the %1$s year and/or month with the %2$s or %3$s placeholders respectively.', 'wpo_wcpdf_pro' ),
								strtolower( __( 'Credit Note', 'wpo_wcpdf_pro' ) ), '<strong>[credit_note_year]</strong>', '<strong>[credit_note_month]</strong>'
							) . ' ' . __( 'Check the Docs article below to see all the available placeholders for prefix/suffix.', 'wpo_wcpdf_pro' ),
						),
						'padding' => array(
							'label'       => __( 'Padding' , 'wpo_wcpdf_pro' ),
							'size'        => 20,
							'type'        => 'number',
							/* translators: document type */
							'description' => sprintf( __( 'Enter the number of digits you want to use as padding. For instance, enter <code>6</code> to display the %s number <code>123</code> as <code>000123</code>, filling it with zeros until the number set as padding is reached.' , 'wpo_wcpdf_pro' ), strtolower( __( 'Credit Note', 'wpo_wcpdf_pro' ) ) ),
						),
					),
					/* translators: document type */
					'description' => __( 'For more information about setting up the number format and see the available placeholders for the prefix and suffix, check this article:', 'wpo_wcpdf_pro' ) . sprintf( ' <a href="https://docs.wpovernight.com/woocommerce-pdf-invoices-packing-slips/number-format-explained/" target="_blank">%s</a>', __( 'Number format explained', 'wpo_wcpdf_pro') ) . '.<br><br>'. sprintf( __( '<strong>Note</strong>: Changes made to the number format will only be reflected on new orders. Also, if you have already created a custom %s number format with a filter, the above settings will be ignored.', 'wpo_wcpdf_pro' ), strtolower( __( 'Credit Note', 'wpo_wcpdf_pro' ) ) ),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'reset_number_yearly',
				'title'    => __( 'Reset credit note number yearly', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'reset_number_yearly',
				)
			),
			// array(
			// 	'type'     => 'setting',
			// 	'id'       => 'my_account_buttons',
			// 	'title'    => __( 'Allow My Account download', 'wpo_wcpdf_pro' ),
			// 	'callback' => 'select',
			// 	'section'  => 'credit_note',
			// 	'args'     => array(
			// 		'option_name' => $option_name,
			// 		'id'          => 'my_account_buttons',
			// 		'options'     => array(
			// 			'available' => __( 'Only when a credit note is already created/emailed' , 'wpo_wcpdf_pro' ),
			// 			'custom'    => __( 'Only for specific order statuses (define below)' , 'wpo_wcpdf_pro' ),
			// 			'always'    => __( 'Always' , 'wpo_wcpdf_pro' ),
			// 			'never'     => __( 'Never' , 'wpo_wcpdf_pro' ),
			// 		),
			// 		'custom'      => array(
			// 			'type' => 'multiple_checkboxes',
			// 			'args' => array(
			// 				'option_name'     => $option_name,
			// 				'id'              => 'my_account_restrict',
			// 				'fields_callback' => array( $this, 'get_wc_order_status_list ),
			// 			),
			// 		),
			// 	)
			// ),
			array(
				'type'			=> 'setting',
				'id'			=> 'credit_note_number_search',
				'title'			=> __( 'Enable credit note number search in the orders list', 'wpo_wcpdf_pro' ),
				'callback'		=> 'checkbox',
				'section'		=> 'credit_note',
				'args'			=> array(
					'option_name'	=> $option_name,
					'id'			=> 'credit_note_number_search',
					'description'   => __( 'Can potentially slow down the search process.', 'wpo_wcpdf_pro' ),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'disable_free',
				'title'    => __( 'Disable for free orders', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'disable_free',
					'description' => sprintf(
						/* translators: %s: price */
						__( 'Disable document when the order total is %s', 'wpo_wcpdf_pro' ),
						function_exists( 'wc_price' ) ? wc_price( 0 ) : 0
					),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'positive_prices',
				'title'    => __( 'Use positive prices', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'positive_prices',
					'description' => __( 'Prices in Credit Notes are negative by default, but some countries (like Germany) require positive prices.', 'wpo_wcpdf_pro' ),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'use_parent_data',
				'title'    => __( 'Use products & totals fallback', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'use_parent_data',
					'description' => __( 'If orders are refunded without setting products, credit notes will not contain these details. This option provides a fallback method by using data from the original order for the credit note. This may cause issues in some setups, so testing is recommended.', 'wpo_wcpdf_pro' ),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'use_latest_settings',
				'title'    => __( 'Always use most current settings', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'use_latest_settings',
					'description' => __( "When enabled, the document will always reflect the most current settings (such as footer text, document name, etc.) rather than using historical settings.", 'wpo_wcpdf_pro' )
					                 . "<br>"
					                 . __( "<strong>Caution:</strong> enabling this will also mean that if you change your company name or address in the future, previously generated documents will also be affected.", 'wpo_wcpdf_pro' ),
				)
			),
		);

		// Legacy filter to allow plugins to alter settings fields.
		$settings_fields = apply_filters( 'wpo_wcpdf_settings_fields_documents_credit_note', $settings_fields, $page, $option_group, $option_name );

		// Allow plugins to alter settings fields.
		$settings_fields = apply_filters( "wpo_wcpdf_settings_fields_documents_{$this->type}_pdf", $settings_fields, $page, $option_group, $option_name, $this );

		if ( ! empty( $settings_fields ) ) {
			WPO_WCPDF()->settings->add_settings_fields( $settings_fields, $page, $option_group, $option_name );
		}
	}

	/**
	 * Get the settings categories.
	 *
	 * @param string $output_format
	 *
	 * @return array
	 */
	public function get_settings_categories( string $output_format ): array {
		if ( ! in_array( $output_format, $this->output_formats, true ) ) {
			return array();
		}

		$settings_categories = array(
			'pdf' => array(
				'general'          => array(
					'title'   => __( 'General', 'wpo_wcpdf_pro' ),
					'members' => array(
						'enabled',
						'title',
						'attach_to_email_ids',
						'auto_generate_on_refunds',
						'disable_for',
						'my_account_buttons',
					),
				),
				'document_details' => array(
					'title'   => __( 'Document details', 'wpo_wcpdf_pro' ),
					'members' => array(
						'number_title',
						'date_title',
						'display_email',
						'display_phone',
						'display_customer_notes',
						'display_shipping_address',
						'display_number',
						'display_invoice_number',
						'next_credit_note_number', // this should follow 'display_number'
						'number_format',
						'display_date',
						'original_invoice_number',
						'positive_prices',
						'use_parent_data',
					)
				),
				'advanced'         => array(
					'title'   => __( 'Advanced', 'wpo_wcpdf_pro' ),
					'members' => array(
						'filename',
						'reset_number_yearly',
						'number_sequence',
						'require_invoice',
						'archive_pdf',
						'disable_free',
						'credit_note_number_search',
						'use_latest_settings',
					)
				),
			),
		);

		return apply_filters( 'wpo_wcpdf_document_settings_categories', $settings_categories[ $output_format ], $output_format, $this );
	}
}

endif; // class_exists

return new Credit_Note();
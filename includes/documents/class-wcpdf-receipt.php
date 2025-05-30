<?php
namespace WPO\WC\PDF_Invoices\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( '\\WPO\\WC\\PDF_Invoices\\Documents\\Receipt' ) ) :

class Receipt extends Pro_Document {

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
		$this->type  = 'receipt';
		$this->title = __( 'Receipt', 'wpo_wcpdf_pro' );
		$this->icon  = WPO_WCPDF_Pro()->plugin_url() . '/assets/images/receipt.svg';

		// Call parent constructor
		parent::__construct( $order );
		
		// Determine numbering system
		add_filter( 'wpo_wcpdf_document_sequential_number_store', array( $this, 'get_number_sequence' ), 1, 2 );
	}

	public function get_title() : string {
		// override/not using $this->title to allow for language switching!
		$title = __( 'Receipt', 'wpo_wcpdf_pro' );
		$title = apply_filters_deprecated( "wpo_wcpdf_{$this->slug}_title", array( $title, $this ), '2.15.11', 'wpo_wcpdf_document_title' ); // deprecated
		return apply_filters( 'wpo_wcpdf_document_title', $title, $this );
	}

	public function get_number_title() : string {
		// override to allow for language switching!
		$title = __( 'Receipt Number:', 'wpo_wcpdf_pro' );
		$title = apply_filters_deprecated( "wpo_wcpdf_{$this->slug}_number_title", array( $title, $this ), '2.15.11', 'wpo_wcpdf_document_number_title' ); // deprecated
		return apply_filters( 'wpo_wcpdf_document_number_title', $title, $this );
	}

	public function get_date_title() : string {
		// override to allow for language switching!
		$title = __( 'Receipt Date:', 'wpo_wcpdf_pro' );
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

	public function get_filename( $context = 'download', $args = array() ) : string {
		$order_count = isset( $args['order_ids'] ) ? count( $args['order_ids'] ) : 1;
		$name        = _n( 'receipt', 'receipts', $order_count, 'wpo_wcpdf_pro' );

		if ( 1 === $order_count ) {
			if ( isset( $this->settings['display_number'] ) ) {
				$suffix = (string) $this->get_number();
			} else {
				if ( empty( $this->order ) && isset( $args['order_ids'][0] ) ) {
					$order  = wc_get_order( $args['order_ids'][0] );
					$suffix = is_callable( array( $order, 'get_order_number' ) ) ? $order->get_order_number() : '';
				} else {
					$suffix = is_callable( array( $this->order, 'get_order_number' ) ) ? $this->order->get_order_number() : '';
				}
			}
			// ensure unique filename in case suffix was empty
			if ( empty( $suffix ) ) {
				if ( ! empty( $this->order_id ) ) {
					$suffix = $this->order_id;
				} elseif ( ! empty( $args['order_ids'] ) && is_array( $args['order_ids'] ) ) {
					$suffix = reset( $args['order_ids'] );
				} else {
					$suffix = uniqid();
				}
			}
		} else {
			$suffix = date( 'Y-m-d' ); // 2020-11-11
		}
		
		$filename  = $name . '-' . $suffix . '.pdf';

		// Filter filename
		$order_ids = isset( $args['order_ids'] ) ? $args['order_ids'] : array( $this->order_id );
		$filename  = apply_filters( 'wpo_wcpdf_filename', $filename, $this->get_type(), $order_ids, $context, $args );

		// sanitize filename (after filters to prevent human errors)!
		return sanitize_file_name( $filename );
	}

	/**
	 * Initialise settings
	 */
	public function init_settings() : void {
		// Register settings.
		$page = $option_group = $option_name = 'wpo_wcpdf_documents_settings_receipt';

		$settings_fields = array(
			array(
				'type'     => 'section',
				'id'       => 'receipt',
				'title'    => '',
				'callback' => 'section',
			),
			array(
				'type'     => 'setting',
				'id'       => 'enabled',
				'title'    => __( 'Enable', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'receipt',
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
				'section'  => 'receipt',
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
				'id'       => 'disable_for_statuses',
				'title'    => __( 'Disable for:', 'wpo_wcpdf_pro' ),
				'callback' => 'select',
				'section'  => 'receipt',
				'args'     => array(
					'option_name'      => $option_name,
					'id'               => 'disable_for_statuses',
					'options_callback' => 'wc_get_order_statuses',
					'multiple'         => true,
					'enhanced_select'  => true,
					'placeholder'      => __( 'Select one or more statuses', 'wpo_wcpdf_pro' ),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'display_shipping_address',
				'title'    => __( 'Display shipping address', 'wpo_wcpdf_pro' ),
				'callback' => 'select',
				'section'  => 'receipt',
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
				'section'  => 'receipt',
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
				'section'  => 'receipt',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'display_phone',
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'display_date',
				'title'    => __( 'Display receipt date', 'wpo_wcpdf_pro' ),
				'callback' => 'select',
				'section'  => 'receipt',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'display_date',
					'options'     => array(
						''           => __( 'No' , 'wpo_wcpdf_pro' ),
						'1'          => __( 'Receipt Date' , 'wpo_wcpdf_pro' ),
						'order_date' => __( 'Order Date' , 'wpo_wcpdf_pro' ),
					),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'display_invoice_number',
				'title'    => __( 'Display invoice number', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'receipt',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'display_invoice_number',
					'description' => __( 'Displays the invoice number if it exists.', 'wpo_wcpdf_pro' ),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'display_number',
				'title'    => __( 'Display receipt number', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'receipt',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'display_number',
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'next_receipt_number',
				'title'    => __( 'Next receipt number (without prefix/suffix etc.)', 'wpo_wcpdf_pro' ),
				'callback' => 'next_number_edit',
				'section'  => 'receipt',
				'args'     => array(
					'store_callback' => array( $this, 'get_sequential_number_store' ),
					'size'           => '10',
					'description'    => __( 'This is the number that will be used for the next document. By default, numbering starts from 1 and increases for every new document. Note that if you override this and set it lower than the current/highest number, this could create duplicate numbers!', 'wpo_wcpdf_pro' ),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'number_format',
				'title'    => __( 'Number format', 'wpo_wcpdf_pro' ),
				'callback' => 'multiple_text_input',
				'section'  => 'receipt',
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
								strtolower( __( 'Receipt', 'wpo_wcpdf_pro' ) ), '<strong>[receipt_year]</strong>', '<strong>[receipt_month]</strong>'
							) . ' ' . __( 'Check the Docs article below to see all the available placeholders for prefix/suffix.', 'wpo_wcpdf_pro' ),
						),
						'suffix'  => array(
							'label'       => __( 'Suffix' , 'wpo_wcpdf_pro' ),
							'size'        => 20,
							'description' => __( 'If set, this value will be used as number suffix.' , 'wpo_wcpdf_pro' ) . ' ' . sprintf(
								/* translators: 1. document type, 2-3 placeholders */
								__( 'You can use the %1$s year and/or month with the %2$s or %3$s placeholders respectively.', 'wpo_wcpdf_pro' ),
								strtolower( __( 'Receipt', 'wpo_wcpdf_pro' ) ), '<strong>[receipt_year]</strong>', '<strong>[receipt_month]</strong>'
							) . ' ' . __( 'Check the Docs article below to see all the available placeholders for prefix/suffix.', 'wpo_wcpdf_pro' ),
						),
						'padding' => array(
							'label'       => __( 'Padding' , 'wpo_wcpdf_pro' ),
							'size'        => 20,
							'type'        => 'number',
							/* translators: document type */
							'description' => sprintf( __( 'Enter the number of digits you want to use as padding. For instance, enter <code>6</code> to display the %s number <code>123</code> as <code>000123</code>, filling it with zeros until the number set as padding is reached.' , 'wpo_wcpdf_pro' ), strtolower( __( 'Receipt', 'wpo_wcpdf_pro' ) ) ),
						),
					),
					/* translators: document type */
					'description' => __( 'For more information about setting up the number format and see the available placeholders for the prefix and suffix, check this article:', 'wpo_wcpdf_pro' ) . sprintf( ' <a href="https://docs.wpovernight.com/woocommerce-pdf-invoices-packing-slips/number-format-explained/" target="_blank">%s</a>', __( 'Number format explained', 'wpo_wcpdf_pro') ) . '.<br><br>'. sprintf( __( '<strong>Note</strong>: Changes made to the number format will only be reflected on new orders. Also, if you have already created a custom %s number format with a filter, the above settings will be ignored.', 'wpo_wcpdf_pro' ), strtolower( __( 'Receipt', 'wpo_wcpdf_pro' ) ) ),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'require_invoice',
				'title'    => __( 'Require invoice', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'receipt',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'require_invoice',
					'description' => __( 'Require invoice to be generated before creating a receipt.', 'wpo_wcpdf_pro' ),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'reset_number_yearly',
				'title'    => __( 'Reset receipt number yearly', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'receipt',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'reset_number_yearly',
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'my_account_buttons',
				'title'    => __( 'Allow My Account download', 'wpo_wcpdf_pro' ),
				'callback' => 'select',
				'section'  => 'receipt',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'my_account_buttons',
					'options'     => array(
						'available'  => __( 'Only when a receipt is already created/emailed' , 'wpo_wcpdf_pro' ),
						'custom'     => __( 'Only for specific order statuses (define below)' , 'wpo_wcpdf_pro' ),
						'always'     => __( 'Always' , 'wpo_wcpdf_pro' ),
						'never'      => __( 'Never' , 'wpo_wcpdf_pro' ),
					),
					'default'     => 'no_invoice',
					'custom'      => array(
						'type' => 'multiple_checkboxes',
						'args' => array(
							'option_name'     => $option_name,
							'id'              => 'my_account_restrict',
							'fields_callback' => array( $this, 'get_wc_order_status_list' ),
						),
					),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'disable_free',
				'title'    => __( 'Disable for free products', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'receipt',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'disable_free',
					'description' => __( "Disable automatic creation/attachment when only free products are ordered", 'wpo_wcpdf_pro' ),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'use_latest_settings',
				'title'    => __( 'Always use most current settings', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'receipt',
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
		$settings_fields = apply_filters( 'wpo_wcpdf_settings_fields_documents_receipt', $settings_fields, $page, $option_group, $option_name );

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
						'auto_generate_for_statuses',
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
						'next_receipt_number', // this should follow 'display_number'
						'number_format',
						'display_date',
					)
				),
				'advanced'         => array(
					'title'   => __( 'Advanced', 'wpo_wcpdf_pro' ),
					'members' => array(
						'filename',
						'reset_number_yearly',
						'require_invoice',
						'archive_pdf',
						'disable_free',
						'use_latest_settings',
					)
				),
			),
		);

		return apply_filters( 'wpo_wcpdf_document_settings_categories', $settings_categories[ $output_format ], $output_format, $this );
	}
}

endif; // class_exists

return new Receipt();
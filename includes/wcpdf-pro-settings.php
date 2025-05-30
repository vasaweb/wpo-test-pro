<?php
namespace WPO\WC\PDF_Invoices_Pro;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( !class_exists( '\\WPO\\WC\\PDF_Invoices_Pro\\Settings' ) ) :

class Settings {
	
	public $settings;
	public $option   = 'wpo_wcpdf_settings_pro';

	public function __construct() {
		$this->settings = get_option( $this->option, [] );

		add_action( 'admin_enqueue_scripts', array( $this, 'load_scripts_styles' ) );
		add_action( 'admin_notices', array( $this, 'pro_template_check' ) );

		add_action( 'wp_ajax_wcpdf_pro_filename_existence_check', array($this, 'filename_existence_check' ));
		add_action( 'wp_ajax_wcpdf_i18n_get_translations', array($this, 'get_translations' ));
		add_action( 'wp_ajax_wcpdf_i18n_save_translations', array($this, 'save_translations' ));

		add_action( 'wpo_wcpdf_settings_tabs', array( $this, 'settings_tab' ) );
		add_action( 'admin_init', array( $this, 'init_settings' ) );
		add_action( 'wpo_wcpdf_settings_output_pro', array( $this, 'output' ), 10, 1 );

		add_filter( 'wpo_wcpdf_settings_fields_general', array( $this, 'settings_fields_general_i18n' ), 10, 1 );
		add_filter( 'wpo_wcpdf_non_historical_settings', array( $this, 'non_historical_pro_settings' ), 10, 1 );

		add_action( 'admin_init', array( $this, 'add_pro_document_settings' ), 1 );
        add_action( 'wpo_wcpdf_document_settings_categories', array( $this, 'add_invoice_pro_settings_categories' ), 10, 3 );

		add_action( 'wpo_wcpdf_preview_after_reload_settings', array( $this, 'preview_reload_pro_settings' ) );
		add_filter( 'wpo_wcpdf_preview_excluded_settings', array( $this, 'preview_excluded_pro_settings' ), 10, 1 );

		add_filter( 'wpo_wcpdf_document_is_allowed', array( $this, 'disable_document_for' ), 1, 2 );
		
		add_filter( 'wpo_wcpdf_setting_types', array( $this, 'pro_setting_types' ), 10, 1 );
		add_filter( 'wpo_wcpdf_export_settings', array( $this, 'pro_settings_export' ), 10, 2 );
		add_filter( 'wpo_wcpdf_import_settings_option', array( $this, 'pro_settings_option_import' ), 10, 3 );
		add_filter( 'wpo_wcpdf_reset_settings_option', array( $this, 'pro_settings_option_reset' ), 10, 2 );

		// Rest API in settings.
		add_filter( 'wpo_wcpdf_settings_debug_sections', array( $this, 'add_rest_api_tab' ) );
		add_action( 'wpo_wcpdf_settings_debug_section_output', array( $this, 'add_rest_api_tab_content' ) );
	}

	public function output( $section ) {
		settings_fields( $this->option );
		do_settings_sections( $this->option );

		submit_button();
	}
	/**
	 * Register settings
	 */
	public function init_settings() {
		// Register settings.
		$page = $option_group = $option_name = $this->option;

		// load invoice to reuse method to get wc emails
		$invoice = wcpdf_get_invoice( null );

		$settings_fields = array(
			/**
			 * Static files section.
			 */
			array(
				'type'     => 'section',
				'id'       => 'static_files',
				'title'    => __( 'Static files', 'wpo_wcpdf_pro' ),
				'callback' => 'section',
			),
			array(
				'type'     => 'setting',
				'id'       => 'static_file',
				'title'    => __( 'Static files', 'wpo_wcpdf_pro' ),
				'callback' => ( class_exists( '\\Polylang' ) || class_exists( '\\TRP_Translate_Press' ) ) ? 'i18n_wrap' : array( $this, 'multiple_file_upload_callback' ),
				'section'  => 'static_files',
				'args'     => array(
					'callback'             => ( class_exists( '\\Polylang' ) || class_exists( '\\TRP_Translate_Press' ) ) ? array( $this, 'multiple_file_upload_callback' ) : '',
					'option_name'          => $option_name,
					'id'                   => 'static_file',
					'uploader_title'       => __( 'Select a file to attach', 'wpo_wcpdf_pro' ),
					'uploader_button_text' => __( 'Set file', 'wpo_wcpdf_pro' ),
					'remove_button_text'   => __( 'Remove file', 'wpo_wcpdf_pro' ),
					'total_files_number'   => apply_filters( 'wpo_wcpdf_total_static_files_number', 3 ),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'static_file_attach_to_email_ids',
				'title'    => __( 'Attach to:', 'wpo_wcpdf_pro' ),
				'callback' => 'multiple_checkboxes',
				'section'  => 'static_files',
				'args'     => array(
					'option_name'     => $option_name,
					'id'              => 'static_file_attach_to_email_ids',
					'fields_callback' => array( $invoice, 'get_wc_emails' ),
					'description'     => ! is_writable( WPO_WCPDF()->main->get_tmp_path( 'attachments' ) )
						? '<span class="wpo-warning">' . sprintf(
							/* translators: %s: temp folder path */
							__( 'It looks like the temp folder (%s) is not writable, check the permissions for this folder! Without having write access to this folder, the plugin will not be able to email invoices.', 'wpo_wcpdf_pro' ),
							'<code>' . WPO_WCPDF()->main->get_tmp_path( 'attachments' ) . '</code>'
						) . '</span>'
						: '',
				)
			),

			/**
			 * Address customization section
			 */
			
			array(
				'type'     => 'section',
				'id'       => 'address_customization',
				'title'    => __( 'Address customization', 'wpo_wcpdf_pro' ),
				'callback' => array( $this, 'custom_address_fields_section_callback' ),
			),
			array(
				'type'     => 'setting',
				'id'       => 'billing_address',
				'title'    => __( 'Billing address', 'wpo_wcpdf_pro' ),
				'callback' => 'i18n_wrap',
				'section'  => 'address_customization',
				'args'     => array(
					'callback'    => 'textarea',
					'option_name' => $option_name,
					'id'          => 'billing_address',
					'width'       => '42',
					'height'      => '8',
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'shipping_address',
				'title'    => __( 'Shipping address', 'wpo_wcpdf_pro' ),
				'callback' => 'i18n_wrap',
				'section'  => 'address_customization',
				'args'     => array(
					'callback'    => 'textarea',
					'option_name' => $option_name,
					'id'          => 'shipping_address',
					'width'       => '42',
					'height'      => '8',
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'remove_whitespace',
				'title'    => __( 'Remove empty lines', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'address_customization',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'remove_whitespace',
					'description' => __( 'Enable this option if you want to remove empty lines left over from empty address/placeholder replacements', 'wpo_wcpdf_pro' ),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'placeholders_allow_line_breaks',
				'title'    => __( 'Allow line breaks within custom fields', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'address_customization',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'placeholders_allow_line_breaks',
				)
			),
			array(
				'type'     => 'section',
				'id'       => 'multilingual',
				'title'    => __( 'Multilingual settings', 'wpo_wcpdf_pro' ),
				'callback' => array( $this, 'multilingual_supported_plugins_callback' ),
			),
			array(
				'type'     => 'setting',
				'id'       => 'document_language',
				'title'    => __( 'Document language', 'wpo_wcpdf_pro' ),
				'callback' => 'select',
				'section'  => 'multilingual',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'document_language',
					'options'     => $this->get_multilingual_document_language_options(),
				)
			),
			array(
				'type'     => 'section',
				'id'       => 'rest_api_settings',
				'title'    => __( 'REST API', 'wpo_wcpdf_pro' ),
				'callback' => 'section',
			),
			array(
				'type'     => 'setting',
				'id'       => 'enable_rest_api',
				'title'    => __( 'Enable', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'rest_api_settings',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'enable_rest_api',
					'disabled'    => WPO_WCPDF_Pro()->dependencies->is_rest_api_supported() ? '' : 'disabled',
					'description' => WPO_WCPDF_Pro()->dependencies->is_rest_api_supported() ?
						sprintf(
							/* translators: 1, 2. Opening anchor tag, 3. Closing anchor tag */
							__( 'Enable to allow operations on documents using REST API. %1$sSee documentation.%3$s<br>This plugin uses the WooCommerce REST API to work, you can enable it %2$shere%3$s.', 'wpo_wcpdf_pro' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=wpo_wcpdf_options_page&tab=debug&section=rest_api' ) ) . '">',
							'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=advanced&section=keys' ) ) . '">',
							'</a>'
						) :
						sprintf(
							/* translators: WordPress version */
							esc_html__( 'The REST API requires WordPress %1$s or higher.', 'wpo_wcpdf_pro' ),
							'<strong>' . WPO_WCPDF_Pro()->dependencies->rest_api_wp_min_version . '</strong>'
						)
				)
			),
		);

		// allow plugins to alter settings fields
		$settings_fields = apply_filters( 'wpo_wcpdf_settings_fields_pro', $settings_fields, $page, $option_group, $option_name );
		WPO_WCPDF()->settings->add_settings_fields( $settings_fields, $page, $option_group, $option_name );
		return;

	}

	public function add_pro_document_settings() {
		add_filter( 'wpo_wcpdf_settings_fields_documents_invoice', array( $this, 'pro_invoice_settings' ), 9, 4 );
		add_filter( 'wpo_wcpdf_settings_fields_documents_packing_slip', array( $this, 'pro_packing_slip_settings' ), 9, 4 );
		add_filter( 'wpo_wcpdf_settings_fields_documents_credit_note', array( $this, 'pro_credit_note_settings' ), 9, 4 );

		// add title, filename and keep PDF settings
		$documents = WPO_WCPDF()->documents->get_documents('all');
		foreach ( $documents as $document ) {
			add_filter( 'wpo_wcpdf_settings_fields_documents_'.$document->slug, function( $settings_fields, $page, $option_group, $option_name ) use ( $document ) {
				$new_setting = array(
					array(
						'type'     => 'setting',
						'id'       => 'title',
						'title'    => __( 'Document title', 'wpo_wcpdf_pro' ),
						'callback' => 'i18n_wrap',
						'section'  => 'custom',
						'args'     => array(
							'callback'    => 'text_input',
							'option_name' => $option_name,
							'id'          => 'title',
							'placeholder' => $document->get_title(),
						)
					),
					array(
						'type'     => 'setting',
						'id'       => 'filename',
						'title'    => __( 'PDF filename', 'wpo_wcpdf_pro' ),
						'callback' => 'i18n_wrap',
						'section'  => 'custom',
						'args'     => array(
							'callback'    => 'text_input',
							'option_name' => $option_name,
							'id'          => 'filename',
							// 'placeholder' => $document->get_type().'-######.pdf',
							'description' => __( 'Leave empty to use default. Placeholders like {{document_number}} and {{order_number}} can be used to include document numbers in the filename.', 'wpo_wcpdf_pro' ),
						)
					),
					array(
						'type'     => 'setting',
						'id'       => 'number_title',
						'title'    => __( 'Document number label', 'wpo_wcpdf_pro' ),
						'callback' => 'i18n_wrap',
						'section'  => 'custom',
						'args'     => array(
							'callback'    => 'text_input',
							'option_name' => $option_name,
							'id'          => 'number_title',
							'placeholder' => $document->get_number_title(),
						)
					),
					array(
						'type'     => 'setting',
						'id'       => 'date_title',
						'title'    => __( 'Document date label', 'wpo_wcpdf_pro' ),
						'callback' => 'i18n_wrap',
						'section'  => 'custom',
						'args'     => array(
							'callback'    => 'text_input',
							'option_name' => $option_name,
							'id'          => 'date_title',
							'placeholder' => $document->get_date_title(),
						)
					),
					array(
						'type'     => 'setting',
						'id'       => 'archive_pdf',
						'title'    => __( 'Keep PDF on server', 'wpo_wcpdf_pro' ),
						'callback' => 'checkbox',
						'section'  => 'custom',
						'args'     => array(
							'option_name' => $option_name,
							'id'          => 'archive_pdf',
							'description' => __( 'Stores the PDF when generated for the first time and reloads this copy each time the document is requested. Please note this can take up considerable disk space on you server.' , 'wpo_wcpdf_pro' ),
						)
					),
				);

				if ( $document->get_type() != 'credit-note' ) {
					$auto_generate_for_statuses = array(
						'type'     => 'setting',
						'id'       => 'auto_generate_for_statuses',
						'title'    => __( 'Create automatically for:', 'wpo_wcpdf_pro' ),
						'callback' => 'select',
						'section'  => 'custom',
						'args'     => array(
							'option_name'      => $option_name,
							'id'               => 'auto_generate_for_statuses',
							'options_callback' => 'wc_get_order_statuses',
							'multiple'         => true,
							'enhanced_select'  => true,
							'placeholder'      => __( 'Select one or more statuses', 'wpo_wcpdf_pro' ),
							'description'      => __( 'The document will be created automatically when the order reaches the status(es) selected above.', 'wpo_wcpdf_pro' ),
						)
					);
					array_push( $new_setting, $auto_generate_for_statuses );
				}

				/**
				 * Expand 'Disable for' option 
				 */

				// Unset the original 'Disable for statuses' setting from the free plugin
				foreach ( $settings_fields as $key => $setting ) {
					if ( $setting['id'] == 'disable_for_statuses' ) {
						unset( $settings_fields[$key] );
					}
				}

				$disable_for = array(
					'type'     => 'setting',
					'id'       => 'disable_for',
					'title'    => __( 'Disable for:', 'wpo_wcpdf_pro' ),
					'callback' => array( $this, 'requirements_callback' ),
					'section'  => 'custom',
					'args'     => array(
						'id'            => 'disable_for',
						'document_type' => $document->get_type(),
						'requirements'  => array(
							array(
								'title'    => __( 'Disable when' , 'wpo_wcpdf_pro' ),
								'callback' => 'select',
								'args'     => array(
									'option_name'      => $option_name,
									'id'               => 'disable_for_require',
									'options'          => array( 
										'one' => __( 'Any requirement is met' , 'wpo_wcpdf_pro' ),
										'all' => __( 'All requirements are met' , 'wpo_wcpdf_pro' ),
									),
									'description'      => __( 'Please select if the document should be disabled when any one of the below requirements are met, or only when all requirements are met.', 'wpo_wcpdf_pro' ),
								),
							),
							array(
								'title'    => __( 'Order status' , 'wpo_wcpdf_pro' ),
								'callback' => 'select',
								'args'     => array(
									'option_name'      => $option_name,
									'id'               => 'disable_for_statuses',
									'options_callback' => 'wc_get_order_statuses',
									'multiple'         => true,
									'enhanced_select'  => true,
									'placeholder'      => __( 'Select one or more statuses', 'wpo_wcpdf_pro' ),
								),
							),
							array(
								'title'    => __( 'Payment method' , 'wpo_wcpdf_pro' ),
								'callback' => 'select',
								'args'     => array(
									'option_name'      => $option_name,
									'id'               => 'disable_for_payment_methods',
									'options_callback' => array( $this, 'get_payment_gateways' ),
									'multiple'         => true,
									'enhanced_select'  => true,
									'placeholder'      => __( 'Select one or more payment methods', 'wpo_wcpdf_pro' ),
								),
							),
							array(
								'title'    => __( 'Billing country' , 'wpo_wcpdf_pro' ),
								'callback' => 'select',
								'args'     => array(
									'option_name'      => $option_name,
									'id'               => 'disable_for_billing_countries',
									'options'          => WC()->countries->countries,
									'multiple'         => true,
									'enhanced_select'  => true,
									'placeholder'      => __( 'Select one or more countries', 'wpo_wcpdf_pro' ),
								),
							),
						),
					),
				);
				array_push( $new_setting, $disable_for );

				$settings_fields = $this->move_setting_after_id( $settings_fields, $new_setting, 'enabled' );
				
				return $settings_fields;
			}, 10, 4 );
		}
	}

    public function add_invoice_pro_settings_categories( array $settings_categories, string $output_format, object $document ) {
		if ( version_compare( WPO_WCPDF()->version, '3.9.1-beta-1', '<' ) ) {
			return $settings_categories;
		}

		if ( 'invoice' === $document->get_type() ) {
			// General category
			$settings_categories = WPO_WCPDF()->settings->add_single_setting_field_to_category( $settings_categories, 'title', 'general', 2 );
			$settings_categories = WPO_WCPDF()->settings->add_multiple_setting_fields_to_category(
				$settings_categories,
				array( 'auto_generate_for_statuses', 'disable_for' ),
				'general',
				WPO_WCPDF()->settings->get_setting_position( $settings_categories, 'general', 'attach_to_email_ids' ) + 1
			);

			// Document display category
			$settings_categories = WPO_WCPDF()->settings->add_multiple_setting_fields_to_category( $settings_categories, array( 'number_title', 'date_title' ), 'document_details', 1 );
			$settings_categories = WPO_WCPDF()->settings->add_multiple_setting_fields_to_category(
				$settings_categories,
				array( 'display_shop_coc', 'display_shop_vat', 'shop_vat_label', 'shop_coc_label', ),
				'document_details',
				WPO_WCPDF()->settings->get_setting_position( $settings_categories, 'document_details', 'display_customer_notes' ) + 1
			);

			// Due date settings
			$settings_categories = WPO_WCPDF()->settings->add_multiple_setting_fields_to_category(
				$settings_categories,
				array( 'due_date_title', 'due_date_allowed_statuses','due_date_allowed_payment_methods'),
				'document_details',
				WPO_WCPDF()->settings->get_setting_position( $settings_categories, 'document_details', 'due_date' ) + 1
			);

			// Advanced category
			$settings_categories = WPO_WCPDF()->settings->add_single_setting_field_to_category( $settings_categories, 'filename', 'advanced', 1 );
			$settings_categories = WPO_WCPDF()->settings->add_single_setting_field_to_category(
				$settings_categories,
				'archive_pdf',
				'advanced',
				WPO_WCPDF()->settings->get_setting_position( $settings_categories, 'advanced', 'reset_number_yearly' ) + 1
			);
		}

		return apply_filters( 'wpo_wcpdf_pro_invoice_settings_categories', $settings_categories, $document );
	}

	public function pro_invoice_settings( $settings_fields, $page, $option_group, $option_name ) {
		$due_date_settings = array(
			array(
				'type'     => 'setting',
				'id'       => 'due_date_title',
				'title'    => 'Due date label',
				'callback' => 'i18n_wrap',
				'section'  => 'invoice',
				'args'     => array(
					'callback'    => 'text_input',
					'option_name' => $option_name,
					'id'          => 'due_date_title',
					'placeholder' => __( 'Due date:', 'wpo_wcpdf_pro' ),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'due_date_allowed_statuses',
				'title'    => __( 'Due date allowed order statuses', 'wpo_wcpdf_pro' ),
				'callback' => 'select',
				'section'  => 'invoice',
				'args'     => array(
					'option_name'      => $option_name,
					'id'               => 'due_date_allowed_statuses',
					'options_callback' => 'wc_get_order_statuses',
					'multiple'         => true,
					'enhanced_select'  => true,
					'placeholder'      => __( 'Select one or more statuses', 'wpo_wcpdf_pro' ),
					'description'      => __( 'The due date will only be displayed in the PDF invoices from orders that match any of the selected statuses above. Leave it empty to display it for all order statuses.', 'wpo_wcpdf_pro' ),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'due_date_allowed_payment_methods',
				'title'    => __( 'Due date allowed payment methods', 'wpo_wcpdf_pro' ),
				'callback' => 'select',
				'section'  => 'invoice',
				'args'     => array(
					'option_name'      => $option_name,
					'id'               => 'due_date_allowed_payment_methods',
					'options_callback' => array( $this, 'get_payment_gateways' ),
					'multiple'         => true,
					'enhanced_select'  => true,
					'placeholder'      => __( 'Select one or more payment methods', 'wpo_wcpdf_pro' ),
					'description'      => __( 'The due date will only be displayed in the PDF invoice from orders that have been placed with any of the payment methods selected above. Leave it empty to display it for all payment methods.', 'wpo_wcpdf_pro' ),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'display_shop_vat',
				'title'    => __( 'Display VAT number', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'invoice',
				'args'     => array(
					'option_name'  => $option_name,
					'id'           => 'display_shop_vat',
					'description'  => __( 'Displays the shop VAT number on the invoice. You can set it under the General settings tab.', 'wpo_wcpdf_pro' ),
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'shop_vat_label',
				'title'    => __( 'VAT number label', 'wpo_wcpdf_pro' ),
				'callback' => 'i18n_wrap',
				'section'  => 'custom',
				'args'     => array(
					'callback'    => 'text_input',
					'option_name' => $option_name,
					'id'          => 'shop_vat_label',
					'placeholder' => __( 'VAT', 'wpo_wcpdf_pro' ),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'display_shop_coc',
				'title'    => __( 'Display COC number', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'invoice',
				'args'     => array(
					'option_name'  => $option_name,
					'id'           => 'display_shop_coc',
					'description'  => __( 'Displays the shop Chamber of Commerce number on the invoice. You can set it under the General settings tab.', 'wpo_wcpdf_pro' ),
				),
			),
			array(
				'type'     => 'setting',
				'id'       => 'shop_coc_label',
				'title'    => __( 'COC number label', 'wpo_wcpdf_pro' ),
				'callback' => 'i18n_wrap',
				'section'  => 'custom',
				'args'     => array(
					'callback'    => 'text_input',
					'option_name' => $option_name,
					'id'          => 'shop_coc_label',
					'placeholder' => __( 'COC', 'wpo_wcpdf_pro' ),
				)
			),
		);

		$settings_fields = $this->move_setting_after_id( $settings_fields, $due_date_settings, 'due_date' );

		return $settings_fields;
	}

	public function pro_credit_note_settings( $settings_fields, $page, $option_group, $option_name ) {
		$auto_generate_on_refunds = array(
			array(
				'type'     => 'setting',
				'id'       => 'auto_generate_on_refunds',
				'title'    => __( 'Create automatically after refunding', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'credit_note',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'auto_generate_on_refunds',
					'description' => __( 'The Credit Note will be created automatically when a (partial or full) refund is created for an order.', 'wpo_wcpdf_pro' ),
				)
			),
		);

		$settings_fields = $this->move_setting_after_id( $settings_fields, $auto_generate_on_refunds, 'enabled' );
		return $settings_fields;
	}

	public function pro_packing_slip_settings( $settings_fields, $page, $option_group, $option_name ) {
		// load packing slip to reuse method to get wc emails
		$packing_slip = wcpdf_get_packing_slip( null );

		// new settings
		$new_settings = array(
			array(
				'type'     => 'setting',
				'id'       => 'attach_to_email_ids',
				'title'    => __( 'Attach to:', 'wpo_wcpdf_pro' ),
				'callback' => 'multiple_checkboxes',
				'section'  => 'packing_slip',
				'args'     => array(
					'option_name'     => $option_name,
					'id'              => 'attach_to_email_ids',
					'fields_callback' => array( $packing_slip, 'get_wc_emails' ),
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
				'section'  => 'packing_slip',
				'args'     => array(
					'option_name'      => $option_name,
					'id'               => 'disable_for_statuses',
					'options_callback' => 'wc_get_order_statuses',
					'multiple'         => true,
					'enhanced_select'  => true,
					'placeholder'      => __( 'Select one or more statuses', 'wpo_wcpdf_pro' ),
				)
			),
		);
		$settings_fields = $this->move_setting_after_id( $settings_fields, $new_settings, 'enabled' );

		// packing slip number setting
		$number_setting = array(
			array(
				'type'     => 'setting',
				'id'       => 'display_date',
				'title'    => __( 'Display packing slip date', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'packing_slip',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'display_date',
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'display_number',
				'title'    => __( 'Display packing slip number', 'wpo_wcpdf_pro' ),
				'callback' => 'select',
				'section'  => 'packing_slip',
				'args'     => array(
					'option_name' => $option_name,
					'id'      => 'display_number',					
					'options' => array(
						''                    => __( 'No', 'wpo_wcpdf_pro' ),
						'packing_slip_number' => __( 'Packing Slip Number', 'wpo_wcpdf_pro' ),
						'order_number'        => __( 'Order Number', 'wpo_wcpdf_pro' ),
					),					
					'description' => sprintf(
						'<strong>%s</strong> %s <a href="https://docs.wpovernight.com/woocommerce-pdf-invoices-packing-slips/invoice-numbers-explained/#why-is-the-pdf-invoice-number-different-from-the-woocommerce-order-number">%s</a>',
						__( 'Warning!', 'wpo_wcpdf_pro' ),
						__( 'Using the Order Number as packing slip number is not recommended as this may lead to gaps in the packing slip number sequence (even when order numbers are sequential).', 'wpo_wcpdf_pro' ),
						__( 'More information', 'wpo_wcpdf_pro' )
					),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'next_packing_slip_number',
				'title'    => __( 'Next packing slip number (without prefix/suffix etc.)', 'wpo_wcpdf_pro' ),
				'callback' => 'next_number_edit',
				'section'  => 'packing_slip',
				'args'     => array(
					'store_callback' => array( $packing_slip, 'get_sequential_number_store' ),
					'size'           => '10',
					'description'    => __( 'This is the number that will be used for the next document. By default, numbering starts from 1 and increases for every new document. Note that if you override this and set it lower than the current/highest number, this could create duplicate numbers!', 'wpo_wcpdf_pro' ),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'number_format',
				'title'    => __( 'Number format', 'wpo_wcpdf_pro' ),
				'callback' => 'multiple_text_input',
				'section'  => 'packing_slip',
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
								strtolower( __( 'Packing Slip', 'wpo_wcpdf_pro' ) ), '<strong>[packing_slip_year]</strong>', '<strong>[packing_slip_month]</strong>'
							) . ' ' . __( 'Check the Docs article below to see all the available placeholders for prefix/suffix.', 'wpo_wcpdf_pro' ),
						),
						'suffix'  => array(
							'label'       => __( 'Suffix' , 'wpo_wcpdf_pro' ),
							'size'        => 20,
							'description' => __( 'If set, this value will be used as number suffix.' , 'wpo_wcpdf_pro' ) . ' ' . sprintf(
								/* translators: 1. document type, 2-3 placeholders */
								__( 'You can use the %1$s year and/or month with the %2$s or %3$s placeholders respectively.', 'wpo_wcpdf_pro' ),
								strtolower( __( 'Packing Slip', 'wpo_wcpdf_pro' ) ), '<strong>[packing_slip_year]</strong>', '<strong>[packing_slip_month]</strong>'
							) . ' ' . __( 'Check the Docs article below to see all the available placeholders for prefix/suffix.', 'wpo_wcpdf_pro' ),
						),
						'padding' => array(
							'label'       => __( 'Padding' , 'wpo_wcpdf_pro' ),
							'size'        => 20,
							'type'        => 'number',
							/* translators: document type */
							'description' => sprintf( __( 'Enter the number of digits you want to use as padding. For instance, enter <code>6</code> to display the %s number <code>123</code> as <code>000123</code>, filling it with zeros until the number set as padding is reached.' , 'wpo_wcpdf_pro' ), strtolower( __( 'Packing Slip', 'wpo_wcpdf_pro' ) ) ),
						),
					),
					/* translators: document type */
					'description' => __( 'For more information about setting up the number format and see the available placeholders for the prefix and suffix, check this article:', 'wpo_wcpdf_pro' ) . sprintf( ' <a href="https://docs.wpovernight.com/woocommerce-pdf-invoices-packing-slips/number-format-explained/" target="_blank">%s</a>', __( 'Number format explained', 'wpo_wcpdf_pro') ) . '.<br><br>'. sprintf( __( '<strong>Note</strong>: Changes made to the number format will only be reflected on new orders. Also, if you have already created a custom %s number format with a filter, the above settings will be ignored.', 'wpo_wcpdf_pro' ), strtolower( __( 'Packing Slip', 'wpo_wcpdf_pro' ) ) ),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'my_account_buttons',
				'title'    => __( 'Allow My Account download', 'wpo_wcpdf_pro' ),
				'callback' => 'select',
				'section'  => 'packing_slip',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'my_account_buttons',
					'options'     => array(
						'never'     => __( 'Never' , 'wpo_wcpdf_pro' ),
						'available' => __( 'Only when a packing slip is already created/emailed' , 'wpo_wcpdf_pro' ),
						'custom'    => __( 'Only for specific order statuses (define below)' , 'wpo_wcpdf_pro' ),
						'always'    => __( 'Always' , 'wpo_wcpdf_pro' ),
					),
					'custom'      => array(
						'type' => 'multiple_checkboxes',
						'args' => array(
							'option_name'     => $option_name,
							'id'              => 'my_account_restrict',
							'fields_callback' => array( $packing_slip, 'get_wc_order_status_list' ),
						),
					),
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'reset_number_yearly',
				'title'    => __( 'Reset packing slip number yearly', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'packing_slip',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'reset_number_yearly',
				)
			),
		);
		$settings_fields = $this->move_setting_after_id( $settings_fields, $number_setting, 'display_customer_notes' );


		// insert refunded qty setting
		$subtract_refunded_qty = array(
			array(
				'type'     => 'setting',
				'id'       => 'subtract_refunded_qty',
				'title'    => __( 'Subtract refunded item quantities from packing slip', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'packing_slip',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'subtract_refunded_qty',
				)
			),
		);
		$settings_fields = $this->move_setting_after_id( $settings_fields, $subtract_refunded_qty, 'display_customer_notes' );

		$hide_virtual_downloadable_products = array(
			array(
				'type'     => 'setting',
				'id'       => 'hide_virtual_downloadable_products',
				'title'    => __( 'Hide products', 'wpo_wcpdf_pro' ),
				'callback' => 'select',
				'section'  => 'packing_slip',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'hide_virtual_downloadable_products',
					'options'     => apply_filters( 'wpo_wcpdf_hide_products_options', array(
						''                         => __( 'No', 'wpo_wcpdf_pro' ),
						'virtual'                  => __( 'Only Virtual', 'wpo_wcpdf_pro' ),
						'downloadable'             => __( 'Only Downloadable', 'wpo_wcpdf_pro' ),
						'virtual_or_downloadable'  => __( 'Virtual or Downloadable', 'wpo_wcpdf_pro' ),
						'virtual_and_downloadable' => __( 'Virtual and Downloadable', 'wpo_wcpdf_pro' ),
					), 'packing-slip' ),
				),
			),
		);
		$settings_fields = $this->move_setting_after_id( $settings_fields, $hide_virtual_downloadable_products, 'display_customer_notes' );

		return $settings_fields;
	}

	public function settings_fields_general_i18n( $settings_fields ) {
		$i18n_wrap = array( 'header_logo', 'shop_name', 'shop_address', 'footer', 'extra_1', 'extra_2', 'extra_3' );
		foreach ( $settings_fields as $key => $settings_field ) {
			if ( $settings_field['type'] == 'setting' && in_array( $settings_field['id'], $i18n_wrap ) ) {
				$settings_field['args']['callback'] = $settings_field['callback'];
				$settings_field['callback']         = 'i18n_wrap';
				$settings_fields[$key]              = $settings_field;
			}
		}

		return $settings_fields;
	}

	public function move_setting_after_id( $settings, $insert_settings, $after_setting_id ) {
		$pos = 1; // this is already +1 to insert after the actual pos
		foreach ( $settings as $setting ) {
			if ( isset( $setting['id'] ) && $setting['id'] == $after_setting_id ) {
				$section = $setting['section'];
				break;
			} else {
				$pos++;
			}
		}

		// replace section
		if ( isset( $section ) ) {
			foreach ( $insert_settings as $key => $insert_setting ) {
				$insert_settings[$key]['section'] = $section;
			}
		} else {
			$empty_section = array(
				array(
					'type'     => 'section',
					'id'       => 'custom',
					'title'    => '',
					'callback' => 'section',
				),
			);
			$insert_settings = array_merge( $empty_section,$insert_settings );
		}
		// insert our api settings
		$new_settings = array_merge( array_slice( $settings, 0, $pos, true ), $insert_settings, array_slice( $settings, $pos, NULL, true ) );

		return $new_settings;
	}

	public function get_translations () {
		check_ajax_referer( 'wcpdf_i18n_translations', 'security' );
		if ( empty( $_POST ) ) {
			die();
		}
		extract( $_POST );
		
		$languages    = function_exists( 'wpo_wcpdf_get_multilingual_languages' ) ? wpo_wcpdf_get_multilingual_languages() : array();
		$input_type   = strtolower( $input_type );
		$translations = get_option( 'wpo_wcpdf_translations' );

		printf( '<div id="%s-translations" class="translations">', $input_attributes['id'] )
		?>
			<ul>
				<?php foreach ( $languages as $lang => $data ) {
					$translation_id = $data['language_code'].'_'.$input_attributes['id'];
					printf('<li><a href="#%s">%s</a></li>', $translation_id, $data['native_name']);
				}
				?>
			</ul>
			<?php foreach ( $languages as $lang => $data ) {
				$translation_id = $data['language_code'].'_'.$input_attributes['id'];
				$value          = isset( $translations[$input_attributes['name']][$data['language_code']] ) ? $translations[$input_attributes['name']][$data['language_code']] : '';
				printf( '<div id="%s">', $translation_id );
				switch ( $input_type ) {
					case 'textarea':
						printf( '<textarea cols="%1$s" rows="%2$s" data-language="%3$s">%4$s</textarea>', $input_attributes['cols'], $input_attributes['rows'], $data['language_code'], $value);
						break;
					case 'input':
						printf( '<input type="text" size="%1$s" value="%2$s" data-language="%3$s"/>', $input_attributes['size'], $value, $data['language_code'] );
						break;
				}
				$spinner = '<div class="spinner"></div>';
				printf( '<div><button class="wpo-wcpdf-i18n-translations-save button button-primary">%s</button>%s</div>', __( 'Save translations', 'wpo_wcpdf_pro' ), $spinner );
				echo '</div>';
			}
			?>
		
		</div>
		<?php

		die();
	}
	public function save_translations () {
		check_ajax_referer( 'wcpdf_i18n_translations', 'security' );
		if (empty($_POST)) {
			die();
		}
		extract($_POST);

		$translations = get_option( 'wpo_wcpdf_translations' );
		$translations[$setting] = $strings;
		update_option( 'wpo_wcpdf_translations', $translations );

		die();
	}

	/**
	 * Scripts & styles for settings page
	 */
	public function load_scripts_styles ( $hook ) {
		// only load on our own settings page
		// maybe find a way to refer directly to WPO\WC\PDF_Invoices\Settings::$options_page_hook ?
		if ( ! ( $hook == 'woocommerce_page_wpo_wcpdf_options_page' || $hook == 'settings_page_wpo_wcpdf_options_page' || ( isset( $_GET['page'] ) && $_GET['page'] == 'wpo_wcpdf_options_page' ) ) ) {
			return;				
		} 

		wp_enqueue_script(
			'wcpdf-file-upload-js',
			WPO_WCPDF_Pro()->plugin_url() . '/assets/js/file-upload.js',
			array(),
			WPO_WCPDF_PRO_VERSION
		);

		wp_enqueue_style(
			'wcpdf-pro-settings-styles',
			WPO_WCPDF_Pro()->plugin_url() . '/assets/css/settings-styles.css',
			array(),
			WPO_WCPDF_PRO_VERSION
		);

		wp_enqueue_script(
			'wcpdf-pro-settings-js',
			WPO_WCPDF_Pro()->plugin_url() . '/assets/js/pro-settings.js',
			array(),
			WPO_WCPDF_PRO_VERSION
		);
		wp_localize_script(
			'wcpdf-pro-settings-js',
			'wpo_wcpdf_pro_settings',
			array(
				'ajaxurl'                   => admin_url( 'admin-ajax.php' ), // URL to WordPress ajax handling page
				'nonce'                     => wp_create_nonce( 'wpo_wcpdf_pro_settings' ),
				'unique_identifier_warning' => __( 'Warning! Your filename does not contain a unique identifier ({{order_number}}, {{document_number}}), this can lead to attachment mixups!', 'wpo_wcpdf_pro' ),
			)
		);

		if ( class_exists( '\\SitePress' ) || class_exists( '\\Polylang' ) || class_exists( '\\TRP_Translate_Press' ) ) {
			wp_enqueue_style(
				'wcpdf-i18n',
				WPO_WCPDF_Pro()->plugin_url() . '/assets/css/wcpdf-i18n.css',
				array(),
				WPO_WCPDF_PRO_VERSION
			);
			wp_enqueue_script(
				'wcpdf-i18n-settings',
				WPO_WCPDF_Pro()->plugin_url() . '/assets/js/wcpdf-i18n-settings.js',
				array( 'jquery', 'jquery-ui-tabs' ),
				WPO_WCPDF_PRO_VERSION
			);
			wp_localize_script(
				'wcpdf-i18n-settings',
				'wpo_wcpdf_i18n',
				array(  
					'ajaxurl'        => admin_url( 'admin-ajax.php' ), // URL to WordPress ajax handling page
					'nonce'          => wp_create_nonce('wcpdf_i18n_translations'),
					'translate_text' => __( 'Translate', 'wpo_wcpdf_pro' ),
					// 'icon'		=> WPO_WCPDF_Pro()->plugins_url() . '/images/some-img.png',
				)
			);
		}

		wp_enqueue_media();

		if ( isset( $_REQUEST['section'] ) && 'rest_api' === $_REQUEST['section'] ) {
			wp_enqueue_style( 'wpcpdf-prism-styles', WPO_WCPDF_Pro()->plugin_url() . '/assets/css/prism.css', array(), WPO_WCPDF_PRO_VERSION );
			wp_enqueue_script( 'wpcpdf-prism-scripts', WPO_WCPDF_Pro()->plugin_url() . '/assets/js/prism.js', array(), WPO_WCPDF_PRO_VERSION, true );
		}
	}

	/**
	 * Warning for missing pro templates
	 */
	public function pro_template_check() {
		if ( ! isset( $_GET['page'] ) || 'wpo_wcpdf_options_page' !== $_GET['page'] ) {
			return;
		}

		$template_path       = WPO_WCPDF()->settings->get_template_path();
		$template_path_array = explode( '/', $template_path );
		$template_name       = end( $template_path_array );
		$enabled_documents   = WPO_WCPDF()->documents->get_documents( 'enabled' );
		$missing_templates   = array();

		foreach ( $enabled_documents as $enabled_document ) {
			$located_template = $enabled_document->locate_template_file( $enabled_document->get_type() . '.php' );

			if (
				( 'Simple' === $template_name || false === strpos( $located_template, '/Simple/' ) ) &&
				file_exists( $located_template )
			) {
				continue;
			}

			$missing_templates[] = $enabled_document->get_title();
		}

		if ( ! empty ( $missing_templates ) ) {
			echo '<div class="notice notice-warning wpo-wcpdf-missing-template-notice"><p>',
			__( "<b>Warning!</b> Your PDF Invoices & Packing Slips for WooCommerce template folder does not contain templates for:", 'wpo_wcpdf_pro' ),
			' <strong>', implode( '</strong>, <strong>', $missing_templates ), '</strong>.';

			if ( class_exists( 'WPO_WCPDF_Templates' ) && strpos( $template_path, 'woocommerce-pdf-ips-templates' ) ) {
				$pro_template_folder = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( WPO_WCPDF_Pro()->plugin_path() ) . '/templates/Simple' );

				printf(
					/* translators: Premium Templates extension folder */
					'<br><br>' . __( 'If you are not using the latest version of the Premium Templates extension, please update it. Otherwise, copy the template files from the newest version in %s and adapt them to your template.', 'wpo_wcpdf_pro' ) . '<br>',
					'<code>' . $pro_template_folder . '</code>'
				);
			} else {
				printf(
					/* translators: 1: Opening anchor tag, 2: Closing anchor tag */
					'<br><br>' . __( 'If you are using a custom template, please refer to the %1$sCreating a custom PDF template%2$s article to learn how to update your template.', 'wpo_wcpdf_pro' ),
					'<a href="https://docs.wpovernight.com/woocommerce-pdf-invoices-packing-slips/creating-a-custom-pdf-template/">',
					'</a>'
				);
			}

			echo '</p></div>';
		}
	}

	/**
	 * add Pro settings tab to the PDF Invoice settings page
	 * @param  array $tabs slug => Title
	 * @return array $tabs with Pro
	 */
	public function settings_tab( $tabs ) {
		$tabs['pro'] = array(
			'title'          => __('Pro','wpo_wcpdf_pro'),
			'preview_states' => 2,
		);
		return $tabs;
	}

	/**
	 * Requirements callback.
	 */
	public function requirements_callback( $args ) {

		$document_settings = get_option( 'wpo_wcpdf_documents_settings_'.$args['document_type'] );
		$current_set_requirements = 0;

		printf( '<select id="%s">', $args['id'] );
		printf( '<option selected="selected" disabled="disabled" value="">%s</option>', __( 'Select additional requirements' , 'wpo_wcpdf_pro' ) );

		foreach ( $args['requirements'] as $requirement ) {
			$disabled = '';
			if ( $requirement['args']['id'] !== 'disable_for_require' ) {			
				if ( isset( $document_settings[$requirement['args']['id']] ) ) {
					$disabled = 'disabled="disabled"';
					$current_set_requirements++;
				}
				printf( '<option value="%s" %s>%s</option>', $requirement['args']['id'], $disabled, $requirement['title'] );
			}
		}
		echo '</select>';

		?>
		<div class="requirements" <?php echo $current_set_requirements === 0 ? 'style="display: none"' : ''; ?>>
		<?php 

		foreach ( $args['requirements'] as $requirement ) {
			$hidden = !isset( $document_settings[$requirement['args']['id']] ) || ( $requirement['args']['id'] == 'disable_for_require' && $current_set_requirements < 2 ) ? 'style="display: none"' : '';

			printf( '<div class="requirement" data-requirement_id="%s" %s><p>%s</p>', $requirement['args']['id'], $hidden, $requirement['title'] );
			if ( isset( $requirement['callback'] ) ) {
				$callback = $requirement['callback'];
				WPO_WCPDF()->settings->callbacks->$callback( $requirement['args'] );
			}
			echo $requirement['args']['id'] !== 'disable_for_require' ? '<span class="dashicons dashicons-trash remove-requirement"></span></div>' : '</div>';
		}
		echo '</div>';
	}

	public function get_payment_gateways() {
		$payment_gateways = array();
		foreach ( WC()->payment_gateways->payment_gateways() as $gateway ) {
			$payment_gateways[$gateway->id] = $gateway->get_title();
		}

		$payment_gateways['n/a'] = __( 'Not available (N/A)', 'wpo_wcpdf_pro' );

		return $payment_gateways;
	}

	public function disable_document_for( $allowed, $document ) {
		if ( ! $document->exists() && ! empty( $document->order ) ) {
			// Reset the status requirement from the free plugin
			if ( $document->is_enabled() && $allowed === false ) {
				$allowed = true;
			}

			// Get latest document settings
			$document_settings = $document->get_settings( true );

			$requirements = array(
				'disable_for_statuses'          => 'get_status',
				'disable_for_payment_methods'   => 'get_payment_method',
				'disable_for_billing_countries' => 'get_billing_country',
			);

			if ( ! empty( $set_requirements = array_intersect_key( $requirements, $document_settings ) ) ) {
				$require_all = isset( $document_settings['disable_for_require'] ) && $document_settings['disable_for_require'] == 'all' ? true : false;

				foreach ( $set_requirements as $requirement => $getter ) {
					$requirement_settings = $document_settings[ $requirement ];

					if ( 'disable_for_statuses' === $requirement ) {
						$requirement_settings = array_map( function( $status ) {
							return ( 'wc-' === substr( $status, 0, 3 ) ) ? substr( $status, 3 ) : $status;
						}, $document_settings[ $requirement ] );
					}
					
					$order = $document->order;
			
					if ( $order instanceof \WC_Order_Refund ) { // credit note
						$order = wc_get_order( $order->get_parent_id() );
					}

					if ( is_callable( array( $order, $getter ) ) ) {
						$value = $order->$getter();

						// 'N/A' order payment method results in an empty string if we call $order->get_payment_method()
						// and therefore, we need to set the $value to 'n/a' in that case.
						if ( 'disable_for_payment_methods' === $requirement && '' === $value ) {
							$value = 'n/a';
						}

						if ( $require_all ) {
							if ( ! in_array( $value, $requirement_settings ) ) {
								return $allowed;
							}
						} elseif ( in_array( $value, $requirement_settings ) ) {
							return false;
						}
					}
				}
				if ( $require_all ) {
					// If we got here requirements were set and were all met
					return false;
				}
			}
		} 
		return $allowed;
	}
	
	public function preview_reload_pro_settings() {
		$this->settings = WPO_WCPDF_Pro()->functions->pro_settings = get_option( $this->option );
	}

	public function preview_excluded_pro_settings( $excluded_settings ) {
		$excluded_pro_settings = array(
			'disable_for_require',
			'disable_for_payment_methods',
			'disable_for_billing_countries',
			'due_date_allowed_statuses',
			'due_date_allowed_payment_methods',
		);

		foreach ( $excluded_pro_settings as $excluded_pro_setting ) {
			if ( ! in_array( $excluded_pro_setting, $excluded_settings ) ) {
				$excluded_settings[] = $excluded_pro_setting;
			}
		}

		return $excluded_settings;
	}

	/**
	 * File upload callback.
	 *
	 * @param array $args Field arguments.
	 * 
	 * @return void.
	 */
	public function file_upload_callback( array $args ): void {
		extract( WPO_WCPDF()->settings->callbacks->normalize_settings_args( $args ) );
		
		$options         = get_option( $menu );
		$is_multilingual = class_exists( '\\Polylang' ) || class_exists( '\\TRP_Translate_Press' ); // WPML has a media translation feature
	
		// multilingual file
		if ( $is_multilingual && isset( $lang ) && isset( $options[ $id ][ $lang ] ) ) {
			$current = $options[ $id ][ $lang ];
			
		// non-multilingual file
		} elseif ( ! $is_multilingual && isset( $options[ $id ] ) ) {
			$current = $options[ $id ];
			
		// no file set
		} else {
			$current = array(
				'id'       => '',
				'filename' => '',
			);
		}

		printf( '<input id="%1$s_id" name="%2$s[%1$s][id]" value="%3$s" type="hidden"/>', $id, $menu, $current['id'] );
		printf( '<input id="%1$s_filename" name="%2$s[%1$s][filename]" size="50" value="%3$s" readonly="readonly"/>', $id, $menu, $current['filename'] );
		if ( ! empty( $current['id'] ) ) {
			printf( '<span class="button remove_file_button" data-input_id="%1$s">%2$s</span>', $id, $remove_button_text );
		}
		printf( '<span class="button upload_file_button %4$s" data-uploader_title="%1$s" data-uploader_button_text="%2$s" data-remove_button_text="%3$s" data-input_id="%4$s">%2$s</span>', $uploader_title, $uploader_button_text, $remove_button_text, $id );

		// Displays option description.
		if ( isset( $description ) ) {
			printf( '<p class="description">%s</p>', $description );
		}
	}

	/**
	 * Multiple file upload callback.
	 *
	 * @param array $args Field arguments.
	 * 
	 * @return void.
	 */
	public function multiple_file_upload_callback( array $args ): void {
		extract( WPO_WCPDF()->settings->callbacks->normalize_settings_args( $args ) );
		
		$options         = get_option( $option_name );
		$is_multilingual = class_exists( '\\Polylang' ) || class_exists( '\\TRP_Translate_Press' ); // WPML has a media translation feature
	
		// multilingual file
		if ( $is_multilingual && isset( $lang ) && isset( $options[ $id ][ $lang ] ) ) {
			$current = $options[ $id ][ $lang ];
			
		// non-multilingual file
		} elseif ( ! $is_multilingual && isset( $options[ $id ] ) ) {
			$current = $options[ $id ];
			
		// no file set
		} else {
			$current = array(
				'id'       => '',
				'filename' => '',
			);
		}
		
		$total_files_number = $total_files_number ?? 3;

		for ( $i = 0; $i < $total_files_number; $i++ ) {
			$file_id  = isset( $current[ $i ] ) ? $current[ $i ]['id'] : '';
			$filename = isset( $current[ $i ] ) ? $current[ $i ]['filename'] : '';

			echo '<div class="static-file-row">';
			printf( '<input id="%1$s_%2$s_id" name="%3$s[%2$s][id]" value="%4$s" type="hidden" class="static-file-id"/>', $id, $i, $setting_name, $file_id );
			printf( '<input id="%1$s_%2$s_filename" name="%3$s[%2$s][filename]" size="50" value="%4$s" type="text" readonly="readonly" class="static-file-filename"/>', $id, $i, $setting_name, $filename );
			if ( ! empty( $file_id ) ) {
				printf( '<span class="button remove_file_button" data-input_id="%1$s_%2$s">%3$s</span>', $id, $i, $remove_button_text );
			}
			printf( '<span class="button upload_file_button %4$s" data-uploader_title="%1$s" data-uploader_button_text="%2$s" data-remove_button_text="%3$s" data-input_id="%4$s_%5$s">%2$s</span>', $uploader_title, $uploader_button_text, $remove_button_text, $id, $i );
			echo '</div>';
		}
	
		// Displays option description.
		if ( isset( $description ) ) {
			printf( '<p class="description">%s</p>', $description );
		}
	}

	/**
	 * Address customization callback.
	 *
	 * @return void.
	 */
	public function custom_address_fields_section_callback() {
		echo __( 'Here you can modify the way the shipping and billing address are formatted in the PDF documents as well as add custom fields to them.', 'wpo_wcpdf_pro').'<br/>';
		echo __( 'You can use the following placeholders in addition to regular text and html tags (like h1, h2, b):', 'wpo_wcpdf_pro').'<br/>';
		?>
		<table style="background-color:#eee;border:1px solid #aaa; margin:1em; padding:1em;">
			<tr>
				<th style="text-align:left; padding:5px 5px 0 5px;"><?php _e( 'Billing fields', 'wpo_wcpdf_pro' ); ?></th>
				<th style="text-align:left; padding:5px 5px 0 5px;"><?php _e( 'Shipping fields', 'wpo_wcpdf_pro' ); ?></th>
				<th style="text-align:left; padding:5px 5px 0 5px;"><?php _e( 'Custom fields', 'wpo_wcpdf_pro' ); ?></th>
			</tr>
			<tr>
				<td style="vertical-align:top; padding:5px;">
					{{billing_address}}<br/>
					{{billing_first_name}}<br/>
					{{billing_last_name}}<br/>
					{{billing_company}}<br/>
					{{billing_address_1}}<br/>
					{{billing_address_2}}<br/>
					{{billing_city}}<br/>
					{{billing_postcode}}<br/>
					{{billing_country}}<br/>
					{{billing_country_code}}<br/>
					{{billing_state}}<br/>
					{{billing_state_code}}<br/>
					{{billing_email}}<br/>
					{{billing_phone}}
				</td>
				<td style="vertical-align:top; padding:5px;">
					{{shipping_address}}<br/>
					{{shipping_first_name}}<br/>
					{{shipping_last_name}}<br/>
					{{shipping_company}}<br/>
					{{shipping_address_1}}<br/>
					{{shipping_address_2}}<br/>
					{{shipping_city}}<br/>
					{{shipping_postcode}}<br/>
					{{shipping_country}}<br/>
					{{shipping_country_code}}<br/>
					{{shipping_state}}<br/>
					{{shipping_state_code}}
				</td>
				<td style="vertical-align:top; padding:5px;">
					{{custom_fieldname}}
				</td>
			</tr>
		</table>
		<?php
		echo __( 'Leave empty to use the default formatting.', 'wpo_wcpdf_pro').'<br/>';
	}
	
	/**
	 * Multilingual supported plugins.
	 *
	 * @return void.
	 */
	public function multilingual_supported_plugins_callback() {
		echo '<p>'.__( 'Here you can select the source for the document language, which is used to translate the PDF.', 'wpo_wcpdf_pro' ).'</p>';
		echo '<p>'.__( 'We supported several multilingual plugins, two in full and the others with more limited functionality. The language source may differ, so please check the table below for the recommended option for the plugin you choose.', 'wpo_wcpdf_pro' ).'</p>';
		$active_multilingual_plugins = WPO_WCPDF_Pro()->functions->get_active_multilingual_plugins();
		?>
		<table class="widefat">
			<thead>
				<tr>
					<th>&nbsp;</th>
					<th><?php _e( 'Plugin', 'wpo_wcpdf_pro' ); ?></th>
					<th><?php _e( 'Support type', 'wpo_wcpdf_pro' ); ?></th>
					<th><?php _e( 'Language source', 'wpo_wcpdf_pro' ); ?></th>
					<th><?php _e( 'Additional', 'wpo_wcpdf_pro' ); ?></th>
					<th><?php _e( 'Activated', 'wpo_wcpdf_pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
					foreach ( WPO_WCPDF_Pro()->functions->multilingual_supported_plugins() as $slug => $plugin ) {
						switch ( $plugin['support'] ) {
							case 'full':
								?>
								<tr>
									<td><img src="<?php echo esc_url_raw( $plugin['logo'] ); ?>" alt="<?php echo esc_attr( $plugin['name'] ); ?>" style="height:32px;"></td>
									<td><strong><?php echo esc_attr( $plugin['name'] ); ?></strong></td>
									<td><?php _e( 'Full', 'wpo_wcpdf_pro' ); ?></td>
									<td><?php _e( 'Order/customer language', 'wpo_wcpdf_pro' ); ?></td>
									<td>
										<?php
											if ( 'translatepress' !== $slug ) {
												printf(
													/* translators: multilingual plugin name */
													__( '%s WooCommerce addon (recommended)', 'wpo_wcpdf_pro' ),
													esc_attr( $plugin['name'] )
												);
											}
										?>
									</td>
									<td><?php echo isset( $active_multilingual_plugins[$slug] ) ? '<strong>'.__( 'Yes', 'wpo_wcpdf_pro' ).'</strong>' : __( 'No', 'wpo_wcpdf_pro' ); ?></td>
								</tr>
								<?php
								break;
							case 'html':
								?>
								<tr>
									<td><img src="<?php echo esc_url_raw( $plugin['logo'] ); ?>" alt="<?php echo esc_attr( $plugin['name'] ); ?>" style="height:32px;"></td>
									<td><strong><?php echo esc_attr( $plugin['name'] ); ?></strong></td>
									<td><?php _e( 'Limited', 'wpo_wcpdf_pro' ); ?></td>
									<td>
										<?php
											printf(
												/* translators: multilingual plugin name */
												__( '%s order language', 'wpo_wcpdf_pro' ),
												esc_attr( $plugin['name'] )
											);
										?>
									</td>
									<td>
										<?php
											if ( 'gtranslate' === $slug ) {
												_e( 'Requires GTranslate Pro subscription + Email Translation setting enabled', 'wpo_wcpdf_pro' );
											} else {
												echo '&nbsp';
											}
										?>
									</td>
									<td><?php echo isset( $active_multilingual_plugins[$slug] ) ? '<strong>'.__( 'Yes', 'wpo_wcpdf_pro' ).'</strong>' : __( 'No', 'wpo_wcpdf_pro' ); ?></td>
								</tr>
								<?php
								break;
						}
					}
				?>
				<tr>
					<td>&nbsp;</td>
					<td><strong><?php _e( 'Other/No multilingual plugin', 'wpo_wcpdf_pro' ); ?></strong></td>
					<td><?php _e( 'Limited', 'wpo_wcpdf_pro' ); ?></td>
					<td><?php _e( 'Active user language', 'wpo_wcpdf_pro' ); ?></td>
					<td>&nbsp;</td>
					<td>&nbsp;</td>
				</tr>
			</tbody>
		</table>
		<p>
			<?php
				printf(
					/* translators: documentation link */
					__( 'To understand this setting better, please read this documentation page: %s', 'wpo_wcpdf_pro' ),
					'<a href="https://docs.wpovernight.com/woocommerce-pdf-invoices-packing-slips/how-is-the-pdf-language-determined/" target="_blank">'.__( 'How is the PDF language determined?', 'wpo_wcpdf_pro' ).'</a>'
				);
			?>
		</p>
		<?php
	}

	public function filename_existence_check() {
		check_ajax_referer( 'wpo_wcpdf_pro_settings', 'nonce' );

		if( empty( $_POST['filename'] ) || empty( $_POST['document_type'] || empty( $_POST['language'] ) ) ) {
			die();
		}

		$filename      = sanitize_text_field( $_POST['filename'] );
		$document_type = sanitize_text_field( $_POST['document_type'] );
		$language      = sanitize_text_field( $_POST['language'] );
		$documents     = WPO_WCPDF()->documents->get_documents();

		foreach( $documents as $document ) {
			if( $document->type != $document_type ) {
				$custom_filename = $document->get_settings_text( 'filename', false, false );
				if ( ! empty( WPO_WCPDF_Pro()->multilingual_full ) && $language != 'default' ) {
					$custom_filename = Language_Switcher::get_i18n_setting( 'filename', $custom_filename, $document, $language );
				}

				if ( $custom_filename == $filename ) {
					/* translators: document title */
					wp_send_json( array( 'error' => sprintf( __( 'This filename is already in use in the %s document! Please choose a new unique filename.', 'wpo_wcpdf_pro' ), $document->title ) ) );
				}
			}
		}

		wp_send_json( array( 'success' => __( 'Filename is unique!', 'wpo_wcpdf_pro' ) ) );

		die();
	}

	public function non_historical_pro_settings( $settings ) {
		$settings[] = 'auto_generate_for_statuses';
		return $settings;
	}
	
	public function pro_setting_types( $setting_types ) {
		$setting_types['pro'] = __( 'Pro', 'wpo_wcpdf_pro' );
		return $setting_types;
	}
	
	public function pro_settings_export( $settings, $type ) {
		if ( $type == 'pro' ) {
			$settings = $this->settings;
		}
		return $settings;
	}
	
	public function pro_settings_option( $settings_option, $type ) {
		if ( 'pro' === $type ) {
			$settings_option = $this->option;
		}
		return $settings_option;
	}
	
	public function pro_settings_option_import( $settings_option, $type, $new_settings ) {
		return $this->pro_settings_option( $settings_option, $type );
	}
	
	public function pro_settings_option_reset( $settings_option, $type ) {
		return $this->pro_settings_option( $settings_option, $type );
	}

	public function add_rest_api_tab( array $sections ): array {
		$sections['rest_api'] = __( 'REST API', 'wpo_wcpdf_pro' );
		return $sections;
	}

	/**
	 * Displays the documentation of the REST API.
	 *
	 * @param string $active_section
	 *
	 * @return void
	 */
	public function add_rest_api_tab_content( string $active_section ): void {
		if ( 'rest_api' !== $active_section ) {
			return;
		}

		include WPO_WCPDF_Pro()->plugin_path() . '/includes/views/rest-api.php';
	}
	
	/**
	 * Get the available languages for multilingual documents.
	 *
	 * @return array
	 */
	private function get_multilingual_document_language_options(): array {
		// Retrieve available languages
		$languages = function_exists( 'wpo_wcpdf_get_multilingual_languages' ) ? wpo_wcpdf_get_multilingual_languages() : array();
		if ( empty( $languages ) ) {
			$available_locales = WPO_WCPDF_Pro()->functions->get_wp_available_languages();
			foreach ( $available_locales as $locale ) {
				$wp_languages_list  = WPO_WCPDF_Pro()->functions->get_wp_language_list();
				if ( ! empty( $wp_languages_list[ $locale ] ) && ! empty( $wp_languages_list[ $locale ]['native_name'] ) ) {
					$languages[ $locale ] = $wp_languages_list[ $locale ]['native_name'];
				}
			}
		}
	
		// Retrieve user language settings
		$user_language = array();
		if ( ! class_exists( '\\SitePress' ) && ! class_exists( '\\Polylang' ) && ! class_exists( '\\TRP_Translate_Press' ) ) {
			$user_language = array(
				'user' => __( 'Active user language', 'wpo_wcpdf_pro' ),
			);
		}
	
		// Retrieve multilingual plugins with HTML support
		$multilingual_html_plugins = array();
		foreach ( WPO_WCPDF_Pro()->functions->get_active_multilingual_plugins() as $slug => $plugin ) {
			if ( 'html' === $plugin['support'] ) {
				$multilingual_html_plugins[ $slug ] = sprintf(
					/* translators: multilingual plugin name */
					__( '%s order language', 'wpo_wcpdf_pro' ),
					$plugin['name']
				);
			}
		}
	
		// Merge all options into a single array
		return array( '' => __( 'Select...', 'wpo_wcpdf_pro' ) ) 
			+ $multilingual_html_plugins 
			+ $user_language 
			+ array(
				'order' => __( 'Order/customer language', 'wpo_wcpdf_pro' ),
				'admin' => __( 'Site default language', 'wpo_wcpdf_pro' ),
			) 
			+ $languages;
	}

} // end class

endif; // class_exists

return new Settings();
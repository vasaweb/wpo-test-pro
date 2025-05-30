<?php
namespace WPO\WC\PDF_Invoices_Pro\Legacy;

defined( 'ABSPATH' ) or exit;

if ( ! class_exists( '\\WPO\\WC\\PDF_Invoices_Pro\\Legacy\\Legacy_Settings' ) ) :

class Legacy_Settings {
	
	public $pro_settings;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->load_legacy_settings();
	}

	public function load_legacy_settings() {
		$this->pro_settings = array();

		// map new settings to old
		$settings_map = array(
			'wpo_wcpdf_settings_pro' => array(
				'static_file'						=> array( 'wpo_wcpdf_pro_settings' => 'static_file' ),
				'static_file_attach_to_email_ids'	=> array( 'wpo_wcpdf_pro_settings' => 'pro_attach_static' ),
				'billing_address'					=> array( 'wpo_wcpdf_pro_settings' => 'billing_address' ),
				'shipping_address'					=> array( 'wpo_wcpdf_pro_settings' => 'shipping_address' ),
				'remove_whitespace'					=> array( 'wpo_wcpdf_pro_settings' => 'remove_whitespace' ),
				'placeholders_allow_line_breaks'	=> array( 'wpo_wcpdf_pro_settings' => 'placeholders_allow_line_breaks' ),
			),
			'wpo_wcpdf_documents_settings_packing-slip' => array(
				'attach_to_email_ids'				=> array( 'wpo_wcpdf_pro_settings' => 'pro_attach_packing-slip' ),
				'subtract_refunded_qty'				=> array( 'wpo_wcpdf_pro_settings' => 'subtract_refunded_qty' ),
				'hide_virtual_products'				=> array( 'wpo_wcpdf_pro_settings' => 'hide_virtual_products' ),
			),
			'wpo_wcpdf_documents_settings_credit-note' => array(
				'attach_to_email_ids'				=> array( 'wpo_wcpdf_pro_settings' => 'pro_attach_credit-note' ),
				'subtract_refunded_qty'				=> array( 'wpo_wcpdf_pro_settings' => 'subtract_refunded_qty' ),
				'display_shipping_address'			=> array( 'wpo_wcpdf_template_settings' => 'invoice_shipping_address' ),
				'display_email'						=> array( 'wpo_wcpdf_template_settings' => 'invoice_email' ),
				'display_phone'						=> array( 'wpo_wcpdf_template_settings' => 'invoice_phone' ),
				'display_date'						=> array( 'wpo_wcpdf_pro_settings' => 'credit_note_date' ),
				'original_invoice_number'			=> array( 'wpo_wcpdf_pro_settings' => 'credit_note_original_invoice_number' ),
				'number_sequence'					=> array( 'wpo_wcpdf_pro_settings' => 'credit_note_number' ),
				'number_format'						=> array( 'wpo_wcpdf_pro_settings' => 'credit_note_number_formatting' ),
				'positive_prices'					=> array( 'wpo_wcpdf_pro_settings' => 'positive_credit_note' ),
				'reset_number_yearly'				=> array( 'wpo_wcpdf_template_settings' => 'yearly_reset_invoice_number' ),
			),
			'wpo_wcpdf_documents_settings_proforma' => array(
				'enabled'							=> array( 'wpo_wcpdf_pro_settings' => 'enable_proforma' ),
				'attach_to_email_ids'				=> array( 'wpo_wcpdf_pro_settings' => 'pro_attach_proforma' ),
				'display_shipping_address'			=> array( 'wpo_wcpdf_template_settings' => 'invoice_shipping_address' ),
				'display_email'						=> array( 'wpo_wcpdf_template_settings' => 'invoice_email' ),
				'display_phone'						=> array( 'wpo_wcpdf_template_settings' => 'invoice_phone' ),
				'display_date'						=> array( 'wpo_wcpdf_pro_settings' => 'proforma_date' ),
				'number_sequence'					=> array( 'wpo_wcpdf_pro_settings' => 'proforma_number' ),
				'number_format'						=> array( 'wpo_wcpdf_pro_settings' => 'proforma_number_formatting' ),
				'reset_number_yearly'				=> array( 'wpo_wcpdf_template_settings' => 'yearly_reset_invoice_number' ),
			),
		);

		// walk through map
		foreach ($settings_map as $new_option => $new_settings_keys) {
			${$new_option} = get_option($new_option);
			foreach ($new_settings_keys as $new_key => $old_setting ) {
				$old_key = reset($old_setting);
				$old_option = key($old_setting);
				if ($old_option == 'wpo_wcpdf_pro_settings' && isset(${$new_option}[$new_key])) {
					$this->pro_settings[$old_key] = ${$new_option}[$new_key];
				}
			}
		}

	}
}

endif; // Class exists check

return new Legacy_Settings();
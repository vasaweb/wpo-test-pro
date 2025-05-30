<?php
namespace WPO\WC\PDF_Invoices_Pro\Legacy;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( '\\WPO\\WC\\PDF_Invoices_Pro\\Legacy\\Legacy_Functions' ) ) :

class Legacy_Functions {
	
	public $pro_settings;
	
	public function __construct() {
		$this->pro_settings = get_option( 'wpo_wcpdf_pro_settings' );
		// add_filter( 'wpo_wcpdf_filename', array( $this, 'build_filename' ), 5, 4 );
		// add_action( 'wpo_wcpdf_process_template_order', array( $this, 'set_numbers_dates' ), 10, 2 );
		// add_filter( 'wpo_wcpdf_proforma_number', array( $this, 'format_proforma_number' ), 20, 4 );
		// add_filter( 'wpo_wcpdf_credit_note_number', array( $this, 'format_credit_note_number' ), 20, 4 );
		add_filter( 'wpo_wcpdf_template_name', array( $this, 'pro_template_names' ), 5, 2 );

	}

	/**
	 * Redirect document function calls directly to document object
	 */
	public function __call( $name, $arguments ) {
		if ( is_object( \WPO\WC\PDF_Invoices\Legacy\WPO_WCPDF_Legacy()->export->document ) && is_callable( array( \WPO\WC\PDF_Invoices\Legacy\WPO_WCPDF_Legacy()->export->document, $name ) ) ) {
			return call_user_func_array( array( \WPO\WC\PDF_Invoices\Legacy\WPO_WCPDF_Legacy()->export->document, $name ), $arguments );
		} else {
			throw new \Exception("Call to undefined method ".__CLASS__."::{$name}()", 1);
		}
	}

	public function get_number( $document_type, $order_id = '' ) {
		if ( is_object( \WPO\WC\PDF_Invoices\Legacy\WPO_WCPDF_Legacy()->export->document ) ) {
			return \WPO\WC\PDF_Invoices\Legacy\WPO_WCPDF_Legacy()->export->document->get_formatted_number( $document_type );
		}
	}

	public function get_date( $document_type, $order_id = '' ) {
		if ( is_object( \WPO\WC\PDF_Invoices\Legacy\WPO_WCPDF_Legacy()->export->document ) ) {
			return \WPO\WC\PDF_Invoices\Legacy\WPO_WCPDF_Legacy()->export->document->get_formatted_date( $document_type );
		}
	}

	/**
	 * Filter to get template name for template type/slug
	 */
	public function pro_template_names ( $template_name, $template_type ) {
		switch ( $template_type ) {
			case 'proforma':
				$template_name = apply_filters( 'wpo_wcpdf_proforma_title', __( 'Proforma Invoice', 'wpo_wcpdf_pro' ) );
				break;
			case 'credit-note':
				$template_name = apply_filters( 'wpo_wcpdf_credit_note_title', __( 'Credit Note', 'wpo_wcpdf_pro' ) );
				break;
		}

		return $template_name;
	}

	/**
	 * 
	 */
	public function build_filename( $filename, $template_type, $order_ids, $context ) {
		if ( !in_array( $template_type, array( 'credit-note', 'proforma' ) ) ) {
			// we're not processing any of the pro documents
			return $filename;
		}

		global $wpo_wcpdf, $wpo_wcpdf_pro;

		$count = count( $order_ids );

		switch ($template_type) {	
			case 'proforma':
				$name = _n( 'proforma-invoice', 'proforma-invoices', $count, 'wpo_wcpdf_pro' );
				$number = $wpo_wcpdf_pro->get_number('proforma');
				break;		
			case 'credit-note':
				$name = _n( 'credit-note', 'credit-notes', $count, 'wpo_wcpdf_pro' );
				$number = $wpo_wcpdf_pro->get_number('credit-note');
				break;
		}

		if ( $count == 1 ) {
			$suffix = $number;			
		} else {
			$suffix = date('Y-m-d'); // 2020-11-11
		}

		return sanitize_file_name( $name . '-' . $suffix . '.pdf' );
	}

	/**
	 * Set number and date for pro documents
	 * @param  string $template_type
	 * @param  int    $order_id
	 * @return void
	 */
	public function set_numbers_dates( $template_type, $order_id ) {
		// check if we're processing one of the pro document types
		if ( ! in_array( $template_type, array( 'proforma', 'credit-note' ) ) ) {
			return;
		}

		$meta_updated = false;

		// name conversion for settings and meta compatibility (credit-note = credit_note)
		$template_type = str_replace('-', '_', $template_type);

		// get order
		$order = $this->get_order( $order_id );

		// get document date
		$date = $order->get_meta( '_wcpdf_'.$template_type.'_date' );
		if ( empty( $date ) ) {
			// first time this document is created for this order
			// set document date
			$date = current_time( 'mysql' );
			$order->update_meta_data( '_wcpdf_'.$template_type.'_date', $date );
			$meta_updated = true;
		}

		// get document number
		$number = $order->get_meta( '_wcpdf_'.$template_type.'_number' );
		if ( empty( $number ) ) {
			// numbering system switch
			$numbering_system = isset( $this->pro_settings[$template_type.'_number'] ) ? $this->pro_settings[$template_type.'_number'] : 'separate';
			switch ( $numbering_system ) {
				case 'main':
					// making direct DB call to avoid caching issues
					global $wpdb;
					$next_invoice_number = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", 'wpo_wcpdf_next_invoice_number' ) );
					$next_invoice_number = apply_filters( 'wpo_wcpdf_next_invoice_number', $next_invoice_number, $order_id );

					// set document number
					$document_number = isset( $next_invoice_number ) ? $next_invoice_number : 1;

					// increase wpo_wcpdf_next_invoice_number
					$update_args = array(
						'option_value'	=> $document_number + 1,
						'autoload'		=> 'yes',
					);
					$result = $wpdb->update( $wpdb->options, $update_args, array( 'option_name' => 'wpo_wcpdf_next_invoice_number' ) );
					break;
				default:
				case 'separate':
					// set document number
					$document_number = isset( $this->pro_settings['next_'.$template_type.'_number'] ) ? $this->pro_settings['next_'.$template_type.'_number'] : 1;

					// increment next document number setting
					$this->pro_settings = get_option( 'wpo_wcpdf_pro_settings' );
					$this->pro_settings['next_'.$template_type.'_number'] += 1;
					update_option( 'wpo_wcpdf_pro_settings', $this->pro_settings );
					break;
			}

			$order->update_meta_data( '_wcpdf_'.$template_type.'_number', $document_number );
			$order->update_meta_data( '_wcpdf_formatted_'.$template_type.'_number', $this->get_number( $template_type, $order_id ) );
			$meta_updated = true;
		}

		if ( $meta_updated ) {
			$order->save_meta_data();
		}
	}

} // end class

endif; // end class_exists

return new Legacy_Functions();
<?php
namespace WPO\WC\PDF_Invoices\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( '\\WPO\\WC\\PDF_Invoices\\Documents\\Pro_Document' ) ) :

/**
 * Pro Document abstract
 * 
 * @class  \WPO\WC\PDF_Invoices\Documents\Pro_Document
 */

abstract class Pro_Document extends Order_Document_Methods {

	public function use_historical_settings() {
		$document_settings = get_option( 'wpo_wcpdf_documents_settings_'.$this->get_type() );
		// this setting is inverted on the frontend so that it needs to be actively/purposely enabled to be used
		if (!empty($document_settings) && isset($document_settings['use_latest_settings'])) {
			$use_historical_settings = false;
		} else {
			$use_historical_settings = true;
		}
		return apply_filters( 'wpo_wcpdf_document_use_historical_settings', $use_historical_settings, $this );
	}

	public function storing_settings_enabled() {
		return apply_filters( 'wpo_wcpdf_document_store_settings', true, $this );
	}

	public function init() {
		// init settings
		$this->init_settings_data();
		$this->save_settings();

		if ( isset( $this->settings['display_date'] ) && 'order_date' === $this->settings['display_date'] && ! empty( $this->order ) ) {
			$this->set_date( $this->order->get_date_created() );
		} elseif ( empty( $this->get_date() ) ) {
			$this->set_date( current_time( 'timestamp', true ) );
		}

		$this->initiate_number();
		
		do_action( 'wpo_wcpdf_init_document', $this );
	}

	public function exists() {
		return ! empty( $this->data['number'] );
	}
	
	/**
	 * Legacy function < v3.8.0
	 * 
	 * Still being used by thrid party plugins.
	 *
	 * @return mixed
	 */
	public function init_number() {
		wcpdf_deprecated_function( 'init_number', '3.8.0', 'initiate_number' );
		return $this->initiate_number();
	}
	
	public function get_number_sequence( $number_store_name, $document ) {
		return isset( $document->settings['number_sequence'] ) ? $document->settings['number_sequence'] : "{$document->slug}_number";
	}

	public function get_formatted_number( $document_type ) {
		if ( $number = $this->get_number( $document_type ) ) {
			return $formatted_number = $number->get_formatted();
		} else {
			return '';
		}
	}

	public function number( $document_type ) {
		echo $this->get_formatted_number( $document_type );
	}

	public function get_formatted_date( $document_type ) {
		if ( $date = $this->get_date( $document_type ) ) {
			return $date->date_i18n( apply_filters( 'wpo_wcpdf_date_format', wc_date_format(), $this ) );
		} else {
			return '';
		}
	}

	public function date( $document_type ) {
		echo $this->get_formatted_date( $document_type );
	}


}

endif; // class_exists

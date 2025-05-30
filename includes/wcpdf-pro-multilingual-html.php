<?php
namespace WPO\WC\PDF_Invoices_Pro;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( '\\WPO\\WC\\PDF_Invoices_Pro\\Multilingual_Html' ) ) :

class Multilingual_Html {
	
	/**
	 * Selected plugin
	 *
	 * @var string
	 */
	public string $selected_plugin = '';

	public function __construct() {
		$pro_settings                   = get_option( 'wpo_wcpdf_settings_pro', array() );
		$multilingual_supported_plugins = WPO_WCPDF_Pro()->functions->multilingual_supported_plugins();
		
		if ( ! empty( $pro_settings['document_language'] ) ) {
			$document_language_option = sanitize_text_field( $pro_settings['document_language'] );
			
			if ( isset( $multilingual_supported_plugins[ $document_language_option ] ) && 'html' === $multilingual_supported_plugins[ $document_language_option ]['support'] ) {
				$this->selected_plugin = $document_language_option;
			}
			
			if ( ! empty( $this->selected_plugin ) && $this->is_plugin_still_active( $this->selected_plugin ) ) {
				$this->init();
			}
		}
	}
	
	/**
	 * Check if plugin is still active
	 *
	 * @param string $slug
	 * @return bool
	 */
	private function is_plugin_still_active( string $slug ): bool {
		$is_active = false;
		
		if ( empty( $slug ) ) {
			return $is_active;
		}
		
		$multilingual_supported_plugins = WPO_WCPDF_Pro()->functions->multilingual_supported_plugins();
		
		if ( ! empty( $multilingual_supported_plugins[ $slug ]['function'] ) && function_exists( $multilingual_supported_plugins[ $slug ]['function'] ) ) {
			$is_active = true;
		}
		
		if ( ! $is_active ) {
			$pro_settings = get_option( 'wpo_wcpdf_settings_pro', array() );
			
			if ( ! empty( $pro_settings['document_language'] ) && $slug === $pro_settings['document_language'] ) {
				unset( $pro_settings['document_language'] );
				update_option( 'wpo_wcpdf_settings_pro', $pro_settings );
				$this->selected_plugin = '';
			}
		}
		
		return $is_active;
	}
	
	/**
	 * Init function
	 *
	 * @return void
	 */
	public function init(): void {
		$this->proprietary_translation_tweaks();

		add_filter( 'wpo_wcpdf_get_html', array( $this, 'translate_document_html' ), 99, 2 );
		add_filter( 'wpo_wcpdf_document_language_attributes', array( $this, 'set_document_language_attribute' ), 9, 2 );
	}
	
	/**
	 * Translate document HTML
	 *
	 * @param string $original_html
	 * @param object $document
	 * @return string
	 */
	public function translate_document_html( string $original_html, $document ): string {
		if ( empty( $this->selected_plugin ) ) {
			return $original_html;
		}
		
		if ( empty( $document ) ) {
			return $original_html;
		}
	
		$order = $document->order;
		
		if ( empty( $order ) ) {
			return $original_html;
		}

		if ( is_callable( array( $order, 'get_type' ) ) && 'shop_order_refund' === $order->get_type() ) {
			$order = wc_get_order( $order->get_parent_id() );
			
			if ( empty( $order ) ) {
				return $original_html;
			}
		}
		
		// get order language
		$woocommerce_order_language = $this->get_order_lang( $order );
		
		if ( empty( $woocommerce_order_language ) ) {
			return $original_html;
		}
		
		// check if document needs translation
		$needs_translation = $this->document_needs_translation( $woocommerce_order_language );
		
		// if no need for translation bail
		if ( ! apply_filters( 'wpo_wcpdf_pro_multilingual_html_needs_translation', $needs_translation, $document, $this ) ) {
			return $original_html;
		}

		// HTML translation
		$translated_html = $this->translate_html( $original_html, $woocommerce_order_language );
				
		if ( empty( $translated_html ) ) {
			return $original_html;
		}
		
		return apply_filters( 'wpo_wcpdf_pro_multilingual_html_translated', $translated_html, $original_html, $woocommerce_order_language, $document, $this );
	}
	
	/**
	 * Get order language
	 *
	 * @param \WC_Abstract_Order $order
	 * @return string
	 */
	private function get_order_lang( \WC_Abstract_Order $order ): string {
		$woocommerce_order_language = '';
		
		if ( empty( $order ) ) {
			return $woocommerce_order_language;
		}
		
		switch ( $this->selected_plugin ) {
			case 'weglot':
				$woocommerce_order_language = $order->get_meta( 'weglot_language', true );
				break;
			case 'gtranslate':
				$woocommerce_order_language = $order->get_meta( 'wcpdf_pro_gtranslate_order_language', true );
				break;
		}
		
		return apply_filters( 'wpo_wcpdf_pro_multilingual_html_order_language', $woocommerce_order_language, $order, $this );
	}
	
	/**
	 * Check if document needs translation
	 *
	 * @param string $woocommerce_order_language
	 * @return bool
	 */
	private function document_needs_translation( string $woocommerce_order_language ): bool {
		$needs_translation = false;
		
		switch ( $this->selected_plugin ) {
			case 'weglot':
				if ( ! empty( $woocommerce_order_language ) && function_exists( 'weglot_get_original_language' ) && weglot_get_original_language() !== $woocommerce_order_language ) {
					$needs_translation = true;
				}
				break;
			case 'gtranslate':
				if ( ! empty( $woocommerce_order_language ) && is_null( $_SERVER['HTTP_X_GT_LANG'] ) ) {
					$_SERVER['HTTP_X_GT_LANG'] = $woocommerce_order_language;
					
					$data = get_option( 'GTranslate' );
				
					if ( isset( $data['default_language'] ) && $data['default_language'] !== $woocommerce_order_language ) {
						$needs_translation = true;
					}
				}
				break;
		}
		
		return $needs_translation;
	}
	
	/**
	 * Translate HTML
	 *
	 * @param string $original_html
	 * @param string $woocommerce_order_language
	 * @return string
	 */
	private function translate_html( string $original_html, string $woocommerce_order_language ): string {
		$translated_html = '';
		
		switch ( $this->selected_plugin ) {
			case 'weglot':
				if ( function_exists( 'weglot_get_service' ) ) {
					$weglot_pdf_translate_service = weglot_get_service( 'Pdf_Translate_Service_Weglot' );
					
					if ( $weglot_pdf_translate_service && is_callable( array( $weglot_pdf_translate_service, 'translate_pdf' ) ) ) {
						$translated_html = $weglot_pdf_translate_service->translate_pdf( $original_html, $woocommerce_order_language );
						$translated_html = isset( $translated_html['content'] ) ? $translated_html['content'] : $original_html;
					}
				}
				break;
			case 'gtranslate':
				if ( function_exists( 'gt_translate_invoice_pdf' ) ) {
					$translated_html = gt_translate_invoice_pdf( $original_html );	
					
					if ( ! is_null( $_SERVER['HTTP_X_GT_LANG'] ) ) {
						$_SERVER['HTTP_X_GT_LANG'] = null;
					}
				}
				break;
		}
		
		return $translated_html;
	}
	
	/**
	 * Proprietary translation tweaks
	 *
	 * @return void
	 */
	public function proprietary_translation_tweaks(): void {
		switch ( $this->selected_plugin ) {
			case 'weglot':
				if ( class_exists( '\WeglotWP\Third\Woocommercepdf\WCPDF_Weglot' ) ) {
					$weglot_class = new \WeglotWP\Third\Woocommercepdf\WCPDF_Weglot();
					
					if ( $weglot_class && is_callable( array( $weglot_class, 'translate_invoice_pdf' ) ) ) {
						remove_filter( 'wpo_wcpdf_before_dompdf_render', array( $weglot_class, 'translate_invoice_pdf' ), 10, 4 );
						remove_filter( 'wpo_wcpdf_after_mpdf_write', array( $weglot_class, 'translate_invoice_pdf' ), 10, 4 );
					}
				}
				break;
			case 'gtranslate':
				add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'set_gtranslate_order_language' ), 10, 1 );
				break;
		}
	}
	
	/**
	 * Set GTranslate order language
	 *
	 * @param int $order_id
	 * @return void
	 */
	public function set_gtranslate_order_language( int $order_id ): void {
		if ( empty( $order_id ) ) {
			return;
		}
		
		if ( 'gtranslate' !== $this->selected_plugin ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! empty( $order ) && ! empty( $_SERVER['HTTP_X_GT_LANG'] ) ) {
			$order->add_meta_data( 'wcpdf_pro_gtranslate_order_language', esc_attr( $_SERVER['HTTP_X_GT_LANG'] ), true );
			$order->save_meta_data();
		}
	}
	
	/**
	 * Set document language attribute
	 * 
	 * @param string $language
	 * @param object $document
	 * 
	 * @return string
	 */
	public function set_document_language_attribute( string $language, object $document ): string {
		if ( empty( $document->order ) ) {
			return $language;
		}
		
		$active_multilingual_plugins = WPO_WCPDF_Pro()->functions->get_active_multilingual_plugins( 'html' );
		
		if ( empty( $active_multilingual_plugins ) ) {
			return $language;
		}
		
		$lang_code = $this->get_order_lang( $document->order );
		
		if ( ! empty( $lang_code ) ) {
			$lang_code = str_replace( '_', '-', $lang_code );
			$language  = preg_replace( '/lang="[^"]*"/', 'lang="' . esc_attr( $lang_code ) . '"', $language );
		}
		
		return $language;
	}
	
}

endif; // class_exists

return new Multilingual_Html();
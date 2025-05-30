<?php
namespace WPO\WC\PDF_Invoices_Pro;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( '\\WPO\\WC\\PDF_Invoices_Pro\\Language_Switcher' ) ) :

class Language_Switcher {
	
	/**
	 * Locale of the order
	 * 
	 * @var string
	 */
	public static string $order_locale = '';

	/**
	 * Language (slug) of the order
	 * 
	 * @var string
	 */
	public static string $order_lang = '';

	/**
	 * Constructor
	 * 
	 * @param \WPO\WC\PDF_Invoices\Documents\Order_Document|null $document
	 */
	public function __construct( ?\WPO\WC\PDF_Invoices\Documents\Order_Document $document = null ) {
		// set order_lang, order_locale properties
		if ( $document ) {
			$this->set_order_lang( $document );
			$this->set_order_locale( $document );
		}
	}

	/**
	 * Switch language/translations
	 * 
	 * @return void
	 */
	public function switch_language(): void {
		// bail if we don't have an order_locale
		if ( empty( self::$order_locale ) ) {
			return;
		}

		// reload text domains
		if ( class_exists( '\\Polylang' ) && function_exists( 'switch_to_locale' ) ) { // WP4.7+
			switch_to_locale( self::$order_locale );
		}

		if ( apply_filters( 'wpo_wcpdf_force_reload_text_domains', false ) ) {
			// apply filters for plugin locale
			add_filter( 'locale', array( $this, 'plugin_locale' ), 10, 2 );
			add_filter( 'plugin_locale', array( $this, 'plugin_locale' ), 10, 2 );
			add_filter( 'theme_locale', array( $this, 'plugin_locale' ), 10, 2 );

			// force reload text domains
			self::reload_textdomains( self::$order_locale );
		}

		// allow third party plugins to reload their textdomains too
		do_action( 'wpo_wcpdf_reload_text_domains', self::$order_locale );

		// reload country name translations
		\WC()->countries = new \WC_Countries();

		// WPML
		if ( class_exists( '\\SitePress' ) ) {
			// filters to ensure correct locale
			add_filter( 'icl_current_string_language', array( $this, 'wpml_admin_string_language' ), 9, 2);
			add_filter( 'wcml_get_order_items_language', array( $this, 'wcml_order_items_string_language' ), 999, 2 );
			add_filter( 'wcml_should_save_adjusted_order_item_in_language', '__return_false' );
			add_filter( 'wcml_should_translate_order_items', '__return_true' );
			add_filter( 'wcml_should_translate_shipping_method_title', '__return_true' );
			
			global $sitepress;
			$sitepress->switch_lang( self::$order_lang );

		// Polylang
		} elseif ( class_exists( '\\Polylang' ) && function_exists( 'PLL' ) && ! empty( \PLL()->model ) && method_exists( \PLL()->model, 'get_language' ) && did_action( 'pll_init' ) ) {
			// set PLL locale to order locale to translate product names correctly
			\PLL()->curlang = \PLL()->model->get_language( self::$order_locale );
			
		// TranslatePress
		} elseif ( class_exists( '\\TRP_Translate_Press' ) && function_exists( 'trp_switch_language' ) ) {
			trp_switch_language( self::$order_lang );

		// Non-multilingual setups
		} else {
			if ( function_exists( 'switch_to_locale' ) ) {
				switch_to_locale( self::$order_locale );
			}
		}
		
		$GLOBALS['wp_locale'] = new \WP_Locale(); // ensures correct translation of dates e.a.
	}
	
	/**
	 * Set order_lang property
	 * 
	 * @param object $document
	 * 
	 * @return void
	 */
	private function set_order_lang( object $document ): void {
		self::$order_lang = self::get_order_lang_locale( $document, 'order_lang' );
	}
	
	/**
	 * Set order_locale property
	 * 
	 * @param object $document
	 * 
	 * @return void
	 */
	private function set_order_locale( object $document ): void {
		self::$order_locale = self::get_order_lang_locale( $document, 'order_locale' );
	}

	/**
	 * Set order_lang and order_locale properties
	 * 
	 * @param object $document
	 * @param string $type  'lang' or 'locale'
	 * 
	 * @return string
	 */
	public static function get_order_lang_locale( object $document, string $type = 'order_lang' ): string {
		if ( ! in_array( $type, array( 'order_lang', 'order_locale' ) ) ) {
			return '';
		}
		
		if ( ! $document || empty( $document->order ) ) {
			return self::${$type};
		}
		
		$document_type     = $document->get_type();
		$document_language = isset( \WPO_WCPDF_Pro()->functions->pro_settings['document_language'] ) ? \WPO_WCPDF_Pro()->functions->pro_settings['document_language'] : 'order';

		// WPML
		if ( class_exists( '\\SitePress' ) ) {
			global $sitepress;
			
			// use order language
			if ( 'order' === $document_language ) {
				$order_lang = $document->order->get_meta( 'wpml_language', true );
				
				if ( empty( $order_lang ) && 'credit-note' === $document_type && 'shop_order_refund' === \WPO_WCPDF()->order_util->get_order_type( $document->order_id ) ) {
					$parent_order = wc_get_order( $document->order->get_parent_id() );
					$order_lang   = $parent_order->get_meta( 'wpml_language', true );
					unset( $parent_order );
				}
				
				if ( '' === $order_lang ) {
					$order_lang = $sitepress->get_default_language();
				}
			} elseif ( apply_filters( 'wpml_language_is_active', NULL, $document_language ) ) {
				$order_lang = $document_language;
			// use site language
			} else {
				$order_lang = $sitepress->get_default_language();
			}
			
			$order_lang   = apply_filters( 'wpo_wcpdf_wpml_language', $order_lang, $document->order_id, $document_type );
			$order_locale = apply_filters( 'wpo_wcpdf_wpml_locale', $sitepress->get_locale( $order_lang ), $document->order_id, $document_type );

		// Polylang
		} elseif (
			class_exists( '\\Polylang' )                         &&
			function_exists( 'PLL' )                             &&
			! empty( \PLL()->model )                             &&
			method_exists( \PLL()->model, 'get_languages_list' ) &&
			did_action( 'pll_init' )                             &&
			function_exists( 'pll_get_post_language' )
		) {
			// use order language
			if ( 'order' === $document_language ) {
				$order_id = $document->order_id;
				
				if ( 'shop_order_refund' === \WPO_WCPDF()->order_util->get_order_type( $order_id ) ) {
					$order_id = $document->order->get_parent_id();
				}
				
				$order_locale = pll_get_post_language( $order_id, 'locale' );
				$order_lang   = pll_get_post_language( $order_id, 'slug' );
				
				if ( '' === $order_lang ) {
					$order_locale = pll_default_language( 'locale' );
					$order_lang   = pll_default_language( 'slug' );
				}
			} elseif ( ! in_array( $document_language, array( 'admin', 'user' ) ) ) {
				$order_lang = $document_language;
				
				foreach ( \PLL()->model->get_languages_list() as $language ) {
					if ( $language->slug === $order_lang ) {
						$order_locale = $language->locale;
					}
				}
			}

			// use site default language
			if ( empty( $order_locale ) ) {
				$order_locale = pll_default_language( 'locale' );
				$order_lang   = pll_default_language( 'slug' );
			}

			$order_lang   = apply_filters( 'wpo_wcpdf_pll_language', $order_lang, $document->order_id, $document_type );
			$order_locale = apply_filters( 'wpo_wcpdf_pll_locale', $order_locale, $document->order_id, $document_type );

		// TranslatePress
		} elseif ( class_exists( '\\TRP_Translate_Press' ) ) {
			$trp          = \TRP_Translate_Press::get_trp_instance();
			$trp_settings = $trp->get_component( 'settings' );
			$settings     = $trp_settings->get_settings();
					
			// use order language
			if ( 'order' === $document_language ) {
				$order_lang = $document->order->get_meta( 'wcpdf_trp_language', true );
				
				if ( empty( $order_lang ) ) {
					// fallback to old/proprietary meta key
					$order_lang = $document->order->get_meta( 'trp_language', true );
				}
				
				if ( empty( $order_lang ) && 'credit-note' === $document_type && 'shop_order_refund' === \WPO_WCPDF()->order_util->get_order_type( $document->order_id ) ) {
					$parent_order = wc_get_order( $document->order->get_parent_id() );
					$order_lang   = $parent_order->get_meta( 'wcpdf_trp_language', true );
					unset( $parent_order );
				}
				
				if ( '' === $order_lang && isset( $settings['default-language'] ) ) {
					$order_lang = $settings['default-language'];
				}
				
			} elseif ( ! in_array( $document_language, array( 'admin', 'user' ) ) ) {
				$order_lang = $document_language;
			}

			if ( empty( $order_lang ) && isset( $settings['default-language'] ) ) {
				$order_lang = $settings['default-language'];
			}
			
			$order_lang   = apply_filters( 'wpo_wcpdf_trp_language', $order_lang, $document->order_id, $document_type );
			$order_locale = apply_filters( 'wpo_wcpdf_trp_locale', trp_get_locale(), $document->order_id, $document_type );
			
		// Non-multilingual setups
		} else {
			// use order language
			if ( 'order' === $document_language ) {
				$order_locale = $document->order->get_meta( 'wcpdf_order_locale', true );
				
				if ( empty( $order_locale ) && $document_type == 'credit-note' && 'shop_order_refund' === \WPO_WCPDF()->order_util->get_order_type( $document->order_id ) ) {
					$parent_order = wc_get_order( $document->order->get_parent_id() );
					$order_locale = $parent_order->get_meta( 'wcpdf_order_locale', true );
					unset( $parent_order );
				}
			} elseif ( ! in_array( $document_language, array( 'admin', 'user' ) ) ) {
				$order_locale = $document_language;
			}

			// use site default language
			if ( empty( $order_locale ) ) {
				$order_locale = get_locale();
			}
			
			$order_lang   = apply_filters( 'wpo_wcpdf_default_language', '', $document->order_id, $document_type );
			$order_locale = apply_filters( 'wpo_wcpdf_default_locale', $order_locale, $document->order_id, $document_type );
		}
		
		return 'order_lang' === $type ? $order_lang : $order_locale;
	}

	/**
	 * Force reload textdomains
	 * 
	 * @param string $order_locale
	 * 
	 * @return void
	 */
	public static function reload_textdomains( string $order_locale = '' ): void {
		// prevent Polylang (2.2.6+) mo file override
		if ( class_exists( '\\Polylang' ) && function_exists( 'PLL' ) && ! empty( \PLL()->filters ) && method_exists( \PLL()->filters, 'load_textdomain_mofile' ) ) {
			remove_filter( 'load_textdomain_mofile', array( \PLL()->filters, 'load_textdomain_mofile' ) );
		}

		// unload text domains
		unload_textdomain( 'woocommerce' );
		unload_textdomain( 'woocommerce-pdf-invoices-packing-slips' );
		unload_textdomain( 'wpo_wcpdf' );
		unload_textdomain( 'wpo_wcpdf_pro' );

		// reload text domains
		\WC()->load_plugin_textdomain();
		\WPO_WCPDF()->translations();
		\WPO_WCPDF_Pro()->translations();

		// WP Core
		if ( ! empty( $order_locale ) ) {
			unload_textdomain( 'default' );
			load_default_textdomain( $order_locale );
		}
	}

	/**
	 * Remove language/locale filters after PDF creation
	 * 
	 * @return void
	 */
	public static function remove_filters(): void {
		// WPML
		if ( class_exists( '\\SitePress' ) ) {
			remove_filter( 'icl_current_string_language', array( __CLASS__, 'wpml_admin_string_language' ) );
			remove_filter( 'wcml_get_order_items_language', array( __CLASS__, 'wcml_order_items_string_language' ), 999, 2 );
			remove_filter( 'wcml_should_save_adjusted_order_item_in_language', '__return_false' );
			remove_filter( 'wcml_should_translate_order_items', '__return_true' );
			remove_filter( 'wcml_should_translate_shipping_method_title', '__return_true' );
		}

		if ( apply_filters( 'wpo_wcpdf_force_reload_text_domains', false ) ) {
			remove_filter( 'locale', array( __CLASS__, 'plugin_locale' ) );
			remove_filter( 'plugin_locale', array( __CLASS__, 'plugin_locale' ) );
			remove_filter( 'theme_locale', array( __CLASS__, 'plugin_locale' ) );

			// force reload text domains
			self::reload_textdomains( self::$order_locale );
		}
	}
	
	/**
	 * WPML specific filter for admin string language
	 * 
	 * @param string $current_language
	 * @param string $name
	 * 
	 * @return string
	 */
	public function wpml_admin_string_language( string $current_language, string $name ): string {
		return self::$order_lang ?? $current_language;
	}

	/**
	 * WCML specific filter for order items string language
	 * 
	 * @param string $language
	 * @param \WC_Abstract_Order $order
	 * 
	 * @return string
	 */
	public function wcml_order_items_string_language( string $language, \WC_Abstract_Order $order ): string {
		return self::$order_lang ?? $language;
	}
	
	/**
	 * Set locale for plugins (used in locale and plugin_locale filters)
	 * 
	 * @param string $locale
	 * @param string $domain
	 * 
	 * @return string $locale
	 */
	public function plugin_locale( string $locale, string $domain = '' ): string {
		return self::$order_locale ?? $locale;
	}

	/**
	 * Filter admin setting texts to apply translations
	 * 
	 * @return void
	 */
	public function translate_setting_texts(): void {
		if ( class_exists( '\\SitePress' ) || class_exists( '\\Polylang' ) || class_exists( '\\TRP_Translate_Press' ) ) {
			add_filter( 'wpo_wcpdf_header_logo_id', array( $this, 'wpml_header_logo_id' ), 8, 2 );
			add_filter( 'wpo_wcpdf_header_logo_id', array( $this, 'get_translated_media_id' ), 9, 2 );
			add_filter( 'wpo_wcpdf_shop_name_settings_text', array( $this, 'get_translated_shop_name_text' ), 9, 2 );
			add_filter( 'wpo_wcpdf_shop_address_settings_text', array( $this, 'get_translated_shop_address_text' ), 9, 2 );
			add_filter( 'wpo_wcpdf_footer_settings_text', array( $this, 'get_translated_footer_text' ), 9, 2 );
			add_filter( 'wpo_wcpdf_extra_1_settings_text', array( $this, 'get_translated_extra_1_text' ), 9, 2 );
			add_filter( 'wpo_wcpdf_extra_2_settings_text', array( $this, 'get_translated_extra_2_text' ), 9, 2 );
			add_filter( 'wpo_wcpdf_extra_3_settings_text', array( $this, 'get_translated_extra_3_text' ), 9, 2 );
			add_filter( 'wpo_wcpdf_shop_vat_label_settings_text', array( $this, 'get_translated_shop_vat_label_text' ), 9, 2 );
			add_filter( 'wpo_wcpdf_shop_coc_label_settings_text', array( $this, 'get_translated_shop_coc_label_text' ), 9, 2 );
		}
	}
	
	/**
	 * Get translated WPML header logo ID
	 * 
	 * @param int $header_logo_id
	 * @param \WPO\WC\PDF_Invoices\Documents\Order_Document|null $document
	 * 
	 * @return int Returns the logo ID as integer
	 */
	public function wpml_header_logo_id( int $header_logo_id, ?\WPO\WC\PDF_Invoices\Documents\Order_Document $document = null ): int {
		$attachment_id = $this->get_setting_string_translation( 'header_logo', $header_logo_id, $document );
		return is_numeric( $attachment_id ) ? absint( $attachment_id ) : 0;
	}

	/**
	 * Get translated media ID
	 * 
	 * @param int $media_id
	 * @param \WPO\WC\PDF_Invoices\Documents\Order_Document|null $document
	 * 
	 * @return int
	 */
	public function get_translated_media_id( int $media_id, ?\WPO\WC\PDF_Invoices\Documents\Order_Document $document = null ): int {
		return ( 0 === $media_id ) ? $media_id : apply_filters( 'wpml_object_id', $media_id, 'attachment', true );
	}

	/**
	 * Get translated string for shop name
	 * 
	 * @param string $shop_name
	 * @param \WPO\WC\PDF_Invoices\Documents\Order_Document|null $document
	 * 
	 * @return string
	 */
	public function get_translated_shop_name_text( string $shop_name, ?\WPO\WC\PDF_Invoices\Documents\Order_Document $document = null ): string {
		return $this->get_setting_string_translation( 'shop_name', $shop_name, $document );
	}
	
	/**
	 * Get translated string for shop address
	 * 
	 * @param string $shop_address
	 * @param \WPO\WC\PDF_Invoices\Documents\Order_Document|null $document
	 * 
	 * @return string
	 */
	public function get_translated_shop_address_text( string $shop_address, ?\WPO\WC\PDF_Invoices\Documents\Order_Document $document = null ): string {
		return wpautop( $this->get_setting_string_translation( 'shop_address', $shop_address, $document ) );
	}
	
	/**
	 * Get translated string for footer
	 * 
	 * @param string $footer
	 * @param \WPO\WC\PDF_Invoices\Documents\Order_Document|null $document
	 * 
	 * @return string
	 */
	public function get_translated_footer_text( string $footer, ?\WPO\WC\PDF_Invoices\Documents\Order_Document $document = null ): string {
		return wpautop( $this->get_setting_string_translation( 'footer', $footer, $document ) );
	}
	
	/**
	 * Get translated string for extra 1
	 * 
	 * @param string $extra_1
	 * @param \WPO\WC\PDF_Invoices\Documents\Order_Document|null $document
	 * 
	 * @return string
	 */
	public function get_translated_extra_1_text( string $extra_1, ?\WPO\WC\PDF_Invoices\Documents\Order_Document $document = null ): string {
		return wpautop( $this->get_setting_string_translation( 'extra_1', $extra_1, $document ) );
	}
	
	/**
	 * Get translated string for extra 2
	 * 
	 * @param string $extra_2
	 * @param \WPO\WC\PDF_Invoices\Documents\Order_Document|null $document
	 * 
	 * @return string
	 */
	public function get_translated_extra_2_text( string $extra_2, ?\WPO\WC\PDF_Invoices\Documents\Order_Document $document = null ): string {
		return wpautop( $this->get_setting_string_translation( 'extra_2', $extra_2, $document ) );
	}
	
	/**
	 * Get translated string for extra 3
	 * 
	 * @param string $extra_3
	 * @param \WPO\WC\PDF_Invoices\Documents\Order_Document|null $document
	 * 
	 * @return string
	 */
	public function get_translated_extra_3_text( string $extra_3, ?\WPO\WC\PDF_Invoices\Documents\Order_Document $document = null ): string {
		return wpautop( $this->get_setting_string_translation( 'extra_3', $extra_3, $document ) );
	}
	
	/**
	 * Get translated string for shop VAT label
	 * 
	 * @param string $vat_label
	 * @param \WPO\WC\PDF_Invoices\Documents\Order_Document|null $document
	 * 
	 * @return string
	 */
	public function get_translated_shop_vat_label_text( string $vat_label, ?\WPO\WC\PDF_Invoices\Documents\Order_Document $document = null ): string {
		return wpautop( $this->get_setting_string_translation( 'shop_vat_label', $vat_label, $document ) );
	}
	
	/**
	 * Get translated string for shop CoC label
	 * 
	 * @param string $coc_label
	 * @param \WPO\WC\PDF_Invoices\Documents\Order_Document|null $document
	 * 
	 * @return string
	 */
	public function get_translated_shop_coc_label_text( string $coc_label, ?\WPO\WC\PDF_Invoices\Documents\Order_Document $document = null ): string {
		return wpautop( $this->get_setting_string_translation( 'shop_coc_label', $coc_label, $document ) );
	}

	/**
	 * Get string translation for string name, using $woocommerce_wpml helper function
	 * 
	 * @param string $string_name
	 * @param string $default
	 * @param \WPO\WC\PDF_Invoices\Documents\Order_Document|null $document
	 * 
	 * @return string
	 */
	public function get_setting_string_translation( string $string_name, string $default, ?\WPO\WC\PDF_Invoices\Documents\Order_Document $document = null ): string {
		// check internal settings first
		$translated = self::get_i18n_setting( $string_name, $default, $document );
		
		if ( false !== $translated ) {
			return $translated;
		}
		
		// fallback to 1.X method
		if ( ! empty( self::$order_lang ) && ( class_exists( '\\SitePress' ) || class_exists( '\\Polylang' ) ) ) {
			global $woocommerce_wpml;
			
			$translations    = get_option( 'wpo_wcpdf_translations' );
			$internal_string = 'wpo_wcpdf_template_settings[' . $string_name . ']';
			
			if ( ! empty( $translations[ $internal_string ][ self::$order_lang ] ) ) {
				return wptexturize( $translations[ $internal_string ][ self::$order_lang ] );
			}

			// fall back to string translations
			if ( class_exists( '\\SitePress' ) ) {
				$full_string_name = '[wpo_wcpdf_template_settings]' . $string_name;
				
				if ( isset( $woocommerce_wpml->emails ) && is_callable( array( $woocommerce_wpml->emails, 'wcml_get_email_string_info' ) ) && function_exists( 'icl_t' ) ) {
					$string_data = $woocommerce_wpml->emails->wcml_get_email_string_info( $full_string_name );
					
					if ( $string_data ) {
						$string = icl_t( $string_data[0]->context, $full_string_name ,$string_data[0]->value );
						return wptexturize( $string );
					}
				}
			} elseif ( class_exists( '\\Polylang' ) && function_exists( '\\pll_translate_string' ) ) {
				// we don't rely on $default, it has been filtered throught wpautop &
				// wptexturize when the apply_filter function was invoked
				if ( ! empty( $document->settings[ $string_name ][ self::$order_lang ] ) ) {
					$string = pll_translate_string( $document->settings[ $string_name ][ self::$order_lang ], self::$order_locale );
					return wptexturize( $string );
				}
			}
		}

		// no translations found, try to at least return a string
		if ( is_array( $default ) ) {
			return array_shift( $default );
		} elseif ( is_string( $default ) ) {
			return $default;
		} else {
			return '';
		}
	}

	/**
	 * Get i18n setting
	 * 
	 * @param string $setting_key
	 * @param string $default
	 * @param \WPO\WC\PDF_Invoices\Documents\Order_Document|null $document
	 * @param string|null $lang
	 * 
	 * @return string
	 */
	public static function get_i18n_setting( string $setting_key, string $default, ?\WPO\WC\PDF_Invoices\Documents\Order_Document $document = null, ?string $lang = null ): string {
		if ( ! empty( $document ) ) {
			$setting    = $document->get_setting( $setting_key, $default );
			$order_lang = empty( self::$order_lang ) ? self::get_order_lang_locale( $document, 'order_lang' ) : self::$order_lang;

			// check if we have a value for this setting
			if ( ! empty( $setting ) && is_array( $setting ) ) {
				// check if we have a translation for this setting in the document language
				if ( ! empty( $document->order ) && ! empty( $order_lang ) && isset( $setting[ $order_lang ] ) ) {
					return wptexturize( $setting[ $order_lang ] );
					
				// use provided language
				} elseif( ! empty( $lang ) && isset( $setting[ $lang ] ) ) {
					return wptexturize( $setting[ $lang ] );
					
				// fallback to default 1
				} elseif ( ! empty( $default ) ) {
					return wptexturize( $default );
					
				// fallback to default 2
				} elseif ( isset( $setting['default'] ) ) {
					return wptexturize( $setting['default'] );
					
				// fallback to first language
				} else {
					$translation = reset( $setting );
					
					if ( ! empty( $translation ) ) {
						return wptexturize( $translation );
					}
				}
			}
		}

		// no translation
		return '';
	}


} // end class

endif; // end class_exists
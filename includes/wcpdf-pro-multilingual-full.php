<?php
namespace WPO\WC\PDF_Invoices_Pro;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( '\\WPO\\WC\\PDF_Invoices_Pro\\Multilingual_Full' ) ) :

class Multilingual_Full {

	/**
	 * The language used before creating a PDF
	 * 
	 * @var string
	 */
	public string $previous_language;
	
	/**
	 * The single instance of the class
	 *
	 * @var self|null
	 */
	protected static ?self $_instance = null;

	/**
	 * Main Instance
	 *
	 * Ensures only one instance of the class is loaded or can be loaded.
	 *
	 * @return self instance
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		
		return self::$_instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		// load switcher class
		include_once( 'wcpdf-pro-order-language-switcher.php' );

		// on non-multilingual setups
		if ( ! class_exists( '\\SitePress' ) && ! class_exists( '\\Polylang' ) ) {
			add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'set_order_locale' ) ); // using checkout block
			add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'set_order_locale' ) );
		}

		// add actions
		add_action( 'wpo_wcpdf_before_html', array( $this, 'store_language' ), 10, 2 );
		add_action( 'wpo_wcpdf_before_html', array( __CLASS__, 'switch_language' ), 11, 2 );
		add_action( 'wpo_wcpdf_after_html', array( $this, 'reset_language' ), 10, 2 );

		// register strings
		add_filter( 'wpo_wcpdf_order_taxes', array( $this, 'register_translate_refund_order_tax_labels' ), 10, 2 );
		add_filter( 'wpo_wcpdf_shipping_notes', array( $this, 'register_translate_notes' ), 10, 1 );
		add_filter( 'wpo_wcpdf_document_notes', array( $this, 'register_translate_notes' ), 10, 1 );
		add_filter( 'wpo_wcpdf_order_note', array( $this, 'register_translate_notes' ), 10, 1 );
		
		// set document language attribute
		add_filter( 'wpo_wcpdf_document_language_attributes', array( $this, 'set_document_language_attribute' ), 9, 2 );

		// Register strings for customizer labels/texts
		$this->register_premium_templates_editor_custom_labels();
		
		add_filter( 'wpo_wcpdf_template_editor_settings', array( $this, 'translate_premium_templates_editor_custom_labels' ), 20, 4 );
		add_filter( 'wpo_wcpdf_templates_table_headers', array( $this, 'translate_premium_templates_default_table_headers' ), 20, 3 );

		// force reloading textdomains if user language is not site default
		if ( $this->get_current_user_locale_setting() && ( class_exists( '\\SitePress' ) || class_exists( '\\Polylang' ) || class_exists( '\\TRP_Translate_Press' ) ) ) {
			add_filter( 'wpo_wcpdf_force_reload_text_domains', '__return_true' );
		}

		if ( class_exists( '\\SitePress' ) ) {
			add_filter( 'wpo_wcpdf_allow_reload_attachment_translations', '__return_false' );
		}
		
		if ( class_exists( '\\TRP_Translate_Press' ) ) {
			add_filter( 'wpo_wcpdf_multilingual_languages', array( $this, 'trp_languages' ) );
			add_filter( 'wpo_wcpdf_title_for', array( $this, 'translate_title_for' ), 10, 3 );
			add_filter( 'wpo_wcpdf_simple_template_default_table_headers', array( $this, 'translate_simple_template_default_table_headers' ), 10, 2 );
			add_filter( 'wpo_wcpdf_document_title', array( $this, 'translate_document_title' ), 10, 2 );
			add_filter( 'wpo_wcpdf_order_item_name', array( $this, 'translate_order_item_name' ), 10, 3 );
			remove_filter( 'trp_stop_translating_page', 'trp_woo_pdf_invoices_and_packing_slips_compatibility_dont_translate_pdf', 10, 2 );
			remove_filter( 'wpo_wcpdf_get_html', 'trp_woo_pdf_invoices_and_packing_slips_compatibility' );
			remove_filter( 'wpo_wcpdf_filename', 'trp_woo_pdf_invoices_and_packing_slips_compatibility' );
			remove_filter( 'wpo_wcpdf_order_item_data', 'trp_woo_wcpdf_translate_product_name', 10, 3 );
		}
	}

	/**
	 * Set order locale
	 * 
	 * @param \WC_Order|int $order_or_id
	 * 
	 * @return void
	 */
	public function set_order_locale( $order_or_id ): void {
		if ( ! $order_or_id ) {
			return;
		}
		
		$order = is_a( $order_or_id, 'WC_Order' ) ? $order_or_id : wc_get_order( absint( $order_or_id ) );
		
		if ( ! $order ) {
			return;
		}
		
		// TranslatePress
		if ( class_exists( '\\TRP_Translate_Press' ) ) {
			global $TRP_LANGUAGE;
			
			$language = $TRP_LANGUAGE;
			$meta_key = 'wcpdf_trp_language';
			
		// Default
		} else {
			// for non-multilingual setups we use the default site language (PDF language might be overridden by Pro tab setting)
			$language = get_locale();
			$meta_key = 'wcpdf_order_locale';
		}
		
		if ( ! empty( $meta_key ) && ! empty( $language ) ) {
			$order->add_meta_data( $meta_key, $language, true );
			$order->save_meta_data();
		}
	}
	
	/**
	 * Get order language
	 * 
	 * @param int $order_id
	 * @param string|null $document_type
	 * 
	 * @return string|null
	 */
	public function get_order_lang( int $order_id, ?string $document_type = null ): ?string {
		if ( empty( $order_id ) ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		
		if ( empty( $order ) ) {
			return null;
		}

		if ( is_callable( array( $order, 'get_type' ) ) && 'shop_order_refund' === $order->get_type() ) {
			$order = wc_get_order( $order->get_parent_id() );
			
			if ( empty( $order ) ) {
				return null;
			}
		}

		$document_type = ( 'static_file' === $document_type ) ? 'invoice' : $document_type; // 'static_file' is not a valid document type. Found in 'attach_static_file()'
		$document      = \WPO_WCPDF()->documents->get_document( $document_type, $order );
		
		if ( empty( $document ) ) {
			return null;
		}
		
		return Language_Switcher::get_order_lang_locale( $document, 'order_lang' );
	}

	/**
	 * Store current language before creating a PDF
	 * 
	 * @param string $document_type
	 * @param object $document
	 * 
	 * @return void
	 */
	public function store_language( string $document_type, object $document ): void {
		if ( empty( $document->order ) || 'bulk' === $document_type ) { // bulk document, no need to switch (this is done per individual document)
			return;
		}
		
		// WPML
		if ( class_exists( '\\SitePress' ) ) {
			global $sitepress;
			$this->previous_language = $sitepress->get_current_language();

		// TranslatePress
		} elseif ( class_exists( '\\TRP_Translate_Press' ) && function_exists( 'trp_get_locale' ) ) {
			$this->previous_language = trp_get_locale();
		
		// Polylang
		// Non-multilingual setups
		} else {
			if ( function_exists( 'determine_locale' ) ) { // WP5.0+
				$this->previous_language = determine_locale();
			} else {
				$this->previous_language = is_admin() && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
			}
		}
	}

	/**
	 * Set language before pdf creation
	 * 
	 * @param string $document_type
	 * @param object $document
	 * 
	 * @return void
	 */
	public static function switch_language( string $document_type, object $document ): void {
		if ( ! $document || empty( $document->order ) || 'bulk' === $document_type ) { // bulk document, no need to switch (this is done per individual document)
			return;
		}

		$language_switcher = new Language_Switcher( $document );
		
		// switch language
		$language_switcher->switch_language();

		// make sure country translations are reloaded
		add_filter( 'woocommerce_countries', array( __CLASS__, 'reload_countries' ), 999 );

		// filter setting texts to use settings field translations
		$language_switcher->translate_setting_texts();
	}

	/**
	 * Set locale/language to default after PDF creation
	 * 
	 * @param string $document_type
	 * @param object $document
	 * 
	 * @return void
	 */
	public function reset_language( string $document_type, object $document ): void {
		if ( empty( $document->order ) || 'bulk' === $document_type ) { // bulk document, no need to switch (this is done per individual document)
			return;
		}
		
		Language_Switcher::remove_filters();
		
		remove_filter( 'woocommerce_countries', array( __CLASS__, 'reload_countries' ), 999 );
		
		// WPML
		if ( class_exists( '\\SitePress' ) ) {
			global $sitepress;
			$sitepress->switch_lang( $this->previous_language );
			
		// Polylang
		} elseif ( class_exists( '\\Polylang' ) && function_exists( 'PLL' ) && ! empty( \PLL()->model ) && method_exists( \PLL()->model, 'get_language' ) && did_action( 'pll_init' ) ) {
			// set PLL locale to order locale to translate product names correctly
			\PLL()->curlang = \PLL()->model->get_language( $this->previous_language );
			
			if ( function_exists( 'switch_to_locale ') ) { // WP4.7+
				switch_to_locale( $this->previous_language );
			}
			
		// TranslatePress
		} elseif ( class_exists( '\\TRP_Translate_Press' ) && function_exists( 'trp_restore_language' ) ) {
			trp_restore_language();
		
		// Non-multilingual setups
		} else {
			if ( function_exists( 'switch_to_locale ') ) { // WP4.7+
				switch_to_locale( $this->previous_language );
			}
		}
	}

	/**
	 * Reload countries
	 * 
	 * @param array $countries
	 * 
	 * @return array
	 */
	public static function reload_countries( array $countries ): array {
		if ( file_exists( \WC()->plugin_path() . '/i18n/countries.php' ) ) {
			$countries = include \WC()->plugin_path() . '/i18n/countries.php';
		}
		
		return $countries;
	}

	/**
	 * Register/translate tax labels for refund orders
	 * @param  array   $taxes    total tax rows
	 * @param  object  $document WCPDF Order Document object
	 * 
	 * @return array   $taxes    total tax rows
	 */
	public function register_translate_refund_order_tax_labels( array $taxes, object $document ): array {
		if ( isset( $document->order ) ) {
			$order_type = method_exists( $document->order, 'get_type' ) ? $document->order->get_type() : $document->order->order_type;
			$textdomain = 'admin_texts_woocommerce_tax';
			
			// only for refund orders!
			if ( 'shop_order_refund' === $order_type ) {
				foreach ( $taxes as $key => $tax ) {
					self::register_string( $taxes[ $key ]['label'], array( 'textdomain' => $textdomain ) );
					$taxes[ $key ]['label'] = self::maybe_get_string_translation( $taxes[ $key ]['label'], $textdomain );
				}
			}
		}
		
		return $taxes;
	}
	
	/**
	 * Register/translate notes
	 * 
	 * @param string $note
	 * 
	 * @return string
	 */
	public function register_translate_notes( string $note ): string {
		if ( ! empty( $note ) ) {
			self::register_string( $note );
			$note = self::maybe_get_string_translation( $note );
		}
		
		return $note;
	}
	
	/**
	 * Translate premium templates default table headers
	 *
	 * @param array $headers
	 * @param string $document_type
	 * @param object $document
	 * @return array
	 */
	public function translate_premium_templates_default_table_headers( array $headers, string $document_type, object $document ): array {
		$textdomain = 'wpo_wcpdf_templates';
		
		foreach ( $headers as $key => $header ) {
			if ( ! empty( $header['label'] ) ) {
				self::register_string( $header['label'], array( 'textdomain' => $textdomain ) );
				$headers[ $key ]['label'] = self::maybe_get_string_translation( $header['label'], $textdomain );
			}
			
			if ( ! empty( $header['title'] ) ) {
				self::register_string( $header['title'], array( 'textdomain' => $textdomain ) );
				$headers[ $key ]['title'] = self::maybe_get_string_translation( $header['title'], $textdomain );
			}
		}
		
		return $headers;
	}

	/**
	 * Register premium templates columns, totals and custom block labels
	 * 
	 * @return void
	 */
	public function register_premium_templates_editor_custom_labels(): void {
		$settings   = get_option( 'wpo_wcpdf_editor_settings', array() );
		$textdomain = 'wpo_wcpdf_templates';
		
		if ( empty( $settings ) ) {
			return;
		}
		
		foreach ( $settings as $setting_key => $setting ) {
			if ( false !== strpos( $setting_key, '_columns' ) || false !== strpos( $setting_key, '_totals' ) || false !== strpos( $setting_key, '_custom' ) ) {
				foreach ( $setting as $value ) {
					if ( empty( $value['type'] ) ) {
						continue;
					}
					
					if ( ! empty( $value['label'] ) ) {
						self::register_string( $value['label'], array( 'textdomain' => $textdomain ) );
					}
					
					if ( ! empty( $value['text'] ) ) {
						self::register_string( $value['text'], array( 'textdomain' => $textdomain ) );
					}
				}
			}
		}
	}

	/**
	 * Translate premium templates columns, totals and custom block labels
	 *
	 * @param array $settings
	 * @param string $document_type
	 * @param string $settings_name
	 * @param object|null $document
	 * 
	 * @return array
	 */
	public function translate_premium_templates_editor_custom_labels( array $settings, string $document_type, string $settings_name, ?object $document = null ): array {
		$textdomain = 'wpo_wcpdf_templates';

		foreach ( $settings as &$setting ) {
			// label
			if ( ! empty( $setting['label'] ) ) {
				$setting['label'] = self::maybe_get_string_translation( $setting['label'], $textdomain );
			}
			// text
			if ( ! empty( $setting['text'] ) ) {
				$setting['text'] = self::maybe_get_string_translation( $setting['text'], $textdomain );
			}
		}
		
		return $settings;
	}
	
	/**
	 * Translate title for
	 * 
	 * @param string $title
	 * @param string $slug
	 * @param object $document
	 * 
	 * @return string
	 */
	public function translate_title_for( string $title, string $slug, $document ): string {
		return self::maybe_get_string_translation( $title, 'woocommerce-pdf-invoices-packing-slips' );
	}
	
	/**
	 * Translate simple template default table headers
	 * 
	 * @param array $headers
	 * @param object $document
	 * 
	 * @return array
	 */
	public function translate_simple_template_default_table_headers( array $headers, $document ): array {
		foreach ( $headers as $key => $header ) {
			$headers[ $key ] = self::maybe_get_string_translation( $header, 'woocommerce-pdf-invoices-packing-slips' );
		}
		
		return $headers;
	}
	
	/**
	 * Translate document title
	 * 
	 * @param string $title
	 * @param object $document
	 * 
	 * @return string
	 */
	public function translate_document_title( string $title, $document ): string {
		return self::maybe_get_string_translation( $title, 'woocommerce-pdf-invoices-packing-slips' );
	}
	
	/**
	 * Translate order item name
	 * 
	 * @param string $name
	 * @param \WC_Order_Item_Product $item
	 * @param \WC_Abstract_Order $order
	 * 
	 * @return string
	 */
	public function translate_order_item_name( string $name, \WC_Order_Item_Product $item, \WC_Abstract_Order $order ): string {
		return self::maybe_get_string_translation( $name, 'woocommerce' );
	}

	/**
	 * Get locale setting from user profile (site-default = empty)
	 * Used to determine whether to force reloading textdomains
	 * 
	 * @return string|bool
	 */
	public function get_current_user_locale_setting() {
		if ( function_exists( 'wp_get_current_user' ) && function_exists( 'get_user_locale' ) ) {
			$user = wp_get_current_user();
			
			if ( $user ) {
				$user_locale = get_user_locale( $user );
				$site_locale = get_locale();
				
				// reload textdomains only if user locale is different from site locale
				if ( $user_locale !== $site_locale ) {
					return $user_locale;
				}
			}
		}
		
		return false;
	}
	
	/**
	 * Maybe get string translation
	 *
	 * @param string $string
	 * @param string $textdomain
	 * 
	 * @return string
	 */
	public static function maybe_get_string_translation( string $string, string $textdomain = 'wpo_wcpdf_pro' ): string {
		if ( empty( $string ) ) {
			return $string;
		}
		
		$translation = $string;
		
		// WPML
		if ( class_exists( '\\SitePress' ) ) {			
			$default_lang = apply_filters( 'wpml_default_language', null );
			$current_lang = apply_filters( 'wpml_current_language', null );
			
			if ( $default_lang && $current_lang && $current_lang !== $default_lang ) {
				$name        = self::generate_wpml_string_name( $string, $textdomain );
				$translation = apply_filters( 'wpml_translate_single_string', $string, $textdomain, $name, $current_lang );
			}
		
		// Polylang
		} elseif ( class_exists( '\\Polylang' ) ) {
			$default_lang = function_exists( 'pll_default_language' ) ? pll_default_language() : null;
			$current_lang = function_exists( 'pll_current_language' ) ? pll_current_language() : null;
			
			if ( $default_lang && $current_lang && $current_lang !== $default_lang && function_exists( 'pll_translate_string' ) ) {
				$translation = pll_translate_string( $string, $current_lang );
			}
			
		// TranslatePress
		} elseif ( class_exists( '\\TRP_Translate_Press' ) ) {
			$translation = self::maybe_get_trp_translation( $string, $textdomain );
		}
		
		// If not translated yet, try native translate() first, then custom filters
		if ( $translation === $string && function_exists( 'translate' ) ) {
			$translation = translate( $string, $textdomain );
		}

		// If still not translated, try custom filters
		if ( $translation === $string ) {
			$translation = wpo_wcpdf_gettext( $string, $textdomain );
		}
		
		return $translation;
	}
	
	/**
	 * Get TranslatePress string translation.
	 *
	 * @param string $string
	 * @param string $textdomain
	 * 
	 * @return string
	 */
	public static function maybe_get_trp_translation( string $string, string $textdomain = 'wpo_wcpdf_pro' ): string {
		if ( empty( $string ) ) {
			return $string;
		}

		global $wpdb, $TRP_LANGUAGE;
		
		$current_lang = apply_filters( 'wpo_wcpdf_trp_current_language', $TRP_LANGUAGE );

		// Attempt translation with trp_translate()
		if ( function_exists( 'trp_translate' ) ) {
			$translation = trp_translate( $string, $current_lang, false );

			// If translation is different from the original string, return it
			if ( ! empty( $translation ) && $translation !== $string ) {
				return $translation;
			}
		}
		
		$trp          = \TRP_Translate_Press::get_trp_instance();
		$trp_settings = $trp->get_component( 'settings' );
		$settings     = $trp_settings->get_settings();
		$default_lang = isset( $settings['default-language'] ) ? $settings['default-language'] : null;

		// Fallback: Query TranslatePress database directly
		if ( $default_lang && $current_lang && $current_lang !== $default_lang ) {
			$table_names = array(
				esc_sql( $wpdb->prefix . 'trp_gettext_' . strtolower( $current_lang ) ),
				esc_sql( $wpdb->prefix . 'trp_dictionary_' . strtolower( $default_lang ) . '_' . strtolower( $current_lang ) ),
			);

			foreach ( $table_names as $index => $table_name ) {
				// Check if the table exists before querying
				$table_exists = $wpdb->get_var(
					$wpdb->prepare( "SHOW TABLES LIKE %s", $table_name )
				);

				if ( $table_exists ) {
					$query = ( $index === 0 ) 
						? "SELECT translated FROM {$table_name} WHERE original = %s AND domain = %s"
						: "SELECT translated FROM {$table_name} WHERE original = %s";

					$params = ( $index === 0 )
						? array( $string, sanitize_key( $textdomain ) )
						: array( $string );

					$translation = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
						$wpdb->prepare( $query, ...$params )
					);

					if ( ! empty( $translation ) && $translation !== $string ) {
						return $translation;
					}
				}
			}
		}

		// Return original string if no translation found
		return $string;
	}
	
	/**
	 * Register string for translation
	 *
	 * @param string $string
	 * @param array  $args
	 * 
	 * @return void
	 */
	public static function register_string( string $string, array $args = array() ): void {
		if ( empty( $string ) ) {
			return;
		}
		
		$default_args = array(
			'textdomain' => 'wpo_wcpdf_pro',
			'context'    => '',
			'group'      => 'woocommerce-pdf-ips-pro',
			'multiline'  => false,
		);
		
		$args = wp_parse_args( $args, $default_args );
		
		// WPML
		if ( class_exists( '\\SitePress' ) && function_exists( 'icl_register_string' ) ) {
			$name = self::generate_wpml_string_name( $string, $args['textdomain'] );
			icl_register_string( $args['textdomain'], $name, $string );
		
		// Polylang
		} elseif ( class_exists( '\\Polylang' ) && function_exists( 'pll_register_string' ) ) {
			pll_register_string( $args['textdomain'], $string, $args['group'], $args['multiline'] );
		}
	}
	
	/**
	 * Generate WPML string name
	 *
	 * @param string $string
	 * @param string $textdomain
	 * 
	 * @return string
	 */
	public static function generate_wpml_string_name( string $string, string $textdomain = 'wpo_wcpdf_pro' ): string {
		return $textdomain . ' - ' . md5( $string );
	}
	
	/**
	 * Get TranslatePress languages
	 * 
	 * @param array $languages
	 * 
	 * @return array
	 */
	public function trp_languages( array $languages ): array {
		if ( function_exists( 'trp_get_languages' ) ) {
			$languages = trp_get_languages();
		}
		
		return $languages;
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
		$active_multilingual_plugins = WPO_WCPDF_Pro()->functions->get_active_multilingual_plugins( 'full' );
		
		if ( empty( $active_multilingual_plugins ) ) {
			return $language;
		}
		
		$lang_code = Language_Switcher::get_order_lang_locale( $document, 'order_locale' );

		if ( ! empty( $lang_code ) ) {
			$lang_code = str_replace( '_', '-', $lang_code );
			$language  = preg_replace( '/lang="[^"]*"/', 'lang="' . esc_attr( $lang_code ) . '"', $language );
		}
		
		return $language;
	}

} // end class

endif; // end class_exists

return Multilingual_Full::instance();

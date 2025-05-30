<?php
/**
 * Plugin Name:          PDF Invoices & Packing Slips for WooCommerce - Professional
 * Requires Plugins:     woocommerce-pdf-invoices-packing-slips
 * Plugin URI:           https://wpovernight.com/downloads/woocommerce-pdf-invoices-packing-slips-professional/
 * Description:          Extended functionality for PDF Invoices & Packing Slips for WooCommerce plugin
 * Version:              2.16.4
 * Author:               WP Overnight
 * Author URI:           https://wpovernight.com/
 * License:              GPLv2 or later
 * License URI:          https://opensource.org/licenses/gpl-license.php
 * Text Domain:          wpo_wcpdf_pro
 * Domain Path:          /languages
 * WC requires at least: 3.3
 * WC tested up to:      9.8
 */

if ( ! class_exists( 'WooCommerce_PDF_IPS_Pro' ) ) :

class WooCommerce_PDF_IPS_Pro {

	public $version             = '2.16.4';
	public $plugin_basename;
	public $cloud_api           = null;
	public $updater;
	public $emails;
	public $settings;
	public $functions;
	public $writepanels;
	public $multilingual_full;
	public $multilingual_html;
	public $bulk_export;
	public $dependencies;
	public $dropbox_api;
	public $ftp_upload;
	public $gdrive_api;
	public $cloud_storage;

	/**
	 * @var WPO\WC\PDF_Invoices_Pro\Rest
	 */
	public $rest;

	protected static $_instance = null;

	/**
	 * Main Plugin Instance
	 *
	 * Ensures only one instance of plugin is loaded or can be loaded.
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
	public function __construct() {
		$this->plugin_basename = plugin_basename(__FILE__);

		$this->define( 'WPO_WCPDF_PRO_VERSION', $this->version );

		// load the localisation & classes
		add_action( 'init', array( $this, 'translations' ), 8 );
		add_action( 'wpo_wcpdf_reload_attachment_translations', array( $this, 'translations' ) );
		add_action( 'init', array( $this, 'load_classes_early' ), 9 );
		add_action( 'init', array( $this, 'load_classes' ) );

		// Load the updater
		add_action( 'init', array( $this, 'load_updater' ), 0 );
		
		// upgrade notices
		add_action( 'admin_notices', array( $this, 'upgrade_notice' ) );
		
		// HPOS compatibility
		add_action( 'before_woocommerce_init', array( $this, 'woocommerce_hpos_compatible' ) );
		
		// adds custom links to plugin meta
		add_filter( 'plugin_row_meta', array( $this, 'add_link_to_plugin_meta' ), 10, 4 );

		// run lifecycle methods
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			add_action( 'wp_loaded', array( $this, 'do_install' ) );
		}

		// Autoloader
		require( plugin_dir_path( __FILE__ ) . 'lib/autoload.php' );
	}

	/**
	 * Define constant if not already set
	 * @param  string $name
	 * @param  string|bool $value
	 */
	private function define( $name, $value ) {
		if ( ! defined( $name ) ) {
			define( $name, $value );
		}
	}
	
	/**
	 * Adds custom links to the plugin row meta
	 *
	 * @param  string[] $plugin_meta
	 * @param  string   $plugin_file
	 * @param  array    $plugin_data
	 * @param  string   $status
	 * @return array
	 */
	public function add_link_to_plugin_meta( $plugin_meta, $plugin_file, $plugin_data, $status ): array {
		if ( plugin_basename( __FILE__ ) === $plugin_file ) {
			$row_meta = array(
				'download' => '<a href="' . esc_url( 'https://wpovernight.com/downloads/woocommerce-pdf-invoices-packing-slips-professional/' ) . '" target="_blank" aria-label="' . esc_attr( $plugin_data['Name'] ) . '">' . esc_html__( 'Visit plugin site', 'wpo_wcpdf_pro' ) . '</a>',
				'docs'     => '<a href="' . esc_url( 'https://docs.wpovernight.com/topic/woocommerce-pdf-invoices-packing-slips/' ) . '" target="_blank" aria-label="' . esc_attr__( 'Documentation', 'wpo_wcpdf_pro' ) . '">' . esc_html__( 'Documentation', 'wpo_wcpdf_pro' ) . '</a>',
				'support'  => '<a href="' . esc_url( 'https://wpovernight.com/contact/' ) . '" target="_blank" aria-label="' . esc_attr__( 'Support', 'wpo_wcpdf_pro' ) . '">' . esc_html__( 'Support', 'wpo_wcpdf_pro' ) . '</a>',
			);
			
			if ( false !== strpos( $plugin_meta[2], '<a' ) ) {
				unset( $plugin_meta[2] );
			}
			
			return array_merge( $plugin_meta, $row_meta );
		}
		
		return $plugin_meta;
	}

	/**
	 * Run the updater scripts
	 * @return void
	 */
	public function load_updater() {
		// Init updater data
		$item_name           = 'PDF Invoices & Packing Slips for WooCommerce - Professional';
		$file                = __FILE__;
		$license_slug        = 'wpo_wcpdf_pro_license';
		$version             = $this->version;
		$author              = 'WP Overnight';
		$updater_helper_file = $this->plugin_path() . '/updater/update-helper.php';

		if ( ! class_exists( 'WPO_Update_Helper' ) && file_exists( $updater_helper_file ) ) {
			include_once $updater_helper_file;
		}

		if ( class_exists( 'WPO_Update_Helper' ) ) {
			$this->updater = new \WPO_Update_Helper( $item_name, $file, $license_slug, $version, $author );
		}

		// if license not activated, show notice in plugin settings page
		if ( ! empty( $this->updater ) && is_callable( array( $this->updater, 'license_is_active' ) ) && ! $this->updater->license_is_active() ) {
			add_action( 'wpo_wcpdf_before_settings_page', array( $this, 'no_active_license_message' ), 1 );
		}

	}

	/**
	 * Displays message if the license is not activated
	 * 
	 * @return void
	 */
	public function no_active_license_message( $active_tab )
	{
		$activation_url = esc_url_raw( add_query_arg( 's', urlencode( 'PDF Invoices & Packing Slips for WooCommerce - Professional' ), network_admin_url( 'plugins.php' ) ) );
		?>
		<div class="notice notice-warning inline">
			<p>
				<?php
					printf(
						/* translators: 1. plugin name, 2. click here */
						__( 'Your license of %1$s has not been activated on this site, %2$s to enter your license key.', 'wpo_wcpdf_pro' ),
						'<strong>'.__( 'PDF Invoices & Packing Slips for WooCommerce - Professional', 'wpo_wcpdf_pro' ).'</strong>',
						'<a href="'.$activation_url.'">'.__( 'click here', 'wpo_wcpdf_pro' ).'</a>'
					);
				?>
			</p>
		</div>
		<?php
	}
	
	/**
	 * Declares WooCommerce HPOS compatibility.
	 *
	 * @return void
	 */
	public function woocommerce_hpos_compatible() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
	
	/**
	 * Load the translation / textdomain files
	 * 
	 * Note: the first-loaded translation file overrides any following ones if the same translation is present
	 */
	public function translations() {
		if ( function_exists( 'determine_locale' ) ) { // WP5.0+
			$locale = determine_locale();
		} else {
			$locale = is_admin() && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
		}
		$locale = apply_filters( 'plugin_locale', $locale, 'woocommerce-pdf-invoices-packing-slips' );
		$dir    = trailingslashit( WP_LANG_DIR );

		/**
		 * Frontend/global Locale. Looks in:
		 *
		 * 		- WP_LANG_DIR/woocommerce-pdf-invoices-packing-slips/wpo_wcpdf_pro-LOCALE.mo
		 * 	 	- WP_LANG_DIR/plugins/wpo_wcpdf_pro-LOCALE.mo
		 * 	 	- woocommerce-pdf-invoices-packing-slips/languages/wpo_wcpdf_pro-LOCALE.mo (which if not found falls back to:)
		 * 	 	- WP_LANG_DIR/plugins/wpo_wcpdf_pro-LOCALE.mo
		 *
		 * WP_LANG_DIR defaults to wp-content/languages
		 */
		if ( current_filter() == 'wpo_wcpdf_reload_attachment_translations' ) {
			unload_textdomain( 'wpo_wcpdf_pro' );
			WC()->countries = new \WC_Countries();
		}
		load_textdomain( 'wpo_wcpdf_pro', $dir . 'woocommerce-pdf-ips-pro/wpo_wcpdf_pro-' . $locale . '.mo' );
		load_textdomain( 'wpo_wcpdf_pro', $dir . 'plugins/wpo_wcpdf_pro-' . $locale . '.mo' );
		load_plugin_textdomain( 'wpo_wcpdf_pro', false, dirname( plugin_basename(__FILE__) ) . '/languages' );
	}

	/**
	 * Load the main plugin classes and functions
	 */
	public function includes() {
		// Plugin classes
		$this->settings    = include_once $this->plugin_path() . '/includes/wcpdf-pro-settings.php';
		$this->functions   = include_once $this->plugin_path() . '/includes/wcpdf-pro-functions.php';
		$this->writepanels = include_once $this->plugin_path() . '/includes/wcpdf-pro-writepanels.php';
		
		// Backwards compatibility with self
		include_once( $this->plugin_path().'/includes/legacy/wcpdf-pro-legacy.php' );
		
		// Multilingual
		foreach ( $this->functions->get_active_multilingual_plugins() as $slug => $plugin ) {
			switch ( $plugin['support'] ) {
				case 'full':
					$this->multilingual_full = include_once $this->plugin_path() . '/includes/wcpdf-pro-multilingual-full.php';
					break;
				case 'html':
					$this->multilingual_html = include_once $this->plugin_path() . '/includes/wcpdf-pro-multilingual-html.php';
					break;
			}
		}

		if ( ! $this->multilingual_full && isset( $this->functions->pro_settings['document_language'] ) ) {
			$avoid_options   = array_keys( $this->functions->multilingual_supported_plugins() );
			$avoid_options[] = 'user';

			if ( ! in_array( $this->functions->pro_settings['document_language'], $avoid_options ) ) {
				$this->multilingual_full = include_once $this->plugin_path() . '/includes/wcpdf-pro-multilingual-full.php';
			}
		}

		// Bulk export
		$this->bulk_export = include_once $this->plugin_path() . '/includes/wcpdf-pro-bulk-export.php';

		// Abstract Cloud API class
		$this->cloud_api        = include_once $this->plugin_path() . '/includes/cloud/abstract-wcpdf-cloud-api.php';
		$cloud_services_enabled = include_once $this->plugin_path() . '/includes/cloud/cloud-services-enabled.php';
		foreach ( $cloud_services_enabled::$services_enabled as $service_slug ) {
			switch ( $service_slug ) {
				case 'dropbox': // Dropbox API
					$this->dropbox_api = include_once $this->plugin_path() . '/includes/cloud/dropbox/dropbox-api.php';
					break;
				case 'ftp': // FTP
					$this->ftp_upload = include_once $this->plugin_path() . '/includes/cloud/ftp/ftp.php';
					break;
				case 'gdrive': // Gdrive API
					$this->gdrive_api = include_once $this->plugin_path() . '/includes/cloud/gdrive/gdrive-api.php';
					break;
			}
		}
		// Cloud Storage class
		$this->cloud_storage = include_once $this->plugin_path() . '/includes/wcpdf-pro-cloud-storage.php';

        // REST API class
        $this->rest = include_once $this->plugin_path() . '/includes/wcpdf-pro-rest.php';
	}
	

	/**
	 * Instantiate classes when woocommerce is activated
	 */
	public function load_classes() {
		if ( $this->dependencies->ready() === false ) {
			return;
		}

		// all systems ready - GO!
		$this->includes();
	}

	/**
	 * Instantiate classes when woocommerce is activated
	 */
	public function load_classes_early() {
		$this->dependencies = include_once( 'includes/wcpdf-pro-dependencies.php' );
		if ( $this->dependencies->ready() === false ) {
			return;
		}

		// all systems ready - GO!
		$this->emails = include_once( $this->plugin_path().'/includes/wcpdf-pro-emails.php' );
	}

	/** Lifecycle methods *******************************************************
	 * Because register_activation_hook only runs when the plugin is manually
	 * activated by the user, we're checking the current version against the
	 * version stored in the database
	****************************************************************************/

	/**
	 * Handles version checking
	 */
	public function do_install() {
		// only install when base plugin is active and up to date
		if ( $this->dependencies->ready() === false ) {
			return;
		}

		$version_setting = 'wpo_wcpdf_pro_version';
		$installed_version = get_option( $version_setting );
		// 1.5.2 and older used wpo_wcpdf_ips_version!
		if ( $installed_version === false ) {
			$installed_version = get_option( 'wpo_wcpdf_ips_version' );
			delete_option( 'wpo_wcpdf_ips_version' );
		}

		// installed version lower than plugin version?
		if ( version_compare( $installed_version, $this->version, '<' ) ) {

			if ( ! $installed_version ) {
				$this->install();
			} else {
				$this->upgrade( $installed_version );
			}

			// new version number
			update_option( $version_setting, $this->version );
		}
	}


	/**
	 * Plugin install method. Perform any installation tasks here
	 */
	protected function install() {
		// set default settings
		$settings_defaults = array(
			'wpo_wcpdf_documents_settings_credit-note' => array(
				'enabled' => 1,
			),
		);
		
		foreach ( $settings_defaults as $option => $defaults ) {
			update_option( $option, $defaults );
		}

		// set customizer defaults for pro documents
		$customizer_settings = get_option( 'wpo_wcpdf_editor_settings', array() );
		if ( function_exists( 'WPO_WCPDF_Templates' ) && ! empty( $customizer_settings ) ) {
			// mark as unsaved to allow overriding
			unset( $customizer_settings['settings_saved'] );
			update_option( 'wpo_wcpdf_editor_settings', $customizer_settings );

			$document_types = array( 'proforma', 'credit-note', 'receipt' );
			foreach ( $document_types as $document_type ) {
				if ( empty( $customizer_settings["fields_{$document_type}_totals"] ) && empty( $customizer_settings["fields_{$document_type}_columns"] ) ) {
					$customizer_settings["fields_{$document_type}_totals"]  = apply_filters( 'wpo_wcpdf_template_editor_defaults', array(), $document_type, 'totals' );
					$customizer_settings["fields_{$document_type}_columns"] = apply_filters( 'wpo_wcpdf_template_editor_defaults', array(), $document_type, 'columns' );
				}
			}
			
			$customizer_settings['settings_saved'] = 1;
			update_option( 'wpo_wcpdf_editor_settings', $customizer_settings );
		}
	}

	/**
	 * Plugin upgrade method. Perform any required upgrades here
	 *
	 * @param string $installed_version the currently installed ('old') version
	 */
	protected function upgrade( $installed_version ) {
		delete_option( 'wpo_wcpdf_pro_upgrade_notice_dismiss' );
		delete_option( 'wpo_wcpdf_pro_upgrade_notice_message' );
		
		// 1.4.0 - set default for new settings
		if ( version_compare( $installed_version, '1.4.0', '<' ) ) {
			$settings_key = 'wpo_wcpdf_pro_settings';
			$current_settings = get_option( $settings_key );
			$new_defaults = array(
				'enable_proforma'	=> 1,
			);
			
			$new_settings = array_merge($current_settings, $new_defaults);

			update_option( $settings_key, $new_settings );
		}

		// 2.0-dev update: reorganize settings
		if ( version_compare( $installed_version, '2.0-dev', '<' ) ) {
			$old_settings = array(
				'wpo_wcpdf_pro_settings'		=> get_option( 'wpo_wcpdf_pro_settings' ),
				'wpo_wcpdf_template_settings'	=> get_option( 'wpo_wcpdf_template_settings' ),
			);

			// combine number formatting in array
			$documents = array( 'proforma', 'credit_note' );
			foreach ($documents as $document) {
				$old_settings['wpo_wcpdf_pro_settings']["{$document}_number_formatting"] = array();
				$format_option_keys = array('padding','suffix','prefix');
				foreach ($format_option_keys as $format_option_key) {
					if (isset($old_settings['wpo_wcpdf_pro_settings']["{$document}_number_formatting_{$format_option_key}"])) {
						$old_settings['wpo_wcpdf_pro_settings']["{$document}_number_formatting"][$format_option_key] = $old_settings['wpo_wcpdf_pro_settings']["{$document}_number_formatting_{$format_option_key}"];
					}
				}
			}

			// convert abbreviated email_ids
			$email_settings = array( 'pro_attach_static', 'pro_attach_credit-note', 'pro_attach_proforma', 'pro_attach_packing-slip' );
			foreach ($email_settings as $email_setting_key) {
				if ( !isset( $old_settings['wpo_wcpdf_pro_settings'][$email_setting_key] ) ) {
					continue;
				}
				foreach ($old_settings['wpo_wcpdf_pro_settings'][$email_setting_key] as $email_id => $value) {
					if ($email_id == 'completed' || $email_id == 'processing') {
						$old_settings['wpo_wcpdf_pro_settings'][$email_setting_key]["customer_{$email_id}_order"] = $value;
						unset($old_settings['wpo_wcpdf_pro_settings'][$email_setting_key][$email_id]);
					}
				}
			}

			// convert old single static file to array
			if ( isset( $old_settings['wpo_wcpdf_pro_settings']['static_file'] ) && isset( $old_settings['wpo_wcpdf_pro_settings']['static_file']['id'] ) ) {
				$old_settings['wpo_wcpdf_pro_settings']['static_file'] = array( $old_settings['wpo_wcpdf_pro_settings']['static_file'] );
			}

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
				${$new_option} = array();
				foreach ($new_settings_keys as $new_key => $old_setting ) {
					$old_key = reset($old_setting);
					$old_option = key($old_setting);
					if (!empty($old_settings[$old_option][$old_key])) {
						${$new_option}[$new_key] = $old_settings[$old_option][$old_key];
					}
				}

				// auto enable credit note
				if ( $new_option == 'wpo_wcpdf_documents_settings_credit-note' ) {
					${$new_option}['enabled'] = 1;
				}

				// auto enable number display
				$enabled = array( 'wpo_wcpdf_documents_settings_proforma', 'wpo_wcpdf_documents_settings_credit-note' );
				if ( in_array( $new_option, $enabled ) ) {
					${$new_option}['display_number'] = 1;
					// echo '<pre>';var_dump(${$new_option});echo '</pre>';die();
				}

				// merge with existing settings
				${$new_option."_old"} = get_option( $new_option, ${$new_option} ); // second argument loads new as default in case the settings did not exist yet
				// echo '<pre>';var_dump(${$new_option."_old"});echo '</pre>';die();
				${$new_option} = ${$new_option} + ${$new_option."_old"}; // duplicate options take new options as default

				// store new option values
				update_option( $new_option, ${$new_option} );
			}

			// copy next numbers to separate options
			$number_map = array(
				'wpo_wcpdf_next_proforma_number'		=> array( 'wpo_wcpdf_pro_settings' => 'next_proforma_number' ),
				'wpo_wcpdf_next_credit_note_number'		=> array( 'wpo_wcpdf_pro_settings' => 'next_credit_note_number' ),
			);
			foreach ($number_map as $number_option => $old_setting) {
				$old_key = reset($old_setting);
				$old_option = key($old_setting);
				if (!empty($old_settings[$old_option][$old_key])) {
					${$number_option} = $old_settings[$old_option][$old_key];
					// store new option values
					update_option( $number_option, ${$number_option} );
				}
			}

			// copy settings fields translations
			$translations = get_option( 'wpo_wcpdf_translations' );
			if ( $translations !== false ) {
				$general_settings = get_option( 'wpo_wcpdf_settings_general' );
				foreach ($translations as $setting => $translations) {
					// settings are stored by HTML form name as key, i.e. wpo_wcpdf_template_settings[shop_name]
					preg_match('/^(.*?)\[(.*?)\]/s',$setting,$matches);
					if ( !empty($matches) && count($matches) == 3 ) {
						$option = $matches[1];
						$option_key = $matches[2];
						if (isset($general_settings[$option_key])) {
							$general_settings[$option_key] = $translations + $general_settings[$option_key];
						} else {
							$general_settings[$option_key] = $translations;
						}
					}
				}
				update_option( 'wpo_wcpdf_settings_general', $general_settings );
			}
			
		}

		// 2.0-beta-2 update: copy next numbers to separate store & convert sequence options
		if ( version_compare( $installed_version, '2.0-beta-2', '<' ) ) {
			// load number store class (just in case)
			include_once( WPO_WCPDF()->plugin_path() . '/includes/documents/class-wcpdf-sequential-number-store.php' );

			// copy next numbers to number store tables
			$number_map = array(
				'proforma_number'		=> 'wpo_wcpdf_next_proforma_number',
				'credit_note_number'	=> 'wpo_wcpdf_next_credit_note_number',
			);
			foreach ($number_map as $store_name => $old_option) {
				$next_number = get_option( $old_option );
				if (!empty($next_number)) {
					$number_store = new \WPO\WC\PDF_Invoices\Documents\Sequential_Number_Store( $store_name );
					$number_store->set_next( (int) $next_number );
				}
				delete_option( $old_option ); // clean up after ourselves
			}

			// convert sequence setting
			// main => invoice_number
			// separate => {$document_slug}_number
			$document_stores = array(
				'wpo_wcpdf_documents_settings_credit-note' => 'credit_note_number',
				'wpo_wcpdf_documents_settings_proforma' => 'proforma_number',
			);
			foreach ($document_stores as $document_option => $number_store_name) {
				$settings = get_option( $document_option, array() );
				if (isset($settings['number_sequence'])) {
					if ($settings['number_sequence'] == 'main' || $settings['number_sequence'] == 'invoice_number' ) { // invoice_number in case this was manually triggered
						$settings['number_sequence'] = 'invoice_number';
					} else { // separate
						$settings['number_sequence'] = $number_store_name;
					}
					update_option( $document_option, $settings );
				}
			}
		}

		// 2.7.0 update: replace and delete legacy Dropbox options
		if ( version_compare( $installed_version, '2.7.0', '<' ) ) {
			// Dropbox legacy settings porting
			if( !empty($legacy_settings = get_option('wpo_wcpdf_dropbox_settings')) ) {
				// update legacy data
				$legacy_settings['cloud_service'] = 'dropbox';
				if( $legacy_settings['access_type'] == 'dropbox' ) {
					$legacy_settings['access_type'] = 'root_folder';
				}

				// check if the legacy Dropbox API settings exist
				if( !empty($legacy_api_settings = get_option('wpo_wcpdf_dropbox_api_v2')) ) {
					// create the new api settings and delete the legacy ones
					update_option('wpo_wcpdf_dropbox_api_settings', $legacy_api_settings);
					delete_option('wpo_wcpdf_dropbox_api_v2');
				}

				// create the new settings and delete the legacy ones
				update_option('wpo_wcpdf_cloud_storage_settings', $legacy_settings);
				delete_option('wpo_wcpdf_dropbox_settings');
			}
		}

		// 2.12.2 update: shipping address setting migration
		if ( version_compare( $installed_version, '2.12.2-dev-1', '<' ) ) {
			$document_types = array( 'proforma', 'credit-note' );
			foreach ( $document_types as $document_type ) {
				$settings = get_option( "wpo_wcpdf_documents_settings_{$document_type}" );
				if ( isset( $settings['display_shipping_address'] ) ) {
					$settings['display_shipping_address'] = 'always';
					update_option( "wpo_wcpdf_documents_settings_{$document_type}", $settings );
				}
			}
		}

		// 2.13.1 update: Polylang static files migration
		if ( version_compare( $installed_version, '2.13.1-dev-1', '<' ) ) {
			$multilingual = false;
			$pro_settings = get_option( 'wpo_wcpdf_settings_pro' );
			
			if ( empty( $this->multilingual_full ) ) {
				return;
			}

			// multilingual 'static_file' migration
			if ( ( class_exists( '\\Polylang' ) || class_exists( '\\TRP_Translate_Press' ) ) && function_exists( 'wpo_wcpdf_get_multilingual_languages' ) ) {
				$multilingual  = true;
				$pll_migration = array();

				foreach ( wpo_wcpdf_get_multilingual_languages() as $slug => $name ) {
					$pll_migration[$slug] = $pro_settings['static_file'];
				}

				$pro_settings['static_file'] = $pll_migration;
			}
			
			// multilingual 'billing_address', 'shipping_address' migration
			if ( ( class_exists( '\\SitePress' ) || class_exists( '\\Polylang' ) || class_exists( '\\TRP_Translate_Press' ) ) && function_exists( 'wpo_wcpdf_get_multilingual_languages' ) ) {
				$multilingual          = true;
				$pro_address_migration = array();

				foreach ( array( 'billing', 'shipping' ) as $type ) {
					foreach ( wpo_wcpdf_get_multilingual_languages() as $slug => $name ) {
						$pro_address_migration[$type][$slug] = $pro_settings[$type.'_address'];
					}
					$pro_settings[$type.'_address'] = $pro_address_migration[$type];
				}
			}

			if ( $multilingual ) {
				update_option( 'wpo_wcpdf_settings_pro', $pro_settings );
			}
		}
		
		// 2.13.6: number sequence bug on Pro documents
		if ( version_compare( $installed_version, '2.13.6', '=' ) ) {
			global $wpdb;
			$documents     = WPO_WCPDF()->documents->get_documents();
			$affected_docs = [];
			foreach ( $documents as $document ) {
				if ( in_array( $document->get_type(), [ 'proforma', 'credit-note' ] ) && $document->is_enabled() && is_callable( [ $document, 'get_number_sequence' ] ) ) {
					$number_sequence = $document->get_number_sequence( '', $document );
					if ( $number_sequence == 'invoice_number' ) {
						$display_date = isset( $document->settings['display_date'] ) ? $document->settings['display_date'] : '';						
						if ( $display_date == 'order_date' ) {
							$affected_docs[] = $document->get_title();
							continue;
						}
						
						$table_name = apply_filters( "wpo_wcpdf_number_store_table_name", "{$wpdb->prefix}wcpdf_{$document->slug}_number", "{$document->slug}_number", WPO_WCPDF()->settings->get_sequential_number_store_method() );
						if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'") == $table_name ) {
							$last_row_date = $wpdb->get_var( "SELECT date FROM {$table_name} ORDER BY id DESC LIMIT 1");
							if ( ! empty( $last_row_date ) && strtotime( $last_row_date ) > strtotime( '2022-12-22 00:00:00' ) ) {
								$affected_docs[] = $document->get_title();
							}
						}
					}
				}
			}
			
			if ( ! empty( $affected_docs ) ) {
				if ( count( $affected_docs ) > 1 ) {
					$docs = sprintf(
						/* translators: 1,2. document names */
						'%1$s and %2$s',
						$affected_docs[0],
						$affected_docs[1]
					);
				} else {
					$docs = $affected_docs[0];
				}
				
				$message  = sprintf(
					/* translators: 1. document name(s) */
					__( 'A bug that got introduced in version <strong>2.13.6</strong> of <u>PDF Invoices & Packing Slips for WooCommerce - Professional</u>, caused the %1$s to start a separate number sequence, instead of following the main Invoice number sequence. This affects users that set their %1$s to follow the main Invoice number sequence.', 'wpo_wcpdf_pro' ),
					$docs
				);
				$message .= '<br><br>';
				$message .= sprintf(
					/* translators: 1. document name(s) */
					__( 'This update fixed the bug, and the %1$s will follow the main Invoice number sequence once more. But we urge you to check the numbers created for these documents from the <u>22nd of December 2022</u> (release date of version 2.13.6) <u>until now</u>, as they might not have the expected document numbers. If so, please make changes to the affected document numbers, and <strong>Next invoice number</strong> setting. If you have any further questions about this, please contact us at: <a href="mailto:support@wpovernight.com">support@wpovernight.com</a>', 'wpo_wcpdf_pro' ),
					$docs
				);
				update_option( 'wpo_wcpdf_pro_upgrade_notice_message', $message );
			}
		}
		
		// 2.13.9: cloud storage upload by order status setting migration
		if ( version_compare( $installed_version, '2.13.9-dev-1', '<' ) ) {
			$cloud_settings = get_option( 'wpo_wcpdf_cloud_storage_settings' );
			if ( ! empty( $cloud_settings ) && isset( $cloud_settings['per_status_upload'] ) ) {
				foreach ( $cloud_settings['per_status_upload'] as $document_type => $upload_status ) {
					$cloud_settings['per_status_upload_'.$document_type] = [ "wc-{$upload_status}" ];
				}
				unset( $cloud_settings['per_status_upload'] );
				update_option( 'wpo_wcpdf_cloud_storage_settings', $cloud_settings );
			}
		}


		// 2.15.4-beta-1: packing slip hide virtual products setting migration
		if ( version_compare( $installed_version, '2.15.4-beta-1', '<' ) ) {
			$packing_slip_settings = WPO_WCPDF()->settings->get_document_settings( 'packing-slip' );

			if ( isset( $packing_slip_settings['hide_virtual_products'] ) && wc_string_to_bool( $packing_slip_settings['hide_virtual_products'] ) ) {
				$packing_slip_settings['hide_virtual_downloadable_products'] = 'virtual_or_downloadable';
				unset( $packing_slip_settings['hide_virtual_products'] );
				update_option( 'wpo_wcpdf_documents_settings_packing-slip', $packing_slip_settings );
			}
		}
		
		// 2.15.6-beta-1: save previous version to see if was affected by the v2.15.4 serious bug, used in `v2_15_4_bug_affected()` function
		if ( version_compare( $installed_version, '2.15.6-beta-1', '<' ) ) {
			update_option( 'wpo_wcpdf_pro_v2_15_4_bug_upgrading_from_version', $installed_version );
		}

		// 2.15.11-beta-1: Remove base due date settings
		if ( version_compare( $installed_version, '2.15.11-beta-1', '<' ) ) {
			$invoice_settings = WPO_WCPDF()->settings->get_document_settings( 'invoice' );
			if ( isset( $invoice_settings['due_date_base_date'] ) ) {
				unset( $invoice_settings['due_date_base_date'] );
				update_option( 'wpo_wcpdf_documents_settings_invoice', $invoice_settings );
			}
		}

		// 2.15.11-beta-1: flush rewrite rules for APIs
		if ( version_compare( $installed_version, '2.15.11-beta-1', '<' ) ) {
			flush_rewrite_rules();
		}
	}
	
	public function upgrade_notice() {
		if ( get_option( 'wpo_wcpdf_pro_upgrade_notice_dismiss' ) !== false ) {
			return;
		} else {
			if ( isset( $_REQUEST['wpo_wcpdf_pro_upgrade_notice_dismiss'] ) && isset( $_REQUEST['_wpnonce'] ) ) {
				// validate nonce
				if ( ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'dismiss_pro_upgrade_notice' ) ) {
					wcpdf_log_error( 'You do not have sufficient permissions to perform this action: wpo_wcpdf_pro_upgrade_notice_dismiss' );
					return;
				} else {
					update_option( 'wpo_wcpdf_pro_upgrade_notice_dismiss', true );
					return;
				}
			}

			$message = get_option( 'wpo_wcpdf_pro_upgrade_notice_message' );
			if ( $message !== false ) {
				?>
				<div class="notice notice-warning wpo-wcpdf-pro-upgrade-notice">
					<p><?= $message; ?></p>
					<p><a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'wpo_wcpdf_pro_upgrade_notice_dismiss', true ), 'dismiss_pro_upgrade_notice' ) ); ?>" class="dismiss-pro-upgrade-notice"><?php esc_html_e( 'Hide this message', 'wpo_wcpdf_pro' ); ?></a></p>
				</div>
				<script type="text/javascript">
					jQuery( function( $ ) {
						$( '.wpo-wcpdf-pro-upgrade-notice' ).on( 'click', '.dismiss-pro-upgrade-notice', function( event ) {
							event.preventDefault();
							window.location.href = $( this ).attr( 'href' );
						});
					} );
				</script>
				<?php
			}
		}
	}

	/**
	 * Get the plugin url.
	 * @return string
	 */
	public function plugin_url() {
		return untrailingslashit( plugins_url( '/', __FILE__ ) );
	}

	/**
	 * Get the plugin path.
	 * @return string
	 */
	public function plugin_path() {
		return untrailingslashit( plugin_dir_path( __FILE__ ) );
	}

} // class WooCommerce_PDF_IPS_Pro

endif; // class_exists

/**
 * Returns the main instance of the plugin to prevent the need to use globals.
 *
 * @since  2.0
 * @return WooCommerce_PDF_IPS_Pro
 */
function WPO_WCPDF_Pro() {
	return WooCommerce_PDF_IPS_Pro::instance();
}

// Load Professional
WPO_WCPDF_Pro();
<?php
namespace WPO\WC\PDF_Invoices_Pro;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
if ( ! class_exists( '\\WPO\\WC\\PDF_Invoices_Pro\\Dependencies' ) ) {

	class Dependencies {

		/**
		 * @var string
		 */
		public $php_version = '7.4';

		/**
		 * @var string
		 */
		public $woocommerce_version = '3.3';

		/**
		 * @var string
		 */
		public $base_plugin_version = '4.0.0';

		/**
		 * @var string
		 */
		public $rest_api_wp_min_version = '5.6';

		/**
		 * @var string
		 */
		public $base_plugin_slug = 'woocommerce-pdf-invoices-packing-slips/woocommerce-pdf-invoices-packingslips.php';

		/**
		 * Check if a certain plugin is installed on the website.
		 * 
		 * @param string $plugin        The plugin we're looking for.
		 * @param bool   $partial_match whether or not to return partial matches
		 * @return array|bool           Representing the plugin data
		 */
		public function get_plugin( $plugin, $partial_match = false ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$installed_plugins = get_plugins();
			// check for full matches first
			foreach ( $installed_plugins as $slug => $plugin_data ) {
				if ( $slug == $plugin && ! $this->is_plugin_deactivation_request( $slug ) ) {
					$plugin_data['partial_match'] = false;
					$plugin_data['slug']          = $slug;

					return $plugin_data;
				}
			}

			// check for partial match if enabled
			if ( $partial_match ) {
				foreach ( $installed_plugins as $slug => $plugin_data ) {
					if ( basename( $slug ) == basename( $plugin ) && ! $this->is_plugin_deactivation_request( $slug ) ) {
						$plugin_data['partial_match'] = true;
						$plugin_data['slug']          = $slug;

						return $plugin_data;
					}
				}
			}

			// no matches
			return false;
		}

		/**
		 * Checks if a plugin deactivation request is running
		 * 
		 * @param  string $slug  The plugin slug, eg. 'woocommerce/woocommerce.php'
		 * @return bool
		 */
		public function is_plugin_deactivation_request( $slug ) {
			if ( empty( $slug ) || ! isset( $_REQUEST['action'] ) || ! isset( $_REQUEST['plugin'] ) ) {
				return false;
			}

			if ( $_REQUEST['action'] == 'deactivate' && $_REQUEST['plugin'] == $slug ) {
				return true;
			}

			return false;
		}

		/**
		 * Check if the base plugin is ready to be loaded
		 * 
		 * @return boolean. Send notice(s) before returning.
		 */
		public function ready() {
			if ( version_compare( PHP_VERSION, $this->php_version, '<' ) ) {
				add_action( 'admin_notices', array ( $this, 'required_php_version' ) );
				return false;
			}

			if ( $this->is_woocommerce_activated() === false ) {
				add_action( 'admin_notices', array ( $this, 'need_woocommerce_notice' ) );
				return false;
			}
			
			if ( version_compare( WC_VERSION, $this->woocommerce_version, '<' ) ) {
				add_action( 'admin_notices', array ( $this, 'update_woocommerce_notice' ) );
				return false;
			}

			$base_plugin = $this->get_plugin( $this->base_plugin_slug, true );
			
			if ( $base_plugin !== false ) {
				// plugin installed but version too low
				if ( class_exists( 'WPO_WCPDF' ) && version_compare( $base_plugin['Version'], $this->base_plugin_version, '<' ) ) {
					add_action( 'admin_notices', array ( $this, 'base_plugin_upgrade_requirement' ) );
					return false;
				// plugin isn't active
				} elseif ( ! class_exists( 'WPO_WCPDF' ) ) { 
					add_action( 'admin_notices', array ( $this, 'base_plugin_activate_requirement' ) );
					return false;
				// there's no issue
				} else { 
					return true;
				}
			} else { 
				// plugin isn't installed
				add_action( 'admin_notices', array ( $this, 'base_plugin_install_requirement' ) );
				return false;
			}
		}

		/**
		 * PHP version requirement notice
		 * 
		 * @return void
		 */
		public function required_php_version() {
			/* translators: php version */
			$error         = sprintf( __( 'PDF Invoices & Packing Slips for WooCommerce - Professional requires PHP %s or higher.', 'wpo_wcpdf_pro' ), $this->php_version ); 
			$how_to_update = __( 'How to update your PHP version', 'wpo_wcpdf_pro' ); 
			$message       = sprintf( '<div class="notice notice-error"><p>%s</p><p><a href="%s">%s</a></p></div>', $error, 'http://docs.wpovernight.com/general/how-to-update-your-php-version/', $how_to_update ); 
		 
			echo $message; 
		}

		/**
		 * Check if woocommerce is activated
		 * 
		 * @return bool
		 */
		public function is_woocommerce_activated() {
			$slug         = 'woocommerce/woocommerce.php';
			$fetch_plugin = $this->get_plugin( $slug, true );
			
			if ( $fetch_plugin !== false && function_exists( 'WC' ) ) { 
				return true;
			}

			return false; 
		}

		/**
		 * WooCommerce not active notice.
		 *
		 * @return void
		 */
		public function need_woocommerce_notice() {
			/* translators: <a> tags */
			$error   = sprintf( __( 'PDF Invoices & Packing Slips for WooCommerce - Professional requires %1$sWooCommerce%2$s to be installed & activated!' , 'wpo_wcpdf_pro' ), '<a href="https://wordpress.org/plugins/woocommerce/">', '</a>' );
			$message = '<div class="notice notice-error"><p>' . $error . '</p></div>';
			echo $message;
		}

		/**
		 * WooCommerce not up-to-date notice.
		 *
		 * @return void
		 */
		public function update_woocommerce_notice() {
			/* translators: 1: WooCommerce version, 2 & 3: <a> tags */
			$error   = sprintf( __( 'PDF Invoices & Packing Slips for WooCommerce - Professional requires at least version %1$s of WooCommerce to be installed. %2$sGet the latest version here%3$s!' , 'wpo_wcpdf_pro' ), $this->woocommerce_version, '<a href="https://wordpress.org/plugins/woocommerce/">', '</a>' );
			$message = '<div class="notice notice-error"><p>' . $error . '</p></div>';
			echo $message;
		}

		/**
		 * Base Plugin notice: not installed.
		 *
		 * @return void
		 */
		public function base_plugin_install_requirement() {
			$latest_version_url = 'https://wordpress.org/plugins/woocommerce-pdf-invoices-packing-slips/';

			/* translators: 1: base plugin version, 2 & 3: <a> tags */
			$error   = sprintf( __( 'PDF Invoices & Packing Slips for WooCommerce - Professional requires at least version %1$s of PDF Invoices & Packing Slips for WooCommerce -  %2$sget it here%3$s!' , 'wpo_wcpdf_pro' ), $this->base_plugin_version, '<a href="' . $latest_version_url . '" target="_blank" >', '</a>' );
			$message = '<div class="notice notice-error"><p>' . $error . '</p></div>';
			echo $message;
		}
		
		/**
		 * Base plugin notice: installed but not activated.
		 *
		 * @return void
		 */
		public function base_plugin_activate_requirement() {
			$plugin_admin_url = wp_nonce_url( esc_url_raw( network_admin_url( 'plugins.php?action=activate&plugin='.$this->base_plugin_slug ) ), 'activate-plugin_'.$this->base_plugin_slug );

			/* translators: <a> tags */
			$error   = sprintf( __( 'PDF Invoices & Packing Slips for WooCommerce - Professional requires the free base plugin to be activated! %1$sActivate it here!%2$s' , 'wpo_wcpdf_pro' ), '<a href="' . $plugin_admin_url . '" >', '</a>' );
			$message = '<div class="notice notice-error"><p>' . $error . '</p></div>';
			echo $message;
		}		

		/**
		* Base Plugin notice: below version 2.10.2+. 
		*
		* @return void
		*/
		public function base_plugin_upgrade_requirement() {
			$plugin_admin_url = esc_url_raw( network_admin_url( 'plugins.php?s=WooCommerce+PDF+Invoices' ) );

			/* translators: 1: base plugin version, 2 & 3: <a> tags  */
			$error   = sprintf( __( 'PDF Invoices & Packing Slips for WooCommerce - Professional requires at least version %1$s of PDF Invoices & Packing Slips for WooCommerce. %2$sUpgrade to the latest version here%3$s!' , 'wpo_wcpdf_pro' ), $this->base_plugin_version, '<a href="' . $plugin_admin_url . '" >', '</a>' );
			$message = '<div class="notice notice-error"><p>' . $error . '</p></div>';
			echo $message;
		}

		/**
		 * Check if the WordPress version meets the minimum requirement for REST API.
		 *
		 * @return bool
		 */
		public function is_rest_api_supported(): bool {
			return version_compare( get_bloginfo( 'version' ), $this->rest_api_wp_min_version, '>=' );
		}

	} // end class
} // end class_exists()

return new Dependencies();
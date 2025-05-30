<?php
/**
 * Plugin Name: WP Overnight Updater
 * Text Domain: wpo-updater
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

if ( class_exists( 'WPO_Update_Helper' ) ) {
	return;
}

class WPO_Update_Helper {
	public $version = '2.1.6';

	private $wpo_update_api;
	private $plugin_item_name;
	private $plugin_file;
	private $plugin_basename;
	private $plugin_slug;
	private $plugin_license_slug;
	private $plugin_version;
	private $plugin_author;
	private $license_keys_option_name;
	private $license_key;
	private $edd_updater;

	// Constant containing all WPO plugins that have built-in updater, with the version when the updater was introduced.
	private const WPO_UPDATER_PLUGINS_WITH_UPDATER = array(
		'woocommerce-product-batch-numbers/woocommerce-product-batch-numbers.php' => '1.1.5',
		'woocommerce-pdf-ips-templates/woocommerce-pdf-ips-templates.php'         => '2.13.1',
		'woocommerce-opening-hours/opening-hours.php'                             => '1.15.25',
		'woocommerce-printnode/print-orders.php'                                  => '1.7.3',
		'woocommerce-customer-manager/woocommerce-customer-manager.php'           => '2.3.0',
		'improved-external-products-pro/wc-improved-external-products-pro.php'    => '1.6.0',
		'woocommerce-next-order-coupon/woocommerce-next-order-coupon.php'         => '1.7.2',
		'wp-menu-cart-pro/wp-menu-cart-pro.php'                                   => '3.5.0',
		'wc-bulk-order-form-pro/woocommerce-bulk-order-form-pro.php'              => '3.2.0',
		'woocommerce-pdf-ips-pro/woocommerce-pdf-ips-pro.php'                     => '2.9.2',
		'woocommerce-bulk-csv-stock-updater/woocommerce-bulk-stock-updater.php'   => '2.1.2',
		'woocommerce-ultimate-barcodes/woocommerce-ultimate-barcodes.php'         => '1.0.0',
		'woocommerce-address-labels/woocommerce-address-labels.php'               => '1.6.9',
		'woocommerce-order-list/woocommerce-order-list.php'                       => '1.6.5',
		'woocommerce-eu-vat-compliance-premium/eu-vat-compliance-premium.php'     => '1.25.10',
		'wc-postcode-checker/wc-postcode-checker.php'                             => '2.5.6',
		'wc-reminder-emails/wc-reminder-emails.php'                               => '2.2.5',
		'woocommerce-order-proposal/woocommerce-order-proposal.php'               => '1.7.19',
		'wc-bulk-order-form-prepopulated/wc-bulk-order-form-prepopulated.php'     => '3.1.5',
		'woocommerce-product-serialnumbers/woocommerce-product-serialnumbers.php' => '1.1.16',
		'woocommerce-manual-products/woocommerce-manual-products.php'             => '1.3.0',
		'woocommerce-minima-and-maxima/woocommerce-minima-and-maxima.php'         => '1.7.4',
	);

	public function __construct( $_item_name, $_file, $_license_slug, $_version, $_author ) {
		$this->wpo_update_api           = apply_filters( 'wpovernight_api_url', 'https://wpovernight.com/license-api/' );
		$this->plugin_item_name         = $_item_name;
		$this->plugin_file              = $_file;
		$this->plugin_basename          = plugin_basename( $_file );
		$this->plugin_slug              = dirname( $this->plugin_basename );
		$this->plugin_license_slug      = $_license_slug;
		$this->plugin_version           = $_version;
		$this->plugin_author            = $_author;
		$this->license_keys_option_name = 'wpocore_settings';
		$this->license_key              = $this->get_license_key();

		if ( ! class_exists( '\\WPO\\EDD_SL_Plugin_Updater' ) ) {
			// load the EDD updater class
			include dirname( __FILE__ ) . '/EDD_SL_Plugin_Updater.php';
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );
		
		add_filter( 'edd_sl_plugin_updater_api_params', array( $this, 'set_url_for_version_request' ), 10, 3 );

		$this->load_edd_updater();

		add_action( 'admin_init', array( $this, 'disable_subsite_update_notifications' ) );

		add_action( 'wp_ajax_wpo_updater_licence_key_action_'.$this->plugin_license_slug, array( $this, 'ajax_remote_license_actions' ) );
		add_filter( 'http_response', array( $this, 'unauthorized_response'), 10, 3 );

		add_filter( 'network_admin_plugin_action_links_' . $this->plugin_basename, array( $this, 'add_license_activation_link' ), 10, 4 );
		add_filter( 'plugin_action_links_' . $this->plugin_basename, array( $this, 'add_license_activation_link' ), 10, 4 );
		
		add_action( 'after_plugin_row_' . $this->plugin_basename, array( $this, 'plugin_license_field' ), 10, 3 );

		add_action( 'admin_enqueue_scripts', array( $this, 'scripts_styles' ) );
		
		add_filter( 'http_request_args', array( $this, 'exclude_plugin_from_wp_api' ), 10, 2 );

		add_action( 'admin_notices', array( $this, 'maybe_show_sidekick_notice' ) );
	}

	/**
	 * Shows the Sidekick uninstall notice if needed.
	 *
	 * @return void
	 */
	public function maybe_show_sidekick_notice(): void {
		static $wpo_updater_sidekick_uninstall_persistent_notice_shown = false;

		if ( isset( get_plugins()['wpovernight-sidekick/wpovernight-sidekick.php'] ) && 0 === $this->plugins_with_lower_version_or_sidekick() ) {
			$message = sprintf(
				/* translators: %s: plugin name */
				__( 'The %s plugin is now redundant since all of your installed WP Overnight plugins have built-in updaters.', 'wpo-updater' ),
				'<strong>WP Overnight Sidekick</strong>'
			) . ' ';

			if ( class_exists( 'WPO_Updater' ) ) {
				deactivate_plugins( 'wpovernight-sidekick/wpovernight-sidekick.php' );
				$message .= sprintf(
					/* translators: %s: plugin name */
					__( "We've deactivated %s, and you can safely delete it.", 'wpo-updater' ),
					'<strong>Sidekick</strong>'
				) . ' ';
			}

			$message .= sprintf(
				/* translators: %s: here link. */
				__( 'More information on managing your license with the built-in updater is available %s.', 'wpo-updater' ), 
				'<a target="_blank" href="https://docs.wpovernight.com/general/installing-wp-overnight-plugins/#activating-your-license">' . __( 'here', 'wpo-updater' ) . '</a>'
			);

			if ( $wpo_updater_sidekick_uninstall_persistent_notice_shown ) {
				return;
			}
			
			if ( is_admin() && ! get_option( 'wpo_updater_sidekick_uninstall_persistent_notice' ) ) {
				?>
				<div class="notice notice-warning">
					<p><?php echo $message; ?></p>
					<p><a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'wpo_updater_sidekick_uninstall_persistent_notice', 'true' ), 'wpo_updater_sidekick_uninstall_persistent_notice_nonce' ) ); ?>"><?php _e( 'Hide this message', 'wpo-updater' ); ?></a></p>
				</div>
				<?php
			}

			$wpo_updater_sidekick_uninstall_persistent_notice_shown = true;
		}
		
		if ( isset( $_REQUEST['wpo_updater_sidekick_uninstall_persistent_notice'] ) && isset( $_REQUEST['_wpnonce'] ) ) {
			if ( wp_verify_nonce( $_REQUEST['_wpnonce'], 'wpo_updater_sidekick_uninstall_persistent_notice_nonce' ) ) {
				update_option( 'wpo_updater_sidekick_uninstall_persistent_notice', true );
			}
			wp_redirect( 'plugins.php' );
			exit;
		}
	}

	/**
	 * Returns the number of plugins that have activated version lower than defined in the array or the plugins that have Sidekick.
	 *
	 * @return int
	 */
	private function plugins_with_lower_version_or_sidekick(): int {
		$count       = 0;
		$all_plugins = get_plugins();
	
		foreach ( self::WPO_UPDATER_PLUGINS_WITH_UPDATER as $plugin => $version ) {
			if ( isset( $all_plugins[ $plugin ] ) ) {
				$plugin_data = $all_plugins[ $plugin ];

				if ( isset( $plugin_data['Version'] ) && version_compare( $plugin_data['Version'], $version, '<' ) ) {					
					$count++;
				}
			}
		}
	
		return $count;
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'wpo-updater', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	public function scripts_styles( $hook ) {
		if ( $hook === 'plugins.php' && ! wp_script_is( 'wpo-update-helper', 'enqueued' ) ) {
			wp_enqueue_style(
				'wpo-update-helper',
				plugin_dir_url( __FILE__ ) . 'assets/style.css',
				array(),
				$this->version
			);

			wp_enqueue_script(
				'wpo-update-helper',
				plugin_dir_url( __FILE__ ) . 'assets/script.js',
				array( 'jquery' ),
				$this->version
			);

			wp_localize_script(
				'wpo-update-helper',
				'wpo_update_helper',
				array(
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'wpo_update_helper' ),
				)
			);
		}
	}

	public function add_license_activation_link( $actions, $plugin_file = '', $plugin_data = array(), $context = '' ) {
		if ( $this->should_show_license_manager() ) {
			$actions['wpo_license'] = sprintf(
				'<a class="wpo-license-registration-toggle" data-edd_action="show_license" data-plugin_license_slug="%1$s" data-plugin_slug="%2$s" id="%2$s-manage-license">%3$s</a>',
				esc_attr( $this->plugin_license_slug ),
				esc_attr( $this->plugin_slug ),
				esc_html__( 'Manage license', 'wpo-updater' )
			);
		}
		return $actions;
	}

	public function plugin_license_field( $file, $plugin, $status = '' ) {
		if ( ! $this->should_show_license_manager() ) {
			return;
		}
		?>
		<tr class="plugin-update-tr active wpo-license-row-<?php echo esc_attr( $this->plugin_slug ); ?>">
			<td colspan="4" class="plugin-update colspanchange wpo-update-helper" data-plugin_license_slug="<?php echo esc_attr( $this->plugin_license_slug ); ?>">
				<?php wp_nonce_field( 'wpo_update_helper', 'wpo_update_helper_nonce' ); ?>
				<?php
				if ( empty( $this->license_key ) ) {
					$this->plugin_license_field_content();
				} else {
					?>
					<div class="license-data"></div>
					<?php
				}
				?>
			</td>
		</tr>
		<?php
	}

	public function should_show_license_manager() {
		if ( is_plugin_active_for_network( $this->plugin_basename ) ) {
			// multisite & network active: show only on network admin
			$show = is_network_admin(); 
		} else {
			// single site OR multisite and not active on network: only show on the subsite
			$show = ! is_network_admin();
		}
		return apply_filters( 'wpo_updater_show_license_manager', $show, $this->plugin_slug );
	}

	public function plugin_license_field_content( $action_args = array() ) {
		$name = "wpocore_settings[{$this->plugin_license_slug}]";
		if ( array_key_exists( 'license_key', $action_args ) ) {
			$this->license_key = $action_args['license_key'];
		}

		if ( empty( $action_args ) ) {
			if ( ! empty( $this->license_key ) ) {
				$action_args = array(
					'edd_action'  => 'check_license',
					'license_key' => trim( $this->license_key ),
				);
			} else {
				$action_args = array();
			}
		}

		$status = $this->remote_license_actions( $action_args );

		if ( is_object( $status ) && isset( $status->license ) ) {
			$activation_status = $status->license;
		} elseif ( is_string( $status ) ) {
			$activation_status = $status;
		} else {
			$activation_status = '';
		}
		if ( empty( $this->license_key ) ) {
			$notice_class = 'notice-warning notice-alt';
		} else {
			$notice_class = 'notice-alt';
		}
		
		// allow setting license field to password
		if ( apply_filters( 'wpo_update_helper_shield_license_field', false ) ) {
			$license_field_type = 'password';
		} else {
			$license_field_type = 'text';
		}
		?>
		<div class="notice inline <?php echo esc_attr( $notice_class ); ?> license-data">
			<p class="wpo-license-status-<?php echo esc_attr( $activation_status ); ?>">
				<?php if ( empty( $this->license_key ) ): ?>
				<p><label for="<?php echo esc_attr( $name ); ?>"><?php esc_attr_e( 'Enter your license key to receive plugin updates', 'wpo-updater' ) ?>:</label></p>
				<?php endif ?>
				<span class="state-indicator">
					<input type="<?php echo $license_field_type; ?>" size="40" class="wpo-license-key" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo $this->license_key; ?>" />
					<span class="license-state"><?php echo ! empty( $status->license_state_message ) ? esc_html( $status->license_state_message ) : ''; ?></span>
				</span>
				<?php if ( $activation_status == 'valid' ): ?>
					<span class="button secondary deactivate" data-edd_action="deactivate_license"><?php esc_html_e( 'Deactivate', 'wpo-updater' ); ?></span>
				<?php else: ?>
					<span class="button secondary activate" data-edd_action="activate_license"><?php esc_html_e( 'Activate', 'wpo-updater' ); ?></span>
				<?php endif ?>
				<?php if ( $activation_status === 'expired' ): ?>
					<a href="https://wpovernight.com/my-account/license-keys/" target="_blank"><span class="button secondary"><?php esc_html_e( 'Renew your license', 'wpo-updater' ); ?></span></a>
				<?php endif ?>
				<?php if ( ! empty( $status->action_message ) ): ?>
					<div class="activation-toggle-message">
						<?php echo wp_kses_post( $status->action_message ); ?>
					</div>
				<?php endif ?>
				<p class="license-info">
					<?php echo ! empty( $status->license_info ) ? wp_kses_post( $status->license_info ) : ''; ?>
				</p>
			</p>
		</div>

		<?php
	}

	public function license_is_active() {
		if( empty( $this->license_key ) ) {
			return false;
		} else {
			return true;
		}
	}

	/**
	 * Load the EDD Updater class
	 */
	public function load_edd_updater() {
		// setup the updater
		if ( ! empty( $this->license_key ) ) {
			$this->edd_updater = new \WPO\EDD_SL_Plugin_Updater( $this->wpo_update_api, $this->plugin_file, array( 
					'version' 	=> $this->plugin_version,   // current version number
					'license' 	=> $this->license_key,      // license key (used get_option above to retrieve from DB)
					'item_name' => $this->plugin_item_name, // name of this plugin
					'author' 	=> $this->plugin_author     // author of this plugin
				)
			);
		}
	}

	/**
	 * Don't show update notifications on subsites when plugin is network activated
	 */	
	public function disable_subsite_update_notifications() {
		if ( is_multisite() && ! is_network_admin() && is_plugin_active_for_network( $this->plugin_basename ) && ! empty( $this->edd_updater ) && method_exists( $this->edd_updater, 'show_update_notification' ) ) {
			remove_action( 'after_plugin_row', array( $this->edd_updater, 'show_update_notification' ) );
		}
	}

	public function ajax_remote_license_actions() {
		check_ajax_referer( "wpo_update_helper", 'security' );

		if ( empty( $_POST['remote_edd_action'] ) ) {
			return;
		}

		if ($_POST['remote_edd_action'] == 'show_license') {
			$args = array(
				'edd_action'  => 'check_license',
				'license_key' => trim( $this->license_key ),
			);
		} else {
			$args = array(
				'edd_action'  => $_POST['remote_edd_action'],
				'license_key' => trim( $_POST['license_key'] ),
			);
		}

		ob_start();
		$this->plugin_license_field_content( $args );
		$html = ob_get_clean();

		wp_send_json( array('html' => $html ) );
	}

	/************************************
	* Activate/deactivate license key
	*************************************/

	public function remote_license_actions( $args ) {
		if ( empty( $args['edd_action'] ) ) {
			return;
		}

		// retrieve the license from the database
		$this->save_license_key( $args['license_key'] );

		if ( empty( $args['license_key'] ) ) {
			$response = array(
				'action_message'        => __( 'Please enter a license key.', 'wpo-updater' ),
				'license_info'          => '',
				'license_state'         => 'incomplete',
				'license_state_message' => __( 'invalid', 'wpo-updater' ),
			);

			return (object) $response;
		}

		// data to send in our API request
		$api_params = array(
			'edd_action' => $args['edd_action'],
			'license' 	 => $args['license_key'],
			'item_name'  => urlencode( $this->plugin_item_name ), // the name of our product in EDD
			'url'        => $this->get_home_url(), // License server has a fallback to the User-Agent, but this could have been filtered so we can't rely on it
		);

		$response = $this->edd_api_action( $api_params );

		if ( isset( $response->wp_error ) ) {
			/* translators: 1. error message, 2. error code, 3. host IP  */
			$message = sprintf( __( 'API Error: %1$s (%2$s), IP: %3$s', 'wpo-updater' ), $response->wp_error, $response->error_code, $this->get_host_ip() );
			$response->action_message        = $message;
			$response->license_state         = 'incomplete';
			$response->license_state_message = __( 'unknown', 'wpo-updater' );
			$response->license_info          = '-';
			return $response;
		}

		if ( isset( $response->expires ) ) {
			if ( $response->expires == 'lifetime' ) {
				$exp_date = __( 'Forever', 'wpo-updater' );
			} else {
				$exp_date = date_i18n( get_option( 'date_format' ), strtotime( $response->expires ) );
			}
		}

		switch ( $response->license ) {
			case 'valid':
				if ( $args['edd_action'] == 'activate_licese' ) {
					$response->action_message    = __( 'Activated your license for this site.', 'wpo-updater' );
				}
				$response->license_state         = 'valid';
				$response->license_state_message = __( 'valid', 'wpo-updater' );
				/* translators: 1. expiration date, 2. active sites count, 3. license limit count  */
				$response->license_info          = sprintf( __( 'This license is valid until: %1$s (Active sites: %2$s / %3$s)', 'wpo-updater' ), $exp_date, $response->site_count, $response->license_limit );
				break;
			case 'deactivated':
				// get activation count & limit
				$api_params = array(
					'edd_action'=> 'check_license',
					'license' 	=> $args['license_key'],
					'item_name' => urlencode( $this->plugin_item_name ), // the name of our product in EDD
				);
				$check_response = $this->edd_api_action( $api_params );
				$exp_date = date_i18n( get_option( 'date_format' ), strtotime( $check_response->expires ) );
				$license_limit = $check_response->license_limit;
				$site_count = $check_response->site_count;

				$response->action_message        = __( 'Deactivated your license for this site.', 'wpo-updater' );
				$response->license_state         = 'invalid';
				$response->license_state_message = __( 'deactivated', 'wpo-updater' );
				$response->license_info          = sprintf( __( 'This license is valid until: %1$s (Active sites: %2$s / %3$s)', 'wpo-updater' ), $exp_date, $site_count, $license_limit );
				break;
			case 'expired':
				$response->license_state         = 'invalid';
				$response->license_state_message = __( 'expired', 'wpo-updater' );
				$response->license_info          = __( 'This license was valid until: ' . $exp_date, 'wpo-updater' );
				break;
			case 'inactive':
			case 'site_inactive':
				$response->license_state         = 'valid';
				$response->license_state_message = __( 'valid', 'wpo-updater' );
				$response->license_info          = sprintf( __( 'This license is valid until: %1$s (Active sites: %2$s / %3$s)', 'wpo-updater' ), $exp_date,  $response->site_count, $response->license_limit );
				break;
			case 'failed':
				$response->action_message        = __( 'Deactivated your license for this site.', 'wpo-updater' );
				$response->license_state         = 'invalid';
				$response->license_state_message = __( 'invalid', 'wpo-updater' );
				$response->license_info          = '';
				break;
			case 'invalid':
				$error = ! empty( $response->error ) ? $response->error : '';
				if ( $error == 'missing' ) {
					$response->action_message        = __( 'Your license key was incorrect.', 'wpo-updater' );
					$response->license_state         = 'incomplete';
					$response->license_state_message = __( 'invalid', 'wpo-updater' );
					$response->license_info          = __( 'Please enter the correct license key.', 'wpo-updater' );
				} elseif ( $error == 'expired' ) {
					$response->action_message        = __( 'Your license key is expired.', 'wpo-updater' );
					$response->license_state         = 'incomplete';
					$response->license_state_message = __( 'expired', 'wpo-updater' );
					/* translators: expiration date */
					$response->license_info          = sprintf( __( 'This license was valid until: %s', 'wpo-updater' ), $exp_date );
				} elseif ($error == 'no_activations_left') {
					/* translators: <a> tags */
					$response->action_message        = sprintf( __( '<strong>No Activations Left</strong> &mdash; Please visit %1$sMy Account%2$s to upgrade your license or deactivate a previous activation.', 'wpo-updater' ), '<a href="https://wpovernight.com/my-account/" target="_blank">', '</a>' );
					$response->license_state         = 'incomplete';
					$response->license_state_message = __( 'invalid', 'wpo-updater' );
					$response->license_info          = '';
				} else {
					$response->action_message        = __( 'Please enter the correct license key.', 'wpo-updater' );
					$response->license_info          = '';
					$response->license_state         = 'incomplete';
					$response->license_state_message = __( 'invalid', 'wpo-updater' );
				}
				break;
			default:
				break;
		}

		return $response;
	}

	public function edd_api_action( $api_params ) {
		// Call the WPO API.
		$response = wp_remote_get( esc_url_raw( add_query_arg( $api_params, $this->wpo_update_api ) ), array( 'timeout' => 15, 'sslverify' => true ) );

		// make sure the response came back okay
		if ( is_wp_error( $response ) ) {
			$error_response = new stdClass();
			$error_response->wp_error = $response->get_error_message();
			$error_response->error_code = $response->get_error_code();
			return $error_response;
		}
		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
			$error_response = new stdClass();
			$error_body = wp_remote_retrieve_body( $response );
			if ( strpos( $error_body, 'Wordfence' ) !== false ) {
				$error_response->wp_error = __( 'Your site host is blocked by the Wordfence network, please contact support@wpovernight.com with this error message.', 'wpo-updater' );
			} else {
				$error_response->wp_error = esc_html( sanitize_text_field( strip_tags( $error_body ) ) );
			}

			$error_response->error_code = wp_remote_retrieve_response_code( $response );
			return $error_response;
		}
		// decode the license data
		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return $response;
	}

	public function unauthorized_response( $response, $args, $url ) {
		if ( ! is_wp_error( $response ) && is_array( $response ) && isset( $response['response'] ) && is_array( $response['response'] ) ) {
			if ( isset( $response['response']['code'] ) && $response['response']['code'] == 401 ) {
				// we have a 401 response, check if it's ours
				$license_server = 'https://wpovernight.com';
				if ( strpos( $url, $license_server ) !== false && strpos( $url, 'package_download' ) !== false ) {
					// this is our request

					// extract values from token
					$url_parts = parse_url( $url );
					$paths     = array_values( explode( '/', $url_parts['path'] ) );
					$token  = end( $paths );
					$values = explode( ':', base64_decode( $token ) );
					if ( count( $values ) !== 6 ) {
						$response['response']['message'] = __( 'Invalid token supplied', 'wpo-updater' );
						return $response;
					}
					$expires        = $values[0];
					$license_key    = $values[1];
					$download_id    = (int) $values[2];
					$url            = str_replace( '@', ':', $values[4] );
					$download_beta  = (bool) $values[5];

					// Check_license response with the above vars
					// data to send in our API request
					$api_params = array(
						'edd_action' => 'check_license',
						'url'		=> $url,
						'license' 	=> $license_key,
						'item_id'	=> $download_id,
					);

					if ( $check_response = $this->edd_api_action( $api_params ) ) {
						if ( isset( $check_response->license ) ) {
							switch( $check_response->license ) {
								case 'expired':
									$message = __( 'Your license has expired, please renew it to install this update.', 'wpo-updater' );
									break;
								case 'inactive':
								case 'site_inactive':
									/* translators: site URL */
									$message = sprintf( __( 'Your license has not been activated for this site (%s), please activate it first.', 'wpo-updater' ), str_replace( array('http://','https://'), '', $url ) );
									break;
								case 'disabled':
									$message = __( 'Your license has been disabled.', 'wpo-updater' );
									break;
								case 'valid':
									$message = "";
									break;
								default:
									$message = __( 'Your license could not be validated.', 'wpo-updater' );
									break;
							}
						} elseif ( isset( $check_response->wp_error ) ) {
							$message = sprintf( __( 'API Error: %1$s (%2$s), IP: %3$s', 'wpo-updater' ), $check_response->wp_error, $check_response->error_code, $this->get_host_ip() );
						}
					} else {
						$message = __( 'License key expired or not activated for URL', 'wpo-updater' );
					}

					$response['response']['message'] = esc_html( $message );

					return $response;

				}
			}
		}
		return $response;
	}

	public function get_host_ip() {
		$response = wp_remote_get( 'https://icanhazip.com', array( 'timeout' => 15, 'sslverify' => true ) );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			$ip = __( 'unknown', 'wpo-updater' );
		} else {
			$ip = wp_remote_retrieve_body( $response );
			// get v4 if we only have v6
			if ( strpos( $ip, ':' ) !== false ) {
				$response = wp_remote_get( 'https://ipv4.icanhazip.com', array( 'timeout' => 15, 'sslverify' => true ) );
				if ( !is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) == 200 ) {
					$ipv4 = wp_remote_retrieve_body( $response );
					$ip = "{$ip} / {$ipv4}";
				}
			}
		}
		return $ip;
	}

	/**
	 * Sets the URL parameter sent in the API request that gets the current version information,
	 * so that it matches what we pass during activation
	 *
	 * @since 2.1.2
	 * @param array  $api_params  The array of data sent in the request.
	 * @param array  $api_data    The array of data set up in the class constructor.
	 * @param string $plugin_file The full path and filename of the file.
	 * 
	 * @return array $api_params
	 */
	public function set_url_for_version_request( $api_params, $api_data, $plugin_file ) {
		if ( $plugin_file === $this->plugin_file ) {
			$api_params['url'] = $this->get_home_url();
		}
		return $api_params;
	}

	/**
	 * Gets the "Site Address (URL)", using the default language in case WPML is installed
	 *
	 * @since 2.1.2
	 * @return string
	 */
	public function get_home_url() {
		global $sitepress;
		if ( is_callable( array( $sitepress, 'language_url' ) ) && is_callable( array( $sitepress, 'get_default_language' ) ) ) {
			// this is the same function that is used when using the wpml_home_url filter,
			// except that filter always passes $sitepress->get_current_language()
			$home_url = $sitepress->language_url( $sitepress->get_default_language() );
		} else {
			$home_url = home_url();
		}
		return $home_url;
	}

	/**
	 * Get the license key
	 *
	 * @since 2.1.3
	 * @return string
	 */
	public function get_license_key() {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}
		
		if ( is_multisite() && is_plugin_active_for_network( $this->plugin_basename ) ) {
			$wpo_license_keys = get_blog_option( get_main_site_id(), $this->license_keys_option_name, array() );
		} elseif ( is_multisite() && ! is_plugin_active_for_network( $this->plugin_basename ) ) {
			$wpo_license_keys = get_blog_option( get_current_blog_id(), $this->license_keys_option_name, array() );
		} else {
			$wpo_license_keys = get_option( $this->license_keys_option_name, array() );
		}

		$license_key = isset( $wpo_license_keys[$this->plugin_license_slug] ) ? $wpo_license_keys[$this->plugin_license_slug] : '';
		return $license_key;
	}

	/**
	 * Save the license key
	 *
	 * @since 2.1.3
	 * @param string $license_key The plugin license key.
	 * @return void
	 */
	public function save_license_key( $license_key ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}

		if ( is_multisite() && is_plugin_active_for_network( $this->plugin_basename ) ) {
			$wpo_license_keys                             = get_blog_option( get_main_site_id(), $this->license_keys_option_name, array() );
			$wpo_license_keys[$this->plugin_license_slug] = $license_key;
			update_blog_option( get_main_site_id(), $this->license_keys_option_name, $wpo_license_keys );
		} elseif ( is_multisite() && ! is_plugin_active_for_network( $this->plugin_basename ) ) {
			$wpo_license_keys                             = get_blog_option( get_current_blog_id(), $this->license_keys_option_name, array() );
			$wpo_license_keys[$this->plugin_license_slug] = $license_key;
			update_blog_option( get_current_blog_id(), $this->license_keys_option_name, $wpo_license_keys );
		} else {
			$wpo_license_keys                             = get_option( $this->license_keys_option_name, array() );
			$wpo_license_keys[$this->plugin_license_slug] = $license_key;
			update_option( $this->license_keys_option_name, $wpo_license_keys );
		}
	}

	/**
	 * Exclude the plugin from the default update checks
	 *
	 * @param array $args The arguments for the request.
	 * @param string|null $url The URL of the request.
	 *
	 * @return array $args
	 * @since 2.1.5
	 */
	public function exclude_plugin_from_wp_api( array $args, ?string $url ): array {
		if ( empty( $url ) ) {
			return $args;
		}

		// Is this an api.wordpress.org update check request?
		$parsed_url = wp_parse_url( $url );
		if ( ! isset( $parsed_url['host'] ) || ( 'api.wordpress.org' !== strtolower( $parsed_url['host'] ) ) ) {
			return $args;
		}
	
		$type_pluralised = 'plugins'; // We are dealing with plugins only
	
		// Check if the body contains the plugin list.
		if ( empty( $args['body'][ $type_pluralised ] ) ) {
			return $args;
		}
	
		$reporting_items = json_decode( $args['body'][ $type_pluralised ], true );
		if ( null === $reporting_items ) {
			return $args;
		}
	
		// Get the update list key (the plugin's basename).
		$update_list_key = $this->plugin_basename;
	
		// Remove the plugin from the reported list of plugins.
		if ( isset( $reporting_items[ $type_pluralised ][ $update_list_key ] ) ) {
			unset( $reporting_items[ $type_pluralised ][ $update_list_key ] );
		}
	
		// Also remove from the 'active' plugins if necessary.
		if ( ! empty( $reporting_items['active'] ) && is_array( $reporting_items['active'] ) ) {
			foreach ( $reporting_items['active'] as $index => $plugin_path ) {
				if ( $plugin_path === $update_list_key ) {
					unset( $reporting_items['active'][ $index ] );
				}
			}
			
			$reporting_items['active'] = array_values( $reporting_items['active'] ); // Re-index the array.
		}
	
		$args['body'][ $type_pluralised ] = wp_json_encode( $reporting_items );
	
		return $args;
	}
	
}
<?php
namespace WPO\WC\PDF_Invoices_Pro\Cloud\Dropbox;

use WPO\WC\PDF_Invoices_Pro\Cloud\Cloud_API;
use WPO\WC\PDF_Invoices_Pro\Cloud\Dropbox\Helpers\Auth_Helper;
use WPO\WC\PDF_Invoices_Pro\Cloud\Dropbox\Helpers\Token_Helper;
use WPO\WC\PDF_Invoices_Pro\Vendor\Spatie\Dropbox\Client as Dropbox_Client;
use WPO\WC\PDF_Invoices_Pro\Vendor\GuzzleHttp\Client as Guzzle_Client;
use WPO\WC\PDF_Invoices_Pro\Vendor\GuzzleHttp\RequestOptions as Guzzle_RequestOptions;
use WPO\WC\PDF_Invoices_Pro\Vendor\Composer\CaBundle\CaBundle;


if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( '\\WPO\\WC\\PDF_Invoices_Pro\\Cloud\\Dropbox\\Dropbox_API' ) ) :

/**
 * Dropbox API Class
 * 
 * @class  \WPO\WC\PDF_Invoices_Pro\Cloud\Dropbox\Dropbox_API
 */

class Dropbox_API extends Cloud_API {

	/**
	 * @var bool|string
	 */
	private $slug;

	/**
	 * @var string
	 */
	private $name;

	/**
	 * @var bool|string
	 */
	private $access_token;

	/**
	 * @var string
	 */
	private $access_type;

	// providers
	/**
	 * @var Auth_Helper
	 */
	protected $auth_helper;

	/**
	 * @var Token_Helper
	 */
	protected $token_helper;

	/**
	 * @var Dropbox_Client
	 */
	protected $dropbox_client;

	/**
	 * Construct
	 * 
	 * @return	void
	 */
	public function __construct() {
		// includes
		$this->includes();

		// Parent constructor
		parent::__construct();

		// register service specific settings
		add_filter( 'wpo_wcpdf_settings_fields_cloud_storage', array( $this, 'service_specific_settings' ), 10, 4 );

		// Check if we are dealing with this service API
		if ( 'dropbox' !== parent::$service_slug ) {
			return;
		}

		$this->slug         = parent::$service_slug;
		$this->name         = parent::$service_name;
		$this->access_token = parent::$service_access_token;
		$this->access_type  = parent::$cloud_storage_settings['access_type'] ?? 'app_folder';

		// Authorization message
		if ( empty( $this->access_token ) && $this->enabled ) {
			if ( 'valid' === WPO_WCPDF_Pro()->functions->get_pro_license_status() ) {
				add_action( 'wpo_wcpdf_before_settings_page', array( $this, 'api_auth_message' ), 10, 2 );
			} else {
				add_action( 'wpo_wcpdf_before_settings_page', array( $this, 'no_license_message' ), 10, 2 );
			}
		}

		if ( ! empty( $_REQUEST['wpo_wcpdf_'.$this->slug.'_code'] ) ) {
			$this->finish_auth();
		}

		if ( isset( $_REQUEST[ 'wpo_wcpdf_' . $this->slug . '_success' ] ) ) {
			add_action( 'wpo_wcpdf_before_settings_page', array( $this, 'auth_success' ), 10, 2 );
		}

		if ( isset( $_REQUEST[ 'wpo_wcpdf_' . $this->slug . '_fail' ] ) ) {
			add_action( 'wpo_wcpdf_before_settings_page', array( $this, 'auth_fail' ), 10, 2 );
		}
	}

	public function service_specific_settings( $settings_fields, $page, $option_group, $option_name ) {
		$service_specific_settings = array(
			array(
				'type'     => 'setting',
				'id'       => 'access_type',
				'title'    => __( 'Destination folder', 'wpo_wcpdf_pro' ),
				'callback' => 'select',
				'section'  => 'cloud_storage_general_settings',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'access_type',
					'options'     => array(
						'app_folder'  => __( 'App folder (restricted access)', 'wpo_wcpdf_pro' ),
						'root_folder' => __( 'Main Dropbox folder', 'wpo_wcpdf_pro' ),
					),
					'description' => __( 'Note: Reauthorization is required after changing this setting!', 'wpo_wcpdf_pro' ),
					'custom'      => array(
						'type'          => 'text_input',
						'custom_option' => 'root_folder',
						'args'          => array(
							'option_name' => $option_name,
							'id'          => 'destination_folder',
							'size'        => '40',
							'description' => __( 'Enter a subfolder to use (optional)', 'wpo_wcpdf_pro' ),
						),
					),
				)
			),
		);

		// register ids for conditional visibility
		$ids = array_column( $service_specific_settings, 'id' );
		add_filter( 'wpo_wcpdf_cloud_service_specific_settings', function ( $settings ) use ( $ids ) {
			$settings['dropbox'] = $ids;

			return $settings;
		} );

		return $this->append_settings_after_setting_id( $settings_fields, $service_specific_settings, 'cloud_service' );
	}

	/**
	 * Includes another files
	 * 
	 * @return	void
	 */
	private function includes() {
		// helpers
		include_once( WPO_WCPDF_Pro()->plugin_path() . '/includes/cloud/dropbox/helpers/auth-helper.php' );
		include_once( WPO_WCPDF_Pro()->plugin_path() . '/includes/cloud/dropbox/helpers/token-helper.php' );
	}

	/**
	 * Defines the Dropbox destination folders and the key/secret pairs
	 * 
	 * @return	array
	 */
	private function destination_folders() {
		$destination_folders = array();
		$license_key         = WPO_WCPDF_Pro()->functions->get_pro_license_key();
		$license_status      = WPO_WCPDF_Pro()->functions->get_pro_license_status();

		if ( ! empty( $license_key ) && 'valid' === $license_status ) {
			// check if we have a cached response
			$transient     = 'wpo_wcpdf_get_dropbox_app_credentials_response';
			$response      = get_transient( $transient );
			$set_transient = false;

			if ( false === $response ) {
				$response      = wp_remote_get( esc_url_raw( 'https://wpovernight.com/wp-json/dropbox-app-credentials/v1/customer/?license=' . $license_key ) );
				$set_transient = true;
			}

			if ( empty( $response ) ) {
				self::log( 'error', 'Request for Dropbox credentials returned an empty response' );
				return $destination_folders;
			}
			
			// check response for errors
			if ( is_wp_error( $response ) ) {
				self::log( 'error', $response->get_error_message() );
				return $destination_folders;
			}
			
			if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
				$error_body = wp_remote_retrieve_body( $response );
				self::log( 'error', $error_body );
				return $destination_folders;
			}

			if ( $set_transient ) {
				set_transient( $transient, $response, MONTH_IN_SECONDS );
			}

			// all good, get body
			$response_body = $this->maybe_json_decode( wp_remote_retrieve_body( $response ) );
			if ( isset( $response_body['app_folder'] ) && isset( $response_body['root_folder'] ) ) {
				$destination_folders = $response_body;
			}
		}
		
		return $destination_folders;
	}

	/**
	 * Gets the authorization provider
	 * 
	 * @return    Auth_Helper|null
	 */
	public function get_auth_helper() {
		if ( ! empty( $this->auth_helper ) ) {
			return $this->auth_helper;
		}

		// destination folders
		$destination_folders = $this->destination_folders();
		if ( empty( $destination_folders ) ) {
			self::log( 'error', "Couldn't get the Dropbox destination folders." );
			return $this->auth_helper;
		}

		foreach ( $destination_folders as $folder_slug => $folder_keys ) {
			if ( $this->access_type === $folder_slug ) {
				$client_id = $folder_keys['key'];
				break;
			}
		}

		if ( empty( $client_id ) ) {
			self::log( 'error', "Couldn't find any client id." );

			return $this->auth_helper;
		}

		$this->auth_helper = new Auth_Helper( array( 'clientId' => $client_id ), array( 'httpClient' => $this->get_http_client() ) );

		return $this->auth_helper;
	}

	/**
	 * Gets the token provider
	 * 
	 * @return	Token_Helper
	 */
	public function get_token_helper() {
		$auth_helper = $this->get_auth_helper();

		if ( empty( $this->token_helper ) && ! empty( $auth_helper ) ) {
			$this->token_helper = new Token_Helper( $auth_helper, $this->service_api_settings_option );
		}

		return $this->token_helper;
	}

	/**
	 * Gets the Dropbox API provider
	 * 
	 * @return	Dropbox_Client
	 */
	private function get_dropbox_client() {
		$this->token_helper = $this->get_token_helper();

		if ( empty( $this->dropbox_client ) && ! empty( $this->token_helper ) ) {
			$this->dropbox_client = new Dropbox_Client( $this->token_helper, $this->get_http_client() );
		}

		return $this->dropbox_client;
	}

	/**
	 * Gets an instance of the Guzzle HttpClient with preset path to the CA bundle
	 * 
	 * @return	Guzzle_Client
	 */
	private function get_http_client() {
		return new Guzzle_Client( array(
			Guzzle_RequestOptions::VERIFY => apply_filters( 'wpo_wcpdf_dropbox_cabundle_path', CaBundle::getSystemCaRootBundlePath() ),
		) );
	}

	/**
	 * Sets the account info in service settings
	 * 
	 * @return	void
	 */
	public function set_account_info( $access_token ) {
		$service_api_settings = get_option( $this->service_api_settings_option ); // to get the last changes from the TokenProvider
		$account_info         = $this->get_account_info();

		if ( ! empty( $access_token ) && ! empty( $account_info ) ) {
			$service_api_settings['account_info'] = $account_info;
		} else {
			unset( $service_api_settings['account_info'] );
		}

		update_option( $this->service_api_settings_option, $service_api_settings );
	}

	/**
	 * Gets the Dropbox user account informations (name and email)
	 * 
	 * @return	string|void
	 */
	public function get_account_info() {
		$dropbox_client = $this->get_dropbox_client();
		if ( empty( $dropbox_client ) ) {
			return '';
		}

		try {
			$account = $dropbox_client->getAccountInfo();
			$name    = $account['name']['display_name'];
			$email   = $account['email'];

			return "{$name} [{$email}]";
		} catch ( \Exception $e ) {
			self::log( 'error', "fetching {$this->slug} account info failed" );
		}
	}

	/**
	 * Generates the Dropbox authorization request URL
	 * 
	 * @return	string
	 */
	public function auth_url() {
		$auth_helper = $this->get_auth_helper();
		if ( $auth_helper ) {
			$authorization_url = $auth_helper->getAuthorizationUrl();

			set_transient( "wpo_wcpdf_{$this->slug}_pkce_code", $auth_helper->getPkceCode(), 900 );

			return esc_url( $authorization_url );
		} else {
			return '';
		}
	}

	/**
	 * Generates the token from the access code provided by the authorization request
	 * 
	 * @return	string
	 */
	public function auth_get_access_token( $auth_code ) {
		$token_helper = $this->get_token_helper();

		return $token_helper->createToken( $auth_code );
	}

	/**
	 * Finishes the authorization process by saving the token on the Dropbox API settings
	 * 
	 * @return void
	 */
	private function finish_auth() {
		$code = sanitize_text_field( $_REQUEST[ 'wpo_wcpdf_' . $this->slug . '_code' ] );

		self::log( 'notice', "{$this->slug} authentication code entered: {$code}" );

		// Fetch the AccessToken
		try {
			// get token
			$access_token = $this->auth_get_access_token( $code );

			// set account info
			$this->set_account_info( $access_token );

			self::log( 'info', "{$this->slug} access token successfully created from code: {$code}" );

			// redirect back to where we came from
			$redirect_url = sanitize_url( $_REQUEST[ 'wpo_wcpdf_' . $this->slug . '_return_url' ] );
			if ( ! empty( $redirect_url ) ) {
				$url = $redirect_url;
			} else {
				$url = admin_url();
			}

			$url = add_query_arg( 'wpo_wcpdf_' . $this->slug . '_success', $access_token, $url );
			wp_redirect( esc_url_raw( $url ) );
			exit();
		} catch ( \Throwable $e ) {
			self::log( 'error', "{$this->slug} failed to create access token: " . $e->getMessage() );
			$url = isset( $_GET["wpo_wcpdf_{$this->slug}_return_url"] ) ? urldecode( $_GET["wpo_wcpdf_{$this->slug}_return_url"] ) : false;
			$url = add_query_arg( array( 'wpo_wcpdf_' . $this->slug . '_fail' => 'true' ), remove_query_arg( 'wpo_wcpdf_' . $this->slug . '_code', $url ) );
			wp_redirect( esc_url_raw( $url ) );
			exit();
		}
	}

	/**
	 * Dropbox upload process
	 * 
	 * @return	array|bool
	 */
	public function upload( $filepath, $order = null, $document_type = null ) {
		if ( empty( $filepath ) ) {
			return false;
		}

		// get service api settings
		$service_api_settings = get_option( $this->service_api_settings_option );
		// check if authorized
		if ( empty( $service_api_settings ) || ! isset( $service_api_settings['access_token'] ) || empty( $service_access_token = $service_api_settings['access_token'] ) ) {
			self::log( 'error', "no access token" );
			return array( 'error' => __( 'Cloud service credentials not set', 'wpo_wcpdf_pro' ) );
		}

		// get service api client
		$dropbox_client = $this->get_dropbox_client();
		if ( empty( $dropbox_client ) ) {
			self::log( 'error', "empty service api client" );
			return array( 'error' => __( 'Could not get service api client', 'wpo_wcpdf_pro' ) );
		}

		$destination_folder = $this->get_destination_folder( $filepath, $order, $document_type );
		$service_name       = $this->name;

		try {
			$filename      = basename( $filepath );
			$file_contents = fopen( $filepath, 'r' );
			$uploaded_file = $dropbox_client->upload( "{$destination_folder}{$filename}", $file_contents, 'overwrite' );

			self::log( 'info', "successfully uploaded {$filename} to {$service_name}" );

			return array( 'success' => $uploaded_file );
		} catch ( \Exception $e ) {
			$error_response = $e->getMessage();
			$error_message  = "trying to upload to {$service_name}: " . $error_response;
			self::log( 'error', $error_message );

			$decoded_response = $this->maybe_json_decode( $error_response );
			// check for JSON
			if ( $decoded_response ) {
				if ( isset( $decoded_response['error'] ) ) {
					$error     = $decoded_response['error'];
					$unlink_on = array( 'invalid_access_token' );
					if ( in_array( $error['.tag'], $unlink_on ) ) {
						$this->set_account_info( '' );
					}
				}
			}

			return array( 'error' => $error_message );
		}
	}

	/**
	 * Gets the destination folder(s)
	 * 
	 * @return	string
	 */
	public function get_destination_folder( $file, $order, $document_type ) {
		$settings = self::$cloud_storage_settings;

		// get destination folder setting
		if ( isset( $settings['access_type'] ) && $settings['access_type'] == 'root_folder' && ! empty( $settings['destination_folder'] ) ) {
			// format folder name
			// 1: forward slashes only
			$destination_folder = str_replace( "\\", "/", $settings['destination_folder'] );
			// 2: start and end with slash
			$destination_folder = '/' . trim( $destination_folder, '\/' ) . '/';
		} else {
			$destination_folder = '/';
		}

		// append year/month according to setting
		$date               = $this->get_file_date_with_fallback( $file );
		$destination_folder = $this->maybe_append_year_month_folders( $destination_folder, $date );

		// filters
		$destination_folder = apply_filters( 'wpo_wcpdf_dropbox_destination_folder', $destination_folder, $order, $document_type ); // legacy (v2.6.6)
		$destination_folder = apply_filters( 'wpo_wcpdf_cloud_service_destination_folder', $destination_folder, $order, $document_type, $file );

		return $destination_folder;
	}

	/**
	 * Displays the authorization notice for Dropbox service
	 * 
	 * @return    void
	 */
	public function api_auth_message( $active_tab, $active_section ) {
		if ( 'cloud_storage' === $active_tab ) {
			$this->auth_message( $this->auth_url() );
		}
	}

}

endif; // class_exists

return new Dropbox_API();
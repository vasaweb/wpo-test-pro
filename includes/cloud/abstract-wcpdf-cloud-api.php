<?php
namespace WPO\WC\PDF_Invoices_Pro\Cloud;

use WC_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( '\\WPO\\WC\\PDF_Invoices_Pro\\Cloud\\Cloud_API' ) ) :

/**
 * Cloud API abstract
 * 
 * @class  \WPO\WC\PDF_Invoices_Pro\Cloud\Cloud_API
 */

abstract class Cloud_API {

	public static $cloud_storage_settings;
	public $enabled;
	public static $service_slug = '';
	public static $service_name = '';
	public static $service_logo = '';
	public $service_api_settings;
	public $service_api_settings_option;
	public static $service_access_token;

	/**
	 * Construct
	 *
	 * @return	void
	 */
	public function __construct()
	{
		self::$cloud_storage_settings = get_option( 'wpo_wcpdf_cloud_storage_settings' );
		$this->enabled = self::is_enabled();
		$this::$service_slug = self::service_enabled();

		if( isset(self::$cloud_storage_settings['cloud_service']) && !empty(self::$cloud_storage_settings['cloud_service']) ) {
			$this->service_api_settings = get_option( 'wpo_wcpdf_'.self::$cloud_storage_settings['cloud_service'].'_api_settings' );
			$this->service_api_settings_option = 'wpo_wcpdf_'.self::$cloud_storage_settings['cloud_service'].'_api_settings';
		}
		foreach( self::available_cloud_services() as $cloud_service ) {
			if( $this::$service_slug == $cloud_service['slug'] ) {
				self::$service_name = $cloud_service['name'];
				self::$service_logo = $cloud_service['logo'];
			}
		}


		// prevent WPML from crashing when activated due to a conflict with tightenco/collect
		if ( isset($_GET['action']) && $_GET['action'] == 'activate' && isset($_GET['plugin']) && strpos($_GET['plugin'], 'sitepress.php') !== false ) {
			return;
		}

		// Get the access token
		$this::$service_access_token = $this->get_access_token();

		// Check if the API is enabled
		if( $this->enabled == true ) {
			return;
		}
	}

	/**
	 * Get list of available cloud services
	 *
	 * @return  array
	 */
	public static function available_cloud_services()
	{
		return array(
			array(
				'slug'		=> 'dropbox',
				'name'		=> __( 'Dropbox' , 'wpo_wcpdf_pro' ),
				'logo'		=> WPO_WCPDF_Pro()->plugin_url().'/assets/images/dropbox-logo.jpg',
				'active'	=> true
			),
			array(
				'slug'		=> 'ftp',
				'name'		=> __( 'FTP/SFTP' , 'wpo_wcpdf_pro' ),
				'logo'		=> '',
				'active'	=> true
			),
			array(
				'slug'		=> 'gdrive',
				'name'		=> __( 'Google Drive' , 'wpo_wcpdf_pro' ),
				'logo'		=> WPO_WCPDF_Pro()->plugin_url().'/assets/images/gdrive-logo.jpg',
				'active'	=> false
			),
			array(
				'slug'		=> 'onedrive',
				'name'		=> __( 'OneDrive' , 'wpo_wcpdf_pro' ),
				'logo'		=> WPO_WCPDF_Pro()->plugin_url().'/assets/images/onedrive-logo.jpg',
				'active'	=> false
			),
		);
	}

	/**
	 * Checks if the API is enabled
	 *
	 * @return  bool
	 */
	public static function is_enabled()
	{
		if( !empty(self::$cloud_storage_settings) && isset(self::$cloud_storage_settings['enabled']) ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Get the current service from the cloud storage settings
	 *
	 * @return  string|bool
	 */
	public static function service_enabled()
	{
		if( !empty(self::$cloud_storage_settings) && isset(self::$cloud_storage_settings['cloud_service']) ) {
			return self::$cloud_storage_settings['cloud_service'];
		} else {
			return false;
		}
	}

	/**
	 * Get Access token from the cloud storage settings
	 * 
	 * @return	string|bool string when available, false when not set
	 */
	public function get_access_token()
	{
		// return token if it's saved in the settings
		if (!empty($this->service_api_settings['access_token'])) {
			return $this->service_api_settings['access_token'];
		} else {
			return false;
		}
	}

	/**
	 * Shows cloud service authorization notice
	 * 
	 * @return	void
	 */
	public function auth_message( $authUrl ) {
		$formUrl   = admin_url( '?wcdal_authorize' );
		$returnUrl = ((!empty($_SERVER["HTTPS"])) ? "https" : "http") . "://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
		?>
		<div class="notice notice-warning wcpdf-pro-cloud-storage-notice inline">
			<p><img class="logo" src="<?= self::$service_logo; ?>" alt="<?= self::$service_name; ?>"></p>
			<?php /* translators: cloud service */ ?>
			<p><strong><?php printf( __( 'Authorize %s cloud service!', 'wpo_wcpdf_pro' ), self::$service_name ); ?></strong></p>
			<?php /* translators: 1. cloud service, 2-3. <a> tags  */ ?>
			<p><?php printf( __( 'Visit %1$s via %2$sthis link%3$s to get an access code and enter this below:' , 'wpo_wcpdf_pro' ), self::$service_name, '<a href="'.$authUrl.'" target="_blank">', '</a>' ); ?></p>
			<form action="<?php echo $formUrl; ?>">
				<input type="hidden" id="wpo_wcpdf_<?= self::$service_slug; ?>_return_url" name="wpo_wcpdf_<?= self::$service_slug; ?>_return_url" value="<?php echo $returnUrl; ?>">
				<input type="text" id="wpo_wcpdf_<?= self::$service_slug; ?>_code" name="wpo_wcpdf_<?= self::$service_slug; ?>_code" size="50"/>
				<?php submit_button( __( 'Authorize', 'wpo_wcpdf_pro' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Shows authorization succeed notice
	 * 
	 * @return	void
	 */
	public function auth_success( $active_tab, $active_section ) {
		if( $active_tab == 'cloud_storage' ) {
			$token = isset($_REQUEST['wpo_wcpdf_'.self::$service_slug.'_success']) ? $_REQUEST['wpo_wcpdf_'.self::$service_slug.'_success'] : '';
			?>
			<div class="notice notice-success inline">
				<?php /* translators: 1. service name, 2. token */ ?>
				<?php printf('<p>'.__( '%1$s connection established! Access token: %2$s', 'wpo_wcpdf_pro' ).'</p>', self::$service_name, $token); ?>
			</div>
			<?php
		}
	}

	/**
	 * Shows authorization fail notice
	 * 
	 * @return	void
	 */
	public function auth_fail( $active_tab, $active_section ) {
		if ( 'cloud_storage' === $active_tab ) {
			$view_log_link = '<a href="'.esc_url_raw( admin_url( 'admin.php?page=wc-status&tab=logs' ) ).'" target="_blank">'.__( 'View logs', 'wpo_wcpdf_pro' ).'</a>';
			/* translators: 1. cloud service, 2. "View logs" link */
			$message = sprintf( __( '%1$s authentication failed. Please try again or check the logs for details: %2$s', 'wpo_wcpdf_pro' ), self::$service_name, $view_log_link );
			
			printf( '<div class="notice notice-error inline"><p>%s</p></div>', $message );
		}
	}
	
	/**
	 * Shows no license notice
	 * 
	 * @return	void
	 */
	public function no_license_message( string $active_tab, string $active_section ) : void {
		if ( 'cloud_storage' === $active_tab ) {
			?>
			<div class="notice notice-warning wcpdf-pro-cloud-storage-notice inline">
				<p><img class="logo" src="<?php echo self::$service_logo; ?>" alt="<?php echo self::$service_name; ?>"></p>
				<p><strong><?php _e( 'Activate your Professional extension license!', 'wpo_wcpdf_pro' ); ?></strong></p>
				<p>
					<?php
						printf(
							/* translators: 1. cloud service, 2. open anchor tag, 3. open anchor tag, 4. close anchor tag */
							__( 'To keep %1$s API limits under control, you are required to activate your Professional extension license to use this service. See how to activate your license %2$shere%4$s, or clear the cache %3$shere%4$s.', 'wpo_wcpdf_pro' ),
							self::$service_name,
							'<a href="https://docs.wpovernight.com/general/how-to-get-the-latest-version/#activating-your-license" target="_blank">',
							'<a href="' . esc_url( admin_url( 'admin.php?page=wpo_wcpdf_options_page&tab=debug&section=tools' ) ) . '">',
							'</a>'
						);
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Gets the destination folder(s)
	 * 
	 * @return	array
	 */
	public function get_destination_folder( $file, $order, $document_type ) {
		// append year/month according to setting
		$date = $this->get_file_date_with_fallback( $file );
		$destination_folder = $this->maybe_append_year_month_folders( '/', $date );

		return apply_filters( 'wpo_wcpdf_cloud_service_destination_folder', $destination_folder, $order, $document_type, $file );
	}

	/**
	 * Append YYYY/MM/ to a path (for a given date) if setting enabled
	 * 
	 * @return string $path
	 */
	public function maybe_append_year_month_folders( $path, $date ) {
		// append year/month according to setting
		if ( ! empty( self::$cloud_storage_settings['year_month_folders'] ) ) {
			$path = sprintf( '%s/%s/%s/', untrailingslashit( $path ), date( "Y", $date ), date( "m", $date ) );
		}
		return $path;
	}

	/**
	 * Get the date from a file if we can
	 * 
	 * @return int $date
	 */
	public function get_file_date_with_fallback( $file = null ) {
		if ( ! empty( $file ) ) {
			$date = filemtime( $file );
		}
		if ( empty( $date ) ) {
			$date = time();
		}
		return $date;
	}

	/**
	 * Write logs enabled in cloud storage settings
	 * 
	 * @return	void
	 */
	public static function log( $level, $message ) {
		$general_settings = self::$cloud_storage_settings;
		$cloud_service_slug = isset($general_settings['cloud_service']) ? $general_settings['cloud_service'] : null;
		if( isset($general_settings['api_log']) ) {
			if( class_exists('WC_Logger') ) {
				$wc_logger = new WC_Logger();
				$context = array( 'source' => 'wpo-wcpdf-'.$cloud_service_slug );
				$wc_logger->log( $level, $message, $context);
			} else {
				$current_date_time = date("Y-m-d H:i:s");
				$message = $current_date_time.' '.$message."\n";

				file_put_contents( plugin_dir_path(__FILE__) . '/wpo_wcpdf_'.$cloud_service_slug.'_log.txt', $message, FILE_APPEND);
			}
		}
	}

	/**
	 * Validates a JSON string
	 * 
	 * @return	array|bool
	 */
	public function maybe_json_decode( $string ) {
		$decoded = json_decode( $string, true );
		if ( json_last_error() == JSON_ERROR_NONE ) {
			return $decoded;
		} else {
			return false;
		}
	}

	/**
	 * Insert array of settings after a specific existing setting ID
	 * 
	 * @param array  $settings          The original settings.
	 * @param array  $append_settings   The settings to append.
	 * @param string $setting_id        ID of the setting after which to append
	 * 
	 * @return	array
	 */
	public function append_settings_after_setting_id( $settings, $append_settings, $setting_id ) {
		$settings_ids = array_column( $settings, 'id' );
		if ( ! in_array( $setting_id, $settings_ids ) ) {
			return $settings;
		}
		$offset = array_search( $setting_id, $settings_ids ) + 1;
		
		return array_merge( array_slice( $settings, 0, $offset, true ), (array) $append_settings, array_slice($settings, $offset, NULL, true ) );
	}

}

endif; // class_exists

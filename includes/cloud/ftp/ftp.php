<?php
namespace WPO\WC\PDF_Invoices_Pro\Cloud\FTP;

use WPO\WC\PDF_Invoices_Pro\Cloud\Cloud_API;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\Local\LocalFilesystemAdapter as LFS_LocalFilesystemAdapter;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\PhpseclibV3\SftpConnectionProvider as LFS_SftpConnectionProvider;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\PhpseclibV3\SftpAdapter as LFS_SftpAdapter;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\UnixVisibility\PortableVisibilityConverter as LFS_PortableVisibilityConverter;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\Ftp\FtpAdapter as LFS_FtpAdapter;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\Ftp\FtpConnectionOptions as LFS_FtpConnectionOptions;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\Filesystem as LFS_Filesystem;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\Config as LFS_Config;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\Visibility as LFS_Visibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( '\\WPO\\WC\\PDF_Invoices_Pro\\Cloud\\FTP\\FTP_Upload' ) ) :

/**
 * FTP Class
 * 
 * @class  \WPO\WC\PDF_Invoices_Pro\Cloud\FTP\FTP_Upload
 */

class FTP_Upload extends Cloud_API {

	private $slug;
	private $name;
	private $server;
	private $username;
	private $password;

	/**
	 * Construct
	 * 
	 * @return	void
	 */
	public function __construct()
	{
		// Parent constructor
		parent::__construct();

		// register service specific settings
		add_filter( 'wpo_wcpdf_settings_fields_cloud_storage', array( $this, 'service_specific_settings' ), 10, 4 );
		add_action( 'wp_ajax_wcpdf_pro_test_ftp_connection', array( $this, 'ajax_connection_test' ) );

		// Check if we are dealing with this service API
		if ( 'ftp' !== parent::$service_slug ) {
			return;
		}

		// set this service slug, name
		$this->slug = parent::$service_slug;
		$this->name = parent::$service_name;
	}

	public function service_specific_settings( $settings_fields, $page, $option_group, $option_name )
	{
		$service_specific_settings = array(
			array(
				'type'		=> 'setting',
				'id'		=> 'ftp_config',
				'title'		=> __( 'FTP server', 'wpo_wcpdf_pro' ),
				'callback'	=> array( $this, 'ftp_setting_callback' ),
				// 'callback'	=> 'text_input',
				'section'	=> 'cloud_storage_general_settings',
				'args'		=> array(
					'option_name'	=> $option_name,
					'id'			=> 'ftp_config',
					'options'       => array(
						'ftp_protocol' => array(
							'title'       => __( 'Protocol', 'wpo_wcpdf_pro' ),
							'callback'    => 'select',
							'options'     => array(
								'ftp'  =>  __( 'FTP', 'wpo_wcpdf_pro' ),
								'sftp' =>  __( 'SFTP', 'wpo_wcpdf_pro' ),
							),
						),
						'ftp_host' => array(
							'placeholder' => __( 'Host (without protocol)', 'wpo_wcpdf_pro' ),
							'callback'    => 'text_input',
							'size'			=> null,
						),
						'ftp_port' => array(
							'placeholder' => __( 'Port', 'wpo_wcpdf_pro' ),
							'callback'    => 'text_input',
							'type'        => 'number',
							'size'        => 4,
						),
						'ftp_username' => array(
							'placeholder' => __( 'Username', 'wpo_wcpdf_pro' ),
							'callback'    => 'text_input',
							'size'        => null,
						),
						'ftp_password' => array(
							'placeholder' => __( 'Password', 'wpo_wcpdf_pro' ),
							'callback'    => 'text_input',
							'type'        => 'password',
							'size'        => null,
						),
						// 'ftp_private_key' => array(
						// 	'placeholder'          => __( 'Private key file', 'wpo_wcpdf_pro' ),
						// 	'callback'             => array( $this, 'file_upload_callback' ),
						// 	'uploader_title'       => 'Select or upload a private key',
						// 	'uploader_button_text' => 'Set file',
						// 	'remove_button_text'   => 'Remove file',
						// ),
						'ftp_remote_folder' => array(
							'placeholder' => __( 'remote folder (optional, relative to root)', 'wpo_wcpdf_pro' ),
							'callback'    => 'text_input',
							'size'        => null,
						),
						'ftp_root_path' => array(
							'placeholder' => __( 'connection root path (optional)', 'wpo_wcpdf_pro' ),
							'callback'    => 'text_input',
							'size'        => null,
						),
					),
				),
			),
		);

		// register ids for conditional visibility
		$ids = array_column( $service_specific_settings, 'id' );
		add_filter( 'wpo_wcpdf_cloud_service_specific_settings', function( $settings ) use ( $ids ) {
			$settings['ftp'] = $ids;
			return $settings;
		});

		return $this->append_settings_after_setting_id( $settings_fields, $service_specific_settings, 'cloud_service' );
	}

	/**
	 * FTP upload process
	 * 
	 * @return	array|bool
	 */
	public function upload( $filepath, $order = null, $document_type = null )
	{	
		if ( empty( $filepath ) ) {
			return false;
		}

		$login_details = array(
			'protocol'    => 'ftp',
			'host'        => null,
			'port'        => null,
			'username'    => null,
			'password'    => null,
			'root_path'   => '/',
		);

		foreach ( $login_details as $key => &$value ) {
			if ( ! empty ( self::$cloud_storage_settings["ftp_{$key}"] ) ) {
				$value = self::$cloud_storage_settings["ftp_{$key}"];
			}
		}

		// check if we have all we need
		if ( count( array_filter( $login_details ) ) < 5 ) {
			return false;
		}

		try {
			$folder     = $this->get_destination_folder( $filepath, $order, $document_type );

			$filepath   = realpath( $filepath );
			$local_base = ! empty( dirname( $filepath ) ) ? trailingslashit( dirname( $filepath ) ) : '/';
			$local      = new LFS_Filesystem( new LFS_LocalFilesystemAdapter( $local_base ) );
			$file       = $local->readStream( basename( $filepath ) );

			$adapter    = $this->get_filesystem_adapter( $login_details );
			$ftp        = new LFS_Filesystem( $adapter );
			$filename   = sanitize_file_name( basename( $filepath ) );
			$adapter->createDirectory( trailingslashit( $folder), new LFS_Config([LFS_Config::OPTION_DIRECTORY_VISIBILITY => LFS_Visibility::PRIVATE]) );
			$ftp->writeStream( trailingslashit( $folder) . $filename, $file );

			return array( 'success' => $filepath );
		} catch ( \Throwable $e) {
			self::log('CRITICAL',$e->getMessage());
			return array( 'error' => $e->getMessage() );
		}
	}

	public function get_filesystem_adapter( $login_details ) {
		// remove protocol from host value (ftp:// or sftp://)
		if ( ! empty( $login_details['host'] ) ) {
			$login_details['host'] = preg_replace( '/^s?ftp:\/\//', '', $login_details['host'] );
		}
		
		switch ($login_details['protocol']) {
			case 'sftp':
				$defaults = [
					'host'             => null,
					'username'         => null,
					'password'         => null,
					'private_key'      => null, // path
					'passphrase'       => null,
					'port'             => 22,
					'use_agent'        => false,
					'timeout'          => 10,
					'max_tries'        => 4,
					'host_fingerprint' => null,
					'root_path'        => '/',
				];
				// allow passing custom options such as the path to a private key and passphrase
				$login_details = apply_filters( 'wpo_wcpdf_pro_sftp_login_details', wp_parse_args( $login_details, $defaults ) );

				if ( ! empty( $login_details['private_key'] ) ) {
					$login_details['password'] = null;
				}

				// make sure root path is never empty
				if ( empty( $login_details['root_path'] ) ) {
					$login_details['root_path'] = $defaults['root_path'];
				} else {
					$login_details['root_path'] = '/' . untrailingslashit( $login_details['root_path'] );
				}

				$connection_provider = new LFS_SftpConnectionProvider(
					$login_details['host'],                // host (required)
					$login_details['username'],            // username (required)
					$login_details['password'],            // password (optional, default: null) set to null if privateKey is used
					$login_details['private_key'],         // path to private key (optional, default: null) can be used instead of password, set to null if password is set
					$login_details['passphrase'],          // passphrase (optional, default: null), set to null if privateKey is not used or has no passphrase
					intval( $login_details['port'] ),      // port (optional, default: 22)
					$login_details['use_agent'],           // use agent (optional, default: false)
					intval( $login_details['timeout'] ),   // timeout (optional, default: 10)
					intval( $login_details['max_tries'] ), // max tries (optional, default: 4)
					$login_details['host_fingerprint']     // host fingerprint (optional, default: null),
					// null,                               // connectivity checker (must be an implementation of 'WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\PhpseclibV3\ConnectivityChecker' to check if a connection can be established (optional, omit if you don't need some special handling for setting reliable connections)
				);
				$connection_provider->provideConnection();
				
				$visibility_converter = LFS_PortableVisibilityConverter::fromArray( apply_filters( 'wpo_wcpdf_pro_sftp_permissions', [
					'file' => [
						'public'  => 0644,
						'private' => 0600,
					],
					'dir'  => [
						'public'  => 0755,
						'private' => 0700,
					],
				] ) );
				
				$adapter = new LFS_SftpAdapter(
					$connection_provider,
					$login_details['root_path'], // root path (required)
					$visibility_converter
				);

				break;
			case 'ftp':
			default:
				$login_details = apply_filters( 'wpo_wcpdf_pro_ftp_login_details', [
					'host'     => $login_details['host'], // required
					'root'     => ! empty( $login_details['root_path'] ) ? $login_details['root_path'] : '/',  // required
					'username' => $login_details['username'], // required
					'password' => $login_details['password'], // required
					'port'     => ! empty( $login_details['port'] ) ? intval( $login_details['port'] ) : 21,
					// 'ssl'                             => false,
					// 'timeout'                         => 90,
					// 'utf8'                            => false,
					// 'passive'                         => true,
					// 'transferMode'                    => FTP_BINARY,
					// 'systemType'                      => null, // 'windows' or 'unix'
					// 'ignorePassiveAddress'            => null, // true or false
					// 'timestampsOnUnixListingsEnabled' => false, // true or false
					// 'recurseManually'                 => true // true 
				] );
				$adapter = new LFS_FtpAdapter( LFS_FtpConnectionOptions::fromArray( $login_details ) );
				break;
		}
		return $adapter;
	}

	/**
	 * Gets the destination folder(s)
	 * 
	 * @return	array
	 */
	public function get_destination_folder( $file, $order, $document_type ) {
		// get destination folder setting
		if ( ! empty( self::$cloud_storage_settings['ftp_remote_folder'] ) ) {
			// format folder name: forward slashes only, start and end with (single) slash
			$destination_folder = '/'.trim( wp_normalize_path( self::$cloud_storage_settings['ftp_remote_folder'] ), '\/').'/';
		} else {
			$destination_folder = '/';
		}

		// append year/month according to setting
		$date = $this->get_file_date_with_fallback( $file );
		$destination_folder = $this->maybe_append_year_month_folders( $destination_folder, $date );

		// filters
		return apply_filters( 'wpo_wcpdf_cloud_service_destination_folder', $destination_folder, $order, $document_type, $file );
	}

	/**
	 * File upload callback.
	 *
	 * @param  array $args Field arguments.
	 */
	public function file_upload_callback( $args ) {
		extract( WPO_WCPDF()->settings->callbacks->normalize_settings_args( $args ) );
		$current = !empty( $current ) && is_array( $current ) ? $current : array();
		printf( '<input id="%1$s_id" name="%1$s[id]" value="%2$s" type="hidden"  />', $setting_name, $current['id'] );
		printf( '<input id="%1$s_filename" name="%1$s[filename]" size="50" value="%2$s" readonly="readonly" />', $setting_name, $current['filename'] );
		if ( !empty($current['id']) ) {
			printf('<span class="button remove_file_button" data-input_id="%1$s">%2$s</span>', $id, $remove_button_text );
		}
		printf( '<span class="button upload_file_button %4$s" data-uploader_title="%1$s" data-uploader_button_text="%2$s" data-remove_button_text="%3$s" data-input_id="%4$s">%2$s</span>', $uploader_title, $uploader_button_text, $remove_button_text, $id );
	
		// Displays option description.
		if ( isset( $description ) ) {
			printf( '<p class="description">%s</p>', $description );
		}
	}

	/**
	 * FTP input fields callback.
	 *
	 * @param  array $args Field arguments.
	 */
	public function ftp_setting_callback( $args ) {
		extract( WPO_WCPDF()->settings->callbacks->normalize_settings_args( $args ) );
		if ( ! function_exists( 'ftp_connect' ) ) {
			unset( $args['options']['ftp_protocol']['options']['ftp'] );
		}

		?>
		<div id="<?= $args['id']; ?>">
			<div class="ftp-protocol">
				<label><?= $args['options']['ftp_protocol']['title']; ?></label>
				<?php $this->ftp_setting_option( $args, 'ftp_protocol' ); ?>
			</div>

			<div class="ftp-details">
				<div class="host-port">
					<?php
					$this->ftp_setting_option( $args, 'ftp_host' );
					$this->ftp_setting_option( $args, 'ftp_port' );
					?>
				</div>
				<div class="username-password">
					<?php
					$this->ftp_setting_option( $args, 'ftp_username' );
					$this->ftp_setting_option( $args, 'ftp_password' );
					?>
				</div>
				<div class="private-key">
					<?php
					// $this->ftp_setting_option( $args, 'ftp_private_key' );
					?>
				</div>
				<div class="remote-folder">
					<?php
					$this->ftp_setting_option( $args, 'ftp_remote_folder' );
					?>
				</div>
				<div class="root-path">
					<?php
					$this->ftp_setting_option( $args, 'ftp_root_path' );
					?>
				</div>
			</div>
			<div>
				<span class="button wpo-wcpdf-pro-test-ftp-connection"><?= __( 'test connection', 'wpo_wcpdf_pro' ) ?></span>
				<span class="ftp-connection-waiting" style="display: none;"><img src="<?php echo WPO_WCPDF_Pro()->plugin_url() . '/includes/views/spinner.gif'; ?>" style="margin-top: 7px;"></span>
				<script>

					jQuery( function( $ ) {
						$('.wpo-wcpdf-pro-test-ftp-connection').on('click', function(){
							$(this).parent().find('.result').remove();
							$(this).attr( 'disabled', true );
							$(this).next().show();
							$.ajax({
								url:  ajaxurl,
								data: {
									action:        'wcpdf_pro_test_ftp_connection',
									nonce:         wpo_wcpdf_pro_settings.nonce,
									protocol:      $('#ftp_protocol').val(),
									host:          $('#ftp_host').val(),
									port:          $('#ftp_port').val(),
									username:      $('#ftp_username').val(),
									password:      $('#ftp_password').val(),
									remote_folder: $('#ftp_remote_folder').val(),
									root_path:     $('#ftp_root_path').val(),
								},
								type: 'POST',
								context: $(this),
								success: function( response ) {
									$(this).attr( 'disabled', false );
									$(this).next().hide();
									$(this).after( '<span class="result" style="line-height:30px;">'+response.data+'</span>' );
								},
								error: function( xhr, status, error ) {
									console.log( error );
								},
							});
						});
					});
				</script>
			</div>
			<?php
			// Displays option description.
			if ( isset( $description ) ) {
				printf( '<p class="description">%s</p>', $description );
			}
			?>
		</div>
		<?php
	}

	public function ajax_connection_test() {
		check_ajax_referer( 'wpo_wcpdf_pro_settings', 'nonce' );
		
		// filter out the data we need
		$login_details = array_intersect_key( $_POST, array_flip( array( 'protocol', 'host', 'port', 'username', 'password', 'root_path' ) ) );
		// clean slashed data		
		$login_details = wp_unslash( $login_details );

		try {
			$adapter  = $this->get_filesystem_adapter( $login_details );
			$ftp      = new LFS_Filesystem( $adapter );
			$filename = 'connection-test.txt';
			$ftp->write( $filename, 'success!' );
			$ftp->delete( $filename );
			$message  =  __('Success!', 'wpo_wcpdf_pro' );
			wp_send_json_success( sprintf( '<span class="dashicons dashicons-yes" style="line-height:30px;"></span> %s', $message ) );
		} catch (\Throwable $th) {
			/* translators: error message */
			$message  =  sprintf( __('Error: %s', 'wpo_wcpdf_pro' ), $th->getMessage() );
			wp_send_json_error( sprintf( '<span class="dashicons dashicons-no" style="line-height:30px;"></span> %s', $message ) );
		}
	}

	public function ftp_setting_option( $args, $option_id ) {
		$option = !empty( $args['options'][$option_id] ) ? $args['options'][$option_id] : array();
		if ( is_array( $option['callback'] ) ) {
			$callback = $option['callback'];
		} else {
			$callback = array( WPO_WCPDF()->settings->callbacks, $option['callback'] );
		}
		if ( !empty( $option['callback'] ) && is_callable( $callback ) ) {
			$args['id'] = $option_id;
			unset($args['options']);
			$args = array_merge( $args, $option );
			call_user_func( $callback, $args );
		}
	}

}

endif; // class_exists

return new FTP_Upload();
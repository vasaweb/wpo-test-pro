<?php
namespace WPO\WC\PDF_Invoices_Pro\Cloud\Gdrive;

use WPO\WC\PDF_Invoices_Pro\Cloud\Cloud_API;
use Google_Client;
use Google_Service_Drive;
use Google_Service_Drive_DriveFile;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( '\\WPO\\WC\\PDF_Invoices_Pro\\Cloud\\Gdrive\\Gdrive_API' ) ) :

/**
 * GDrive API Class
 * 
 * @class  \WPO\WC\PDF_Invoices_Pro\Cloud\Gdrive\Gdrive_API
 */

class Gdrive_API extends Cloud_API {

	/** 
	 * Get API credentials
	 * https://console.developers.google.com/apis/credentials
	 * 
	 * Activate GDrive API
	 * https://console.developers.google.com/apis/api/drive.googleapis.com
	*/

	/**
	 * @var string
	 */
	private $app_name = 'PDF Invoices & Packing Slips for WooCommerce';

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
	private $client_id = 'WPOVERNIGHT_CLIENT_ID';

	/**
	 * @var string
	 */
	private $secret = 'WPOVERNIGHT_SECRET';

	/**
	 * @var string
	 */
	private $redirect_uri = 'https://wpovernight.com';

	/**
	 * @var Google_Client|object
	 */
	private $client;

	/**
	 * @var Google_Service_Drive|object
	 */
	private $service;

	/**
	 * Construct
	 * 
	 * @return	void
	 */
	public function __construct()
	{
		// Parent constructor
		parent::__construct();

		// Check if we are dealing with this service API
		if( parent::$service_slug != 'gdrive' ) return;

		// set this service slug, name and token
		$this->slug = parent::$service_slug;
		$this->name = parent::$service_name;
		$this->access_token = parent::$service_access_token;

		// Gonfigure gdrive
		$this->client = $this->configure_client( $this->access_token );
		$this->service = $this->configure_service( $this->client );

		// Authorization message
		if ( empty($this->access_token) && $this->enabled ) {
			add_action( 'wpo_wcpdf_before_settings_page', array( $this, 'api_auth_message' ), 10, 2 );
		}

		if ( !empty($_REQUEST['wpo_wcpdf_'.$this->slug.'_code']) ) {
			$this->finish_auth();
		}

		if ( isset($_REQUEST['wpo_wcpdf_'.$this->slug.'_success']) ) {
			add_action( 'wpo_wcpdf_before_settings_page', array( $this, 'auth_success' ), 10, 2 );
		}

		if ( isset($_REQUEST['wpo_wcpdf_'.$this->slug.'_fail']) ) {
			add_action( 'wpo_wcpdf_before_settings_page', array( $this, 'auth_fail' ), 10, 2 );
		}
	}

	/**
	 * GDrive API redirect URI
	 * 
	 * @return	string
	 */
	private function redirect_uri()
	{
		return $this->redirect_uri;
	}

	/**
	 * Appends a query string to the redirect URI, this way we can redirect the user back
	 * 
	 * @return	string
	 */
	private function state()
	{
		return 'source:'.esc_url_raw( admin_url( 'admin.php?page=wpo_wcpdf_options_page&tab=cloud_storage' ) );
	}

	/**
	 * Configures GDrive API client
	 * 
	 * @return	object
	 */
	private function configure_client( $access_token )
	{
		$client = new Google_Client();
		$client->setApplicationName($this->app_name);
		$client->setScopes(Google_Service_Drive::DRIVE_FILE);
		$client->setClientId($this->client_id);
		$client->setClientSecret($this->secret);
		$client->setAccessType('offline');
		$client->setRedirectUri($this->redirect_uri());
		$client->setPrompt('select_account consent');
		$client->setState($this->state());

		if ( !empty($access_token) ) {
			$client->setAccessToken($access_token);
		} else {
			delete_option($this->service_api_settings_option);
			self::log( 'error', "empty access token" );
		}
	
		// If there is no previous token or it's expired.
		if ($client->isAccessTokenExpired() && isset($access_token['refresh_token'])) {
			// Refresh the token if possible, else require new authentication
			if ($refresh_token = $access_token['refresh_token']) {
				$client->fetchAccessTokenWithRefreshToken($refresh_token);
				$access_token = $client->getAccessToken();
                $access_token['refresh_token'] = json_decode($refresh_token);
				$access_token = json_encode($access_token);
				$client->setAccessToken($access_token);

				// update token on api settings option
				$service_api_settings = $this->service_api_settings;
				$service_api_settings['access_token']['access_token'] = $client->getAccessToken();
				update_option( $this->service_api_settings_option, $service_api_settings );
			}
		}

		return $client;
	}

	/**
	 * Configures GDrive API service
	 * 
	 * @return	object
	 */
	private function configure_service( $client )
	{
		return new Google_Service_Drive($client);
	}

	/**
	 * Saves the token response from Google in the GDrive API settings
	 * 
	 * @return	void
	 */
	public function set_access_token( $token_response )
	{
		$service_api_settings = $this->service_api_settings;
		$service_api_settings['access_token'] = $token_response;
		if ( !empty($token_response) ) {
			$service_api_settings['account_info'] = $this->get_account_info( $token_response );
		} else {
			unset($service_api_settings['account_info']);
		}
		update_option( $this->service_api_settings_option, $service_api_settings );
		return;
	}

	/**
	 * Gets the Google user account informations (name and email)
	 * 
	 * @return	string|void
	 */
	public function get_account_info( $access_token )
	{
		try {
			$client = $this->configure_client( $access_token );
			$service = $this->configure_service( $client );
			$user = $service->about->get(array('fields' => 'user'))->getUser();

			$name = $user->getdisplayName();
			$email = $user->getemailAddress();
			
			return "{$name} [{$email}]";
		} catch ( \Exception $e ) {
			self::log( 'error', "fetching {$this->slug} account info failed" );
		}
	}

	/**
	 * Generates the GDrive authorization request URL
	 * 
	 * @return	string
	 */
	public function auth_url()
	{
		return $this->client->createAuthUrl();
	}

	/**
	 * Generates the token response from the access code provided by the authorization request
	 * 
	 * @return	array|void
	 */
	public function auth_get_access_token( $code )
	{
		if ( $this->client->isAccessTokenExpired() ) {
			$token_response = $this->client->fetchAccessTokenWithAuthCode($code);
            return $token_response;
		}
	}

	/**
	 * Finishes the authorization process by saving the token response on the GDrive API settings
	 * 
	 * @return	resource|void
	 */
	public function finish_auth()
	{
		$code = sanitize_text_field( $_REQUEST['wpo_wcpdf_'.$this->slug.'_code'] );

		self::log( 'notice', "{$this->slug} authentication code entered: {$code}" );

		// Fetch the AccessToken
		try {
			// get token response
			$token_response = $this->auth_get_access_token( $code );

			// save token to settings
			$this->set_access_token( $token_response );
			self::log( 'info', "{$this->slug} access token successfully created from code: {$code}" );

			// redirect back to where we came from
			if (!empty($_REQUEST['wpo_wcpdf_'.$this->slug.'_return_url'])) {
				$url = $_REQUEST['wpo_wcpdf_'.$this->slug.'_return_url'];
			} else {
				$url = admin_url();
			}

			$url = add_query_arg( 'wpo_wcpdf_'.$this->slug.'_success', $token_response['access_token'], $url);
			wp_redirect( esc_url_raw( $url ) );

		} catch ( \Exception $e ) {
			self::log( 'error', "{$this->slug} failed to create access token: ".$e->getMessage() );
			$url = add_query_arg( [ 'wpo_wcpdf_'.$this->slug.'_fail', 'true' ], remove_query_arg( 'wpo_wcpdf_'.$this->slug.'_code' ) );
			wp_redirect( esc_url_raw( $url ) );
		} catch ( \TypeError $e ) {
			self::log( 'error', "{$this->slug} failed to create access token: ".$e->getMessage() );
			$url = add_query_arg( [ 'wpo_wcpdf_'.$this->slug.'_fail', 'true' ], remove_query_arg( 'wpo_wcpdf_'.$this->slug.'_code' ) );
			wp_redirect( esc_url_raw( $url ) );
		} catch ( \Error $e ) {
			self::log( 'error', "{$this->slug} failed to create access token: ".$e->getMessage() );
			$url = add_query_arg( [ 'wpo_wcpdf_'.$this->slug.'_fail', 'true' ], remove_query_arg( 'wpo_wcpdf_'.$this->slug.'_code' ) );
			wp_redirect( esc_url_raw( $url ) );
		}
	}

	/**
	 * GDrive upload process
	 * 
	 * @return	array|bool
	 */
	public function upload( $file = null, $folder = null )
	{	
		if ( empty($file) ) {
			return false;
		}

		// grab general settings
		$general_settings = self::$cloud_storage_settings;

		if( ! empty( $folder ) && $folder != '/' ) {
			$destination_folder = substr( $folder, 1, -1 );
			$destination_folder = explode( '/', $destination_folder );
			$folder = array();

			// add year/month dirs
			if ( isset($general_settings['year_month_folders']) ) {
				$year_month = array_slice( $destination_folder , -2, 2, true );  
				foreach( $year_month as $key => $date ) {
					if( strlen( $date ) == 4 && is_numeric( $date ) ) {
						$folder['year'] = $date;
					} elseif( strlen( $date ) == 2 && is_numeric( $date ) ) {
						$folder['month'] = $date;
					}
					unset($destination_folder[$key]);
				}
			}

			// add 'parent_folder' and other 'sub_folders' (if exist)
			if( empty($destination_folder) ) {
				$folder['parent_folder'] = '/';
			} else {
				foreach( $destination_folder as $key => $value ) {
					if( $key == 0 ) {
						$folder['parent_folder'] = $value;
					} else {
						$folder['sub_folders'][] = $value;
					}
				}
			}

		} else {
			$folder = array( 'parent_folder' => '/' );
		}

		// grab saved gdrive settings
		$service_api_settings = $this->service_api_settings;

		// create folders if needed
		$service_api_settings = $this->create_folders( $folder, $service_api_settings );

		// create file
		return $this->create_file( $file, $service_api_settings );
	}

	/**
	 * Creates all the required folders inside GDrive
	 * 
	 * @return	array
	 */
	private function create_folders( $folder, $service_api_settings )
	{
		if( empty($folder) ) return $service_api_settings;

		// get the parent folder name
		if( $folder['parent_folder'] == '/' ) {
			$parent_folder = $this->app_name;
			if( parent::$cloud_storage_settings['access_type'] == 'root_folder' ) { // if root folder and no subfolder provided, don't create parent folder
				$parent_folder = null;
			}
		} else {
			$parent_folder = str_replace('/', '', $folder['parent_folder']);
		}

		// create parent folder if not exist in gdrive
		if( empty($service_api_settings['parent_dir']) ) {
			// if we have a parent folder to create
			if( ! empty($parent_folder) ) {
				// create the folder on gdrive
				$service_api_settings = $this->create_folder(
					$parent_folder,
					null,
					'parent_dir',
					$service_api_settings
				);
			}
		} else {
			// get the parent folder name
			if( ! empty($parent_folder) ) {
				if( $parent_folder != $service_api_settings['parent_dir']['name'] ) {
					// remove the old one
					$service_api_settings = $this->delete_api_setting( 'parent_dir' );
					
					// create a new parent folder on gdrive
					$service_api_settings = $this->create_folder(
						$parent_folder,
						null,
						'parent_dir',
						$service_api_settings
					);
				}
			} elseif( empty($parent_folder) && parent::$cloud_storage_settings['access_type'] == 'root_folder' ) {
				// if not parent folder provided and we have one saved, delete it
				$service_api_settings = $this->delete_api_setting( 'parent_dir' );
			}
		}

		// create the sub-folders dirs
		if( ! empty($service_api_settings['parent_dir']) ) {
			// we have sub-folders to create
			if( ! empty($folder['sub_folders']) ) {
				// first we compare the folders with the saved ones
				if( ! empty($service_api_settings['sub_folders']) ) {
					$saved_folder_names = array();
					$missmatch = false;
					foreach( $service_api_settings['sub_folders'] as $saved_folder ) {
						$saved_folder_names[] = $saved_folder['name'];
					}
					foreach( $folder['sub_folders'] as $folder_name ) {
						if( ! in_array( $folder_name, $saved_folder_names ) ) {
							$missmatch = true;
						}
					}
					// if we have some missmatch we should delete the saved sub-folders and start again
					if( $missmatch ) {
						$service_api_settings = $this->delete_api_setting( 'sub_folders' );
					}
				}
				// then we create the sub-folders
				if( empty($service_api_settings['sub_folders']) ) {
					foreach( $folder['sub_folders'] as $key => $folder_name ) {
						if( $key == 0 ) {
							// create a new subfolder on gdrive
							$service_api_settings = $this->create_folder(
								$folder_name,
								$service_api_settings['parent_dir']['id'],
								'sub_folders',
								$service_api_settings
							);
						} else {
							$last_key = array_key_last( $service_api_settings['sub_folders'] ); // get always the last one
							// create a new subfolder on gdrive
							$service_api_settings = $this->create_folder(
								$folder_name,
								$service_api_settings['sub_folders'][$last_key]['id'],
								'sub_folders',
								$service_api_settings
							);
						}
					}
				}

			// we don't have folders to create, but if we have already saved ones we should delete them
			} else {
				if( ! empty($service_api_settings['sub_folders']) ) {
					$service_api_settings = $this->delete_api_setting( 'sub_folders' );
				}
			}
		}

		// create the year/month dirs
		if( ! empty( parent::$cloud_storage_settings['year_month_folders'] ) ) {
			// year dir
			if( ! empty($service_api_settings['parent_dir']) && ! empty($folder['year']) ) {
				// if we have already a year dir, check the parent ID/name match
				if( ! empty($service_api_settings['year']) ) {
					// if we have sub-folders saved
					if( ! empty($service_api_settings['sub_folders']) ) {
						// check for ID
						$last_key = array_key_last( $service_api_settings['sub_folders'] ); // get always the last one
						if( $service_api_settings['year']['parents'][0] !== $service_api_settings['sub_folders'][$last_key]['id'] ) {
							$this->move_folder(
								$service_api_settings['year'],
								$service_api_settings['sub_folders'][$last_key],
								'year',
								$service_api_settings
							);
						}
					} else {
						if( $service_api_settings['year']['parents'][0] !== $service_api_settings['parent_dir']['id'] ) {
							$this->move_folder(
								$service_api_settings['year'],
								$service_api_settings['parent_dir'],
								'year',
								$service_api_settings
							);
						}
					}

					// check for name
					if( $service_api_settings['year']['name'] != $folder['year'] ) {
						$service_api_settings = $this->delete_api_setting( 'year' );
					}
				}

				// if we don't have dir yet lets create it
				if( empty($service_api_settings['year']) ) {
					// if we have sub-folders
					if( ! empty($service_api_settings['sub_folders']) ) {
						$last_key = array_key_last( $service_api_settings['sub_folders'] ); // get always the last one
						// create a new subfolder on gdrive
						$service_api_settings = $this->create_folder(
							$folder['year'],
							$service_api_settings['sub_folders'][$last_key]['id'],
							'year',
							$service_api_settings
						);
					} else {
						// create a new subfolder on gdrive
						$service_api_settings = $this->create_folder(
							$folder['year'],
							$service_api_settings['parent_dir']['id'],
							'year',
							$service_api_settings
						);
					}
				}

			// if no parent dir
			} else {
				if( ! empty($service_api_settings['year']) ) {
					// create a new subfolder on gdrive root
					$service_api_settings = $this->create_folder(
						$folder['year'],
						null,
						'year',
						$service_api_settings
					);
				}
			}

			// month dir
			if( ! empty($service_api_settings['year']) && ! empty($folder['month']) ) {
				// if we have already a month dir, check the parent ID/name match
				if( ! empty($service_api_settings['month']) ) {
					// if we have year dir saved
					if(  $service_api_settings['month']['parents'][0] !== $service_api_settings['year']['id'] ) {
						$this->move_folder(
							$service_api_settings['month'],
							$service_api_settings['year'],
							'month',
							$service_api_settings
						);
					}

					// check for name
					if( $service_api_settings['month']['name'] !== $folder['month'] ) {
						$service_api_settings = $this->delete_api_setting( 'month' );
					}
				}

				// if we don't have dir yet lets create it
				if( empty($service_api_settings['month']) ) {
					// create a new subfolder on gdrive
					$service_api_settings = $this->create_folder(
						$folder['month'],
						$service_api_settings['year']['id'],
						'month',
						$service_api_settings
					);
				}
			}

		} else {
			if( ! empty($service_api_settings['year']) ) {
				$service_api_settings = $this->delete_api_setting( 'year' );
			}
			if( ! empty($service_api_settings['month']) ) {
				$service_api_settings = $this->delete_api_setting( 'month' );
			}
		}

		return $service_api_settings;
	}

	/**
	 * Creates the PDF document files inside the respective folder in GDrive
	 * 
	 * @return	array
	 */
	private function create_file( $file, $service_api_settings )
	{
		$file_name = basename($file);

		try {
			if( ! empty($service_api_settings['month']) ) {
				$parent_id = $service_api_settings['month']['id'];
			} elseif( ! empty($service_api_settings['parent_dir']) ) {
				if( empty($service_api_settings['sub_folders']) ) {
					$parent_id = $service_api_settings['parent_dir']['id'];
				} else {
					$last_key = array_key_last( $service_api_settings['sub_folders'] ); // get always the last one
					$parent_id = $service_api_settings['sub_folders'][$last_key]['id'];
				}
			}

			$metadata = array(
				'name'	=> $file_name
			);

			if( ! empty($parent_id) ) {
				$folder = $this->service->files->get( $parent_id, array( 'fields' => 'trashed' ) ); // check if parent folder is trashed in GDrive
				$parent_trashed = $folder['trashed'] ? true : false;
				if( ! $parent_trashed ) {
					$metadata['parents'] = array($parent_id);
				} else {
					// if we not mention a parent ID the file is stored in GDrive root
					$message = __( 'A parent directory in GDrive was trashed, the file was transfered to the GDrive root directory.', 'wpo_wcpdf_pro' );
					self::log( 'error', $message );
				}
			}

			$content = file_get_contents($file);

			$file_metadata = new Google_Service_Drive_DriveFile($metadata);
			$file = $this->service->files->create($file_metadata, array(
				'data'			=> $content,
				'mimeType'		=> 'application/pdf',
				'uploadType'	=> 'multipart',
				'fields'		=> 'id, parents, name'
			));

			self::log( 'info', "successfully uploaded {$file_name} to {$this->name}" );

			return array( 'success' => $file );
		} catch ( \Exception $e ) {
			$error_message = "creating {$this->name} file {$file_name}";
			self::log( 'error', $error_message );
			return array( 'error' => $error_message );
		}
	}

	/**
	 * Creates a folder in GDrive
	 * 
	 * @return	array|void
	 */
	private function create_folder( $folder_name, $parent_id = null, $setting_name = null, $service_api_settings = array() )
	{
		if( empty($service_api_settings) ) {
			$service_api_settings = $this->get_last_api_settings();
		}
		
		try {
			// prepare metadata
			$metadata = array(
				'name'		=> $folder_name,
				'mimeType'	=> 'application/vnd.google-apps.folder'
			);
			if( !is_null($parent_id) ) {
				$metadata['parents'] = array($parent_id);
			}

			// create the folder on gdrive
			$file_metadata = new Google_Service_Drive_DriveFile($metadata);
			$file = $this->service->files->create($file_metadata, array( // in gdrive folders are threat has files
				'fields' 	=> 'id, parents, name'
			));

			// update the gdrive api option
			if( ! empty($setting_name) ) {
				$atts = array(
					'id'		=> $file->id,
					'name'		=> $file->name,
					'parents'	=> $file->parents, // array of parent folders
				);
				if( $setting_name == 'sub_folders' ) {
					$service_api_settings[$setting_name][] = $atts;
				} else {
					$service_api_settings[$setting_name] = $atts;
				}
			}
			update_option( $this->service_api_settings_option, $service_api_settings );
			self::log( 'info', "successfully created the folder {$folder_name} in {$this->name}" );

			return $this->get_last_api_settings();
		} catch ( \Exception $e ) {
			self::log( 'error', "creating {$this->name} folder {$folder_name}" );
		}
	}

	/**
	 * Moves a GDrive folder to another parent folder
	 * 
	 * @return	array|void
	 */
	private function move_folder( $folder, $parent, $setting_name = null, $service_api_settings = array() )
	{
		if( empty($service_api_settings) ) {
			$service_api_settings = $this->get_last_api_settings();
		}

		try {
			$empty_file_metadata = new Google_Service_Drive_DriveFile();
			// Retrieve the existing parents to remove
			$file = $this->service->files->get($folder['id'], array('fields' => 'parents'));
			$previous_parents = join(',', $file->parents);
			// Move the file to the new folder
			$file = $this->service->files->update($folder['id'], $empty_file_metadata, array(
				'addParents' 	=> $parent['id'],
				'removeParents'	=> $previous_parents,
				'fields'		=> 'id, parents, name'
			));

			// update the gdrive api option
			if( ! empty($setting_name) ) {
				$atts = array(
					'id'		=> $file->id,
					'name'		=> $file->name,
					'parents'	=> $file->parents, // array of parent folders
				);
				if( $setting_name == 'sub_folders' ) {
					$service_api_settings[$setting_name][] = $atts;
				} else {
					$service_api_settings[$setting_name] = $atts;
				}
			}

			update_option( $this->service_api_settings_option, $service_api_settings );
			self::log( 'info', "successfully moved the folder {$folder['name']} into {$parent['name']}" );

			return $this->get_last_api_settings();
		} catch ( \Exception $e ) {
			self::log( 'error', "moving {$this->name} folder {$folder['name']}" );
		}
	}

	/**
	 * Gets the last GDrive API settings
	 * 
	 * @return	array
	 */
	private function get_last_api_settings()
	{
		return get_option( $this->service_api_settings_option );
	}

	/**
	 * Delete GDrive API setting
	 * 
	 * @return	array
	 */
	private function delete_api_setting( $setting_name )
	{
		$service_api_settings = $this->get_last_api_settings();
		if( isset($service_api_settings[$setting_name]) ) {
			unset( $service_api_settings[$setting_name] );
			update_option( $this->service_api_settings_option, $service_api_settings );
		}
		return $this->get_last_api_settings();
	}

	/**
	 * Displays the authorization notice for GDrive service
	 * 
	 * @return	resource|void
	 */
	public function api_auth_message( $active_tab, $active_section )
	{
		if ( $active_tab == 'cloud_storage' ) {
			return $this->auth_message( $this->auth_url() );
		}
	}

}

endif; // class_exists

return new Gdrive_API();
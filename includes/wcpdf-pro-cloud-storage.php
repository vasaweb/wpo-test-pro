<?php
namespace WPO\WC\PDF_Invoices_Pro;

use WPO\WC\PDF_Invoices_Pro\Cloud\Cloud_API;
use WPO\WC\PDF_Invoices_Pro\Cloud\Dropbox\Dropbox_API;
use WPO\WC\PDF_Invoices_Pro\Cloud\FTP\FTP_Upload;
use WPO\WC\PDF_Invoices_Pro\Cloud\Gdrive\Gdrive_API;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( !class_exists( '\\WPO\\WC\\PDF_Invoices_Pro\\Cloud_Storage' ) ) :

/**
 * Cloud Storage Class
 * 
 * @class \WPO\WC\PDF_Invoices_Pro\Cloud\Gdrive\Gdrive_API
 */

class Cloud_Storage {

	public $settings_name   = 'cloud_storage_settings';
	public $settings_option = 'wpo_wcpdf_cloud_storage_settings';
	public $cloud_services;

	/**
	 * Construct
	 * 
	 * @return	void
	 */
	public function __construct() {
		// Registers settings
		add_action( 'admin_init', array( $this, 'init_settings' ) );
		add_filter( 'sanitize_option_'.$this->settings_option, array( $this, 'maybe_unlink_service' ), 20, 3 );
		add_action( 'wpo_wcpdf_after_settings_page', array( $this, 'service_specific_settings_visibility' ), 10, 1 );

		// hook into main pdf plugin settings
		add_filter( 'wpo_wcpdf_settings_tabs', array( $this, 'settings_tab' ) );
		// add unlink button
		add_action( 'wpo_wcpdf_before_settings', array( $this, 'unlink' ), 10, 1 );

		// Get cloud services
		$this->cloud_services = array();
		foreach( Cloud_API::available_cloud_services() as $cloud_service ) {
			if( $cloud_service['active'] === true ) {
				$this->cloud_services[$cloud_service['slug']] = $cloud_service['name'];
			}
		}

		add_action( 'wpo_wcpdf_email_attachment', array( $this, 'upload_attachment'), 10, 3 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'upload_by_status'), 10, 4 );
		add_action( 'wpo_wcpdf_cloud_storage_upload_document', array( $this, 'upload_document' ), 10, 4 );
		add_action( 'wpo_wcpdf_cloud_storage_upload_file', array( $this, 'upload_file' ), 10, 1 );
		add_action( 'wpo_wc_ubl_attachment_file', array( $this, 'upload_ubl_attachment' ), 10, 2 );
		add_action( 'load-edit.php', array( $this, 'bulk_export' ) );
		add_action( 'load-edit.php', array( $this, 'export_queue' ) );

		// Enqueue scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		
		// Export bulk actions
		add_action( 'bulk_actions-edit-shop_order', array( $this, 'export_actions' ), 30 );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'export_actions' ), 30 ); // WC 7.1+
			
		// Upload queue
		add_action( 'admin_notices', array( $this, 'upload_queue' ) );
	}

	/**
	 * Add Cloud Storage settings tab to the PDF Invoice settings page
	 * @param  array $tabs slug => Title
	 * @return array $tabs with Cloud Storage
	 */
	public function settings_tab( $tabs ) {
		if (count($this->cloud_services) == 1) {
			$tabs['cloud_storage'] = array_pop($this->cloud_services);
		} else {
			$tabs['cloud_storage'] = __('Cloud storage', 'wpo_wcpdf_pro');
		}
		return $tabs;
	}

	/**
	 * General cloud storage settings define by the user
	 * 
	 * @return	void
	 */
	public function init_settings() {
		// Register settings.
		$page = $option_group = $option_name = $this->settings_option;
		
		$settings_fields = array(
			array(
				'type'     => 'section',
				'id'       => 'cloud_storage_general_settings',
				'title'    => __( 'General settings', 'wpo_wcpdf_pro' ),
				'callback' => 'section',
			),
			array(
				'type'     => 'setting',
				'id'       => 'enabled',
				'title'    => __( 'Enable', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'cloud_storage_general_settings',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'enabled',
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'cloud_service',
				'title'    => __( 'Cloud service', 'wpo_wcpdf_pro' ),
				'callback' => 'select',
				'section'  => 'cloud_storage_general_settings',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'cloud_service',
					'options'     => $this->cloud_services,
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'auto_upload',
				'title'    => __( 'Upload all email attachments', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'cloud_storage_general_settings',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'auto_upload',
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'year_month_folders',
				'title'    => __( 'Organize uploads in folders by year/month', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'cloud_storage_general_settings',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'year_month_folders',
				)
			),
			array(
				'type'     => 'setting',
				'id'       => 'api_log',
				'title'    => __( 'Log all communication (debugging only!)', 'wpo_wcpdf_pro' ),
				'callback' => 'checkbox',
				'section'  => 'cloud_storage_general_settings',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'api_log',
					'description' => '<a href="'.esc_url_raw( admin_url( 'admin.php?page=wc-status&tab=logs' ) ).'" target="_blank">'.__( 'View logs', 'wpo_wcpdf_pro' ).'</a>',
				)
			),
			array(
				'type'		=> 'section',
				'id'		=> 'cloud_storage_upload_by_order_status',
				'title'		=> __( 'Upload by order status', 'wpo_wcpdf_pro' ),
				'callback'	=> array( $this, 'upload_by_order_status_section' ),
			),
		);
		
		foreach ( WPO_WCPDF_Pro()->functions->get_document_type_options() as $option ) {
			$settings_fields = array_merge( $settings_fields, array(
				array(
					'type'     => 'setting',
					'id'       => "per_status_upload_{$option['value']}",
					'title'    => $option['title'],
					'callback' => 'select',
					'section'  => 'cloud_storage_upload_by_order_status',
					'args'     => array(
						'option_name'      => $option_name,
						'id'               => "per_status_upload_{$option['value']}",
						'options_callback' => 'wc_get_order_statuses',
						'multiple'         => true,
						'enhanced_select'  => true,
					)
				)
			) );
		}
	
		// allow plugins to alter settings fields
		$settings_fields = apply_filters( 'wpo_wcpdf_settings_fields_cloud_storage', $settings_fields, $page, $option_group, $option_name );
		WPO_WCPDF()->settings->add_settings_fields( $settings_fields, $page, $option_group, $option_name );
	}

	/**
	 * Toggle visibility of service specific settings
	 *
	 * @param  string $tab active settings tab
	 */
	public function service_specific_settings_visibility( $tab = '' ) {
		$service_specific_settings = apply_filters( 'wpo_wcpdf_cloud_service_specific_settings', array() );
		if ( $tab == 'cloud_storage' && ! empty( $service_specific_settings ) ) {
			?>
			<script>
				jQuery( function( $ ) {
					$('select#cloud_service').on('change', function(){
						let selected_service = $(this).val();
						let service_specific_settings = <?= json_encode($service_specific_settings) ?>;
						$.each( service_specific_settings, function( service, settings ) {
							$.each( settings, function( index, setting_id ) {
								if ( service == selected_service ) {
									$('#'+setting_id).closest('tr').show();
								} else {
									$('#'+setting_id).closest('tr').hide();
								}
							});
							// console.log(service);
							// console.log(settings);
						});
					}).trigger('change');
				});
			</script>
			<?php
		}
	}
	
	/**
	 * Custom fields section callback.
	 *
	 * @return void.
	 */
	public function upload_by_order_status_section() {
		echo wp_kses_post( __( 'If you are already emailing the documents, leave these settings empty to avoid slowing down your site (use the setting to upload all email attachments instead)', 'wpo_wcpdf_pro' ) );
	}

	/**
	 * Button to unlink cloud service account
	 * 
	 * @return	void
	 */
	public function unlink( $tab ): void {
		if ( ! Cloud_API::is_enabled() ) {
			return;
		}

		// Remove API details if requested.
		if ( isset( $_REQUEST[ 'wpo_wcpdf_unlink_' . Cloud_API::service_enabled() ] ) ) {
			delete_option( 'wpo_wcpdf_' . Cloud_API::service_enabled() . '_api_settings', '' );
			wp_redirect( esc_url_raw( remove_query_arg( 'wpo_wcpdf_unlink_' . Cloud_API::service_enabled() ) ) );
			exit();
		}

		// Display unlink button if we have an access token.
		$service_api_settings = get_option( 'wpo_wcpdf_' . Cloud_API::service_enabled() . '_api_settings' );
		if ( 'cloud_storage' === $tab && isset( $service_api_settings['access_token'] ) ) {
            echo '<div class="wcpdf-cloud-service-connection">';

			if ( ! empty( $service_api_settings['account_info'] ) ) {
				printf( '<p><strong>&#10004; ' . __( 'Connected to', 'wpo_wcpdf_pro' ) . ' ' . Cloud_API::$service_name . ':</strong> %s</p>', $service_api_settings['account_info'] );
			}

			$unlink_url = add_query_arg( 'wpo_wcpdf_unlink_' . Cloud_API::service_enabled(), 'true' );

			/* translators: service name */
			printf( '<a href="%s" class="button">' . __( 'Unlink %s account', 'wpo_wcpdf_pro' ) . '</a>', esc_url( $unlink_url ), Cloud_API::$service_name );

            echo '</div>';
		}
	}

	/**
	 * Get a list of WooCommerce order statuses (without the wc- prefix)
	 *
	 * @return  array status slug => status name
	 */
	public function get_order_statuses() {
		$statuses = wc_get_order_statuses();
		foreach ( $statuses as $status_slug => $status ) {
			$status_slug   = 'wc-' === substr( $status_slug, 0, 3 ) ? substr( $status_slug, 3 ) : $status_slug;
			$order_statuses[$status_slug] = $status;
		}

		return $order_statuses;
	}

	/**
	 * Check if we need to unlink the cloud service
	 *
	 * @param string $value          The sanitized option value.
	 * @param string $option         The option name.
	 * @param string $original_value The original value passed to the function. (since WP4.3)
	 *
	 * @return string
	 */
	public function maybe_unlink_service( $value, $option, $original_value = null ) {
		// get general settings
		$last_settings = get_option( $this->settings_option );

		// unlink app if access_type changed
		$last_access_type = isset($last_settings['access_type']) ? $last_settings['access_type'] : null;
		$new_access_type  = isset($value['access_type'])         ? $value['access_type']         : null;
		if ( ($last_access_type != $new_access_type) && isset($last_settings['cloud_service']) ) {
			delete_option( 'wpo_wcpdf_'.$last_settings['cloud_service'].'_api_settings' );
		}

		return $value;
	}

	/**
	 * Upload PDF to cloud service during/after email attachment
	 * 
	 * @return	void
	 */
	public function upload_attachment( $file, $document_type = '', $document = null ) {
		// check if we have a cloud service
		if ( empty($cloud_service_slug = Cloud_API::service_enabled()) ) {
			return;
		}

		// get service api settings
		$service_settings = get_option( $this->settings_option );
		
		// check if upload enabled
		if ( !isset($service_settings['auto_upload']) || $service_settings['auto_upload'] == 0 || Cloud_API::is_enabled() === false ) {
			return;
		}

		if ( !empty($document) && !empty($document->order) ) {
			$this->upload_to_service( $file, 'attachment', $document->order, $document->get_type() );
		} else {
			$this->upload_to_service( $file, 'attachment', null, null );			
		}
	}

	/**
	 * Upload PDF to cloud service during/after email attachment
	 * 
	 * @return	void
	 */
	public function upload_by_status( $order_id, $old_status, $new_status, $order ) {
		// check if we have a cloud service
		if ( empty( $cloud_service_slug = Cloud_API::service_enabled() ) ) {
			return;
		}

		// get service api settings
		$service_settings           = get_option( $this->settings_option );
		$per_status_upload_settings = [];
		
		foreach ( $service_settings as $setting_key => $setting_value ) {
			if ( strpos( $setting_key, 'per_status_upload_' ) !== false ) {
				$document_type = str_replace( 'per_status_upload_', '', $setting_key );
				$per_status_upload_settings[$document_type] = $setting_value;
			}
		}
		
		// check if upload enabled
		if ( empty( $per_status_upload_settings ) || Cloud_API::is_enabled() === false ) {
			return;
		}

		foreach ( $per_status_upload_settings as $document_type => $upload_statuses ) {
			if ( empty( $upload_statuses ) ) {
				continue;
			}
			
			if ( false !== strpos( $document_type, '_ubl' ) ) {
				$document_type = str_replace( '_ubl', '', $document_type );
				$output_format = 'ubl';
			} else {
				$output_format = 'pdf';
			}
			
			foreach ( $upload_statuses as $upload_status ) {
				$upload_status = str_replace( 'wc-', '', $upload_status );
				
				// check if new status matches upload status for document
				if ( $new_status === $upload_status ) {
					// check if free order + free invoice disabled
					$document_settings = WPO_WCPDF()->settings->get_document_settings( $document_type, $output_format );
					$free_disabled     = isset( $document_settings['disable_free'] );

					if ( $free_disabled ) {
						$order_total = $order->get_total();
						
						if ( $order_total == 0 ) {
							continue;
						}
					}

					// hook into upload by status
					do_action( 'wpo_wcpdf_cloud_storage_upload_by_status', $document_type, $order, $new_status, $old_status, $output_format );

					// upload file to cloud service
					$upload_response = $this->upload_document( $document_type, $order, 'status', $output_format );
					
					if ( ! $upload_response ) {
						Cloud_API::log( 'error', 'upload failed for order ID #' . $order_id . ', document type ' . $document_type . ' and context upload_by_status().' );
					}
				}
			}
		}
	}

	/**
	 * Create and upload PDF document to cloud service
	 * 
	 * @param string $document_type
	 * @param object $order
	 * @param string $context
	 * @param string $output_format
	 * 
	 * @return array|false|void
	 */
	public function upload_document( string $document_type, object $order, string $context = 'action_hook', string $output_format = 'pdf' ) {
		if ( ! $order ) {
			Cloud_API::log( 'error', 'Invalid order object' );
			return false;
		}
		
		// NOTE:
		// we use ID to force to reloading the order to make sure that all meta data is up to date.
		// this is especially important when multiple emails with the PDF document are sent in the same session
		$document = wcpdf_get_document( $document_type, (array) $order->get_id(), true );
				
		if ( ! $document || ! $document->exists() ) {
			Cloud_API::log( 'error', 'Couldn\'t get or create document for upload' );
			return false;
		}
		
		try {
			$file_path = wcpdf_get_document_file( $document, $output_format );
		} catch ( \Exception $e ) {
			wcpdf_log_error( $e->getMessage(), 'critical', $e );
			return false;
		} catch ( \Dompdf\Exception $e ) {
			wcpdf_log_error( 'DOMPDF exception: ' . $e->getMessage(), 'critical', $e );
			return false;
		} catch ( \Throwable $e ) {
			wcpdf_log_error( $e->getMessage(), 'critical', $e );
			return false;
		}
		
		if ( empty( $file_path ) && ! file_exists( $file_path ) ) {
			Cloud_API::log( 'error', 'File does not exist!' );
			return false;
		}

		// upload file to cloud service
		return $this->upload_to_service( $file_path, $context, $order, $document_type );
	}

	/**
	 * Upload file to cloud service
	 * 
	 * @return	void
	 */
	public function upload_file( $file ) {
		if( empty( $file ) ) {
			return;
		}

		// upload file to cloud service
		$this->upload_to_service( $file, 'upload_file', null, null );
	}

	/**
	 * Upload UBL attachment to cloud service
	 * 
	 * @return	void
	 */
	public function upload_ubl_attachment( $file, $order ) {
		if( empty( $file ) ) {
			return;
		}

		// upload file to cloud service
		$this->upload_to_service( $file, 'attachment', $order, null );
	}

	/**
	 * Upload file to cloud service
	 * 
	 * @return	array|void
	 */
	public function upload_to_service( $file, $context = 'attachment', $order = null, $document_type = null ) {
		// check if enabled
		if ( Cloud_API::is_enabled() === false ) {
			return;
		}

		// check if we have a cloud service
		if ( empty( $cloud_service_slug = Cloud_API::service_enabled() ) ) {
			return;
		}

		// custom hook to allow/stop the file upload
		$allow_upload = apply_filters( 'wpo_wcpdf_custom_cloud_service_allow_upload', true, $file, $order, $document_type, $cloud_service_slug );

		if ( ! empty( $file ) && file_exists( $file ) && $allow_upload === true ) {

			Cloud_API::log( 'info', 'Upload to '.$cloud_service_slug.' initiated' );
			
			$result = $this->upload_service_selection( $file, $order, $document_type );
			
			if ( isset( $result['error'] ) ) {
				Cloud_API::log( 'error', "{$cloud_service_slug} upload permission denied" );
				
				// there was an error uploading the file, copy file to queue
				$this->queue_file( $file, $order, $document_type );
				
				return array( 'error' => __( 'Cloud service upload permission denied', 'wpo_wcpdf_pro' ) );
			} else {
				// unless we are uploading attachments (which are needed in other processes), delete the file after uploading
				if ( apply_filters( 'wpo_wcpdf_cloud_delete_temporary_file_after_upload', ( ! in_array( $context, array( 'attachment', 'upload_file' ) ) ), $file, $context, $document_type ) ) {
					@unlink( $file );
				}
				return $result;
			}
			
		} else {
			Cloud_API::log( 'error', 'file does not exist!' );
			return array( 'error' => __( 'File does not exist', 'wpo_wcpdf_pro' ) );
		}
	}

	/**
	 * Selects the correct cloud service to upload
	 * 
	 * @return	array
	 */
	public function upload_service_selection( $file, $order, $document_type )
	{
		switch ( Cloud_API::service_enabled() ) {
			case 'dropbox':
				$dropbox = new Dropbox_API;
				$result = $dropbox->upload( $file, $order, $document_type );
				break;
			case 'ftp':
				$ftp = new FTP_Upload;
				$result = $ftp->upload( $file, $order, $document_type );
				break;
			case 'gdrive':
				$gdrive = new Gdrive_API;
				$result = $gdrive->upload( $file, $order, $document_type );
				break;
			default:
				$result = false;
				break;
		}

		return $result;
	}

	/**
	 * Export PDFs in bulk from the order actions drop down
	 * 
	 * @return void
	 */
	public function bulk_export() {
		// check if enabled
		if ( false === Cloud_API::is_enabled() ) {
			return;
		}
		
		if ( isset( $_GET['post_type'] ) && 'shop_order' === wp_unslash( $_GET['post_type'] ) ) {
			
			// Check if all parameters are set
			if ( ( empty( $_GET['order_ids'] ) && empty( $_REQUEST['post'] ) ) || empty( $_GET['action'] ) ) {
				return;
			}

			// Check the user privileges
			if ( ! current_user_can( 'manage_woocommerce_orders' ) && ! current_user_can( 'edit_shop_orders' ) && ! isset( $_GET['my-account'] ) ) {
				return;
			}
			
			// convert order_ids to array if set
			if ( isset( $_GET['order_ids'] ) ) {
				$order_ids = (array) explode( 'x', $_GET['order_ids'] );
			} else {
				$order_ids = (array) $_REQUEST['post'];
			}
			
			if ( empty( $order_ids ) ) {
				return;
			}

			// Process oldest first: reverse $order_ids array
			$order_ids = array_reverse( $order_ids );
			
			// get the action
			$wp_list_table        = _get_list_table( 'WP_Posts_List_Table' );
			$action               = $wp_list_table->current_action();
			$export_action_prefix = 'wpo_wcpdf_cloud_service_export_';

			if ( 'wpo_wcpdf_cloud_service_export_process' === $action ) {
				$document_type = esc_attr( $_GET['template'] );
				$this->bulk_export_process( $order_ids, $document_type );
			} elseif ( ! empty( $export_action_prefix ) && ! empty( $action ) && false !== strpos( $action, $export_action_prefix ) ) {
				$document_type = str_replace( $export_action_prefix, '', $action );
				$this->bulk_export_page( $order_ids, $document_type );
			} else {
				return;
			}

			exit();
		}
	}

	/**
	 * Process export queue
	 * 
	 * @return void
	 */
	public function export_queue() {
		// check if enabled
		if ( Cloud_API::is_enabled() === false ) {
			return;
		}

		if ( isset( $_GET['post_type'] ) && 'shop_order' === wp_unslash( $_GET['post_type'] ) ) {
			$action        = isset( $_GET['action'] ) && ! empty( $_GET['action'] ) ? str_replace( 'wpo_wcpdf_cloud_service_', '', esc_attr( $_GET['action'] ) ) : '';
			$valid_actions = array(
				'upload_queue',
				'clear_queue',
				'queue_process',
			);

			// Check action
			if ( ! in_array( $action, $valid_actions ) ) {
				return;
			}

			// Check the user privileges
			if ( ! current_user_can( 'manage_woocommerce_orders' ) && ! current_user_can( 'edit_shop_orders' ) && ! isset( $_GET['my-account'] ) ) {
				return;
			}
			
			switch ( $action ) {
				case 'upload_queue':
					$this->queue_page( 'upload' );
					break;
				case 'clear_queue':
					$this->queue_page( 'clear' );
					break;
				case 'queue_process':
					$do = isset( $_GET['do'] ) ? esc_attr( $_GET['do'] ) : '';
					$this->queue_process( $do );
					break;
				default:
					return;
			}

			exit();
		}
	}

	/**
	 * Displays the queue notification modal
	 * 
	 * @return	void
	 */
	public function queue_page ( $do ) {
		// create url/path to process page
		$action_args = array (
			'action'	=> 'wpo_wcpdf_cloud_service_queue_process',
			'do'		=> $do,
		);

		$new_page = add_query_arg( $action_args, remove_query_arg( 'action' ) );

		// render pre-export page (waiting page with spinner)
		if ( $do == 'upload' ) {
			/* translators: service name */
			$message = sprintf( __( 'Please wait while your queued PDF documents are being uploaded to %s...', 'wpo_wcpdf_pro' ), Cloud_API::$service_name );
		} else {
			$message = __( 'Please wait while the upload queue is being cleared', 'wpo_wcpdf_pro' );
		}

		$service_name = Cloud_API::$service_name;
		$plugin_url = WPO_WCPDF_Pro()->plugin_url();
		
		include( WPO_WCPDF_Pro()->plugin_path().'/includes/cloud/templates/template-bulk-export-page.php');
	}

	/**
	 * Displays the bulk export notification modal
	 * 
	 * @return	void
	 */
	public function bulk_export_page ( $order_ids, $template_type ) {
		// create url/path to process page
		$action_args  = array (
			'action'	=> 'wpo_wcpdf_cloud_service_export_process',
			'template'	=> $template_type,
		);

		// render pre-export page (waiting page with spinner)
		/* translators: service name */
		$message      = sprintf( __( 'Please wait while your PDF documents are being uploaded to %s...', 'wpo_wcpdf_pro' ), Cloud_API::$service_name );
		$new_page     = add_query_arg( $action_args, remove_query_arg( 'action' ) );
		$service_name = Cloud_API::$service_name;
		$plugin_url   = WPO_WCPDF_Pro()->plugin_url();

		include( WPO_WCPDF_Pro()->plugin_path().'/includes/cloud/templates/template-bulk-export-page.php');
	}		

	/**
	 * Bulk export process
	 * 
	 * @param array  $order_ids
	 * @param string $document_type
	 * 
	 * @return	void
	 */
	public function bulk_export_process( array $order_ids, string $document_type ): void {
		foreach ( $order_ids as $order_id ) {
			if ( false !== strpos( $document_type, '_ubl' ) ) {
				$_document_type = str_replace( '_ubl', '', $document_type );
				$output_format  = 'ubl';
			} else {
				$_document_type = $document_type;
				$output_format  = 'pdf';
			}
			
			$order = wc_get_order( absint( $order_id ) );
			
			if ( ! $order ) {
				Cloud_API::log( 'error', 'invalid order object: #' . $order_id );
				continue;
			}
			
			$upload_response = $this->upload_document( $_document_type, $order, 'export', $output_format );
			
			if ( ! $upload_response ) {
				$error_message = 'upload failed for order ID #' . $order_id . ', document type ' . $document_type . ' and context bulk_export_process().';
				Cloud_API::log( 'error', $error_message );
				$errors[ $order_id ] = $error_message;
				continue;
			}

			if ( ! empty( $upload_response['error'] ) ) {
				// Houston, we have a problem
				$errors[ $order_id ] = $upload_response['error'];
			}
		}

		// render export done page
		if ( isset( $errors ) ) {
			$service_api_settings = get_option( 'wpo_wcpdf_' . Cloud_API::service_enabled() . '_api_settings' );
			
			$message  = sprintf(
				/* translators: cloud service name */
				__( 'There were errors when trying to upload to %s.', 'wpo_wcpdf_pro' ),
				Cloud_API::$service_name
			) .'<br><br>';
			
			// mention pro license
			if ( 'valid' !== WPO_WCPDF_Pro()->functions->get_pro_license_status() ) {
				$message .= __( 'Your Professional extension license is not valid or is inactive.', 'wpo_wcpdf_pro' );
				
			// mention logs
			} else {
				if ( ! isset( $service_api_settings['api_log'] ) ) {
					$message  .= sprintf(
						/* translators: here link */
						__( 'Please enable the service logging %s to be able to debug the issue.', 'wpo_wcpdf_pro' ),
						'<a href="' . esc_url_raw( admin_url( 'admin.php?page=wpo_wcpdf_options_page&tab=cloud_storage' ) ) . '" target="_blank">' . __( 'here', 'wpo_wcpdf_pro' ) . '</a>'
						);
				} else {
					$view_log  = '<a href="' . esc_url_raw( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '" target="_blank">'.__( 'View logs', 'wpo_wcpdf_pro' ).'</a>';
					$message  .= __( 'Check the error log for details:', 'wpo_wcpdf_pro' ) . '<br>' . $view_log;
				}
			}
			
		} else {
			$message = sprintf(
				/* translators: cloud service name */
				__( 'Documents successfully uploaded to %s!', 'wpo_wcpdf_pro' ),
				Cloud_API::$service_name
			);
		}

		$service_name = Cloud_API::$service_name;
		$plugin_url   = WPO_WCPDF_Pro()->plugin_url();

		include WPO_WCPDF_Pro()->plugin_path() . '/includes/cloud/templates/template-bulk-export-process.php';		
	}

	/**
	 * Adds PDF file to queue
	 * 
	 * @return	void
	 */
	public function queue_file ( $file, $order = null, $document_type = null ) {
		$queue_folder = $this->get_queue_path();
		$filename = basename($file);
		$queue_file = $queue_folder . $filename;
		copy( $file, $queue_file );

		// store order reference in db if available
		if (!empty($order) && is_object($order)) {
			$cloud_service_queue = get_option( 'wpo_wcpdf_'.Cloud_API::$service_slug.'_queue', array() );
			if (!isset($cloud_service_queue[$queue_file])) {
				$order_id = method_exists($order, 'get_id') ? $order->get_id(): $order->id;
				$cloud_service_queue[$queue_file] = array(
					'order_id'		=> $order_id,
					'document_type'	=> $document_type,
				);
				update_option( 'wpo_wcpdf_'.Cloud_API::$service_slug.'_queue', $cloud_service_queue );
			}
		}

		Cloud_API::log( 'info', "file placed in queue: {$queue_file}" );
	}

	/**
	 * Gets the queue path
	 * 
	 * @return	string|void
	 */
	public function get_queue_path () {
		if ( ! function_exists('WPO_WCPDF') && empty( WPO_WCPDF()->main ) ) {
			return;
		} 

		$queue_path = trailingslashit( WPO_WCPDF()->main->get_tmp_path( Cloud_API::$service_slug ) );

		// make sure the queue path is protected!
		// create .htaccess file and empty index.php to protect in case an open webfolder is used!
		if ( !file_exists($queue_path . '.htaccess') || !file_exists($queue_path . 'index.php') ) {
			@file_put_contents( $queue_path . '.htaccess', 'deny from all' );
			@touch( $queue_path . 'index.php' );
		}
		return $queue_path;
	}

	/**
	 * Gets the queued files
	 * 
	 * @return	array|bool
	 */
	public function get_queued_files ( $value = '' ) {
		// get list of all files in the queue folder
		$queue_folder = $this->get_queue_path();
		$queued_files = scandir( $queue_folder );
		$queued_files = ! $queued_files ? [] : $queued_files;
		
		// remove . & ..
		$queued_files = array_diff( $queued_files, array( '.', '..', '.htaccess', 'index.php', '.DS_Store' ) );

		if ( ! count( $queued_files ) > 0 ) {
			// no files in queue;
			return false;
		} else {
			return $queued_files;
		}
	}

	/**
	 * Process to upload and clear queue
	 * 
	 * @return	void
	 */
	public function queue_process ( $do ) {
		// check if enabled
		if ( false === Cloud_API::is_enabled() ) {
			return;
		}
		
		if ( empty( $do ) ) {
			return;
		}

	 	switch ( $do ) {
	 		case 'upload':
				$queued_files = $this->get_queued_files();
				
				if ( ! empty( $queued_files ) ) {
					$cloud_service_queue = get_option( 'wpo_wcpdf_'.Cloud_API::$service_slug.'_queue', array() );
					
					foreach ( $queued_files as $queued_file ) {
						$file_path = $this->get_queue_path() . $queued_file;

						// load order if we have stored it
						if ( ! empty( $cloud_service_queue[ $file_path ] ) && is_array( $cloud_service_queue[ $file_path ] ) ) {
							$document_type = $cloud_service_queue[$file_path]['document_type'];
							$order_id      = $cloud_service_queue[$file_path]['order_id'];
							$order         = wc_get_order( $order_id );
						} else {
							$document_type = null;
							$order         = null;
						}
						
						// upload file to cloud service
						$upload_response = $this->upload_to_service( $file_path, 'export', $order, $document_type );

						if ( ! empty( $upload_response['error'] ) ) {
							// Houston, we have a problem
							$errors[] = $upload_response['error'];
							Cloud_API::log( 'error', $upload_response['error'] );
							
						} else {
							// remove file
							if ( file_exists( $file_path ) ) {
								unlink( $file_path );
							}
							
							// and from queue reference
							if ( isset( $cloud_service_queue[ $file_path ] ) ) {
								unset( $cloud_service_queue[ $file_path ] );
								update_option( 'wpo_wcpdf_'.Cloud_API::$service_slug.'_queue', $cloud_service_queue );
							}
						}
					}						
				}
	 			break;
	 		case 'clear':
	 			// delete all pdf files from queue folder
	 			$queue_path     = $this->get_queue_path();
				$output_formats = array( 'pdf', 'ubl' );
				
				foreach ( $output_formats as $output_format ) {
					array_map( 'unlink', ( glob( $queue_path . '*' . wcpdf_get_document_output_format_extension( $output_format ) ) ? glob( $queue_path . '*' . wcpdf_get_document_output_format_extension( $output_format ) ) : array() ) );
				}
				
				// delete queue option
				delete_option( 'wpo_wcpdf_'.Cloud_API::$service_slug.'_queue' );
	 			break;
	 	}

		// render export done page
		if ( isset( $errors ) ) {
			$service_api_settings = get_option( 'wpo_wcpdf_' . Cloud_API::service_enabled() . '_api_settings' );
			
			$message  = sprintf( __( 'There were errors when trying to upload to %s.', 'wpo_wcpdf_pro' ), Cloud_API::$service_name ) .'<br><br>';
			
			// mention pro license
			if ( 'valid' !== WPO_WCPDF_Pro()->functions->get_pro_license_status() ) {
				$message .= __( 'Your Professional extension license is not valid or is inactive.', 'wpo_wcpdf_pro' );
				
			// mention logs
			} else {
				if ( ! isset( $service_api_settings['api_log'] ) ) {
					$message  .= sprintf(
						/* translators: here link */
						__( 'Please enable the service logging %s to be able to debug the issue.', 'wpo_wcpdf_pro' ),
						'<a href="' . esc_url_raw( admin_url( 'admin.php?page=wpo_wcpdf_options_page&tab=cloud_storage' ) ) . '" target="_blank">' . __( 'here', 'wpo_wcpdf_pro' ) . '</a>'
						);
				} else {
					$view_log  = '<a href="' . esc_url_raw( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '" target="_blank">'.__( 'View logs', 'wpo_wcpdf_pro' ).'</a>';
					$message  .= __( 'Check the error log for details:', 'wpo_wcpdf_pro' ) . '<br>' . $view_log;
				}
			}
			
		} elseif ( $do == 'upload' ) {
			$message = sprintf(
				/* translators: service name */
				__( 'PDF documents successfully uploaded to %s!', 'wpo_wcpdf_pro' ),
				Cloud_API::$service_name
			);
			
		} else {
			$message = __( 'Upload queue successfully cleared!', 'wpo_wcpdf_pro' );
		}

		$service_name = Cloud_API::$service_name;
		$plugin_url = WPO_WCPDF_Pro()->plugin_url();

		include( WPO_WCPDF_Pro()->plugin_path().'/includes/cloud/templates/template-bulk-export-process.php');			
	}

	/**
	 * Display notification about upload queue with link to process queue
	 * 
	 * @return void
	 */
	public function upload_queue() {
		$queue = $this->get_queued_files();
		if ( !empty($queue) && Cloud_API::is_enabled() ) {
			$files_count = count($queue);

			$upload_button	= '<a href="edit.php?post_type=shop_order&action=wpo_wcpdf_cloud_service_upload_queue" class="button-primary" id="cloud_service_upload_queue">'.__( 'Upload files', 'wpo_wcpdf_pro' ).'</a>';
			$clear_button	= '<a href="edit.php?post_type=shop_order&action=wpo_wcpdf_cloud_service_clear_queue"  class="button-primary" id="cloud_service_clear_queue" >'.__( 'Clear queue', 'wpo_wcpdf_pro' ).'</a>';

			// display message
			?>
				<div class="updated">
					<?php /* translators: 1. files count, service name */ ?>
					<p><?php printf( __( 'There are %1$s unfinished uploads in your the upload queue from PDF Invoices & Packing Slips for WooCommerce to %2$s.', 'wpo_wcpdf_pro' ), $files_count, Cloud_API::$service_name ); ?></p>
					<p><?php echo $upload_button . ' ' . $clear_button; ?></p>
				</div>
			<?php			

		}
	}

	/**
	 * Add cloud service actions to bulk action drop down menu, WP3.5+
	 */
	public function export_actions( $actions ) {
		if ( Cloud_API::is_enabled() ) {
			foreach ( WPO_WCPDF_Pro()->functions->get_document_type_options() as $option ) {
				$actions["wpo_wcpdf_cloud_service_export_{$option['value']}"] = sprintf(
					/* translators: 1. document title, 2. service name */
					__( '%1$s to %2$s', 'wpo_wcpdf_pro' ),
					$option['title'],
					Cloud_API::$service_name
				);
			}
		}
		return $actions;
	}

	/**
	 * Enqueue scripts
	 * 
	 * @return	void
	 */
	public function enqueue_scripts() {
		$screen = get_current_screen();
		
		if ( ! is_null( $screen ) && in_array( $screen->id, array( 'shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders' ) ) ) {
			wp_enqueue_script(
				'wcpdf-pro-cloud-storage-export',
				WPO_WCPDF_Pro()->plugin_url() . '/assets/js/pro-cloud-storage-export.js',
				array( 'jquery', 'thickbox' ),
				WPO_WCPDF_PRO_VERSION
			);
		}

		if ( $this->get_queued_files() ) {
			wp_enqueue_style( 'thickbox' );
			wp_enqueue_script(
				'wcpdf-pro-cloud-storage-queue',
				WPO_WCPDF_Pro()->plugin_url() . '/assets/js/pro-cloud-storage-queue.js',
				array( 'jquery', 'thickbox' ),
				WPO_WCPDF_PRO_VERSION
			);
		}

		wp_enqueue_style(
			'wcpdf-pro-cloud-storage-styles',
			WPO_WCPDF_Pro()->plugin_url() . '/assets/css/cloud-storage-styles.css',
			array(),
			WPO_WCPDF_PRO_VERSION
		);
	}

} // end class

endif; // end class_exists

return new Cloud_Storage();

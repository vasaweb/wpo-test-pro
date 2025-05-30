<?php
namespace WPO\WC\PDF_Invoices_Pro;

use WPO\WC\PDF_Invoices_Pro\Cloud\Cloud_API;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( !class_exists( '\\WPO\\WC\\PDF_Invoices_Pro\\Bulk_Export' ) ) :

class Bulk_Export {
	public function __construct() {
		// hook into main pdf plugin settings
		add_filter( 'wpo_wcpdf_settings_tabs', array( $this, 'settings_tab' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'load_scripts_styles' ) ); // Load scripts & styles

		// bulk export page
		add_action( 'wpo_wcpdf_after_settings_form', array( $this, 'bulk_export_tab' ), 10, 1 );
		add_filter( 'wpo_wcpdf_settings_form_excluded_sections', array( $this, 'exclude_bulk_export_from_settings_form') );

		// Bulk export ajax actions
		add_action( 'wp_ajax_wpo_wcpdf_export_get_order_ids', array( $this, 'ajax_get_order_ids' ) );
		add_action( 'wp_ajax_wpo_wcpdf_export_bulk', array( $this, 'save_bulk' ) );
		add_action( 'wp_ajax_wpo_wcpdf_zip_bulk', array( $this, 'zip_bulk' ) );
		add_action( 'wp_ajax_wpo_wcpdf_download_file', array( $this, 'download_file' ) );
		add_action( 'wp_ajax_wpo_wcpdf_cloud_upload', array( $this, 'cloud_upload' ) );
		add_action( 'wp_ajax_wpo_wcpdf_search_users', array( $this, 'search_users' ) );

		// Delete cache action
		add_action( 'wpo_wcpdf_pro_bulk_export_delete_cache', array( $this, 'delete_cache' ) );
	}

	/**
	 * add Bulk Export settings tab to the PDF Invoice settings page
	 * @param  array $tabs slug => Title
	 * @return array $tabs with Bulk Export
	 */
	public function settings_tab( $tabs ) {
		// if (WPO_WCPDF_Dropbox()->api->is_enabled() !== false) {
			$tabs['bulk_export'] = __( 'Bulk export', 'wpo_wcpdf_pro' );
		// }

		return $tabs;
	}

	/**
	 * Scrips & styles for settings page
	 */
	public function load_scripts_styles( $hook ) {
		$tab  = isset( $_GET['tab'] ) ? $_GET['tab'] : '';
		$page = isset( $_GET['page'] ) ? $_GET['page'] : '';
		if ( 'wpo_wcpdf_options_page' !== $page || 'bulk_export' !== $tab ) {
			return;
		}

		wp_enqueue_style(
			'woocommerce-pdf-ips-pro-jquery-ui-style',
			'https://ajax.googleapis.com/ajax/libs/jqueryui/1.8.2/themes/smoothness/jquery-ui.css'
		);
		wp_enqueue_script(
			'woocommerce-pdf-pro-bulk',
			WPO_WCPDF_Pro()->plugin_url() . '/assets/js/pro-bulk-export.js',
			array( 'jquery', 'jquery-ui-datepicker', 'wc-enhanced-select' ),
			WPO_WCPDF_PRO_VERSION
		);
		wp_localize_script(
			'woocommerce-pdf-pro-bulk',
			'woocommerce_pdf_pro_bulk',
			array(
				'ajax_url'        => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'wpo_wcpdf_pro_bulk' ),
				'chunk_size'      => apply_filters( 'wpo_wcpdf_export_bulk_chunk_size', 5 ),
				// send following dynamic variables for accessibility
				'order_date'      => __( 'Order date', 'wpo_wcpdf_pro' ),
				'refund_date'     => __( 'Refund date', 'wpo_wcpdf_pro' ),
				'download_zip'    => __( 'Download ZIP', 'wpo_wcpdf_pro' ),
				'download_pdf'    => __( 'Download PDF', 'wpo_wcpdf_pro' ),
				'documents_saved' => __( 'document(s) saved', 'wpo_wcpdf_pro' ),
				'uploading'       => __( 'Uploading files to cloud service, please wait...', 'wpo_wcpdf_pro' ),
			)
		);

	}

	public function bulk_export_tab( $tab ) {
		if ( 'bulk_export' === $tab ) {
			if ( ! is_null( WPO_WCPDF_Pro()->cloud_api ) ) {
				$cloud_api_is_enabled = Cloud_API::is_enabled();
				$cloud_service_name   = Cloud_API::$service_name;
				$cloud_service_slug   = Cloud_API::service_enabled();
			}
			
			include( WPO_WCPDF_Pro()->plugin_path() . '/includes/views/bulk-export.php' );
		}
	}

	public function exclude_bulk_export_from_settings_form( array $sections ): array {
		$sections[] = 'bulk_export';
		return $sections;
	}

	/**
	 * Handle AJAX request
	 */
	public function ajax_get_order_ids() {
		check_ajax_referer( 'wpo_wcpdf_pro_bulk', 'security' );

		if ( ! isset( $_POST['status_filter'] ) && ! ( isset( $_POST['date_type'] ) && 'document_date' === $_POST['date_type'] ) ) {
			$return = array(
				'error'  => __( 'No orders found!', 'wpo_wcpdf_pro' ),
				'posted' => var_export( $_POST, true ),
			);
			echo json_encode( $return );
			exit();
		}

		// get in utc timestamp for WC3.1+
		$document_type   = sanitize_text_field( $_POST['document_type'] );
		// get dates from input
		$export_settings = array(
			'date_after'    => $this->get_date_string_from_input( 'date_from', 'hour_from', 'minute_from', false, true ),
			'date_before'   => $this->get_date_string_from_input( 'date_to', 'hour_to', 'minute_to', true, true ),
			'date_type'     => esc_attr( $_POST['date_type'] ),
			'document_type' => $document_type,
			'statuses'      => $_POST['status_filter'] ?? array(),
			'users'         => $_POST['users_filter'] ?? array(),
			'skip_free'     => isset( $_POST['skip_free'] ) && 'true' === $_POST['skip_free'],
			'only_existing' => isset( $_POST['only_existing'] ) && 'true' === $_POST['only_existing'],
		);

		// save export settings temporarily to be used by save_bulk() function
		$cache     = "wpo_wcpdf_bulk_export_settings_{$document_type}";
		$set_cache = $this->set_cache( $cache, $export_settings );
		
		if ( false === $set_cache ) {
			wcpdf_log_error( sprintf( 'Could not set cache for bulk export settings (%s)', $cache ), 'critical' );
		}

		$order_ids = $this->get_orders( $export_settings );

		if ( ! empty( $order_ids ) && ( $export_settings['only_existing'] || $export_settings['skip_free'] ) ) {
			foreach ( $order_ids as $key => $order_id ) {
				$order = wc_get_order( $order_id );
				
				if ( empty( $order ) ) {
					unset( $order_ids[ $key ] );
					continue;
				}

				// Skip free orders.
				if ( $export_settings['skip_free'] && method_exists( $order, 'get_total' ) && 0 == $order->get_total() ) {
					unset( $order_ids[ $key ] );
					continue;
				}

				// Only existing documents.
				if ( $export_settings['only_existing'] ) {
					$document = ( 'summary' === $export_settings['document_type'] ) ? wcpdf_get_invoice( $order ) : wcpdf_get_document( $export_settings['document_type'], $order );

					if ( ! $document || ! $document->exists() ) {
						unset( $order_ids[ $key ] );
						continue;
					}
				}
			}
		}

		if ( empty( $order_ids ) ) {
			$return = array(
				'error' => __( 'No orders found!', 'wpo_wcpdf_pro' ),
			);
			echo json_encode( $return );
		} else {
			echo json_encode( array_values( $order_ids ) );
		}

		exit();
	}

	public function save_bulk() {
		check_ajax_referer( 'wpo_wcpdf_pro_bulk', 'security' );

		if ( empty( $_POST['order_ids'] ) ) {
			$return = array(
				'error' => __( 'No orders found!', 'wpo_wcpdf_pro' ),
			);
			echo json_encode( $return );
			exit();
		}

		$return        = array();
		$order_ids     = $_POST['order_ids'];
		$document_type = esc_attr( $_POST['document_type'] );
		$output_format = esc_attr( $_POST['output_format'] );
		$skip_free     = isset( $_POST['skip_free'] ) && 'true' === $_POST['skip_free'];
		$only_existing = isset( $_POST['only_existing'] ) && 'true' === $_POST['only_existing'];
		$args          = array(
			'order_ids'     => $order_ids,
			'document_type' => $document_type,
			'output_format' => $output_format,
			'skip_free'     => $skip_free,
			'only_existing' => $only_existing
		);

		// Allows an external bulk handler to hook in here, before any of the
		// logic below is being executed, effectively short circuiting the routine
		do_action( 'wpo_wcpdf_export_bulk_save_bulk_handler', $args );

		// create cache with file list of this export
		// @TODO: use unique cache name to allow parallel downloads
		$cache    = "wpo_wcpdf_bulk_export_files_{$document_type}_{$output_format}";
		$filelist = $this->get_cache( $cache );
		if ( ! is_array( $filelist ) ) {
			$filelist = array();
		}

		// turn off deprecation notices during bulk creation
		add_filter( 'wcpdf_disable_deprecation_notices', '__return_true' );

		// get bulk documents chunk and merge with cached files
		$bulk_documents            = $this->get_documents_for_bulk( $args );
		$bulk_documents['success'] = array_merge( $filelist, $bulk_documents['success'] );

		// prepare return
		$return['success']       = ! empty( $bulk_documents['success'] ) ? $bulk_documents['success'] : array();
		$return['error']         = ! empty( $bulk_documents['error'] ) ? $bulk_documents['error'] : array();
		$return['cache']         = $cache;
		$return['filename']      = sanitize_file_name( $document_type . '.zip' );
		$return['document_type'] = $document_type;
		$return['output_format'] = $output_format;

		// set cache
		$set_cache = $this->set_cache( $return['cache'], $return['success'] );
		if ( ! $set_cache ) {
			wcpdf_log_error( sprintf( 'Could not set cache for bulk export process (%s)', $return['cache'] ), 'critical' );
		}

		// re-enable deprecation notices
		remove_filter( 'wcpdf_disable_deprecation_notices', '__return_true' );

		echo json_encode( $return );
		exit();
	}

	public function get_documents_for_bulk( $args ) {
		$return = array(
			'success' => array(),
			'error'   => array(),
		);

		if ( ! empty( $args ) ) {
			$document_type     = $args['document_type'];
			$output_format     = $args['output_format'];
			$skip_free         = $args['skip_free'];
			$init_document     = $args['only_existing'] ? false : true;
			$order_ids         = $args['order_ids'];
			$summary_order_ids = array();

			foreach ( $order_ids as $order_id ) {
				$order = wc_get_order( $order_id );
				
				if ( empty( $order ) ) {
					continue;
				}
	
				// check skip free setting
				if ( $skip_free && method_exists( $order, 'get_total' ) && 0 == $order->get_total() ) {
					continue;
				}
				
				if ( 'summary' === $document_type ) {
					$document = wcpdf_get_invoice( $order, $init_document );
				} else {
					$document = wcpdf_get_document( $document_type, $order, $init_document );
				}
				
				$document = apply_filters( 'wpo_wcpdf_export_bulk_document', $document, $order, $args );
				
				if ( ! $document || ! $document->exists() ) {
					continue;
				}
				
				if ( 'summary' === $document_type ) {
					$summary_order_ids[] = $order_id;
					continue;
				}

				$file_path = apply_filters( 'wpo_wcpdf_export_bulk_create_file', '', $order, $args );
				
				if ( ! $file_path ) {
					try {
						$file_path = wcpdf_get_document_file( $document, $output_format );
						
						if ( ! empty( $file_path ) ) {
							$return['success'][] = $file_path;
						} else {
							$return['error'][] = sprintf( 'An error occurred while trying to get the %1$s document in %2$s format!', $document_type, $output_format );
						}
						
					} catch ( \Exception $e ) {
						wcpdf_log_error( $e->getMessage(), 'critical', $e );
						continue;
					} catch ( \Dompdf\Exception $e ) {
						wcpdf_log_error( 'DOMPDF exception: '.$e->getMessage(), 'critical', $e );
						continue;
					} catch ( \Error $e ) {
						wcpdf_log_error( $e->getMessage(), 'critical', $e );
						continue;
					}
					
				} else {
					$return['success'][] = $file_path;
				}
			}

			// summary specific
			if ( ! empty( $summary_order_ids ) ) {
				$export_settings = $this->get_cache( "wpo_wcpdf_bulk_export_settings_{$document_type}" );
				
				if ( empty( $export_settings ) ) {
					$export_settings = array();
				}
				
				$summary_document = WPO_WCPDF_Pro()->functions->get_summary_document( $summary_order_ids, $export_settings );
				$summary_document = apply_filters( 'wpo_wcpdf_export_bulk_document_summary', $summary_document, $summary_order_ids, $args );

				if ( $summary_document ) {
					try {
						// Prevent using cached document for summary.
						add_filter( 'wpo_wcpdf_reuse_attachment_age', '__return_zero' );
						
						$file_path = wcpdf_get_document_file( $summary_document );
							
						if ( ! empty( $file_path ) ) {
							$return['success'][] = $file_path;
						} else {
							$return['error'][] = sprintf( 'An error occurred while trying to get the summary document file!' );
						}
					} catch ( \Exception $e ) {
						wcpdf_log_error( $e->getMessage(), 'critical', $e );
					} catch ( \Dompdf\Exception $e ) {
						wcpdf_log_error( 'DOMPDF exception: '.$e->getMessage(), 'critical', $e );
					} catch ( \Error $e ) {
						wcpdf_log_error( $e->getMessage(), 'critical', $e );
					}
					
				} else {
					$return['error'][] = sprintf( 'An error occurred while trying to get the summary document object!' );
				}

				$this->delete_cache( "wpo_wcpdf_bulk_export_settings_{$document_type}" );
			}
		}

		return $return;
	}

	public function zip_bulk() {
		check_ajax_referer( 'wpo_wcpdf_pro_bulk', 'security' );

		@set_time_limit(0);

		if ( isset( $_GET['cache'] ) ) {
			$cache    = sanitize_key( $_GET['cache'] );
			$filelist = $this->get_cache( $cache );

			if ( ! $filelist ) {
				wcpdf_log_error( sprintf( 'Could not read file list cache (%s) for bulk export process', $cache ), 'critical' );
			}

			$this->delete_cache( $cache );
		} else {
			// legacy method using filelist from postdata
			$filelist = $_POST['files'];
			if ( is_string( $filelist ) && false !== strpos( $filelist, '[' ) ) {
				$filelist = json_decode( stripslashes( $filelist ) );
			}
		}

		do_action( 'wpo_wcpdf_export_bulk_save_bulk_download', array(
			'filelist'      => $filelist,
			'document_type' => $_REQUEST['document_type'],
		) );

		$document_type = $_REQUEST['document_type'];
		$filename      = sanitize_file_name( $document_type . '.zip' );

		try {
			if ( $zipfile = $this->create_zip( $filelist, $filename ) ) {
				if ( headers_sent() ) {
					echo 'HTTP header already sent';
				} else {
					if ( function_exists( 'apache_setenv' ) ) {
						apache_setenv( 'no-gzip', 1 );
						apache_setenv( 'dont-vary', 1 );
					}
					$output_compression = ini_get( 'zlib.output_compression' );
					if ( $output_compression &&  'off' !== $output_compression ) {
						$set_output_compression = ini_set( 'zlib.output_compression', 0 );
						if ( $output_compression &&  'off' !== $output_compression ) {
							throw new \Exception( 'zlib.output_compression needs to be turned off in PHP to create a zip file' );
						}
					}
					ob_clean();
					ob_end_flush();
					header( 'Content-Description: File Transfer' );
					header( 'Content-Type: application/x-zip' );
					header( 'Content-Disposition: attachment; filename="'.$filename.'"' );
					header( 'Content-Transfer-Encoding: binary' );
					header( 'Connection: Keep-Alive' );
					header( 'Expires: 0' );
					header( 'Cache-Control: no-cache, must-revalidate, post-check=0, pre-check=0' );
					header( 'Pragma: public' );
					@readfile( $zipfile );
					@unlink( $zipfile ); // destroy after reading
				}
			}	
		} catch ( \Exception $e ) {
			wcpdf_log_error( $e->getMessage(), 'critical' );
			echo $e->getMessage();
		}
		exit;
	}

	public function check_zip_archive() {
		if (!class_exists('\\ZipArchive')) {
			return false;
		} else {
			return true;
		}
	}

	public function create_zip( $filelist, $zip_filename ) {
		$zip      = new \ZipArchive();
		$tmp_path = trailingslashit( WPO_WCPDF()->main->get_tmp_path( 'attachments' ) );
		@unlink( $tmp_path . $zip_filename );
		if ( $zip->open( $tmp_path . $zip_filename, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== TRUE) {
			throw new \Exception( sprintf( 'An error occurred creating your ZIP file (%s)', $tmp_path . $zip_filename ) );
		}

		foreach ( $filelist as $filepath ) {
			if ( is_readable( $filepath ) ) {
				$added = $zip->addFile( $filepath, basename( $filepath ) );
				if ( $added === false ) {
					wcpdf_log_error( sprintf( 'Could not add PDF file (%s) during ZIP process', $filepath ), 'critical' );
				}
			} else {
				wcpdf_log_error( sprintf( 'Could not read PDF file (%s) during ZIP process', $filepath ), 'critical' );
			}
		}

		$closed = $zip->close();
		if ( $closed === true ) {
			return $tmp_path . $zip_filename;
		} else {
			throw new \Exception( sprintf( 'ZIP file could not be saved (%s)', $tmp_path . $zip_filename ) );
		}
	}

	public function download_file() {
		check_ajax_referer( 'wpo_wcpdf_pro_bulk', 'security' );

		if ( isset( $_GET['cache'] ) && isset( $_GET['output_format'] ) ) {
			$cache         = sanitize_key( $_GET['cache'] );
			$output_format = sanitize_text_field( $_GET['output_format'] );
			$filelist      = $this->get_cache( $cache );

			if ( false === $filelist ) {
				wcpdf_log_error( sprintf( 'Could not read file list cache (%s) for bulk export process', $cache ), 'critical' );
				return;
			}	
			$this->delete_cache( $cache );

			$file = reset( $filelist );

			if ( ! empty( $file ) ) {
				switch ( $output_format ) {
					case 'pdf':
					default:
						wcpdf_pdf_headers( basename( $file ), 'download', $file );
						break;
					case 'ubl':
						// free >= 3.7.0
						if ( function_exists( 'wcpdf_ubl_headers' ) ) {
							$quoted = sprintf( '"%s"', addcslashes( basename( $file ), '"\\' ) );
							$size   = filesize( $file );
							wcpdf_ubl_headers( $quoted, $size );
						} else {
							die();
						}
						break;
				}

				readfile( $file );
			}
		}

		die();
	}

	public function cloud_upload() {
		$success  = array();
		$error    = array();
		$messages = array();
		
		$security = check_ajax_referer( 'wpo_wcpdf_pro_bulk', 'security', false );
		if ( false === $security ) {
			$error[] = 1;
		}

		if ( empty( $error ) && isset( $_REQUEST['cache'] ) ) {
			$cache    = sanitize_key( $_REQUEST['cache'] );
			$filelist = $this->get_cache( $cache );

			if ( false === $filelist ) {
				wcpdf_log_error( sprintf( 'Could not read file list cache (%s) for cloud upload process', $cache ), 'critical' );
			}

			$this->delete_cache( $cache );

			if ( ! empty( $filelist ) && is_array( $filelist ) ) {
				foreach ( $filelist as $file_path ) {
					try {
						// TODO: we could schedule the upload using AS action
						$cloud_storage   = new Cloud_Storage;
						$upload_response = $cloud_storage->upload_to_service( $file_path, 'export' );
		
						// Houston, we have a problem
						if ( ! empty( $upload_response['error'] ) ) {
							wcpdf_log_error( $upload_response['error'], 'error' );
							$error[] = $file_path;
						} elseif ( ! empty( $upload_response['success'] ) ) {
							$success[] = $file_path;
						} else {
							continue;
						}
					} catch ( \Exception $e ) {
						wcpdf_log_error( $e->getMessage(), 'critical' );
						echo $e->getMessage();
					}
				}
			}
		}

		if ( ! empty( $success ) ) {
			$messages['success'] = sprintf(
				/* translators: files count */
				_n( 'Upload completed! %s file transferred to cloud service.', 'Upload completed! %s files transferred to cloud service.', count( $success ), 'wpo_wcpdf_pro' ),
				count( $success )
			);
		}
		if ( ! empty( $error ) ) {
			$messages['error'] = sprintf(
				/* translators: files count */
				_n( '%s file not uploaded to cloud! Please see logs.', '%s files not uploaded to cloud! Please see logs.', count( $error ), 'wpo_wcpdf_pro' ),
				count( $error )
			);
		}

		wp_send_json( $messages );

		die();
	}

	public function get_orders( $export_settings ) {
		$args = array(
			'status' => $export_settings['statuses'],
			'return' => 'ids',
			'type'   => 'shop_order',
			'limit'  => -1,
		);

		// Filter by user ids if set
		if ( ! empty( $export_settings['users'] ) && $export_settings['users'] === array_filter( $export_settings['users'], 'is_numeric' ) ) {
			$args['customer_id'] = $export_settings['users'];
		}

		if ( empty( $export_settings['date_type'] ) || 'order_date' === $export_settings['date_type'] ) {
			$export_settings['date_type'] = 'date_created';
		}

		$wc_date_types = array(
			'date_created',
			'date_modified',
			'date_completed',
			'date_paid',
		);

		if ( in_array( $export_settings['date_type'], $wc_date_types ) ) {
			if ( 'credit-note' === $export_settings['document_type'] ) {
				$args['type'] = 'shop_order_refund'; // Note that this will change the export behavior of orders with multiple refunds: instead of one PDF per order with all the credit notes, it will always create them separately.
			}
			$date_arg = $export_settings['date_type'];
		} elseif ( 'document_date' === $export_settings['date_type'] ) {
			if ( 'credit-note' === $export_settings['document_type'] ) {
				$args['type'] = 'shop_order_refund';
			}
			$document_slug = 'summary' === $export_settings['document_type'] ? 'invoice' : str_replace( '-', '_', $export_settings['document_type'] ); // querying documents functions may be more reliable but this works fine and prevents issues with UBL export
			$date_arg      = "wcpdf_{$document_slug}_date";
		} else {
			$date_arg = "wcpdf_bulk_export_{$export_settings['date_type']}";
		}

		// for code readability
		$date_before = $export_settings['date_before'];
		$date_after  = $export_settings['date_after'];

		if ( $date_after && ! $date_before ) {
			// after date
			$args[ $date_arg ] = '>=' . $date_after;
		} elseif ( $date_before ) {
			if ( ! $date_after ) {
				// before date
				$args[ $date_arg ] = '<=' . $date_before;
			} else {
				// between dates
				$args[ $date_arg ] = $date_after . '...' . $date_before;
			}
		}

		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '6.8.0', '>=' ) && WPO_WCPDF()->order_util->custom_orders_table_usage_is_enabled() ) { // Woo >= 6.8.0 + HPOS
			$args = wpo_wcpdf_parse_document_date_for_wp_query( $args, $args );
		} else {
			add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', 'wpo_wcpdf_parse_document_date_for_wp_query', 10, 2 );
		}

		// Allow 3rd parties to alter the arguments used to fetch the order IDs
		// @author Aelia
		$args = apply_filters( 'wpo_wcpdf_export_bulk_get_orders_args', $args, $export_settings );

		/**
		 * In case we want to get shop order refunds and filter them by status,
		 * we need to get their parent order objects and filter refunds by parent statuses
		 * because order refund statuses are always set to "wc-completed".
		 */
		if ( 'shop_order_refund' === $args['type'] ) {
			unset( $args['status'] );
			unset( $args['return'] );
			
			$order_refunds = wc_get_orders( $args );
			$parent_ids    = array_unique( array_map( function( $order_refund ) {
				return $order_refund->get_parent_id();
			}, $order_refunds ) );

			$args = apply_filters( 'wpo_wcpdf_export_bulk_get_order_refunds_args', array(
				'post__in' => $parent_ids,
				'status'   => $export_settings['statuses'],
				'return'   => 'ids',
				'limit'    => -1,
			), $order_refunds, $parent_ids, $export_settings );
		}

		$order_ids = wc_get_orders( $args );
		
		remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', 'wpo_wcpdf_parse_document_date_for_wp_query', 10 );

		// sort ids if date type is order date
		if ( apply_filters( 'wpo_wcpdf_export_bulk_sort_order_ids', 'date_created' === $date_arg, $order_ids, $args, $export_settings ) ) {
			asort( $order_ids );
		}
		
		// Allow 3rd parties to alter the list of order IDs returned by the query
		// @author Aelia
		$order_ids = apply_filters( 'wpo_wcpdf_export_bulk_order_ids', $order_ids, $args, $export_settings );

		return $order_ids;
	}

	public function get_date_string_from_input( $date_key, $hour_key, $minute_key, $include_minute = false, $utc_timestamp = false ) {
		$date   = isset( $_REQUEST[$date_key] ) ? sanitize_text_field( $_REQUEST[$date_key] ) : null;
		$hour   = isset( $_REQUEST[$hour_key] ) ? sanitize_text_field( $_REQUEST[$hour_key] ) : null;
		$minute = isset( $_REQUEST[$minute_key] ) ? sanitize_text_field( $_REQUEST[$minute_key] ) : null;

		if ( empty( $date ) ) {
			return false;
		}

		if ( 'date_to' === $date_key  && ! is_null( WPO_WCPDF_Pro()->cloud_api ) ) {
			// store last export date & time
			update_option( 'wpo_wcpdf_'.Cloud_API::service_enabled() . '_last_export', array( 'date' => $date, 'hour' => $hour, 'minute' => $minute ) );
		}

		if ( ! empty( $hour ) ) {
			$seconds = $include_minute ? '59' : '00';
			$date    = sprintf("%s %02d:%02d:%02d", $date, $hour, $minute, $seconds);
		}

		if ( $utc_timestamp ) {
			// Convert local WP timezone to UTC.
			if ( 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(Z|((-|\+)\d{2}:\d{2}))$/', $date, $date_bits ) ) {
				$offset    = ! empty( $date_bits[7] ) ? iso8601_timezone_to_offset( $date_bits[7] ) : wc_timezone_offset();
				$timestamp = gmmktime( $date_bits[4], $date_bits[5], $date_bits[6], $date_bits[2], $date_bits[3], $date_bits[1] ) - $offset;
			} else {
				$timestamp = wc_string_to_timestamp( get_gmt_from_date( gmdate( 'Y-m-d H:i:s', wc_string_to_timestamp( $date ) ) ) );
			}
			$date = $timestamp;
		}

		return $date;
	}

	public function search_users(): void {
		check_ajax_referer( 'wpo_wcpdf_pro_bulk', 'security' );

		$term = (string) wc_clean( stripslashes( filter_input( INPUT_GET, 'term' ) ) );

		if ( empty( $term ) ) {
			wp_die();
		}

		$users = get_users( array(
			'search'   => '*' . $term . '*',
			'role__in' => apply_filters( 'wpo_wcpdf_pro_bulk_filter_users_roles', array() ),
		) );

		$found_users = array();
		foreach ( $users as $user ) {
			$supplier_string = sprintf(
				esc_html__( '%1$s (#%2$s - %3$s)', 'woocommerce' ),
				$user->display_name,
				absint( $user->ID ),
				$user->user_email
			);

			$found_users[ $user->data->ID ] = $supplier_string;
		}

		wp_send_json( $found_users );
	}

	public function set_cache( $cache, $value ) {
		/**
		 * Schedule the expiration of the option.
		 * 
		 * First unschedule any previous, this is to prevent the option from expiring earlier than expected.
		 * 
		 * Then set the new (latest) expiration date.
		 */
		as_unschedule_all_actions( '', array(), $cache );
		as_schedule_single_action(
			time() + HOUR_IN_SECONDS,
			'wpo_wcpdf_pro_bulk_export_delete_cache',
			compact( 'cache' ),
			$cache // $group
		);

		// Check if the stored value is the same as the new
		$old_value = get_option( $cache );
		if ( $value === $old_value || maybe_serialize( $value ) === maybe_serialize( $old_value ) ) {
			return true; // The cache was already set
		}

		$set_cache = update_option( $cache, $value );
		return $set_cache;
	}

	public function get_cache( $cache ) {
		return get_option( $cache );
	}

	public function delete_cache( $cache ) {
		delete_option( $cache );
	}
} // end class

endif; // end class_exists

return new Bulk_Export();

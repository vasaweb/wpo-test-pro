<?php
namespace WPO\WC\PDF_Invoices_Pro;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( '\\WPO\\WC\\PDF_Invoices_Pro\\Functions' ) ) :

class Functions {

	public $pro_settings;

	public function __construct() {
		$this->pro_settings = WPO_WCPDF_Pro()->settings->settings;

		add_filter( 'wpo_wcpdf_document_classes', array( $this, 'register_documents' ), 10, 1 );
		add_filter( 'woocommerce_email_attachments', array( $this, 'attach_static_file' ), 99, 3);
		add_filter( 'wpo_wcpdf_template_file', array( $this, 'pro_template_files' ), 10, 2 );
		add_filter( 'wpo_wcpdf_process_order_ids', array( $this, 'credit_notes_order_ids' ), 10, 2 );
		add_filter( 'wpo_wcpdf_email_attachment_order', array( $this, 'refund_email_object' ), 10, 3 );
		add_filter( 'wpo_wcpdf_custom_attachment_condition', array( $this, 'restrict_credit_notes_attachment' ), 10, 5 );

		add_filter( 'wpo_wcpdf_billing_address', array( $this, 'billing_address_filter' ), 10, 2 );
		add_filter( 'wpo_wcpdf_shipping_address', array( $this, 'shipping_address_filter' ), 10, 2 );

		// register Partially Refunded alias for Refunded Order email
		add_filter( 'wpo_wcpdf_attach_documents', array( $this, 'register_partially_refunded_email_id' ), 10, 1 );

		// always process invoice before credit note if both are attached to the same email
		add_filter( 'wpo_wcpdf_document_types_for_email', array( $this, 'credit_note_attachment_priority' ), 10, 3 );

		// document specific filters
		// Packing Slip
		add_action( 'wpo_wcpdf_init_document', array( $this, 'init_packing_slip' ), 10, 1 );
		add_filter( 'wpo_wcpdf_order_items_data', array( $this, 'subtract_refunded_qty' ), 10, 3 );
		add_action( 'wpo_wcpdf_before_order_data', array( $this, 'packing_slip_number_date' ), 10, 2 );
		add_filter( 'wpo_wcpdf_order_items_data', array( $this, 'hide_virtual_downloadable_products' ), 10, 3 );

		// Credit Note
		add_action( 'wpo_wcpdf_process_template', array( $this, 'positive_credit_note' ) );
		add_filter( 'wpo_wcpdf_after_order_data', array( $this, 'original_invoice_number' ), 10, 2 );
		add_filter( 'woocommerce_get_formatted_order_total', array( $this, 'refund_taxes_simple_template' ), 10, 4 );
		add_action( 'wpo_wcpdf_before_html', array( $this, 'credit_note_maybe_use_order_items' ), 10, 2 );
		add_action( 'wpo_wcpdf_after_html', array( $this, 'credit_note_dont_use_order_items' ), 10, 2 );
		add_action( 'woocommerce_order_partially_refunded', array( $this, 'generate_credit_note_on_refund' ), 10, 2  );
		add_action( 'woocommerce_order_fully_refunded', array( $this, 'generate_credit_note_on_refund' ), 10, 2  );

		// apply title & filename settings
		add_action( 'init', array( $this, 'filter_document_titles' ), 999999 );
		add_filter( 'wpo_wcpdf_filename', array( $this, 'override_document_filename' ), 10, 5 );

		// Keep PDF on server functions
		add_action( 'wpo_wcpdf_pdf_created', array( $this, 'store_pdf_file_in_archive' ), 10, 2 );
		add_action( 'wpo_wcpdf_delete_document', array( $this, 'unlink_archived_pdf' ), 10, 1 );
		add_filter( 'wpo_wcpdf_load_pdf_file_path', array( $this, 'load_archived_pdf_file_path' ), 10, 2 );
		add_filter( 'wpo_wcpdf_pdf_data', array( $this, 'store_bulk_documents_in_archive' ), 10, 2 );
		add_action( 'wpo_wcpdf_regenerate_document', array( $this, 'regenerate_archived_pdf' ), 10, 2 );
		add_filter( 'wpo_wcpdf_plugin_directories', array( $this, 'add_archive_dir_to_status' ), 10, 2 );

		// removes third party filters to avoid conflicts
		add_action( 'wpo_wcpdf_before_html', array( $this, 'remove_third_party_filters' ), 10, 2 );

		// generate PDFs on order status using AS
		add_action( 'woocommerce_order_status_changed', array( $this, 'generate_documents_on_order_status' ), 7, 4  );
		add_action( 'wpo_wcpdf_generate_document_on_order_status', array( $this, 'generate_document_from_order_status' ), 10, 2 );

		// filter Credit Note preview order search args
		add_filter( 'wpo_wcpdf_preview_order_search_args', array( $this, 'credit_note_preview_order_search_args' ), 10, 2 );
		// filter Credit Note preview default order ID query args
		add_filter( 'wpo_wcpdf_preview_default_order_id_query_args', array( $this, 'credit_note_default_order_id_query_args' ), 10, 2 );

		// add summary document to bulk export selection
		add_action( 'wpo_wcpdf_export_bulk_after_document_type_options', array( $this, 'add_summary_to_bulk_export_documents' ), 99 );

		// validate order data before generating credit note
		add_filter( 'wpo_wcpdf_document_is_allowed', array( $this, 'is_pro_document_allowed' ), 2, 2 );
		
		// pro document triggers
		add_filter( 'wpo_wcpdf_document_triggers', array( $this, 'pro_document_triggers' ), 1, 1 );
		
		// credit note number search
		if ( $this->credit_note_number_search_enabled() ) { // prevents slowing down the orders list search
			add_filter( 'woocommerce_shop_order_search_results', array( $this, 'credit_note_number_search_query' ), 999, 3 );
		}

		add_filter( 'wpo_wcpdf_due_date_display', function ( $due_date, $due_date_timestamp, $document_type, $document ) {
			$this->validate_due_date_display( $due_date, $document );
		}, 10, 4 ); // Legacy filter
		add_filter( 'wpo_wcpdf_document_due_date', array( $this, 'validate_due_date_display' ), 10, 2 );

		// show COC/VAT numbers on the invoice
		add_action( 'wpo_wcpdf_after_shop_address', array( $this, 'display_shop_coc_number_in_invoice' ), 20, 2 );
		add_action( 'wpo_wcpdf_after_shop_address', array( $this, 'display_shop_vat_number_in_invoice' ), 20, 2 );
		
		// v2.15.4 major bug
		add_action( 'wpo_wcpdf_after_debug_tools', array( $this, 'v2_15_4_corrupted_data_tool' ), 99, 1 );
		add_action( 'wp_ajax_wpo_wcpdf_pro_resolve_v2_15_4_corrupted_data', array( $this, 'v2_15_4_mark_resolved' ) );
		add_action( 'admin_notices', array( $this, 'v2_15_4_corrupted_data_notice' ) );
	}

	public function register_documents( $documents ) {
		// Load pro document abstract
		include_once( dirname( __FILE__ ) . '/documents/abstract-wcpdf-pro-document.php' );
		
		// Load Summary
		include_once( dirname( __FILE__ ) . '/documents/class-wcpdf-summary.php' );

		// Load Pro Documents
		$documents['\WPO\WC\PDF_Invoices\Documents\Proforma']    = include( 'documents/class-wcpdf-proforma.php' );
		$documents['\WPO\WC\PDF_Invoices\Documents\Receipt']     = include( 'documents/class-wcpdf-receipt.php' );
		$documents['\WPO\WC\PDF_Invoices\Documents\Credit_Note'] = include( 'documents/class-wcpdf-credit-note.php' );

		return $documents;
	}

	public function register_partially_refunded_email_id( $attach_documents ) {
		foreach ( $attach_documents as $output_format => $documents ) {
			foreach ( $documents as $document_type => $attach_to_email_ids ) {
				if ( in_array( 'customer_refunded_order', $attach_to_email_ids ) ) {
					$attach_documents[ $output_format ][ $document_type ][] = 'customer_partially_refunded_order';
				}
			}
		}
		return $attach_documents;
	}

	/**
	 * Make sure credit notes are always processed last, so that invoices may be generated before it
	 * @param  array  $document_types  list of documents to attach
	 * @param  string $email_id        id/slug of the email
	 * @param  object $order           order object
	 * @return array  $document_types  reorderded list of documents to attach
	 */
	public function credit_note_attachment_priority( $document_types, $email_id, $order ) {
		$look_for_type = 'credit-note';
		
		foreach ( $document_types as $output_format => $types ) {
			$key = array_search( $look_for_type, $types );
			
			if ( false !== $key ) {
				unset( $document_types[ $output_format ][ $key ] );
				$document_types[ $output_format ][] = $look_for_type;
			}
		}
		
		return $document_types;
	}

	/**
	 * Attach static file to WooCommerce emails of choice
	 * @param  array  $attachments  list of attachment paths
	 * @param  string $email_id     id/slug of the email
	 * @param  object $order        order object
	 * @return array  $attachments  including static file
	 */
	public function attach_static_file( $attachments, $email_id, $order ) {
		if ( empty( $this->pro_settings['static_file'] ) ) {
			return $attachments;
		}

		// fake $document_type for attachment condition filter
		$document_type = 'static_file';

		// get file ids to attach
		$static_files = $this->pro_settings['static_file'];

		// if using Polylang or TranslatePress get the order language (WPML has a media translation tool)
		if ( is_callable( array( WPO_WCPDF_Pro()->multilingual_full, 'get_order_lang' ) ) && ( class_exists( '\\Polylang' ) || class_exists( '\\TRP_Translate_Press' ) ) && is_callable( array( $order, 'get_id' ) ) ) {
			$order_lang   = WPO_WCPDF_Pro()->multilingual_full->get_order_lang( $order->get_id(), $document_type );
			$static_files = ( ! empty( $order_lang ) && isset( $static_files[ $order_lang ] ) ) ? $static_files[ $order_lang ] : $static_files;
		}

		// get settings
		$attach_to_email_ids = isset( $this->pro_settings['static_file_attach_to_email_ids'] ) ? array_keys( $this->pro_settings['static_file_attach_to_email_ids'] ) : array();
		if ( in_array( 'customer_refunded_order', $attach_to_email_ids ) ) {
			$attach_to_email_ids[] = 'customer_partially_refunded_order';
		}

		if ( is_subclass_of( $order, '\WC_Abstract_Order' ) ) {
			// use this filter to add an extra condition - return false to disable the file attachment
			$attach_files  = apply_filters( 'wpo_wcpdf_custom_attachment_condition', true, $order, $email_id, $document_type, 'pdf' );
		}

		if ( in_array( $email_id, $attach_to_email_ids ) && $attach_files ) {
			foreach ( $static_files as $number => $static_file ) {
				if ( isset( $static_file['id'] ) ) {
					$file_id       = apply_filters( 'wpml_object_id', $static_file['id'], 'attachment', true );
					$file_path     = get_attached_file( $file_id );
					$document_type = isset($document_type) ? $document_type : null;
					$attach_file   = apply_filters( 'wpo_wcpdf_attach_static_file', true, $order, $email_id, $document_type, $static_file, $number, $file_path ); // $number starts from 0 and ends in 2
					
					if ( file_exists( $file_path ) && $attach_file ) {
						$attachments[] = $file_path;
					}
				}
			}
		}

		return $attachments;
	}

	/**
	 * Set file locations for pro document types
	 */
	public function pro_template_files( $template, $template_type ) {
		// bail out if file already exists in default or custom path!
		if( file_exists( $template ) ){
			return $template;
		}

		$pro_template = WPO_WCPDF_Pro()->plugin_path() . '/templates/Simple/' . $template_type . '.php';

		if( file_exists( $pro_template ) ){
			// default to bundled Simple template
			return $pro_template;
		} else {
			// unknown document type! This will inevitably throw an error unless there's another filter after this one.
			return $template;
		}
	}

	public function credit_notes_order_ids( $order_ids, $template_type ) {
		if ( $template_type == 'credit-note' ) {
			$credit_notes_order_ids = array();
			foreach ( $order_ids as $order_id ) {
				if ( 'shop_order_refund' === WPO_WCPDF()->order_util->get_order_type( $order_id ) ) {
					$credit_notes_order_ids[] =  $order_id;
				} else {
					if ( $order = wc_get_order( $order_id ) ) {
						$refunds = $order->get_refunds();
						foreach ( $refunds as $key => $refund ) {
							$credit_notes_order_ids[] = $refund->get_id();
						}
					}
				}
			}
			return apply_filters( 'wpo_wcpdf_credit_notes_order_ids', $credit_notes_order_ids, $order_ids );
		} else {
			return $order_ids;
		}
	}

	/**
	 * Use refund order object for refund email attachments
	 */

	public function refund_email_object( $order, $email, $document_type = null ) {
		if( !empty( $email ) && !empty( $email->refund ) && $document_type == 'credit-note' ) {
			$order = $email->refund;
		}
		return $order;
	}

	/**
	 * If credit notes attachment is enabled for invoice email, and an invoice email is sent when
	 * the order is not refunded, an empty credit note would otherwise be attached.
	 * This method prevents that from happening.
	 *
	 * In addition, this method prevents the attachment of credit notes for orders without an invoice
	 */
	public function restrict_credit_notes_attachment( $condition, $order, $status, $document_type, $output_format ) {
		// only process credit notes
		if ( 'credit-note' !== $document_type ) {
			return $condition;
		}

		// get refunds & check for invoice
		if ( is_callable( array( $order, 'get_type' ) ) && 'shop_order_refund' === $order->get_type() ) {
			$refunds         = array( $order );
			$parent_order_id = $order->get_parent_id();
			$invoice         = wcpdf_get_invoice( array( $parent_order_id ) );
		} elseif ( is_callable( array( $order, 'get_refunds' ) ) ) {
			$refunds         = $order->get_refunds();
			$invoice         = wcpdf_get_invoice( $order );
		}

		// only attach credit note pdf when there are refunds
		if ( empty( $refunds ) ) {
			return false;
		}

		// only attach credit note when there is an invoice for this order
		if ( $invoice && ! $invoice->exists() ) {
			return false;
		}

		return $condition;
	}

	/**
	 * filters addresses when replacement placeholders configured via plugin settings!
	 */
	public function billing_address_filter( $original_address, $document ) {
		return $this->address_replacements( $original_address, $document, 'billing' );
	}

	public function shipping_address_filter( $original_address, $document ) {
		return $this->address_replacements( $original_address, $document, 'shipping' );
	}

	public function address_replacements( $original_address, $document, $type ) {
		if ( ! isset( $this->pro_settings[$type.'_address'] ) || empty( $this->pro_settings[$type.'_address'] ) ) {
			// nothing set, use default woocommerce formatting
			return $original_address;
		}

		// Get pro settings address.
		$pro_address = apply_filters( 'wpo_wcpdf_pro_address', $this->pro_settings[ $type . '_address' ], $document, $type );

		// load the order
		$order = &$document->order;

		// get the address format from the settings
		if ( is_array( $pro_address ) ) {
			$order_lang = ! empty( WPO_WCPDF_Pro()->multilingual_full ) && is_callable( array( WPO_WCPDF_Pro()->multilingual_full, 'get_order_lang' ) ) ? WPO_WCPDF_Pro()->multilingual_full->get_order_lang( $order->get_id(), $document->get_type() ) : false;
			if ( ! $order_lang && isset( $pro_address['default'] ) && ! empty( $pro_address['default'] ) ) { // default
				$address = nl2br( $pro_address['default'] );
			} elseif ( $order_lang && isset( $pro_address[$order_lang] ) && ! empty( $pro_address[$order_lang] ) ) { // multilingual
				$address = nl2br( $pro_address[$order_lang] );
			} else { // original
				return $original_address;
			}
		} else { // non multilingual
			$address = nl2br( $pro_address );
		}

		// backwards compatibility for old settings using [placeholder] instead of {{placeholder}}
		$address = str_replace( array('[',']'), array('{{','}}'), $address);

		$address = $this->make_replacements( $address, $order );

		preg_match_all('/\{\{.*?\}\}/', $address, $placeholders_used);
		$placeholders_used = array_shift($placeholders_used); // we only need the first match set

		// remove empty placeholder lines, but preserve user-defined empty lines
		if (isset($this->pro_settings['remove_whitespace'])) {
			// break formatted address into lines
			$address = explode("\n", $address);
			// loop through address lines and check if only placeholders (remove HTML formatting first)
			foreach ($address as $key => $address_line) {
				// strip html tags for checking
				$clean_line = trim(strip_tags($address_line));
				// clean zero-width spaces
				$clean_line = str_replace("\xE2\x80\x8B", "", $clean_line);
				if (empty($clean_line)) {
					continue; // user defined newline!
				}
				// check without leftover placeholders
				$clean_line = trim( str_replace($placeholders_used, '', $clean_line) );

				// remove empty lines
				if (empty($clean_line)) {
					unset($address[$key]);
				}
			}

			// glue address lines back together
			$address = implode("\n", $address);
		}

		// Remove leftover placeholders and return.
		return apply_filters( 'wpo_wcpdf_address_replacements', str_replace( $placeholders_used, '', $address ), $placeholders_used, $address, $document );
	}

	public function make_replacements( $text, $order ) {
		$order_id = $order->get_id();
		// load parent order for refunds
		if ( 'shop_order_refund' === WPO_WCPDF()->order_util->get_order_type( $order_id ) ) {
			$parent_order = wc_get_order( $order->get_parent_id() );
		}

		$text = apply_filters( 'wpo_wcpdf_before_make_text_replacements', $text, $order );

		// make an index of placeholders used in the text
		preg_match_all('/\{\{.*?\}\}/', $text, $placeholders_used);
		$placeholders_used = array_shift($placeholders_used); // we only need the first match set

		// load countries & states
		$countries = new \WC_Countries;

		// loop through placeholders and make replacements
		foreach ( $placeholders_used as $placeholder ) {
			$placeholder_clean = trim( $placeholder, "{{}}" );

			// first try to read data from order, fallback to parent order (for refunds)
			$data_sources = array( 'order', 'parent_order' );
			foreach ( $data_sources as $data_source ) {
				if ( empty( $$data_source ) ) {
					continue;
				}

				// custom/third party filters
				$filter = 'wpo_wcpdf_pro_replace_' . sanitize_title( $placeholder_clean );

				if ( has_filter( $filter ) ) {
					$custom_filtered = ''; // we always want to replace these tags, regardless of errors/output
					ob_start(); // in case a plugin outputs data instead of returning it
					try {
						$custom_filtered = apply_filters( $filter, $custom_filtered, $$data_source, $placeholder_clean );
					} catch ( \Throwable $e ) {
						if ( function_exists( 'wcpdf_log_error' ) ) {
							wcpdf_log_error( $e->getMessage(), 'critical', $e );
						}
					} catch ( \Exception $e ) {
						if ( function_exists( 'wcpdf_log_error' ) ) {
							wcpdf_log_error( $e->getMessage(), 'critical', $e );
						}
					}
					ob_get_clean();
					$text = str_replace( $placeholder, $custom_filtered, $text );
					continue 2;
				}

				// special treatment for country & state
				$country_placeholders = array( 'shipping_country', 'billing_country' );
				$state_placeholders   = array( 'shipping_state', 'billing_state' );
				foreach ( array_merge( $country_placeholders, $state_placeholders ) as $country_state_placeholder ) {
					if ( strpos( $placeholder_clean, $country_state_placeholder ) !== false ) {
						// check if formatting is needed
						if ( strpos( $placeholder_clean, '_code' ) !== false ) {
							// no country or state formatting
							$placeholder_clean = str_replace( '_code', '', $placeholder_clean );
							$format = false;
						} else {
							$format = true;
						}

						$country_or_state = '';

						if ( method_exists( $$data_source, "get_{$placeholder_clean}" ) ) {
							$country_or_state = call_user_func( array( $$data_source, "get_{$placeholder_clean}" ) );
						}

						if ( $format === true ) {
							// format country or state
							if ( in_array( $placeholder_clean, $country_placeholders ) ) {
								$country_or_state = ( $country_or_state && isset( $countries->countries[ $country_or_state ] ) ) ? $countries->countries[ $country_or_state ] : $country_or_state;
							} elseif ( in_array( $placeholder_clean, $state_placeholders ) ) {
								// get country for address
								$callback         = 'get_'.str_replace( 'state', 'country', $placeholder_clean );
								$country          = call_user_func( array( $$data_source, $callback ) );
								$country_or_state = ( $country && $country_or_state && isset( $countries->states[ $country ][ $country_or_state ] ) ) ? $countries->states[ $country ][ $country_or_state ] : $country_or_state;
							}
						}

						if ( ! empty( $country_or_state ) ) {
							$text = str_replace( $placeholder, $country_or_state, $text );
							continue 3;
						}
					}
				}

				// Custom placeholders
				$custom = '';
				switch ($placeholder_clean) {
					case 'site_title':
						$custom = get_bloginfo();
						break;
					case 'order_number':
						if ( method_exists( $$data_source, 'get_order_number' ) ) {
							$custom = ltrim($$data_source->get_order_number(), '#');
						} else {
							$custom = '';
						}
						break;
					case 'order_status':
						$custom = wc_get_order_status_name( $$data_source->get_status() );
						break;							
					case 'order_date':
						$order_date = $$data_source->get_date_created();
						$custom     = $order_date->date_i18n( wc_date_format() );
						break;
					case 'order_time':
						$order_date = $$data_source->get_date_created();
						$custom     = $order_date->date_i18n( wc_time_format() );
						break;
					case 'date_completed':
						if ( $date = $$data_source->get_date_completed() ) {
							$custom = $date->date_i18n( wc_date_format() );
						}
						break;
					case 'date_paid':
						if ( $date = $$data_source->get_date_paid() ) {
							$custom = $date->date_i18n( wc_date_format() );
						}
						break;
					case 'order_total':
						$custom = method_exists( $$data_source, 'get_total' ) ? $$data_source->get_total() : '';
						break;
					default:
						break;
				}

				$custom = apply_filters( 'wpo_wcpdf_make_text_replacements_placeholder_value', $custom, $placeholder_clean, $$data_source, $order );

				if ( ! empty( $custom ) ) {
					$text = str_replace( $placeholder, $custom, $text );
					continue 2;
				}

				// Order Properties
				if (in_array($placeholder_clean, array('shipping_address', 'billing_address'))) {
					$placeholder_clean = "formatted_{$placeholder_clean}";
				}
				$property_meta_keys = array(
					'_order_currency'		=> 'currency',
					'_order_tax'			=> 'total_tax',
					'_order_total'			=> 'total',
					'_order_version'		=> 'version',
					'_order_shipping'		=> 'shipping_total',
					'_order_shipping_tax'	=> 'shipping_tax',
				);
				if (in_array($placeholder_clean, array_keys($property_meta_keys))) {
					$property_name = $property_meta_keys[$placeholder_clean];
				} else {
					$property_name = str_replace('-', '_', sanitize_title( ltrim($placeholder_clean, '_') ) );
				}
				if ( is_callable( array( $$data_source, "get_{$property_name}" ) ) ) {
					$prop = trim( call_user_func( array( $$data_source, "get_{$property_name}" ) ) );
					if ( ! empty( $prop ) ) {
						$text = str_replace( $placeholder, $prop, $text );
						continue 2;
					}
				}

				// Order Meta
				if ( ! $this->is_order_prop( $placeholder_clean ) ) {
					$meta = $$data_source->get_meta( $placeholder_clean );
					if ( ! empty( $meta ) ) {
						$text = str_replace( $placeholder, $meta, $text );
						continue 2;
					} else {
						// Fallback to hidden meta
						$meta = $$data_source->get_meta( "_{$placeholder_clean}" );
						if ( ! empty( $meta ) ) {
							$text = str_replace( $placeholder, $meta, $text );
							continue 2;
						}
					}
				}

			}
		}

		return apply_filters( 'wpo_wcpdf_make_replacements_after', $text, $order );
	}

	/**
	 * Replacement function for PDF document specific placeholders (numbers, dates)
	 */
	public function make_document_replacements( $text, $document ) {
		if ( empty( $document ) || empty( $document->order ) ) {
			return;
		}

		// make an index of placeholders used in the text
		preg_match_all( '/\{\{.*?\}\}/', $text, $placeholders_used );
		$placeholders_used = array_shift( $placeholders_used ); // we only need the first match set

		// loop through placeholders and make replacements
		foreach ( $placeholders_used as $placeholder ) {
			$placeholder_clean = trim( $placeholder,"{{}}" );
			$replacement       = '';
			
			switch ( $placeholder_clean ) {
				case 'document_date':
					$replacement = $document->get_date( '', null, 'view', true );
					break;
				case 'document_number':
					$replacement = $document->get_number( '', null, 'view', true );
					break;
				case 'invoice_number':
					$replacement = $document->get_number( 'invoice', null, 'view', true );
					break;
				case 'proforma_number':
					$replacement = $document->get_number( 'proforma', null, 'view', true );
					break;
				case 'receipt_number':
					$replacement = $document->get_number( 'receipt', null, 'view', true );
					break;
				case 'credit_note_number':
					$replacement = $document->get_number( 'credit-note', null, 'view', true );
					break;
				default:
					break;
			}
			
			if ( ! empty( $replacement ) ) {
				$text = str_replace( $placeholder, $replacement, $text );
				continue;
			}
		}

		return $text;
	}

	public function is_order_prop( $key ) {
		// Taken from WC class
		$order_props = array(
			// Abstract order props
			'parent_id',
			'status',
			'currency',
			'version',
			'prices_include_tax',
			'date_created',
			'date_modified',
			'discount_total',
			'discount_tax',
			'shipping_total',
			'shipping_tax',
			'cart_tax',
			'total',
			'total_tax',
			// Order props
			'customer_id',
			'order_key',
			'billing_first_name',
			'billing_last_name',
			'billing_company',
			'billing_address_1',
			'billing_address_2',
			'billing_city',
			'billing_state',
			'billing_postcode',
			'billing_country',
			'billing_email',
			'billing_phone',
			'shipping_first_name',
			'shipping_last_name',
			'shipping_company',
			'shipping_address_1',
			'shipping_address_2',
			'shipping_city',
			'shipping_state',
			'shipping_postcode',
			'shipping_country',
			'payment_method',
			'payment_method_title',
			'transaction_id',
			'customer_ip_address',
			'customer_user_agent',
			'created_via',
			'customer_note',
			'date_completed',
			'date_paid',
			'cart_hash',
		);
		return in_array($key, $order_props);
	}

	/**
	 * Wrapper for str_replace that applies nl2br when required
	 * @param  string $find    string to replace
	 * @param  string $replace replacement
	 * @param  string $text    source text
	 * @return string $text    modified text
	 */
	public function replace_text( $find, $replace, $text ) {
		if (isset($this->pro_settings['placeholders_allow_line_breaks']) && is_string($text)) {
			$text = nl2br( wptexturize( $text ) );
		}

		$text = str_replace($find, $replace, $text);
		return $text;
	}

	public function init_packing_slip( $document ) {
		if ( 'packing-slip' === $document->get_type() ) {
			if ( empty( $document->get_date() ) ) {
				$document->set_date( current_time( 'timestamp', true ) );
			}
			
			$document->initiate_number();
		}
	}
	
	/**
	 * Legacy function < v3.8.0
	 * 
	 * Still being used by thrid party plugins.
	 *
	 * @return mixed
	 */
	public function init_packing_slip_number( $packing_slip ) {
		wcpdf_deprecated_function( 'init_packing_slip_number', '3.8.0', 'initiate_number' );
		return $packing_slip->initiate_number();
	}

	public function packing_slip_number_date( $document_type, $order ) {
		if ( 'packing-slip' === $document_type ) {
			$packing_slip_settings = WPO_WCPDF()->settings->get_document_settings( $document_type );
			
			if ( isset( $packing_slip_settings['display_date'] ) || isset( $packing_slip_settings['display_number'] ) ) {
				$packing_slip = wcpdf_get_document( $document_type, $order );
				if ( empty( $packing_slip ) ) {
					return;
				}
				
				// Preview
				if ( isset( $_REQUEST['action'] ) && 'wpo_wcpdf_preview' === $_REQUEST['action'] ) {
					if ( ! $packing_slip->exists() ) {
						$packing_slip->set_date( current_time( 'timestamp', true ) );
						$number_store_method = WPO_WCPDF()->settings->get_sequential_number_store_method();
						$number_store_name   = apply_filters( 'wpo_wcpdf_document_sequential_number_store', "{$packing_slip->slug}_number", $packing_slip );
						$number_store        = new \WPO\WC\PDF_Invoices\Documents\Sequential_Number_Store( $number_store_name, $number_store_method );
						$packing_slip->set_number( $number_store->get_next() );
					}

					// apply document number formatting
					$document_number = $packing_slip->get_number( $document_type );
					if ( ! empty( $document_number ) ) {
						if ( ! empty( $packing_slip->settings['number_format'] ) ) {
							foreach ( $packing_slip->settings['number_format'] as $key => $value ) {
								$document_number->$key = $packing_slip->settings['number_format'][$key];
							}
						}
						$document_number->apply_formatting( $packing_slip, $order );
					}
				}
				
				$number = $packing_slip->get_number( $packing_slip->get_type() );
				$date   = $packing_slip->get_date();

				// Packing Slip Number
				if ( ! empty( $packing_slip_settings['display_number'] ) && $number ) {
					?>
					<tr class="packing-slip-number">
						<th><?php echo $packing_slip->get_number_title(); ?></th>
						<td><?php echo $number; ?></td>
					</tr>
					<?php
				}
				// Packing Slip Date
				if ( isset( $packing_slip_settings['display_date'] ) && $date ) {
					?>
					<tr class="packing-slip-date">
						<th><?php echo $packing_slip->get_date_title(); ?></th>
						<td><?php echo $date->date_i18n( apply_filters( 'wpo_wcpdf_date_format', wc_date_format(), $packing_slip ) ); ?></td>
					</tr>
					<?php
				}
			}
		}
	}

	public function subtract_refunded_qty ( $items_data, $order, $document_type ) {
		$packing_slip_settings = WPO_WCPDF()->settings->get_document_settings( 'packing-slip' );

		if ( $document_type == 'packing-slip' && isset($packing_slip_settings['subtract_refunded_qty']) ) {

			foreach ($items_data as $key => &$item) {
				if ( empty($item['quantity']) || !is_numeric($item['quantity']) ) {
					continue;
				}
				// item_id is required! (introduced in 1.5.3 of main plugin)
				if ( isset( $item['item_id'] ) ) {
					$refunded_qty     = $order->get_qty_refunded_for_item( $item['item_id'] );
					$item['quantity'] = $item['quantity'] + $refunded_qty;

				}

				if ( $item['quantity'] == 0 ) {
					//remove 0 qty items
					unset( $items_data[$key] );
				}
			}
		}
		return $items_data;
	}

	public function hide_virtual_downloadable_products( $items_data, $order, $document_type ) {
		$packing_slip_settings = WPO_WCPDF()->settings->get_document_settings( 'packing-slip' );

		if ( 'packing-slip' !== $document_type || ! isset( $packing_slip_settings['hide_virtual_downloadable_products'] ) || empty( $packing_slip_settings['hide_virtual_downloadable_products'] ) ) {
			return $items_data;
		}

		foreach ( $items_data as $key => &$item ) {
			// Ignore WooCommerce Product Bundle containers if the bundle contains a non-virtual/non-downloadable product
			if ( function_exists( 'wc_pb_get_bundled_order_items' ) ) {
				$bundled_items = wc_pb_get_bundled_order_items( $item['item'], $order );
				if ( ! empty( $bundled_items ) ) {
					foreach ( $bundled_items as $bundled_item ) {
						if ( ! $bundled_item->get_product()->is_virtual() && ! $bundled_item->get_product()->is_downloadable() ) {
							continue 2;
						}
					}
				}
			}

			if ( empty( $item['product'] ) ) {
				continue;
			}

			switch ( $packing_slip_settings['hide_virtual_downloadable_products'] ) {
				case 'virtual':
					if ( $item['product']->is_virtual() && ! $item['product']->is_downloadable() ) {
						unset( $items_data[ $key ] );
					}
					break;
				case 'downloadable':
					if ( $item['product']->is_downloadable() && ! $item['product']->is_virtual() ) {
						unset( $items_data[ $key ] );
					}
					break;
				case 'virtual_and_downloadable':
					if ( $item['product']->is_virtual() && $item['product']->is_downloadable() ) {
						unset( $items_data[ $key ] );
					}
					break;
				case 'virtual_or_downloadable':
					if ( $item['product']->is_virtual() || $item['product']->is_downloadable() ) {
						unset( $items_data[ $key ] );
					}
					break;
			}
		}

		return $items_data;
	}

	/**
	 * Show positive prices on credit note following user settings
	 */
	public function positive_credit_note ( $template_type ) {
		$credit_note_settings = WPO_WCPDF()->settings->get_document_settings( 'credit-note' );
		if ( $template_type == 'credit-note' && isset( $credit_note_settings['positive_prices'] ) ) {
			add_filter( 'wc_price', array( $this, 'woocommerce_positive_prices' ), 10, 3 );
		}
	}

	public function woocommerce_positive_prices ( $formatted_price, $price, $args ) {
		if( strpos($formatted_price, '<bdi>') !== false ) {
			$formatted_price = str_replace('amount"><bdi>-', 'amount"><bdi>', $formatted_price);
		} else {
			$formatted_price = str_replace('amount">-', 'amount">', $formatted_price);
		}
		return $formatted_price;
	}

	public function original_invoice_number ($template_type, $order) {
		$credit_note_settings = WPO_WCPDF()->settings->get_document_settings( 'credit-note' );
		if ($template_type == 'credit-note' && isset( $credit_note_settings['original_invoice_number'] ) ) {
			$credit_note = wcpdf_get_document( 'credit-note', $order );
			if ( $credit_note && $credit_note->exists() ) {
				?>
				<tr class="invoice-number">
					<th><?php _e( 'Original Invoice Number:', 'wpo_wcpdf_pro' ); ?></th>
					<td><?php $credit_note->number( 'invoice' ); ?></td>
				</tr>
				<?php
			}
		}
	}

	public function credit_note_maybe_use_order_items( $document_type, $document ) {
		$credit_note_settings = WPO_WCPDF()->settings->get_document_settings( 'credit-note' );
		if ( $document_type == 'credit-note' && isset( $credit_note_settings['use_parent_data'] ) && !empty( $document->order ) && $document->order->get_type() == 'shop_order_refund' ) {
			$parent_order = wc_get_order( $document->order->get_parent_id() );
			$refund_items = $document->order->get_items();
			$refund_amount = round( abs( $document->order->get_amount() ), 2 );
			$original_amount = round( abs( $parent_order->get_total() ), 2 );
			if ( $refund_amount == $original_amount && empty($refund_items) ) {
				add_filter( 'woocommerce_order_get_items', array( $this, 'get_items_refund_parent' ),10,3);
				add_filter( 'wc_price', array( $this, 'wc_negative_prices' ), 99, 4 );
				add_filter( 'woocommerce_get_order_item_totals', array( $this, 'fix_discount_double_negative_sign' ) );
				foreach ($this->get_refund_parent_properties() as $property) {
					add_filter( "woocommerce_order_refund_get_{$property}", array( $this, 'use_refund_parent_properties' ), 10, 2 );
				}
			}
		}
	}

	public function credit_note_dont_use_order_items( $document_type, $document ) {
		remove_filter( 'woocommerce_order_get_items', array( $this, 'get_items_refund_parent' ),10,3);
		remove_filter( 'wc_price', array( $this, 'wc_negative_prices' ), 99, 4 );
		remove_filter( 'woocommerce_get_order_item_totals', array( $this, 'fix_discount_double_negative_sign' ) );
		foreach ($this->get_refund_parent_properties() as $property) {
			remove_filter( "woocommerce_order_refund_get_{$property}", array( $this, 'use_refund_parent_properties' ), 10, 2 );
		}
	}

	public function get_items_refund_parent($items, $order, $types) {
		if ($order->get_type() == 'shop_order_refund') {
			$parent_order = wc_get_order( $order->get_parent_id() );
			$items = $parent_order->get_items($types);
			foreach ($items as $item_id => $item) {
				if ( is_callable( array(  $item, "set_quantity" ) ) ) {
					$items[$item_id]->set_quantity($item->get_quantity()*-1);
				}
			}
		}
		return $items;
	}

	public function use_refund_parent_properties( $value, $refund ) {
		$prop = str_replace( 'woocommerce_order_refund_get_', '', current_filter() );
		$parent_order = wc_get_order( $refund->get_parent_id() );
		return $parent_order->{"get_{$prop}"}();
	}

	public function wc_negative_prices( $formatted_price, $price, $args, $unformatted_price = null ) {
		if (empty($args['is_negative_price']) && !empty($unformatted_price)) {
			$args['is_negative_price'] = true;
			$formatted_price = wc_price( $unformatted_price * -1 , $args );
		}
		return $formatted_price;
	}

	public function fix_discount_double_negative_sign( $total_rows ) {
		$credit_note_settings = WPO_WCPDF()->settings->get_document_settings( 'credit-note' );
		if ( isset( $total_rows['discount'] ) && ! isset( $credit_note_settings['positive_prices'] ) ) {
			$total_rows['discount']['value'] = str_replace( '-<span', '<span', $total_rows['discount']['value'] );
		}

		return $total_rows;
	}

	public function get_refund_parent_properties() {
		return array(
			'discount_total',
			'discount_tax',
			'shipping_total',
			'shipping_tax',
			'cart_tax',
			'total',
			'total_tax',
		);
	}


	/**
	 * Add '(includes %s)' tax string to refund total
	 * @param  string $formatted_total formatted order/refund total
	 * @param  object $order           WC_Order object
	 * @return string                  formatted order/refund total with taxes added for refunds
	 */
	public function refund_taxes_simple_template( $formatted_total, $order, $tax_display = '', $display_refunded = true ) {
		// don't apply this if already filtered externally
		if ( function_exists( 'woocommerce_get_formatted_refund_total' ) ) {
			return $formatted_total;
		}

		$order_type = method_exists( $order, 'get_type' ) ? $order->get_type() : $order->order_type;
		
		if ( 'shop_order_refund' === $order_type ) {
			// Tax for inclusive prices.
			if ( wc_tax_enabled() ) {
				$tax_string_array  = array();
				$tax_total_display = get_option( 'woocommerce_tax_total_display' );
				
				if ( 'itemized' === $tax_total_display ) {
					foreach ( $order->get_tax_totals() as $code => $tax ) {
						$tax_amount         = $tax->formatted_amount;
						$tax_string_array[] = sprintf( '%s %s', $tax_amount, $tax->label );
					}
				} else {
					$tax_amount      = $order->get_total_tax();
					// get currency from parent
					$parent_order_id = $order->get_parent_id();
					$parent_order    = wc_get_order( $parent_order_id );

					$tax_string_array[] = sprintf( '%s %s', wc_price( $tax_amount, array( 'currency' => $parent_order->get_currency() ) ), WC()->countries->tax_or_vat() );
				}
				
				if ( ! empty( $tax_string_array ) ) {
					$tax_string       = ' <small class="includes_tax">' . sprintf( __( '(includes %s)', 'woocommerce' ), implode( ', ', $tax_string_array ) ) . '</small>';
					$formatted_total .= $tax_string;
				}
			}
		}

		return $formatted_total;
	}

	public function filter_document_titles() {
		$documents = WPO_WCPDF()->documents->get_documents( 'all' );

		foreach ( $documents as $_document ) {
			// document title
			add_filter( 'wpo_wcpdf_document_title', function( $title, $document = null ) use ( $_document ) {
				return $this->get_custom_title_for_filter( 'title', $title, $document, $_document );
			}, 20, 2 );

			// document number title
			add_filter( 'wpo_wcpdf_document_number_title', function( $title, $document = null ) use ( $_document ) {
				return $this->get_custom_title_for_filter( 'number_title', $title, $document, $_document );
			}, 20, 2 );

			// document date title
			add_filter( 'wpo_wcpdf_document_date_title', function( $title, $document = null ) use ( $_document ) {
				return $this->get_custom_title_for_filter( 'date_title', $title, $document, $_document );
			}, 20, 2 );

			// due date title
			add_filter( 'wpo_wcpdf_document_due_date_title', function( $title, $document = null ) use ( $_document ) {
				return $this->get_custom_title_for_filter( 'due_date_title', $title, $document, $_document );
			}, 20, 2 );
		}
	}

	private function get_custom_title_for_filter( $title_slug, $title, $document, $_document) {
		if ( empty( $document ) ) {
			$document = &$_document;
		}
		
		if ( 'summary' === $document->get_type() ) {
			return $title;
		}

		$custom_title = $document->get_settings_text( $title_slug, false, false );
		if ( ! empty( $document->order ) && ! empty( WPO_WCPDF_Pro()->multilingual_full ) ) {
			$custom_title = Language_Switcher::get_i18n_setting( $title_slug, $custom_title, $document );
		}
		if ( ! empty( $custom_title ) ) {
			$title = $custom_title;
		}
		return $title;
	}

	public function override_document_filename( $filename, $document_type, $order_ids = array(), $context = '', $args = array() ) {
		if ( ! empty( $args['output'] ) && 'ubl' === esc_attr( $args['output'] ) ) {
			return $filename;
		}

		$document_settings = WPO_WCPDF()->settings->get_document_settings( $document_type );

		if ( ! empty( $document_settings['filename'] ) && ! empty( array_filter( $document_settings['filename'] ) ) && count( $order_ids ) == 1 ) {
			$order           = wc_get_order( $order_ids[0] );
			$document        = wcpdf_get_document( $document_type, $order );
			$custom_filename = $document->get_settings_text( 'filename', false, false );

			if ( ! empty( $document->order ) && ! empty( WPO_WCPDF_Pro()->multilingual_full ) ) {
				$custom_filename = Language_Switcher::get_i18n_setting( 'filename', $custom_filename, $document );
			}

			if ( ! empty( $custom_filename ) ) {
				// replace document numbers
				$custom_filename = $this->make_document_replacements( $custom_filename, $document );
				// replace order data
				$custom_filename = $this->make_replacements( $custom_filename, $order );
				$filename_parts  = explode( '.', $custom_filename );
				$extension       = end( $filename_parts );

				if ( strtolower( $extension ) != 'pdf' ) {
					$custom_filename .= '.pdf';
				}

				if ( ! empty( str_replace( '.pdf', '', $custom_filename ) ) ) {
					return $custom_filename;
				}
			}
		}
		return $filename;
	}

	public function store_bulk_documents_in_archive( $pdf, $bulk_document ) {
		$document_settings = WPO_WCPDF()->settings->get_document_settings( $bulk_document->type );

		if ( ! isset( $bulk_document->is_bulk ) || ! isset( $document_settings['archive_pdf'] ) ) {
			return $pdf;
		}

		$merger = new \WPO\WC\PDF_Invoices_Pro\Vendor\iio\libmergepdf\Merger;
		$pdfs   = array();

		foreach ( $bulk_document->order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			
			if ( $order ) {
				$document = wcpdf_get_document( $bulk_document->type, $order );
				
				if ( $document && $document->exists() ) {
					$pdfs[] = $document->get_pdf();
				}
			}
		}
		
		if ( ! empty( $pdfs ) ) {
			foreach ( $pdfs as $pdf ) {
				$merger->addRaw( $pdf );
			}
			return $merger->merge();
		} 
	}

	public function store_pdf_file_in_archive( $pdf, $document ) {
		$document_settings = $document->get_settings( true );
		if ( $order = $document->order ) {

			$parent_order = $refund_id = false;

			// if credit note or other document using child order
			if ( ! is_callable( array( $order, 'get_order_key' ) ) && is_callable( array( $order , 'get_parent_id' ) ) ) {
				$parent_id = $order->get_parent_id();
				
				if ( $parent_id != 0 ) {
					$refund_id    = $order->get_id();
					$parent_order = wc_get_order( $parent_id );
					
					if ( ! is_callable( array( $parent_order, 'get_order_key' ) ) ) {
						return;
					}
					
				} else {
					return;
				}
			}

			$order_key = $parent_order ? $parent_order->get_order_key() : $order->get_order_key();

			if ( isset( $document_settings['archive_pdf'] ) && empty( $order->get_meta( "_wpo_wcpdf_{$document->slug}_archived" ) ) ) {
				$archive_path = WPO_WCPDF()->main->get_tmp_path( 'archive' );
				$filename = $refund_id ? sprintf('%s-%s-%s.pdf', $document->slug, $refund_id, $order_key ) : sprintf('%s-%s-%s.pdf', $document->slug, $order->get_id(), $order_key );
				$filename = sanitize_file_name( apply_filters( 'wpo_wcpdf_filename_archived_pdf', $filename, $document ) );
				file_put_contents( $archive_path . '/' . $filename, $pdf, LOCK_EX );
				$order->update_meta_data( "_wpo_wcpdf_{$document->slug}_archived", $filename );
				$order->save_meta_data();
			} 
		}
	}

	public function unlink_archived_pdf( $document ) {
		if ( $order = $document->order ) {
			$order->delete_meta_data( "_wpo_wcpdf_{$document->slug}_archived" );
			$order->save_meta_data();
		}
	}

	public function regenerate_archived_pdf( $document ) {
		$document_settings = $document->get_settings( true );
		if ( isset( $document_settings['archive_pdf'] ) ) {
			$this->unlink_archived_pdf( $document );
			$document->get_pdf();
		}
	}

	public function load_archived_pdf_file_path( $pdf_file, $document ) {
		$document_settings = $document->get_settings( true );
		if ( isset( $document_settings['archive_pdf'] ) && ( $order = $document->order ) ) {

			if ( $filename = $order->get_meta( "_wpo_wcpdf_{$document->slug}_archived" ) ) {
				$archive_path = WPO_WCPDF()->main->get_tmp_path( 'archive' );

				if ( ! file_exists( $archive_path . '/' . $filename ) ) {
					// Remove archived meta
					$order->delete_meta_data( "_wpo_wcpdf_{$document->slug}_archived" );
					$order->save_meta_data();

					// if credit note or other document using child order
					if ( ! is_callable( array( $order, 'get_order_key' ) ) && is_callable( array( $order, 'get_parent_id' ) ) ) {
						$parent_id = $order->get_parent_id();
						if ( $parent_id != 0 ) {
							$refund_id    = $order->get_id();
							$parent_order = wc_get_order( $parent_id );
						}
					}
					
					if ( empty( $parent_order ) ) {
						$parent_order = $refund_id = false;
					}

					// Add order note
					$note_order = $parent_order ? $parent_order : $order;
					if ( is_callable( array( $note_order, 'add_order_note' ) ) ) {
						if ( $refund_id ) {
							/* translators: 1. document title, 2. refund ID */
							$note = sprintf( __( '%1$s (refund #%2$s) was marked as archived but not found on the server. A new version has been uploaded.', 'wpo_wcpdf_pro' ), $document->get_title(), $refund_id );
						} else {
							/* translators: 1. document title */
							$note = sprintf( __( '%s was marked as archived but not found on the server. A new version has been uploaded.', 'wpo_wcpdf_pro' ), $document->get_title() );
						}
						$note_order->add_order_note( $note );
					}
				} else {
					$pdf_file = $archive_path . '/' . $filename;
				}
				clearstatcache();
			}
			
		}

		return $pdf_file;
	}
	
	public function add_archive_dir_to_status( $directories, $status ) {
		$archive_dir                      = WPO_WCPDF()->main->get_tmp_path( 'archive' );
		$directories['WCPDF_ARCHIVE_DIR'] = array (
			'description'    => __( 'Archive folder', 'wpo_wcpdf_pro' ),
			'value'          => trailingslashit( $archive_dir ),
			'status'         => is_writable( $archive_dir ) ? 'ok' : 'failed',			
			'status_message' => is_writable( $archive_dir ) ? $status['ok'] : $status['failed'],
		);
		return $directories;
	}

	public function get_valid_order_status_to_generate_pdfs() {
		$documents = WPO_WCPDF()->documents->get_documents();
		$status    = array();

		foreach ( $documents as $document ) {
			$enable_for_status  = ! empty( $document->settings['auto_generate_for_statuses'] ) ? $document->settings['auto_generate_for_statuses'] : array();
			$disable_for_status = ! empty( $document->settings['disable_for_statuses'] )       ? $document->settings['disable_for_statuses']       : array();
			
			foreach ( array_diff( $enable_for_status, $disable_for_status ) as $valid_status ) {
				$status[ $valid_status ][] = $document->get_type();
			}
		}

		return apply_filters( 'wpo_wcpdf_pro_order_status_to_generate_pdfs', $status, $documents );
	}

	public function generate_documents_on_order_status( $order_id, $old_status, $new_status, $order ) {
		// we use ID to force to reloading the order to make sure that all meta data is up to date.
		// this is especially important when multiple processes create the same document in the same request
		if ( empty( $order_id ) || empty( $new_status ) ) {
			return;
		}

		$hook         = 'wpo_wcpdf_generate_document_on_order_status';
		$valid_status = $this->get_valid_order_status_to_generate_pdfs();
		$status       = "wc-{$new_status}";

		if ( ! empty( $valid_status[ $status ] ) ) {
			foreach ( $valid_status[ $status ] as $document_type ) {
				if ( ! apply_filters( 'wpo_wcpdf_allow_document_generation_on_order_status', true, $document_type, $order_id, $old_status, $new_status ) ) {
					continue;
				}

				if ( function_exists( 'as_enqueue_async_action' ) ) {
					$args = array(
						'document_type' => $document_type,
						'order_id'      => $order_id,
					);
					as_enqueue_async_action( $hook, $args );
				} else {
					$this->generate_document_from_order_status( $document_type, $order_id );
				}
			}
		}
	}

	/**
	 * Generate document from order status.
	 *
	 * @param string     $document_type
	 * @param string|int $order_id
	 *
	 * @return void
	 */
	public function generate_document_from_order_status( string $document_type, $order_id ): void {
		$document = wcpdf_get_document( $document_type, absint( $order_id ), true );

		// archive PDF file
		if ( $document && $document->exists() ) {
			$document_settings = $document->get_settings( true );
			if ( isset( $document_settings['archive_pdf'] ) && empty( $document->order->get_meta( "_wpo_wcpdf_{$document->slug}_archived" ) ) ) {
				$document->get_pdf();

				WPO_WCPDF()->main->log_document_creation_to_order_notes( $document, 'automatically_order_status' );
				WPO_WCPDF()->main->log_document_creation_trigger_to_order_meta( $document, 'automatically_order_status' );
			}
		}
	}

	public function generate_credit_note_on_refund( $order_id, $refund_id ) {
		$credit_note_settings = WPO_WCPDF()->settings->get_document_settings( 'credit-note' );
		if ( empty( $credit_note_settings['auto_generate_on_refunds'] ) ) {
			return;
		}

		$refund       = wc_get_order( $refund_id );
		$invoice      = wcpdf_get_invoice( wc_get_order( $order_id ) );
		$can_generate = apply_filters( 'wpo_wcpdf_can_generate_credit_note_on_refund', ( $refund && $invoice && $invoice->exists() ), $order_id, $refund_id );

		if ( $can_generate ) {
			$document = wcpdf_get_document( 'credit-note', $refund, true );

			WPO_WCPDF()->main->log_document_creation_to_order_notes( $document, 'automatically_order_refund' );
			WPO_WCPDF()->main->log_document_creation_trigger_to_order_meta( $document, 'automatically_order_refund' );

		}
	}

	public function remove_third_party_filters( $document_type, $document ) {
		if ( 'credit-note' === $document_type ) {
			// WooCommerce German Market
			if ( class_exists( 'WGM_Template' ) ) {
				remove_filter( 'woocommerce_get_formatted_order_total', array( 'WGM_Template', 'kur_review_order_item' ), 1, 1 );
				remove_filter( 'woocommerce_get_order_item_totals', array( 'WGM_Template', 'get_order_item_totals' ), 10, 2 );
				remove_filter( 'woocommerce_get_order_item_totals', array( 'WGM_Fee', 'add_tax_string_to_fee_order_item' ), 10, 2 );
				remove_filter( 'woocommerce_order_get_tax_totals', array( 'WGM_Fee', 'add_fee_to_order_tax_totals' ), 10, 2 );
			}
			
			// SUMO Reward Points - http://fantasticplugins.com
			if ( function_exists( 'rs_redeemed_point_in_thank_you_page' ) ) {
				remove_filter( 'woocommerce_get_order_item_totals', 'rs_redeemed_point_in_thank_you_page', 8, 3 );
			}
			
			// WebToffee WooCommerce Gift Cards - https://www.webtoffee.com/product/woocommerce-gift-cards/
			if ( class_exists( 'Wt_Gc_Refund_Store_Credit' ) ) {
				remove_filter( 'woocommerce_get_order_item_totals', array( \Wt_Gc_Refund_Store_Credit::get_instance(), 'display_refund_data_order_total' ), 16, 3 );
			}
			
			// WooPayments - https://wordpress.org/plugins/woocommerce-payments/
			if ( function_exists( 'WC_Payments_Multi_Currency' ) && is_callable( array( WC_Payments_Multi_Currency(), 'get_frontend_currencies' ) ) ) {
				try {
					$frontend_currencies = WC_Payments_Multi_Currency()->get_frontend_currencies();

					if ( $frontend_currencies && is_callable( array( $frontend_currencies, 'maybe_clear_order_currency_after_formatted_order_total' ) ) ) {
						remove_filter( 'woocommerce_get_formatted_order_total', array( $frontend_currencies, 'maybe_clear_order_currency_after_formatted_order_total' ), 900, 4 );
						remove_action( 'woocommerce_get_formatted_order_total', array( $frontend_currencies, 'maybe_clear_order_currency_after_formatted_order_total' ), 900, 4 ); // see https://github.com/Automattic/woocommerce-payments/issues/9335
					}
				} catch ( \Throwable $exception ) {
					wcpdf_log_error( $exception->getMessage() );
                }
			}
		}
	}

	public function get_wp_language_list() {
		require_once( ABSPATH . 'wp-admin/includes/translation-install.php' );

		$language_list = wp_get_available_translations();
		$language_list = array_merge(
			array(
				'en_US' => array(
					'language'    => 'en_US',
					'native_name' => 'English (US)',
					'iso'         => array( 'en' ),
				),
			),
			$language_list
		); // by default 'en_US' is not included

		// return installed languages only
		if( apply_filters( 'wpo_wcpdf_wp_languages_list_installed_only', true ) ) {
			$available_locales = $this->get_wp_available_languages();
			$language_list     = array_intersect_key( $language_list, array_flip( $available_locales ) );
		}

		return $language_list;
	}

	public function get_wp_available_languages() {
		$available_locales = get_available_languages();
		$available_locales = array_merge( array( 'en_US' ), $available_locales ); // by default 'en_US' is not included

		return $available_locales;
	}

	public function credit_note_preview_order_search_args( $args, $document_type ) {
		if ( $document_type == 'credit-note' ) {
			$args['status'] = array( 'wc-refunded' );
		}
		return $args;
	}

	public function credit_note_default_order_id_query_args( $args, $document_type ) {
		if ( $document_type == 'credit-note' ) {
			$args['type'] = 'shop_order_refund';
		}
		return $args;
	}

	public function get_summary_document( $order_ids, $export_settings ) {
		return new \WPO\WC\PDF_Invoices\Documents\Summary( $order_ids, $export_settings );
	}

	public function add_summary_to_bulk_export_documents() {
		printf( '<option value="summary">%s</option>', __( 'Summary of Invoices', 'wpo_wcpdf_pro' ) );
	}

	public function get_export_bulk_date_types() {
		return apply_filters( 'wpo_wcpdf_export_bulk_date_type_options', array(
			'order_date'    => __( 'Order date', 'wpo_wcpdf_pro' ),
			'document_date' => __( 'Document date', 'wpo_wcpdf_pro' ),
		) );
	}

	/**
	 * Check if specific Pro documents are allowed.
	 * 
	 * @param $allowed
	 * @param $document
	 * 
	 * @return bool
	 */
	public function is_pro_document_allowed( $allowed, $document ) {
		$order         = $document->order;
		$document_type = $document->get_type();
		
		if ( 'credit-note' === $document_type ) {
			$refunds = '';
			$amount  = '';
			
			if ( $order instanceof \WC_Order ) {
				$refunds   = is_callable( array( $order, 'get_refunds' ) ) ? $order->get_refunds() : '';
			} elseif ( $order instanceof \WC_Order_Refund ) {
				$amount    = is_callable( array( $order, 'get_amount' ) ) ? $order->get_amount() : '';
				$parent_id = $order->get_parent_id();
				$order     = wc_get_order( $parent_id );
			}
			
			if ( empty( $refunds ) && empty( $amount ) ) {
				return false;
			}
		}
		
		$receipt_settings = WPO_WCPDF()->settings->get_document_settings( 'receipt' );
		$require_invoice  = array( 'credit-note' );
		
		if ( isset( $receipt_settings['require_invoice'] ) ) {
			$require_invoice[] = 'receipt';
		}
		
		if ( in_array( $document_type, $require_invoice ) ) {
			$invoice = wcpdf_get_invoice( $order );
			
			if ( $invoice && ! $invoice->exists() ) {
				return false;
			}
		}
		
		return $allowed;
	}

	public function pro_document_triggers( array $triggers ): array {
		$triggers['automatically_order_status'] = __( 'automatically on order status', 'wpo_wcpdf_pro' );
		$triggers['automatically_order_refund'] = __( 'automatically on order refund', 'wpo_wcpdf_pro' );
		$triggers['rest_document_data']         = __( 'REST API', 'wpo_wcpdf_pro' );

		return $triggers;
	}
	
	public function credit_note_number_search_enabled() {
		$is_enabled           = false;
		$credit_note_settings = WPO_WCPDF()->settings->get_document_settings( 'credit-note' );
		
		if ( isset( $credit_note_settings['credit_note_number_search'] ) ) {
			$is_enabled = true;
		}
		
		return $is_enabled;
	}
	
	public function credit_note_number_search_query( $order_ids, $term, $search_fields ) {
		$args = array(
			'type'       => 'shop_order_refund',
			'limit'      => -1,
			'date_query' => array(
				array(
					'year'    => date( 'Y' ),
					'compare' => '>='
				),
			),
			'credit_note_number_search' => esc_attr( $term ),
		);
		
		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '6.8.0', '>=' ) && WPO_WCPDF()->order_util->custom_orders_table_usage_is_enabled() ) { // Woo >= 6.8.0 + HPOS
			$args = $this->credit_note_number_search_custom_query_var( $args, $args );
		} else {
			add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( $this, 'credit_note_number_search_custom_query_var' ), 10, 2 );
		}
		
		$refunds = wc_get_orders( $args );
		
		remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( $this, 'credit_note_number_search_custom_query_var' ), 10, 2 );
		
		if ( ! empty( $refunds ) ) {
			foreach ( $refunds as $refund ) {
				$parent_id = absint( $refund->get_parent_id() );
				if ( 0 != $parent_id && ! in_array( $parent_id, $order_ids ) ) {
					$order_ids[] = $parent_id;
				}
			}
		}
		
		return $order_ids;
	}
	
	public function credit_note_number_search_custom_query_var( $query, $query_vars ) {
		if ( ! empty( $query_vars['credit_note_number_search'] ) ) {
			$query['meta_query'][] = array(
				'key'   => '_wcpdf_credit_note_number',
				'value' => esc_attr( $query_vars['credit_note_number_search'] ),
			);
			
			if ( isset( $query['credit_note_number_search'] ) ) {
				unset( $query['credit_note_number_search'] );
			}
		}
	
		return $query; 
	}

	public function multilingual_supported_plugins(): array {
		return apply_filters( 'wpo_wcpdf_pro_multilingual_supported_plugins', array(
			'wpml' => array(
				'function' => 'wcml_is_multi_currency_on', // since v3.8.3
				'name'     => 'WPML',
				'support'  => 'full',
				'logo'     => WPO_WCPDF_Pro()->plugin_url() . '/assets/images/wpml.svg',
			),
			'polylang' => array(
				'function' => 'pll_is_cache_active',
				'name'     => 'Polylang',
				'support'  => 'full',
				'logo'     => WPO_WCPDF_Pro()->plugin_url() . '/assets/images/polylang.svg',
			),
			'translatepress' => array(
				'function' => 'trp_translate',
				'name'     => 'TranslatePress',
				'support'  => 'full',
				'logo'     => WPO_WCPDF_Pro()->plugin_url() . '/assets/images/translatepress.svg',
			),
			'weglot' => array(
				'function' => 'weglot_plugin_loaded',
				'name'     => 'Weglot',
				'support'  => 'html',
				'logo'     => WPO_WCPDF_Pro()->plugin_url() . '/assets/images/weglot.svg',
			),
			'gtranslate' => array(
				'function' => 'sg_cache_exclude_js_gtranslate', // the better named functions are behind data checks.
				'name'     => 'GTranslate',
				'support'  => 'html',
				'logo'     => WPO_WCPDF_Pro()->plugin_url() . '/assets/images/gtranslate.svg',
			),
		) );
	}
	
	/**
	 * Get active multilingual plugins.
	 *
	 * @param string $support  Can be 'full', 'html' or 'all'.
	 *
	 * @return array
	 */
	public function get_active_multilingual_plugins( string $support = 'all' ): array {
		$active_plugins = array();
		
		foreach ( $this->multilingual_supported_plugins() as $slug => $plugin ) {
			if ( function_exists( $plugin['function'] ) ) {
				if ( 'all' === $support || ( isset( $plugin['support'] ) && $plugin['support'] === $support ) ) {
					$active_plugins[ $slug ] = $plugin;
				}
			}
		}
		
		return $active_plugins;
	}
	
	public function get_pro_license_key(): string {
		$transient   = 'wpo_wcpdf_pro_license_key_transient';
		$license_key = ( false !== get_transient( $transient ) ) ? get_transient( $transient ) : '';

		if ( empty( $license_key ) ) {
			$updater = WPO_WCPDF_Pro()->updater;

			if ( ! empty( $updater ) ) {
				// built-in updater
				if ( is_callable( array( $updater, 'get_license_key' ) ) ) {
					$license_key = $updater->get_license_key();

				// sidekick (legacy)
				} elseif ( property_exists( $updater, 'license_key' ) ) {
					$license_slug     = "wpo_wcpdf_pro_license";
					$wpo_license_keys = get_option( 'wpocore_settings', array() );
					$license_key      = isset( $wpo_license_keys[ $license_slug ] ) ? $wpo_license_keys[ $license_slug ] : $license_key;
				}
			}

			set_transient( $transient, $license_key, HOUR_IN_SECONDS );
		}

		return $license_key;
	}
	
	public function get_pro_license_status(): string {
		$license_status = 'inactive';
		
		if ( is_callable( array( WPO_WCPDF()->settings->upgrade, 'get_extensions_license_data' ) ) ) {
			$license_data = WPO_WCPDF()->settings->upgrade->get_extensions_license_data();
			
			if ( ! empty( $license_data ) && isset( $license_data['pro']['status'] ) ) {
				$license_status = esc_attr( $license_data['pro']['status'] );
			}
		}
		
		return $license_status;
	}

	/**
	 * Check to see if the due date should be displayed on the document.
	 *
	 * @param int|string $due_date The legacy hook sends a string, while the new hook sends an int.
	 * @param \WPO\WC\PDF_Invoices\Documents\Order_Document $document
	 *
	 * @return int|string The legacy hook receives a string, while the new hook receives an int.
	 */
	public function validate_due_date_display( $due_date, \WPO\WC\PDF_Invoices\Documents\Order_Document $document ) {
		$allowed_statuses        = $document->get_setting( 'due_date_allowed_statuses' );
		$allowed_payment_methods = $document->get_setting( 'due_date_allowed_payment_methods' );

		// Check for allowed order statuses.
		if ( ! empty( $allowed_statuses ) && ! in_array( 'wc-' . $document->order->get_status(), $allowed_statuses, true ) ) {
			return 0;
		}

		// Check for allowed payment methods.
		if ( ! empty( $allowed_payment_methods ) && ! in_array( $document->order->get_payment_method(), $allowed_payment_methods, true ) ) {
			return 0;
		}

		return $due_date;
	}

	/**
	 * Displays the COC number in the Invoice
	 *
	 * @param  string $document_type
	 * @param  object $order
	 * @return void
	 */
	public function display_shop_coc_number_in_invoice( string $document_type, object $order ): void {
		if ( ! empty( $order ) && 'invoice' === $document_type ) {
			$invoice          = wcpdf_get_invoice( $order );
			$invoice_settings = WPO_WCPDF()->settings->get_document_settings( $document_type );
			$label            = apply_filters( 'wpo_wcpdf_shop_coc_number_label', $invoice->get_settings_text( 'shop_coc_label', __( 'COC', 'wpo_wcpdf_pro' ), false ), $document_type );

			if ( $invoice && is_callable( array( $invoice, 'shop_coc_number' ) ) && isset( WPO_WCPDF()->settings->general_settings['coc_number'] ) && isset( $invoice_settings['display_shop_coc'] ) ) {
				?>
					<div class="shop-coc-number"><label><?php echo $label; ?>:</label>&nbsp;<span><?php $invoice->shop_coc_number(); ?></span></div>
				<?php
			}
		}
	}

	/**
	 * Displays the VAT number in the Invoice
	 *
	 * @param  string $document_type
	 * @param  object $order
	 * @return void
	 */
	public function display_shop_vat_number_in_invoice( string $document_type, object $order ): void {
		if ( ! empty( $order ) && 'invoice' === $document_type ) {
			$invoice          = wcpdf_get_invoice( $order );
			$invoice_settings = WPO_WCPDF()->settings->get_document_settings( $document_type );
			$label            = apply_filters( 'wpo_wcpdf_shop_vat_number_label', $invoice->get_settings_text( 'shop_vat_label', __( 'VAT', 'wpo_wcpdf_pro' ), false ), $document_type );

			if ( $invoice && is_callable( array( $invoice, 'shop_vat_number' ) ) && isset( WPO_WCPDF()->settings->general_settings['vat_number'] ) && isset( $invoice_settings['display_shop_vat'] ) ) {
				?>
					<div class="shop-vat-number"><label><?php echo $label; ?>:</label>&nbsp;<span><?php $invoice->shop_vat_number(); ?></span></div>
				<?php
			}
		}
	}
	
	/**
	 * Get document type options
	 *
	 * @return array
	 */
	public function get_document_type_options(): array {
		$options = array();
		
		foreach ( wcpdf_get_bulk_actions() as $action => $title ) {
			$options[] = array(
				'value' => $action,
				'title' => $title,
			);
		}
		
		return apply_filters( 'wpo_wcpdf_pro_document_type_options', $options );
	}
	
	/**
	 * Checks if the store is affected by the v2.15.4 bug.
	 *
	 * @return bool
	 */
	public function v2_15_4_bug_affected() {
		$upgrading_from_version = get_option( 'wpo_wcpdf_pro_v2_15_4_bug_upgrading_from_version' );
		
		if ( version_compare( $upgrading_from_version, '2.15.4', '<' ) || version_compare( $upgrading_from_version, '2.15.5', '>' ) ) {
			return false;
		} else {
			return true;
		}
	}
	
	/**
	 * Marks the v2.15.4 bug corrupted data as resolved.
	 *
	 * @return void
	 */
	public function v2_15_4_mark_resolved() {
		if ( wp_verify_nonce( $_REQUEST['nonce'], $_REQUEST['action'] ) ) {
			delete_option( 'wpo_wcpdf_pro_v2_15_4_bug_upgrading_from_version' );
		}
		
		wp_safe_redirect( esc_url( admin_url( 'admin.php?page=wpo_wcpdf_options_page' ) ) );
	}
	
	/**
	 * Gets the v2.15.4 bug portential corrupted data.
	 *
	 * @return array
	 */
	public function v2_15_4_corrupted_data() {
		$args = apply_filters( 'wpo_wcpdf_pro_v2_15_4_corrupted_data_query_args', array(
			'limit'        => -1,
			'return'       => 'ids',
			'type'         => 'shop_order',
			'status'       => array( 'wc-completed', 'wc-processing' ),
			'date_created' => '>' . strtotime( '2024-01-09T00:01:00Z' ), // v2.15.4 release date
		) );
		
		return wc_get_orders( $args );
	}
	
	/**
	 * v2.15.4 corrupted data notice.
	 *
	 * @return void
	 */
	public function v2_15_4_corrupted_data_notice() {
		if ( ! $this->v2_15_4_bug_affected() ) {
			return;
		}
		
		?>
		<div class="notice notice-error">
			<p><strong><?php _e( 'Immediate action required!', 'wpo_wcpdf_pro' ); ?></strong></p>
			<p>
				<?php 
					printf(
						/* translators: version 2.15.4 */
						__( 'In version %1$s of PDF Invoices & Packing Slips for WooCommerce - Professional, a major bug was discovered that allowed orders to be processed without the corresponding payment being made. Although these orders were marked as paid during the checkout process, no actual payment was collected from the payment provider.', 'wpo_wcpdf_pro' ),
						'<code>2.15.4</code>'
					);
				?>
			</p>
			<p>
				<?php
					printf(
						/* translators: 1. open anchor tag, 2. close anchor tag */
						__( 'More information about this issue can be found %1$shere%2$s', 'wpo_wcpdf_pro' ),
						'<a href="https://docs.wpovernight.com/woocommerce-pdf-invoices-packing-slips/payment-gateway-bug/" target="_blank">',
						'</a>'
					);
				?>
			</p>
			<p>
				<?php
					printf(
						/* translators: 1. open anchor tag, 2. close anchor tag */
						__( 'To help you more easily track down possible affected orders you can use %1$sthe following tool%2$s.', 'wpo_wcpdf_pro' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=wpo_wcpdf_options_page&tab=debug&section=tools#corrupted-data' ) ) . '">',
						'</a>'
					);
				?>
			</p>
			<p><?php echo $this->v2_15_4_get_resolve_link_html(); ?></p>
		</div>
		<?php
	}
	
	/**
	 * v2.15.4 corrupted data tool.
	 *
	 * @return void
	 */
	public function v2_15_4_corrupted_data_tool() {
		if ( ! $this->v2_15_4_bug_affected() ) {
			return;
		}
			
		$order_ids = $this->v2_15_4_corrupted_data();
		?>
		<div class="tool" id="corrupted-data">
			<h4><?php _e( 'Payment gateway bug - Possible affected orders', 'wpo_wcpdf_pro' ); ?></h4>
			<p><?php printf(
				/* translators: 1. version 2.15.4, 2. open anchor tag, 3. close anchor tag */
				__( 'We\'ve detected that your site possibly ran version %1$s of our Professional extension at some point in the past. This version contained a bug that prevented payments from being properly processed, which means some of your past orders might be affected by this. You can use this tool to more easily find possible affected orders. This tool displays a list of orders made starting from January 9th and beyond, which do not contain a transaction ID but are marked as paid (order status processing or completed). More information about this issue %2$shere%3$s', 'wpo_wcpdf_pro' ),
				'<code>2.15.4</code>',
				'<a href="https://docs.wpovernight.com/woocommerce-pdf-invoices-packing-slips/payment-gateway-bug/" target="_blank">',
				'</a>'
			); ?></p>
			<table class="widefat">
				<thead>
					<tr>
						<th><?php _e( 'Order', 'wpo_wcpdf_pro' ); ?></th>
						<th><?php _e( 'Status (Processing or Completed)', 'wpo_wcpdf_pro' ); ?></th>
						<th><?php _e( 'Payment method', 'wpo_wcpdf_pro' ); ?></th>
						<th><?php _e( 'Transaction ID', 'wpo_wcpdf_pro' ); ?></th>
						<th><?php _e( 'Marked as paid?', 'wpo_wcpdf_pro' ); ?></th>
						<th><?php _e( 'Payment link', 'wpo_wcpdf_pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
						if ( ! empty( $order_ids ) ) :
							foreach ( $order_ids as $order_id ) :
								$order = wc_get_order( absint( $order_id ) );
								if ( $order ) :
									$payment_method       = is_callable( array( $order, 'get_payment_method' ) ) ? $order->get_payment_method() : '';
									$transaction_id       = is_callable( array( $order, 'get_transaction_id' ) ) ? $order->get_transaction_id() : '';
									$payment_method_title = is_callable( array( $order, 'get_payment_method_title' ) ) ? $order->get_payment_method_title() : '';
									$payment_link         = is_callable( array( $order, 'get_checkout_payment_url' ) ) ? $order->get_checkout_payment_url() : '';
									$order_status         = wc_get_order_status_name( $order->get_status() );
									$order_is_paid        = is_callable( array( $order, 'is_paid' ) ) ? $order->is_paid() : false;
									
									if ( ! empty( $payment_method ) && empty( $transaction_id ) && $order_is_paid ) :
					?>
										<tr>
											<td><?php echo '<a href="' . esc_url( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ) . '" target="_blank">#' . $order_id . '</a>'; ?></td>
											<td><?php echo $order_status; ?></td>
											<td><?php echo $payment_method_title; ?></td>
											<td><?php echo __( 'No', 'wpo_wcpdf_pro' ); ?></td>
											<td><?php echo __( 'Yes', 'wpo_wcpdf_pro' ); ?></td>
											<td><a href="<?php echo esc_url( $payment_link ); ?>" target="_blank"><span class="dashicons dashicons-admin-links"></span></a></td>
										</tr>
									<?php endif; ?>
								<?php endif; ?>
							<?php endforeach; ?>
						<?php else : ?>
							<tr>
								<td colspan="6"><?php echo __( 'No orders that could potentially be affected were identified.', 'wpo_wcpdf_pro' ); ?></td>
							</tr>
						<?php endif; ?>
				</tbody>
				<tfoot>
					<tr>
						<td colspan="6"><?php echo $this->v2_15_4_get_resolve_link_html(); ?></td>
					</tr>
				</tfoot>
			</table>
		</div>
		<?php
	}
	
	public function v2_15_4_get_resolve_link_html() {
		$resolve_action = 'wpo_wcpdf_pro_resolve_v2_15_4_corrupted_data';
		$resolve_link   = add_query_arg( array(
			'action' => $resolve_action,
			'nonce'  => wp_create_nonce( $resolve_action ),
		), admin_url( 'admin-ajax.php' ) );
		
		return '<a href="' . esc_url( $resolve_link ) . '" class="button button-primary">' . __( 'Mark as resolved', 'wpo_wcpdf_pro' ) . '</a>';
	}

} // end class

endif; // end class_exists

return new Functions();
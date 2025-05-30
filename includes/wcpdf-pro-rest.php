<?php

namespace WPO\WC\PDF_Invoices_Pro;

use WPO\WC\PDF_Invoices\Documents\Order_Document_Methods;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\WPO\\WC\\PDF_Invoices_Pro\\Rest' ) ) :

	class Rest {

		/**
		 * @var string
		 */
		protected $namespace = 'wc/v3';

		/**
		 * @var string
		 */
		protected $rest_base = 'orders';

		/**
		 * @var \WC_Abstract_Order
		 */
		protected $order;

		public function __construct() {
			if ( ! isset( WPO_WCPDF_Pro()->settings->settings['enable_rest_api'] ) ) {
				return;
			}

			if ( ! WPO_WCPDF_Pro()->dependencies->is_rest_api_supported() ) {
				unset( WPO_WCPDF_Pro()->settings->settings['enable_rest_api'] );
				update_option( 'wpo_wcpdf_settings_pro', WPO_WCPDF_Pro()->settings->settings );

				return;
			}

			add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
		}

		/**
		 * Registers REST API routes for handling order documents.
		 *
		 *  This function initializes the following REST API routes:
		 *  - Adds a custom 'documents' field to 'shop_order'.
		 *  - Creates or regenerates a document for a specific order.
		 *  - Deletes a document for a specific order.
		 *
		 * @return void
		 */
		public function rest_api_init(): void {
			// Add documents field to order.
			register_rest_field( 'shop_order', 'documents', array(
				'get_callback'    => array( $this, 'order_get_callback' ),
				'update_callback' => null,
				'schema'          => null,
			) );

			// Create/regenerate document.
			register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<order_id>\d+)/documents', array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_document_request' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => rest_get_endpoint_args_for_schema( $this->get_item_schema() ),
				),
				'schema' => array( $this, 'get_item_schema' ),
			) );

			// Download documents.
			register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<order_id>\d+)/documents', array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'download_document' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			) );

			// Delete document.
			register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<order_id>\d+)/documents', array(
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_document' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			) );
		}

		/**
		 * Get callback
		 *
		 * @param array $object
		 * @param string $field_name
		 * @param \WP_REST_Request $request
		 * @param string $object_type
		 *
		 * @return array
		 */
		public function order_get_callback( array $object, string $field_name, \WP_REST_Request $request, string $object_type ): array {
			if ( 'GET' !== $request->get_method() ) {
				return array();
			}

			$order = $this->get_order( absint( $object['id'] ) );

			if ( empty( $order ) ) {
				return array();
			}

			$document_types = WPO_WCPDF_Pro()->functions->get_document_type_options();
			$documents      = array();

			foreach ( $document_types as $document_type ) {
				$type   = $document_type['value'];

				if ( 'credit-note' === $type ) {
					$order_ids = array_map( function ( $refund ) {
						return $refund->get_id();
					}, $order->get_refunds() );
				} else {
					$order_ids = array( $order->get_id() );
				}

				foreach ( $order_ids as $order_id ) {
					$document = wcpdf_get_document( $type, $order_id );

					if ( $document && $document->exists() ) {
						if ( 'credit-note' === $type ) {
							$documents[ $type ][] = $this->output_document_data( $document );
						} else {
							$documents[ $type ] = $this->output_document_data( $document );
						}
					}
				}
			}

			return $documents;
		}

		/**
		 * Handle creation or regeneration of a document.
		 *
		 * @param \WP_REST_Request $request
		 *
		 * @return \WP_REST_Response
		 */
		public function handle_document_request( \WP_REST_Request $request ): \WP_REST_Response {
			$validation_error = $this->validate_request( $request );

			if ( $validation_error ) {
				return $validation_error;
			}

			$order           = $this->get_order( absint( $request['order_id'] ) );
			$document        = wcpdf_get_document( sanitize_key( $request->get_param( 'type' ) ), $order );
			$is_regeneration = wc_string_to_bool( $request->get_param( 'regenerate' ) );

			if ( ! $document ) {
				$error_message = sprintf( 'Document %s failed.', $is_regeneration ? 'regeneration' : 'creation' );
				wcpdf_log_error( $error_message, 'critical' );
				return new \WP_REST_Response( array( 'error' => $error_message ), 422 );
			}

			$document_data = $this->validate_document_data( $request );

			if ( $is_regeneration ) {
				return $this->handle_regeneration( $document, $document_data, $order );
			} else {
				return $this->handle_creation( $document, $document_data, $order );
			}
		}

		/**
		 * Downloads a document for the given order.
		 *
		 * @param \WP_REST_Request $request
		 *
		 * @return void|\WP_REST_Response
		 */
		public function download_document( \WP_REST_Request $request ) {
			$validation_error = $this->validate_request( $request );

			if ( $validation_error ) {
				return $validation_error;
			}

			$order         = $this->get_order( absint( $request['order_id'] ) );
			$document_type = sanitize_key( $request->get_param( 'type' ) );
			$init          = wc_string_to_bool( $request->get_param( 'generate' ) );

			$document = wcpdf_get_document( $document_type, $order, $init );

			if ( ! $document || ! $document->exists() ) {
				return new \WP_REST_Response( array( 'error' => 'Document does not exist.' ), 500 );
			}

			$document->output_pdf();
			exit;
		}

		/**
		 * Deletes a document for the given order.
		 *
		 * @param \WP_REST_Request $request
		 *
		 * @return \WP_REST_Response
		 */
		public function delete_document( \WP_REST_Request $request ): \WP_REST_Response {
			$validation_error = $this->validate_request( $request );

			if ( $validation_error ) {
				return $validation_error;
			}

			$document = wcpdf_get_document( sanitize_key( $request->get_param( 'type' ) ), $this->get_order( absint( $request['order_id'] ) ) );

			if ( $document && $document->exists() ) {
				$document->delete();

				return new \WP_REST_Response( array( 'success' => 'Document deleted.' ), 200 );
			}

			return new \WP_REST_Response( array( 'error' => 'Document not found.' ), 404 );
		}

		/**
		 * Checks if the current user has the necessary permissions to access the API endpoint.
		 *
		 * @param \WP_REST_Request $request
		 *
		 * @return bool
		 */
		public function permissions_check( \WP_REST_Request $request ): bool {
			return apply_filters( 'wpo_wcpdf_pro_api_permission_check', wc_rest_check_manager_permissions( 'settings', 'edit' ), $request );
		}

		/**
		 * Gets the JSON schema for the document item.
		 *
		 * @return array
		 */
		public function get_item_schema(): array {
			return array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'document',
				'type'       => 'object',
				'properties' => array(
					'number' => array(
						'description' => __( 'The number of the document.', 'wpo_wcpdf_pro' ),
						'type'        => 'string',
						'required'    => false,
					),
					'date'   => array(
						'description' => __( 'The issue date of the document.', 'wpo_wcpdf_pro' ),
						'type'        => 'string',
						'required'    => false,
					),
					'note'   => array(
						'description' => __( 'Additional notes for the document.', 'wpo_wcpdf_pro' ),
						'type'        => 'string',
						'required'    => false,
					),
				),
			);
		}

		/**
		 * Validates the incoming WP REST request.
		 *
		 * @param \WP_REST_Request $request
		 *
		 * @return \WP_REST_Response|null
		 */
		private function validate_request( \WP_REST_Request $request ): ?\WP_REST_Response {
			$order = $this->get_order( absint( $request['order_id'] ) );

			if ( empty( $order ) ) {
				return new \WP_REST_Response( array( 'error' => 'Order not found.' ), 404 );
			}

			$document_type = sanitize_key( $request->get_param( 'type' ) );

			if ( empty( $document_type ) ) {
				return new \WP_REST_Response( array( 'error' => 'Document type is required.' ), 400 );
			}

			if ( ! in_array( $document_type, array_column( WPO_WCPDF_Pro()->functions->get_document_type_options(), 'value' ) ) ) {
				return new \WP_REST_Response( array( 'error' => 'Document type is invalid.' ), 404 );
			}

			return null;
		}

		/**
		 * Handles the regeneration of an existing document.
		 *
		 * @param Order_Document_Methods $document
		 * @param array $document_data
		 * @param \WC_Abstract_Order $order
		 *
		 * @return \WP_REST_Response
		 */
		private function handle_regeneration( Order_Document_Methods $document, array $document_data, \WC_Abstract_Order $order ): \WP_REST_Response {
			if ( ! $document->exists() ) {
				return new \WP_REST_Response( array( 'error' => 'Document not found to regenerate.' ), 404 );
			}

			$document_settings = $document->get_settings( true );

			// Check if the document is eligible to regenerate.
			if ( ! $document->use_historical_settings() && ! isset( $document_settings['archive_pdf'] ) ) {
				return new \WP_REST_Response( array( 'error' => 'Document not eligible for regeneration.' ), 400 );
			}

			if ( empty( $document_data['number'] ) ) {
				$document_data['number'] = $document->get_number()->get_plain();
			}

			if ( empty( $document_data['date'] ) ) {
				$document_data['date'] = $document->get_date();
			}

			$document->regenerate( $order, $document_data );
			WPO_WCPDF()->main->log_document_creation_trigger_to_order_meta( $document, 'rest_document_data', true );

			return new \WP_REST_Response( $this->output_document_data( $document ), 201 );
		}

		/**
		 * Handles the creation of a new document.
		 *
		 * @param Order_Document_Methods $document
		 * @param array $document_data
		 * @param \WC_Abstract_Order $order
		 *
		 * @return \WP_REST_Response
		 */
		private function handle_creation( Order_Document_Methods $document, array $document_data, \WC_Abstract_Order $order ): \WP_REST_Response {
			// Do not generate a document if it already exists; regeneration should not be processed here.
			if ( $document->exists() ) {
				return new \WP_REST_Response( $this->output_document_data( $document ), 200 );
			}

			if ( empty( $document_data['date'] ) ) {
				$document_data['date'] = current_time( 'timestamp', true );
			}

			$document->set_data( $document_data, $order );

			// Initiate number if not set.
			if ( $document->get_date() && empty( $document->get_number() ) ) {
				$document->initiate_number();
			}

			$document->save();

			WPO_WCPDF()->main->log_document_creation_to_order_notes( $document, 'rest_document_data' );
			WPO_WCPDF()->main->log_document_creation_trigger_to_order_meta( $document, 'rest_document_data' );
			WPO_WCPDF()->main->mark_document_printed( $document, 'rest_document_data' );

			if ( ! $document->exists() ) {
				return new \WP_REST_Response( array( 'error' => 'Document creation failed.' ), 500 );
			}

			return new \WP_REST_Response( $this->output_document_data( $document ), 201 );
		}

		/**
		 * Outputs the document data in an array format.
		 *
		 * @param Order_Document_Methods $document
		 *
		 * @return array
		 */
		private function output_document_data( Order_Document_Methods $document ): array {
			if ( ! $document->exists() ) {
				return array();
			}

			return array(
				'number'         => $document->exists() && ! empty( $document->get_number() ) ? (int) $document->get_number()->get_plain() : '',
				'date'           => $document->exists() && ! empty( $document->get_date() ) ? $document->get_date()->date_i18n( 'Y-m-d\TH:i:s' ) : '',
				'date_timestamp' => $document->exists() && ! empty( $document->get_date() ) ? $document->get_date()->getTimestamp() : '',
			);
		}

		/**
		 * Validates document data from the incoming WP REST request.
		 *
		 * @param \WP_REST_Request $request
		 *
		 * @return array
		 */
		private function validate_document_data( \WP_REST_Request $request ): array {
			$document_data = array(
				'number' => absint( $request->get_param( 'number' ) ),
				'date'   => sanitize_text_field( $request->get_param( 'date' ) ),
				'note'   => sanitize_textarea_field( $request->get_param( 'note' ) ),
			);

			// Validate number.
			if ( ! empty( $document_data['number'] ) ) {
				$document_data['number'] = sanitize_text_field( $document_data['number'] );
			}

			// Validate date.
			if ( ! empty( $document_data['date'] ) ) {
				$document_data['date'] = \DateTime::createFromFormat( \DateTime::ATOM, $document_data['date'] );
				$document_data['date'] = $document_data['date'] ? $document_data['date']->getTimestamp() : current_time( 'timestamp', true );
			}

			if ( ! empty( $document_data['note'] ) ) {
				// Validate note.
				$allowed_html = array(
					'a'      => array(
						'href'  => array(),
						'title' => array(),
						'id'    => array(),
						'class' => array(),
						'style' => array(),
					),
					'br'     => array(),
					'em'     => array(),
					'strong' => array(),
					'div'    => array(
						'id'    => array(),
						'class' => array(),
						'style' => array(),
					),
					'span'   => array(
						'id'    => array(),
						'class' => array(),
						'style' => array(),
					),
					'p'      => array(
						'id'    => array(),
						'class' => array(),
						'style' => array(),
					),
					'b'      => array(),
				);

				$document_data['notes'] = wp_kses( $document_data['note'], $allowed_html );
			}

			// Return data which are not empty.
			return array_filter( $document_data );
		}

		/**
		 * Retrieves the order object from cache if it exists, otherwise fetches it based on the provided order ID.
		 *
		 * @param int $order_id
		 *
		 * @return \WC_Abstract_Order|false
		 */
		private function get_order( int $order_id ) {
			if ( empty( $this->order ) ) {
				$this->order = wc_get_order( $order_id );
			}

			return $this->order;
		}

	}

endif;

return new Rest();
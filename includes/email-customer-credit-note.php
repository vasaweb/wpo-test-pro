<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'WC_Email_Customer_Credit_Note' ) ) :

/**
 * Customer Credit Note
 *
 * An email sent to the customer via admin.
 *
 * @class 		WC_Email_Customer_Credit_Note
 * @extends 	WC_Email
 */
class WC_Email_Customer_Credit_Note extends WC_Email {

	public $find;
	public $replace;
	public $body;

	/**
	 * Constructor
	 */
	public function __construct() {
		// this class loads earlier, so we need to include the Multilingual_Full class here
		if ( ! class_exists( '\\WPO\\WC\\PDF_Invoices_Pro\\Multilingual_Full' ) ) {
			include_once WPO_WCPDF_Pro()->plugin_path() . '/includes/wcpdf-pro-multilingual-full.php';
		}
		
		$this->id             = 'customer_credit_note';
		$this->title          = __( 'Customer Credit Note', 'wpo_wcpdf_pro' );
		$this->description    = __( 'Customer Credit Note emails can be sent to the user with the PDF Credit Note attached.', 'wpo_wcpdf_pro' );

		$this->template_html  = 'emails/customer-credit-note.php';
		$this->template_plain = 'emails/plain/customer-credit-note.php';
		$this->template_base  = trailingslashit( dirname( __FILE__ ) );

		$this->subject        = __( 'Credit Note for order {order_number} from {order_date}', 'wpo_wcpdf_pro' );
		$this->heading        = __( 'Credit Note for order {order_number}', 'wpo_wcpdf_pro' );
		$this->body           = __( 'A refund has been issued for your order, attached to this email you will find a credit note with the details.', 'wpo_wcpdf_pro' );

		// Trigger according to settings
		$trigger_refunded = $this->get_option( 'trigger' );
		if ( wc_string_to_bool( $trigger_refunded ) ) {
			add_action( 'woocommerce_order_status_refunded', array( $this, 'trigger' ) );
		}

		// Call parent constructor
		parent::__construct();

		$this->subject = $this->get_option( 'subject', $this->subject );
		$this->heading = $this->get_option( 'heading', $this->heading );
		$this->body    = $this->get_option( 'body', $this->body );

		$this->register_email_strings();
	}

	/**
	 * trigger function.
	 *
	 * @return void
	 */
	public function trigger( $order ) {
		if ( ! is_object( $order ) ) {
			$order = wc_get_order( absint( $order ) );
		}
		
		if ( ! $order ) {
			return;
		}

		$this->translate_email_strings( $order );

		$this->object = $order;
		if ( 'shop_order' === $this->object->get_type() ) {
			$this->recipient = $this->object->get_billing_email();
		} elseif ( 'shop_order_refund' === $this->object->get_type() ) {
			$parent_order    = wc_get_order( $this->object->get_parent_id() );
			$this->recipient = $parent_order->get_billing_email();
		} else {
			return;
		}

		$this->find['order-date']      = '{order_date}';
		$this->find['order-number']    = '{order_number}';

		$order_date = $this->object->get_date_created();
		$this->replace['order-date']   = $order_date->date_i18n( wc_date_format() );
		$this->replace['order-number'] = $this->object->get_order_number();

		if ( ! $this->get_recipient() ) {
			return;
		}

		if ( $this->is_automatic() && ! $this->is_enabled() ) {
			return;
		}

		$refunds = $order->get_refunds();
		if ( empty( $refunds ) ) {
			return;
		}

		$result = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		do_action( 'wpo_wcpdf_pro_email_sent', $result, $this->id, $order );
	}

	/**
	 * get_subject function.
	 *
	 * @return string
	 */
	public function get_subject() {
		return apply_filters( 'woocommerce_email_subject_customer_credit_note', $this->format_string( $this->subject ), $this->object );
	}

	/**
	 * get_heading function.
	 *
	 * @return string
	 */
	public function get_heading() {
		return apply_filters( 'woocommerce_email_heading_customer_credit_note', $this->format_string( $this->heading ), $this->object );
	}

	/**
	 * get_body function.
	 *
	 * @return string
	 */
	public function get_body() {
		return apply_filters( 'woocommerce_email_body_customer_credit_note', $this->format_string( $this->body ), $this->object );
	}

	/**
	 * get_content_html function.
	 *
	 * @return string
	 */
	public function get_content_html() {
		ob_start();
		wc_get_template(
			$this->template_html,
			array(
				'order' 		=> $this->object,
				'email_heading'	=> $this->get_heading(),
				'email_body'	=> $this->get_body(),
				'sent_to_admin'	=> false,
				'plain_text'	=> false,
				'email'			=> $this,
			), '',
			$this->template_base
		);
		return ob_get_clean();
	}

	/**
	 * get_content_plain function.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		ob_start();
		wc_get_template(
			$this->template_plain,
			array(
				'order' 		=> $this->object,
				'email_heading' => $this->get_heading(),
				'email_body'    => $this->get_body(),
				'sent_to_admin' => false,
				'plain_text'    => true
			), '',
			$this->template_base
		);
		return ob_get_clean();
	}

	/**
	 * Initialise Settings Form Fields
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'trigger' => array(
				'title'   => __( 'Automatically send', 'wpo_wcpdf_pro' ),
				'type'    => 'checkbox',
				'label'   => __( 'Automatically send email when order status is set to refunded', 'wpo_wcpdf_pro' ),
				'default' => 'no'
			),
			'subject' => array(
				'title'       => __( 'Email subject', 'wpo_wcpdf_pro' ),
				'type'        => 'text',
				'description' => sprintf(
					/* translators: %s default email value */
					__( 'Defaults to %s', 'wpo_wcpdf_pro' ),
					'<code>' . $this->subject . '</code>'
				),
				'placeholder' => '',
				'default'     => ''
			),
			'heading' => array(
				'title'       => __( 'Email heading', 'wpo_wcpdf_pro' ),
				'type'        => 'text',
				'description' => sprintf(
					/* translators: %s default email value */
					__( 'Defaults to %s', 'wpo_wcpdf_pro' ),
					'<code>' . $this->heading . '</code>'
				),
				'placeholder' => '',
				'default'     => ''
			),
			'body' => array(
				'title'       => __( 'Email body text', 'wpo_wcpdf_pro' ),
				'css'         => 'width:100%; height: 75px;',
				'type'        => 'textarea',
				'description' => sprintf(
					/* translators: %s default email value */
					__( 'Defaults to %s', 'wpo_wcpdf_pro' ),
					'<code>' . $this->body . '</code>'
				),
				'placeholder' => '',
				'default'     => $this->body
			),
			'email_type' => array(
				'title'       => __( 'Email type', 'wpo_wcpdf_pro' ),
				'type'        => 'select',
				'description' => __( 'Choose which format of email to send.', 'wpo_wcpdf_pro' ),
				'default'     => 'html',
				'class'       => 'email_type',
				'options'     => array(
					'plain'     => __( 'Plain text', 'wpo_wcpdf_pro' ),
					'html'      => __( 'HTML', 'wpo_wcpdf_pro' ),
					'multipart' => __( 'Multipart', 'wpo_wcpdf_pro' ),
				)
			)
		);
	}

	/**
	 * Register email strings for translation
	 *
	 * @return void
	 */
	public function register_email_strings(): void {
		if (
			! class_exists( '\WPO\WC\PDF_Invoices_Pro\Multilingual_Full' ) ||
			! method_exists( '\WPO\WC\PDF_Invoices_Pro\Multilingual_Full', 'register_string' )
		) {
			return;
		}
		
		$string_slugs = array( 'subject', 'heading', 'body' );
		
		foreach ( $string_slugs as $string_slug ) {
			$args = array(
				'multiline' => ( 'body' === $string_slug ) ? true : false,
			);
			
			\WPO\WC\PDF_Invoices_Pro\Multilingual_Full::register_string( $this->{$string_slug}, $args );
		}
	}

	/**
	 * Translate email strings
	 *
	 * @param \WC_Abstract_Order $order
	 * 
	 * @return void
	 */
	public function translate_email_strings( \WC_Abstract_Order $order ): void {
		if (
			! class_exists( '\WPO\WC\PDF_Invoices_Pro\Multilingual_Full' ) ||
			! method_exists( '\WPO\WC\PDF_Invoices_Pro\Multilingual_Full', 'switch_language' ) ||
			! method_exists( '\WPO\WC\PDF_Invoices_Pro\Multilingual_Full', 'maybe_get_string_translation' )
		) {
			return;
		}
		
		$document_type = 'credit-note';
		$document      = wcpdf_get_document( $document_type, $order );
		
		if ( ! $document ) {
			return;
		}
		
		\WPO\WC\PDF_Invoices_Pro\Multilingual_Full::switch_language( $document_type, $document );
		
		$string_slugs = array( 'subject', 'heading', 'body' );
		
		foreach ( $string_slugs as $string_slug ) {
			$this->{$string_slug} = \WPO\WC\PDF_Invoices_Pro\Multilingual_Full::maybe_get_string_translation( $this->{$string_slug} );
		}
	}

	public function is_enabled() {
		return apply_filters( 'woocommerce_email_enabled_' . $this->id, $this->is_automatic(), $this->object );
	}

	public function is_manual() {
		return ! $this->is_automatic();
	}

	public function is_automatic()	{
		$trigger_refunded = $this->get_option( 'trigger' );
		return wc_string_to_bool( $trigger_refunded );
	}

	public function is_customer_email() {
		return true;
	}

}

endif;

return new WC_Email_Customer_Credit_Note();

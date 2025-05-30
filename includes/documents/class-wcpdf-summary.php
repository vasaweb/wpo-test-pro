<?php
namespace WPO\WC\PDF_Invoices\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( '\\WPO\\WC\\PDF_Invoices\\Documents\\Summary' ) ) :

/**
 * Summary Document
 * 
 * @class  \WPO\WC\PDF_Invoices\Documents\Summary
 */

class Summary {

	public $type;
	public $slug;
	public $title;
	public $wrapper_document;
	public $order_ids;
	public $export_settings;
	public $common_settings;
	public $output_formats;

	public function __construct( $order_ids = array(), $export_settings = array() ) {
		// set properties
		$this->type            = 'summary';
		$this->slug            = 'summary';
		$this->title           = __( 'Summary of Invoices', 'wpo_wcpdf_pro' );
		$this->order_ids       = apply_filters( 'wpo_wcpdf_summary_order_ids', $order_ids );
		$this->export_settings = apply_filters( 'wpo_wcpdf_summary_export_settings', $export_settings );
		$this->common_settings = WPO_WCPDF()->settings->get_common_document_settings();
		
		$this->output_formats  = apply_filters( 'wpo_wcpdf_document_output_formats', array( 'pdf' ), $this );

		// remove, we are not using logo here
		remove_action( 'wpo_wcpdf_custom_styles', array( WPO_WCPDF()->main, 'set_header_logo_height' ), 9, 2 );
	}

	public function get_type() {
		return $this->type;
	}

	public function get_date() {
		return new \WC_DateTime( 'now', new \DateTimeZone( 'UTC' ) );
	}

	public function output_date() {
		echo date_i18n( wcpdf_date_format( $this, 'document_date' ), $this->get_date() );
	}

	public function get_date_title() {
		// override to allow for language switching!
		$title = __( 'Summary date', 'wpo_wcpdf_pro' );
		$title = apply_filters_deprecated( "wpo_wcpdf_{$this->slug}_date_title", array( $title, $this ), '2.15.11', 'wpo_wcpdf_document_date_title' ); // deprecated
		return apply_filters( 'wpo_wcpdf_document_date_title', $title, $this );
	}

	public function get_export_date_type() {
		$date_types = WPO_WCPDF_Pro()->functions->get_export_bulk_date_types();
		if ( ! empty( $date_type = $this->export_settings['date_type'] ) ) {
			return $date_types[$date_type];
		} else {
			return false;
		}
	}

	public function output_export_date_type() {
		echo $this->get_export_date_type();
	}

	public function get_export_date_interval() {
		if ( ! empty( $this->export_settings['date_after'] ) && ! empty( $this->export_settings['date_before'] ) ) {
			$date_after  = date_i18n( wcpdf_date_format( $this, $this->get_export_date_type() ), $this->export_settings['date_after'] );
			$date_before = date_i18n( wcpdf_date_format( $this, $this->get_export_date_type() ), $this->export_settings['date_before'] );
			$to          = __( 'to', 'wpo_wcpdf_pro' );
			return "{$date_after} {$to} {$date_before}";
		} else {
			return false;
		}
	}

	public function output_export_date_interval() {
		echo $this->get_export_date_interval();
	}

	public function get_title() {
		return $this->title;
	}

	public function exists() {
        return true;
    }

	/**
	 * Check if the document is enabled.
	 *
	 * @param string $output_format
	 *
	 * @return mixed
	 */
	public function is_enabled( string $output_format = 'pdf' ): bool {
		return apply_filters( 'wpo_wcpdf_document_is_enabled', true, $this->type, $output_format );
	}

	public function get_pdf() {
		do_action( 'wpo_wcpdf_before_pdf', $this->get_type(), $this );

		$html         = $this->get_html();
		$pdf_settings = array(
			'paper_size'		=> ! empty( $this->common_settings['paper_size'] ) ? $this->common_settings['paper_size'] : 'A4',
			'paper_orientation'	=> 'portrait',
			'font_subsetting'	=> ! empty( $this->common_settings['font_subsetting'] ) ? $this->common_settings['font_subsetting'] : false,
		);
		$pdf_maker    = wcpdf_get_pdf_maker( $html, $pdf_settings );
		$pdf          = apply_filters( 'wpo_wcpdf_pdf_data', $pdf_maker->output(), $this );
		
		do_action( 'wpo_wcpdf_after_pdf', $this->get_type(), $this );

		return $pdf;
	}

	public function get_html() {
		do_action( 'wpo_wcpdf_before_html', $this->get_type(), $this );

		$html = $this->render_template( $this->locate_template_file( "{$this->get_type()}.php" ) );
		$html = $this->wrap_html_content( $html );
		
		if ( apply_filters( 'wpo_wcpdf_convert_encoding', function_exists( 'htmlspecialchars_decode' ) && function_exists( 'wcpdf_convert_encoding' ) ) ) {
			$html = htmlspecialchars_decode( wcpdf_convert_encoding( $html ), ENT_QUOTES );
		} elseif ( apply_filters( 'wpo_wcpdf_convert_encoding', function_exists( 'utf8_decode' ) && function_exists( 'mb_convert_encoding' ) ) ) {
			$html = utf8_decode( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ) );
		}

		do_action( 'wpo_wcpdf_after_html', $this->get_type(), $this );
		
		return $html;
	}

	public function output_html() {
		echo $this->get_html();
		die();
	}

	public function wrap_html_content( $content ) {
		$html = $this->render_template( $this->locate_template_file( "html-document-wrapper.php" ), array(
				'content' => apply_filters( 'wpo_wcpdf_html_content', $content ),
			)
		);
		return $html;
	}

	public function get_filename( $context = 'download', $args = array() ) {
		$name        = __( 'summary', 'wpo_wcpdf_pro' );
		$suffix      = date( 'Y-m-d' ); // 2020-11-11
		$filename    = $name . '-' . $suffix . '.pdf';

		// Filter filename
		$order_ids = isset( $args['order_ids'] ) ? $args['order_ids'] : $this->order_ids;
		$filename  = apply_filters( 'wpo_wcpdf_filename', $filename, $this->get_type(), $order_ids, $context, $args );

		// sanitize filename (after filters to prevent human errors)!
		return sanitize_file_name( $filename );
	}

	public function render_template( $file, $args = array() ) {
		do_action( 'wpo_wcpdf_process_template', $this->get_type(), $this );

		if ( ! empty( $args ) && is_array( $args ) ) {
			extract( $args );
		}

		ob_start();
		if ( file_exists( $file ) ) {
			include( $file );
		}
		return ob_get_clean();
	}

	public function locate_template_file( $file ) {
		if ( empty( $file ) ) {
			$file = $this->get_type().'.php';
		}
		$file_path = WPO_WCPDF_Pro()->plugin_path() . '/templates/Summary/' . $file;
		$file_path = apply_filters( 'wpo_wcpdf_template_file', $file_path, $this->get_type(), $this->order_ids );

		return $file_path;
	}

	public function template_styles() {
		$css = apply_filters( 'wpo_wcpdf_template_styles_file', $this->locate_template_file( "style.css" ) );

		ob_start();
		if ( file_exists( $css ) ) {
			include( $css );
		}
		$css = ob_get_clean();
		$css = apply_filters( 'wpo_wcpdf_template_styles', $css, $this );
		
		echo $css;
	}

}

endif; // class_exists
<?php
namespace WPO\WC\PDF_Invoices_Pro\Cloud;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( '\\WPO\\WC\\PDF_Invoices_Pro\\Cloud\\Cloud_Services_Enabled' ) ) :

/**
 * Coud Services Enabled Class
 * 
 * @class  \WPO\WC\PDF_Invoices_Pro\Cloud\Cloud_Services_Enabled
 */

class Cloud_Services_Enabled extends Cloud_API {

	public static $services_enabled = array();

	/**
	 * Construct
	 * 
	 * @return	void
	 */
	public function __construct()
	{
		foreach( self::available_cloud_services() as $cloud_service ) {
			if( $cloud_service['active'] ) {
				self::$services_enabled[] = $cloud_service['slug'];
			}
		}
	}

}

endif; // class_exists

if( class_exists( '\\WPO\\WC\\PDF_Invoices_Pro\\Cloud\\Cloud_API' ) ) {
	return new Cloud_Services_Enabled();
}
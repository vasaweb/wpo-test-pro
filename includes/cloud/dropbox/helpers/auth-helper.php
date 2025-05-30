<?php

namespace WPO\WC\PDF_Invoices_Pro\Cloud\Dropbox\Helpers;

use WPO\WC\PDF_Invoices_Pro\Vendor\League\OAuth2\Client\Provider\AbstractProvider;
use WPO\WC\PDF_Invoices_Pro\Vendor\Stevenmaguire\OAuth2\Client\Provider\Dropbox;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( '\\WPO\\WC\\PDF_Invoices_Pro\\Cloud\\Dropbox\\Helpers\\Auth_Helper' ) ) :

class Auth_Helper extends Dropbox
{
	protected function getPkceMethod() {
		return AbstractProvider::PKCE_METHOD_S256;
	}

	public function getAuthorizationUrl( $options = array() ) {
		$options['token_access_type'] = 'offline';
		return parent::getAuthorizationUrl( $options );
	}

}

endif; // class_exists

<?php

namespace WPO\WC\PDF_Invoices_Pro\Cloud\Dropbox\Helpers;

use WPO\WC\PDF_Invoices_Pro\Vendor\Spatie\Dropbox\TokenProvider;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\OAuth2\Client\Token\AccessToken;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( '\\WPO\\WC\\PDF_Invoices_Pro\\Cloud\\Dropbox\\Helpers\\Token_Helper' ) ) :

class Token_Helper implements TokenProvider {

	/**
	 * Token object when initiated on createToken() or refreshToken().
	 *
	 * @var null|AccessToken
	 */
	private $token = null;

	/**
	 * @var array
	 */
	private $settings_option;

	/**
	 * @var Auth_Helper

	 */
	private $auth;

	/**
	 * @var array
	 */
	private $settings;


	public function __construct( $auth, $settings_option )
	{
		$this->auth            = $auth;
		$this->settings_option = $settings_option;
	}

	public function getToken(): string
	{
		if ( ! $this->token ) {
			$this->restoreToken();
		}
		// if we still don't have a token, try legacy long-lived access token
		if ( ! $this->token && ! empty( $this->settings['access_token'] ) ) {
			return $this->settings['access_token'];
		}

		if ( $this->token->getExpires() + 300 <= time() ) {
			$this->refreshToken();
		}

		return $this->token->getToken();
	}

	public function createToken( $auth_code )
	{
		$pkce_code = $this->getPkceCodeFromTransient();

		$this->auth->setPkceCode( $pkce_code );

		$this->token = $this->auth->getAccessToken(
			'authorization_code',
			[
			'code' => $auth_code,
			]
		);

		$this->saveToken();

		return $this->getToken();
	}

	public function refreshToken()
	{
		if( ! $this->token->getRefreshToken() ) {
			$settings      = $this->getServiceLastSettings();
			$refresh_token = $settings['refresh_token'];
		} else {
			$refresh_token = $this->token->getRefreshToken();
		}

		$this->token = $this->auth->getAccessToken(
			'refresh_token',
			[
			'refresh_token' => $refresh_token,
			]
		);

		$this->saveToken();
	}

	public function saveToken()
	{
		$this->settings                  = $this->getServiceLastSettings();
		$this->settings['token_options'] = $this->getOptionsFromToken( $this->token );
		$this->settings['access_token']  = $this->token->getToken();

		// 'refresh_token' request won't return a new refresh token since refresh tokens don't expire automatically and can be reused repeatedly.
		// so we save the refresh token in a new key to get new access tokens when they expire
		if( ( $refresh_token = $this->token->getRefreshToken() ) ) {
			$this->settings['refresh_token'] = $refresh_token;
		}

		update_option( $this->settings_option, $this->settings );
	}

	public function getOptionsFromToken( $token )
	{
		$options = [
			'access_token'      => $token->getToken(),
			'refresh_token'     => $token->getRefreshToken(),
			'expires'           => $token->getExpires(),
			'resource_owner_id' => $token->getResourceOwnerId(),
		] + $token->getValues();
		return $options;
	}

	public function getTokenFromOptions( $options )
	{
		return new AccessToken( $options );
	}

	public function restoreToken()
	{
		$this->settings = $this->getServiceLastSettings();
		if ( !empty( $this->settings['token_options'] ) ) {
			$this->token = $this->getTokenFromOptions( $this->settings['token_options']);
		}
	}

	public function getServiceLastSettings()
	{
		return get_option( $this->settings_option, array() );
	}

	public function getPkceCodeFromTransient()
	{
		$pkce_code = get_transient( 'wpo_wcpdf_dropbox_pkce_code' );

		if ( empty( $pkce_code ) ) {
			throw new \Exception( 'Empty PKCE code value.' );
		}

		delete_transient( 'wpo_wcpdf_dropbox_pkce_code' );

		return $pkce_code;
	}

}

endif; // class_exists
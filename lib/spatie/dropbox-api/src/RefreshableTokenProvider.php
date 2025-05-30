<?php
/**
 * @license MIT
 *
 * Modified using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace WPO\WC\PDF_Invoices_Pro\Vendor\Spatie\Dropbox;

use WPO\WC\PDF_Invoices_Pro\Vendor\GuzzleHttp\Exception\ClientException;

interface RefreshableTokenProvider extends TokenProvider
{
    /**
     * @return bool Whether the token was refreshed.
     */
    public function refresh(ClientException $exception): bool;
}

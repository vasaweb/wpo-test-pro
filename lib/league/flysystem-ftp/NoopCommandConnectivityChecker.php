<?php
/**
 * @license MIT
 *
 * Modified using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\Ftp;

use TypeError;
use ValueError;

class NoopCommandConnectivityChecker implements ConnectivityChecker
{
    public function isConnected($connection): bool
    {
        // @codeCoverageIgnoreStart
        try {
            $response = @ftp_raw($connection, 'NOOP');
        } catch (TypeError | ValueError $typeError) {
            return false;
        }
        // @codeCoverageIgnoreEnd

        $responseCode = $response ? (int) preg_replace('/\D/', '', implode('', $response)) : false;

        return $responseCode === 200;
    }
}

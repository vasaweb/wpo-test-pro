<?php
/**
 * @license MIT
 *
 * Modified using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\Ftp;

use RuntimeException;

final class UnableToConnectToFtpHost extends RuntimeException implements FtpConnectionException
{
    public static function forHost(string $host, int $port, bool $ssl): UnableToConnectToFtpHost
    {
        $usingSsl = $ssl ? ', using ssl' : '';

        return new UnableToConnectToFtpHost("Unable to connect to host $host at port $port$usingSsl.");
    }
}

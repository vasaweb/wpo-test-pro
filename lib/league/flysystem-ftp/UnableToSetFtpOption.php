<?php
/**
 * @license MIT
 *
 * Modified using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\Ftp;

use RuntimeException;

class UnableToSetFtpOption extends RuntimeException implements FtpConnectionException
{
    public static function whileSettingOption(string $option): UnableToSetFtpOption
    {
        return new UnableToSetFtpOption("Unable to set FTP option $option.");
    }
}

<?php
/**
 * @license MIT
 *
 * Modified using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\Ftp;

use RuntimeException;

final class UnableToEnableUtf8Mode extends RuntimeException implements FtpConnectionException
{
}

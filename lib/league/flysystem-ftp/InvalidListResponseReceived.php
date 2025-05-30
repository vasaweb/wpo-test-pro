<?php
/**
 * @license MIT
 *
 * Modified using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\Ftp;

use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\FilesystemException;
use RuntimeException;

class InvalidListResponseReceived extends RuntimeException implements FilesystemException
{
}

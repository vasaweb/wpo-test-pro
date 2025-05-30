<?php
/**
 * @license MIT
 *
 * Modified using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem;

use RuntimeException;
use Throwable;

class UnableToCheckFileExistence extends RuntimeException implements FilesystemOperationFailed
{
    public static function forLocation(string $path, Throwable $exception = null): UnableToCheckFileExistence
    {
        return new UnableToCheckFileExistence("Unable to check file existence for: ${path}", 0, $exception);
    }

    public function operation(): string
    {
        return FilesystemOperationFailed::OPERATION_FILE_EXISTS;
    }
}

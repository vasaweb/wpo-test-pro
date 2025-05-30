<?php
/**
 * @license MIT
 *
 * Modified using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\PhpseclibV3;

use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\FilesystemException;
use RuntimeException;

class UnableToEstablishAuthenticityOfHost extends RuntimeException implements FilesystemException
{
    public static function becauseTheAuthenticityCantBeEstablished(string $host): UnableToEstablishAuthenticityOfHost
    {
        return new UnableToEstablishAuthenticityOfHost("The authenticity of host $host can't be established.");
    }
}

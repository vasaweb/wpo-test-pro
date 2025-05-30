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

class UnableToLoadPrivateKey extends RuntimeException implements FilesystemException
{
    public function __construct(string $message = "Unable to load private key.")
    {
        parent::__construct($message);
    }
}

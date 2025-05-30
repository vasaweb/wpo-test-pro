<?php
/**
 * @license MIT
 *
 * Modified using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem;

use RuntimeException;

class PathTraversalDetected extends RuntimeException implements FilesystemException
{
    /**
     * @var string
     */
    private $path;

    public function path(): string
    {
        return $this->path;
    }

    public static function forPath(string $path): PathTraversalDetected
    {
        $e = new PathTraversalDetected("Path traversal detected: {$path}");
        $e->path = $path;

        return $e;
    }
}

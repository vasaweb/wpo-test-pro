<?php
/**
 * @license MIT
 *
 * Modified using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem;

use LogicException;

class UnableToMountFilesystem extends LogicException implements FilesystemException
{
    /**
     * @param mixed $key
     */
    public static function becauseTheKeyIsNotValid($key): UnableToMountFilesystem
    {
        return new UnableToMountFilesystem(
            'Unable to mount filesystem, key was invalid. String expected, received: ' . gettype($key)
        );
    }

    /**
     * @param mixed $filesystem
     */
    public static function becauseTheFilesystemWasNotValid($filesystem): UnableToMountFilesystem
    {
        $received = is_object($filesystem) ? get_class($filesystem) : gettype($filesystem);

        return new UnableToMountFilesystem(
            'Unable to mount filesystem, filesystem was invalid. Instance of ' . FilesystemOperator::class . ' expected, received: ' . $received
        );
    }
}

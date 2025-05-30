<?php
/**
 * @license MIT
 *
 * Modified using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\PhpseclibV3;

use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\Config;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\DirectoryAttributes;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\FileAttributes;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\FilesystemAdapter;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\FilesystemException;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\PathPrefixer;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\StorageAttributes;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\UnableToCheckDirectoryExistence;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\UnableToCheckFileExistence;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\UnableToCopyFile;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\UnableToCreateDirectory;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\UnableToMoveFile;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\UnableToReadFile;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\UnableToRetrieveMetadata;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\UnableToSetVisibility;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\UnableToWriteFile;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\Flysystem\UnixVisibility\VisibilityConverter;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\MimeTypeDetection\FinfoMimeTypeDetector;
use WPO\WC\PDF_Invoices_Pro\Vendor\League\MimeTypeDetection\MimeTypeDetector;
use WPO\WC\PDF_Invoices_Pro\Vendor\phpseclib3\Net\SFTP;
use Throwable;

use function rtrim;

class SftpAdapter implements FilesystemAdapter
{
    /**
     * @var ConnectionProvider
     */
    private $connectionProvider;

    /**
     * @var VisibilityConverter
     */
    private $visibilityConverter;

    /**
     * @var PathPrefixer
     */
    private $prefixer;

    /**
     * @var MimeTypeDetector
     */
    private $mimeTypeDetector;

    public function __construct(
        ConnectionProvider $connectionProvider,
        string $root,
        VisibilityConverter $visibilityConverter = null,
        MimeTypeDetector $mimeTypeDetector = null
    ) {
        $this->connectionProvider = $connectionProvider;
        $this->prefixer = new PathPrefixer($root);
        $this->visibilityConverter = $visibilityConverter ?: new PortableVisibilityConverter();
        $this->mimeTypeDetector = $mimeTypeDetector ?: new FinfoMimeTypeDetector();
    }

    public function fileExists(string $path): bool
    {
        $location = $this->prefixer->prefixPath($path);

        try {
            return $this->connectionProvider->provideConnection()->is_file($location);
        } catch (Throwable $exception) {
            throw UnableToCheckFileExistence::forLocation($path, $exception);
        }
    }

    public function directoryExists(string $path): bool
    {
        $location = $this->prefixer->prefixDirectoryPath($path);

        try {
            return $this->connectionProvider->provideConnection()->is_dir($location);
        } catch (Throwable $exception) {
            throw UnableToCheckDirectoryExistence::forLocation($path, $exception);
        }
    }

    /**
     * @param string          $path
     * @param string|resource $contents
     * @param Config          $config
     *
     * @throws FilesystemException
     */
    private function upload(string $path, $contents, Config $config): void
    {
        $this->ensureParentDirectoryExists($path, $config);
        $connection = $this->connectionProvider->provideConnection();
        $location = $this->prefixer->prefixPath($path);

        if ( ! $connection->put($location, $contents, SFTP::SOURCE_STRING)) {
            throw UnableToWriteFile::atLocation($path, 'not able to write the file');
        }

        if ($visibility = $config->get(Config::OPTION_VISIBILITY)) {
            $this->setVisibility($path, $visibility);
        }
    }

    private function ensureParentDirectoryExists(string $path, Config $config): void
    {
        $parentDirectory = dirname($path);

        if ($parentDirectory === '' || $parentDirectory === '.') {
            return;
        }

        /** @var string $visibility */
        $visibility = $config->get(Config::OPTION_DIRECTORY_VISIBILITY);
        $this->makeDirectory($parentDirectory, $visibility);
    }

    private function makeDirectory(string $directory, ?string $visibility): void
    {
        $location = $this->prefixer->prefixPath($directory);
        $connection = $this->connectionProvider->provideConnection();

        if ($connection->is_dir($location)) {
            return;
        }

        $mode = $visibility ? $this->visibilityConverter->forDirectory(
            $visibility
        ) : $this->visibilityConverter->defaultForDirectories();

        if ( ! $connection->mkdir($location, $mode, true)) {
            throw UnableToCreateDirectory::atLocation($directory);
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $this->upload($path, $contents, $config);
        } catch (UnableToWriteFile $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        try {
            $this->upload($path, $contents, $config);
        } catch (UnableToWriteFile $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function read(string $path): string
    {
        $location = $this->prefixer->prefixPath($path);
        $connection = $this->connectionProvider->provideConnection();
        $contents = $connection->get($location);

        if ( ! is_string($contents)) {
            throw UnableToReadFile::fromLocation($path);
        }

        return $contents;
    }

    public function readStream(string $path)
    {
        $location = $this->prefixer->prefixPath($path);
        $connection = $this->connectionProvider->provideConnection();
        /** @var resource $readStream */
        $readStream = fopen('php://temp', 'w+');

        if ( ! $connection->get($location, $readStream)) {
            fclose($readStream);
            throw UnableToReadFile::fromLocation($path);
        }

        rewind($readStream);

        return $readStream;
    }

    public function delete(string $path): void
    {
        $location = $this->prefixer->prefixPath($path);
        $connection = $this->connectionProvider->provideConnection();
        $connection->delete($location);
    }

    public function deleteDirectory(string $path): void
    {
        $location = rtrim($this->prefixer->prefixPath($path), '/') . '/';
        $connection = $this->connectionProvider->provideConnection();
        $connection->delete($location);
        $connection->rmdir($location);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->makeDirectory($path, $config->get(Config::OPTION_DIRECTORY_VISIBILITY, $config->get(Config::OPTION_VISIBILITY)));
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $location = $this->prefixer->prefixPath($path);
        $connection = $this->connectionProvider->provideConnection();
        $mode = $this->visibilityConverter->forFile($visibility);

        if ( ! $connection->chmod($mode, $location, false)) {
            throw UnableToSetVisibility::atLocation($path);
        }
    }

    private function fetchFileMetadata(string $path, string $type): FileAttributes
    {
        $location = $this->prefixer->prefixPath($path);
        $connection = $this->connectionProvider->provideConnection();
        $stat = $connection->stat($location);

        if ( ! is_array($stat)) {
            throw UnableToRetrieveMetadata::create($path, $type);
        }

        $attributes = $this->convertListingToAttributes($path, $stat);

        if ( ! $attributes instanceof FileAttributes) {
            throw UnableToRetrieveMetadata::create($path, $type, 'path is not a file');
        }

        return $attributes;
    }

    public function mimeType(string $path): FileAttributes
    {
        try {
            $contents = $this->read($path);
            $mimetype = $this->mimeTypeDetector->detectMimeType($path, $contents);
        } catch (Throwable $exception) {
            throw UnableToRetrieveMetadata::mimeType($path, $exception->getMessage(), $exception);
        }

        if ($mimetype === null) {
            throw UnableToRetrieveMetadata::mimeType($path, 'Unknown.');
        }

        return new FileAttributes($path, null, null, null, $mimetype);
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->fetchFileMetadata($path, FileAttributes::ATTRIBUTE_LAST_MODIFIED);
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->fetchFileMetadata($path, FileAttributes::ATTRIBUTE_FILE_SIZE);
    }

    public function visibility(string $path): FileAttributes
    {
        return $this->fetchFileMetadata($path, FileAttributes::ATTRIBUTE_VISIBILITY);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $connection = $this->connectionProvider->provideConnection();
        $location = $this->prefixer->prefixPath(rtrim($path, '/')) . '/';
        $listing = $connection->rawlist($location, false);

        if (false === $listing) {
            return;
        }

        foreach ($listing as $filename => $attributes) {
            if ($filename === '.' || $filename === '..') {
                continue;
            }

            // Ensure numeric keys are strings.
            $filename = (string) $filename;
            $path = $this->prefixer->stripPrefix($location . ltrim($filename, '/'));
            $attributes = $this->convertListingToAttributes($path, $attributes);
            yield $attributes;

            if ($deep && $attributes->isDir()) {
                foreach ($this->listContents($attributes->path(), true) as $child) {
                    yield $child;
                }
            }
        }
    }

    private function convertListingToAttributes(string $path, array $attributes): StorageAttributes
    {
        $permissions = $attributes['mode'] & 0777;
        $lastModified = $attributes['mtime'] ?? null;

        if ($attributes['type'] === NET_SFTP_TYPE_DIRECTORY) {
            return new DirectoryAttributes(
                ltrim($path, '/'),
                $this->visibilityConverter->inverseForDirectory($permissions),
                $lastModified
            );
        }

        return new FileAttributes(
            $path,
            $attributes['size'],
            $this->visibilityConverter->inverseForFile($permissions),
            $lastModified
        );
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $sourceLocation = $this->prefixer->prefixPath($source);
        $destinationLocation = $this->prefixer->prefixPath($destination);
        $connection = $this->connectionProvider->provideConnection();

        try {
            $this->ensureParentDirectoryExists($destination, $config);
        } catch (Throwable $exception) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $exception);
        }

        if ( ! $connection->rename($sourceLocation, $destinationLocation)) {
            throw UnableToMoveFile::fromLocationTo($source, $destination);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $readStream = $this->readStream($source);
            $visibility = $this->visibility($source)->visibility();
            $this->writeStream($destination, $readStream, new Config(compact('visibility')));
        } catch (Throwable $exception) {
            if (isset($readStream) && is_resource($readStream)) {
                @fclose($readStream);
            }
            throw UnableToCopyFile::fromLocationTo($source, $destination, $exception);
        }
    }
}

<?php
declare(strict_types=1);

namespace Fatkulnurk\Microframework\Http\Message;

use InvalidArgumentException;
use RuntimeException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use function fopen;
use function is_int;
use function is_resource;
use function is_string;
use function move_uploaded_file;
use function rename;
use function sprintf;
use const PHP_SAPI;
use const UPLOAD_ERR_OK;

/**
 * @author Michael Dowling and contributors to guzzlehttp/psr7
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 * @author Martijn van der Ven <martijn@vanderven.se>
 * @author Fatkul Nur K <fatkulnurk@gmail.com>
 */
final class UploadedFile implements UploadedFileInterface
{
    /** @var array */
    private const ERRORS = [
        UPLOAD_ERR_OK => 1,
        \UPLOAD_ERR_INI_SIZE => 1,
        \UPLOAD_ERR_FORM_SIZE => 1,
        \UPLOAD_ERR_PARTIAL => 1,
        \UPLOAD_ERR_NO_FILE => 1,
        \UPLOAD_ERR_NO_TMP_DIR => 1,
        \UPLOAD_ERR_CANT_WRITE => 1,
        \UPLOAD_ERR_EXTENSION => 1,
    ];

    /** @var string */
    private $clientFilename;

    /** @var string */
    private $clientMediaType;

    /** @var int */
    private $error;

    /** @var string|null */
    private $file;

    /** @var bool */
    private $moved = false;

    /** @var int */
    private $size;

    /** @var StreamInterface|null */
    private $stream;

    /**
     * @param StreamInterface|string|resource $streamOrFile
     * @param int $size
     * @param int $errorStatus
     * @param string|null $clientFilename
     * @param string|null $clientMediaType
     */
    public function __construct($streamOrFile, $size, $errorStatus, $clientFilename = null, $clientMediaType = null)
    {
        if (false === is_int($errorStatus) || !isset(self::ERRORS[$errorStatus])) {
            throw new InvalidArgumentException(
                'Upload file error status must be an integer value and one of the "UPLOAD_ERR_*" constants.'
            );
        }

        if (false === is_int($size)) {
            throw new InvalidArgumentException('Upload file size must be an integer');
        }

        if (null !== $clientFilename && !is_string($clientFilename)) {
            throw new InvalidArgumentException('Upload file client filename must be a string or null');
        }

        if (null !== $clientMediaType && !is_string($clientMediaType)) {
            throw new InvalidArgumentException('Upload file client media type must be a string or null');
        }

        $this->error = $errorStatus;
        $this->size = $size;
        $this->clientFilename = $clientFilename;
        $this->clientMediaType = $clientMediaType;

        if (UPLOAD_ERR_OK === $this->error) {
            // Depending on the value set file or stream variable.
            if (is_string($streamOrFile)) {
                $this->file = $streamOrFile;
            } elseif (is_resource($streamOrFile)) {
                $this->stream = Stream::create($streamOrFile);
            } elseif ($streamOrFile instanceof StreamInterface) {
                $this->stream = $streamOrFile;
            } else {
                throw new InvalidArgumentException('Invalid stream or file provided for UploadedFile');
            }
        }
    }

    /**
     * @throws RuntimeException if is moved or not ok
     */
    private function validateActive(): void
    {
        if (UPLOAD_ERR_OK !== $this->error) {
            throw new RuntimeException('Cannot retrieve stream due to upload error');
        }

        if ($this->moved) {
            throw new RuntimeException('Cannot retrieve stream after it has already been moved');
        }
    }

    public function getStream(): StreamInterface
    {
        $this->validateActive();

        if ($this->stream instanceof StreamInterface) {
            return $this->stream;
        }

        $resource = fopen($this->file, 'r');

        return Stream::create($resource);
    }

    public function moveTo($targetPath): void
    {
        $this->validateActive();

        if (!is_string($targetPath) || '' === $targetPath) {
            throw new InvalidArgumentException('Invalid path provided for move operation; must be a non-empty string');
        }

        if (null !== $this->file) {
            $this->moved = 'cli' === PHP_SAPI ? rename($this->file, $targetPath) :
                move_uploaded_file($this->file, $targetPath);
        } else {
            $stream = $this->getStream();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            // Copy the contents of a stream into another stream until end-of-file.
            $dest = Stream::create(fopen($targetPath, 'w'));
            while (!$stream->eof()) {
                if (!$dest->write($stream->read(1048576))) {
                    break;
                }
            }

            $this->moved = true;
        }

        if (false === $this->moved) {
            throw new RuntimeException(sprintf('Uploaded file could not be moved to %s', $targetPath));
        }
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }
}

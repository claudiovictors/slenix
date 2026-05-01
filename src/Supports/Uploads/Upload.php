<?php

/*
|--------------------------------------------------------------------------
| Upload Class
|--------------------------------------------------------------------------
|
| Manages file uploads in a robust manner, providing methods to access
| file information and move it to a destination directory, with advanced
| validations, error handling, and security features.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Uploads;

use RuntimeException;

class Upload
{
    // File data
    protected array $file = [];
    protected string $originalName;
    protected string $tempPath;
    protected int $size;
    protected int $error;
    protected ?string $clientMimeType;

    // Validation settings (optional)
    protected int $maxSize = 10485760; // 10MB by default
    protected array $allowedMimeTypes = [];
    protected array $allowedExtensions = [];
    protected array $forbiddenExtensions = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8',
        'exe', 'bat', 'cmd', 'scr', 'com', 'pif', 'vbs', 'js',
        'jar', 'sh', 'py', 'pl', 'rb', 'asp', 'aspx', 'jsp',
    ];

    // Internal cache
    private ?string $realMimeType = null;
    private ?string $extension = null;
    private ?array $imageInfo = null;
    private ?bool $isValid = null;

    // Validation errors
    protected array $validationErrors = [];

    // Security settings
    protected bool $strictMimeValidation = true;
    protected bool $allowExecutableFiles = false;
    protected int $maxFilenameLength = 255;
    protected array $dangerousSignatures = [
        'executable' => ["\x4D\x5A"], // PE/COFF executable
        'php'        => ['<?php', '<?=', '<script'],
        'html'       => ['<script', '<iframe', '<object', '<embed'],
    ];

    /**
     * Upload class constructor.
     *
     * @param array $file    File data from $_FILES
     * @param array $options Optional configuration options
     */
    public function __construct(array $file, array $options = [])
    {
        $this->initializeFileData($file);
        $this->applyOptions($options);
        // Basic security checks in the constructor for minimum robustness
        $this->performBasicSecurityChecks();
    }

    /**
     * Validates the file against all configured rules.
     * Must be called explicitly by the developer if validation is desired.
     *
     * @param  bool $throwOnError Whether to throw an exception on failure (default: true)
     * @return bool
     * @throws RuntimeException If validation fails and $throwOnError is true
     */
    public function validate(bool $throwOnError = true): bool
    {
        if ($this->isValid !== null) {
            return $this->isValid;
        }

        $this->validationErrors = []; // Clear previous errors

        $validations = [
            [$this, 'validateSize'],
            [$this, 'validateMimeType'],
            [$this, 'validateExtension'],
            [$this, 'validateFilename'],
            [$this, 'validateFileContent'],
            [$this, 'performSecurityChecks'],
        ];

        foreach ($validations as $validation) {
            try {
                call_user_func($validation);
            } catch (RuntimeException $e) {
                $this->validationErrors[] = $e->getMessage();
                if ($throwOnError) {
                    $this->isValid = false;
                    throw $e;
                }
            }
        }

        $this->isValid = empty($this->validationErrors);
        return $this->isValid;
    }

    /**
     * Checks whether the file is valid without throwing exceptions.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->validate(false);
    }

    /**
     * Returns the validation errors from the last attempt.
     *
     * @return array<string>
     */
    public function getErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Clears accumulated validation errors and resets the validation state.
     *
     * @return self
     */
    public function clearErrors(): self
    {
        $this->validationErrors = [];
        $this->isValid = null; // Reset for a new validation
        return $this;
    }

    /**
     * Moves the file to the specified destination.
     * It is recommended to call validate() first for robustness.
     *
     * @param  string      $directory            Destination directory
     * @param  string|null $filename             Target filename (optional)
     * @param  bool        $preserveOriginalName Whether to keep the original filename
     * @return string Full path of the moved file
     * @throws RuntimeException If the move fails
     */
    public function move(string $directory, ?string $filename = null, bool $preserveOriginalName = false): string
    {
        $this->validate(); // Mandatory validation for move() security

        if (!$this->createDirectoryIfNotExists($directory)) {
            throw new RuntimeException("Could not create directory: {$directory}");
        }

        $finalFilename = $this->resolveFilename($filename, $preserveOriginalName);
        $destination   = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $finalFilename;

        // If destination file already exists, generate a unique name
        if (file_exists($destination)) {
            $destination = $this->generateUniqueFilename($directory, $finalFilename);
        }

        if (!move_uploaded_file($this->tempPath, $destination)) {
            $error = error_get_last();
            throw new RuntimeException(
                'Failed to move uploaded file: ' . ($error['message'] ?? 'Unknown error')
            );
        }

        // Set safe file permissions
        chmod($destination, 0644);

        return $destination;
    }

    /**
     * Saves the file with an automatically generated unique name.
     * It is recommended to call validate() first for robustness.
     *
     * @param  string $directory    Destination directory
     * @param  bool   $useTimestamp Whether to include a timestamp in the filename
     * @return string Full path of the saved file
     */
    public function store(string $directory, bool $useTimestamp = true): string
    {
        $this->validate(); // Mandatory validation for store() security

        $extension  = $this->getExtension();
        $prefix     = $useTimestamp ? date('Y-m-d_His_') : '';
        $uniqueName = $prefix . bin2hex(random_bytes(8)) . ($extension ? '.' . $extension : '');

        return $this->move($directory, $uniqueName);
    }

    /**
     * Returns the original (sanitized) filename.
     *
     * @return string
     */
    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    /**
     * Returns the original filename without its extension.
     *
     * @return string
     */
    public function getBasename(): string
    {
        return pathinfo($this->originalName, PATHINFO_FILENAME);
    }

    /**
     * Returns the file extension in lowercase.
     *
     * @return string
     */
    public function getExtension(): string
    {
        if ($this->extension === null) {
            $extension = strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION)) ?: '';

            // Validate extension against the real MIME type if strict mode is enabled (cached)
            if ($this->strictMimeValidation) {
                $mimeExtension = $this->getExtensionFromMimeType($this->getMimeType());
                if ($mimeExtension && $extension !== $mimeExtension) {
                    // Security log: extension does not match the real file type
                    error_log("Upload security warning: Extension mismatch - claimed: {$extension}, actual: {$mimeExtension}");
                }
            }

            $this->extension = $extension;
        }

        return $this->extension;
    }

    /**
     * Returns the temporary path of the uploaded file.
     *
     * @return string
     */
    public function getRealPath(): string
    {
        return $this->tempPath;
    }

    /**
     * Returns the temporary path (alias for getRealPath).
     *
     * @return string
     */
    public function getTempPath(): string
    {
        return $this->getRealPath();
    }

    /**
     * Returns the file size in bytes.
     *
     * @return int
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * Returns the file size in a human-readable format.
     *
     * @param  int $precision Decimal precision
     * @return string
     */
    public function getHumanSize(int $precision = 2): string
    {
        $size  = $this->size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
            $size /= 1024;
        }

        return round($size, $precision) . ' ' . $units[$i];
    }

    /**
     * Returns the real MIME type of the file (detected from its content).
     *
     * @return string
     */
    public function getMimeType(): string
    {
        if ($this->realMimeType === null) {
            if (!file_exists($this->tempPath)) {
                $this->realMimeType = 'application/octet-stream'; // Robust fallback
                return $this->realMimeType;
            }

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo === false) {
                $this->realMimeType = 'application/octet-stream'; // Fallback
                return $this->realMimeType;
            }

            $mimeType = finfo_file($finfo, $this->tempPath);

            $this->realMimeType = $mimeType ?: 'application/octet-stream';
        }

        return $this->realMimeType;
    }

    /**
     * Returns the MIME type reported by the client.
     *
     * @return string|null
     */
    public function getClientMimeType(): ?string
    {
        return $this->clientMimeType;
    }

    /**
     * Returns the upload error code.
     *
     * @return int
     */
    public function getError(): int
    {
        return $this->error;
    }

    /**
     * Returns image metadata (only if the file is an image).
     *
     * @return array|null
     */
    public function getImageInfo(): ?array
    {
        if ($this->imageInfo === null && $this->isImage()) {
            $info = getimagesize($this->tempPath);
            if ($info !== false) {
                $this->imageInfo = [
                    'width'    => $info[0],
                    'height'   => $info[1],
                    'type'     => $info[2],
                    'html'     => $info[3],
                    'mime'     => $info['mime'],
                    'channels' => $info['channels'] ?? null,
                    'bits'     => $info['bits'] ?? null,
                ];
            }
        }

        return $this->imageInfo;
    }

    /**
     * Returns the SHA-256 hash of the file.
     *
     * @return string
     */
    public function getHash(): string
    {
        return hash_file('sha256', $this->tempPath) ?: '';
    }

    /**
     * Checks whether the file is an image.
     *
     * @return bool
     */
    public function isImage(): bool
    {
        $imageMimes = [
            'image/jpeg', 'image/png', 'image/gif',
            'image/webp', 'image/bmp', 'image/svg+xml',
        ];

        return in_array($this->getMimeType(), $imageMimes, true);
    }

    /**
     * Checks whether the file is a document.
     *
     * @return bool
     */
    public function isDocument(): bool
    {
        $documentMimes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
            'text/csv',
        ];

        return in_array($this->getMimeType(), $documentMimes, true);
    }

    /**
     * Checks whether the file is a video.
     *
     * @return bool
     */
    public function isVideo(): bool
    {
        return str_starts_with($this->getMimeType(), 'video/');
    }

    /**
     * Checks whether the file is an audio file.
     *
     * @return bool
     */
    public function isAudio(): bool
    {
        return str_starts_with($this->getMimeType(), 'audio/');
    }

    /**
     * Checks whether the file is executable (potentially dangerous).
     *
     * @return bool
     */
    public function isExecutable(): bool
    {
        return in_array($this->getExtension(), $this->forbiddenExtensions, true);
    }

    /**
     * Sets the maximum allowed file size.
     *
     * @param  int $size Size in bytes
     * @return self
     */
    public function setMaxSize(int $size): self
    {
        $this->maxSize = $size;
        $this->isValid = null; // Reset validation
        return $this;
    }

    /**
     * Sets the allowed MIME types.
     *
     * @param  array $mimeTypes
     * @return self
     */
    public function setAllowedMimeTypes(array $mimeTypes): self
    {
        $this->allowedMimeTypes = $mimeTypes;
        $this->isValid = null; // Reset validation
        return $this;
    }

    /**
     * Sets the allowed file extensions.
     *
     * @param  array $extensions
     * @return self
     */
    public function setAllowedExtensions(array $extensions): self
    {
        $this->allowedExtensions = array_map('strtolower', $extensions);
        $this->isValid = null; // Reset validation
        return $this;
    }

    /**
     * Enables or disables strict MIME type validation.
     *
     * @param  bool $strict
     * @return self
     */
    public function setStrictMimeValidation(bool $strict): self
    {
        $this->strictMimeValidation = $strict;
        return $this;
    }

    /**
     * Allows or disallows executable file uploads.
     *
     * @param  bool $allow
     * @return self
     */
    public function setAllowExecutableFiles(bool $allow): self
    {
        $this->allowExecutableFiles = $allow;
        $this->isValid = null; // Reset validation
        return $this;
    }

    /**
     * Creates an instance from a $_FILES array entry.
     *
     * @param  array $file
     * @param  array $options
     * @return self
     */
    public static function createFromArray(array $file, array $options = []): self
    {
        return new self($file, $options);
    }

    /**
     * Creates multiple instances from a $_FILES array.
     *
     * @param  array $files
     * @param  array $options
     * @return array<string, self>
     */
    public static function createMultiple(array $files, array $options = []): array
    {
        $uploads = [];

        foreach ($files as $key => $file) {
            if (is_array($file['name'])) {
                // Multiple files in the same field
                $count = count($file['name']);
                for ($i = 0; $i < $count; $i++) {
                    $singleFile = [
                        'name'     => $file['name'][$i]     ?? '',
                        'type'     => $file['type'][$i]     ?? '',
                        'tmp_name' => $file['tmp_name'][$i] ?? '',
                        'error'    => $file['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
                        'size'     => $file['size'][$i]     ?? 0,
                    ];

                    try {
                        $uploads["{$key}_{$i}"] = new self($singleFile, $options);
                    } catch (RuntimeException $e) {
                        error_log("Failed to create Upload for {$key}_{$i}: " . $e->getMessage());
                    }
                }
            } else {
                try {
                    $uploads[$key] = new self($file, $options);
                } catch (RuntimeException $e) {
                    error_log("Failed to create Upload for {$key}: " . $e->getMessage());
                }
            }
        }

        return $uploads;
    }

    /**
     * Initializes file data from the raw $_FILES entry.
     *
     * @param array $file
     */
    private function initializeFileData(array $file): void
    {
        $this->file           = $file;
        $this->originalName   = $this->sanitizeFilename($file['name'] ?? '');
        $this->tempPath       = $file['tmp_name'] ?? '';
        $this->size           = (int) ($file['size'] ?? 0);
        $this->error          = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $this->clientMimeType = $file['type'] ?? null;
    }

    /**
     * Applies configuration options to the instance.
     *
     * @param array $options
     */
    private function applyOptions(array $options): void
    {
        if (isset($options['max_size'])) {
            $this->setMaxSize($options['max_size']);
        }

        if (isset($options['allowed_mime_types'])) {
            $this->setAllowedMimeTypes($options['allowed_mime_types']);
        }

        if (isset($options['allowed_extensions'])) {
            $this->setAllowedExtensions($options['allowed_extensions']);
        }

        if (isset($options['strict_mime_validation'])) {
            $this->setStrictMimeValidation($options['strict_mime_validation']);
        }

        if (isset($options['allow_executable_files'])) {
            $this->setAllowExecutableFiles($options['allow_executable_files']);
        }
    }

    /**
     * Runs basic security checks at construction time.
     */
    private function performBasicSecurityChecks(): void
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            error_log("Upload error code: {$this->error} for file: {$this->originalName}");
        }

        if (!is_uploaded_file($this->tempPath)) {
            error_log("Security warning: File {$this->originalName} was not uploaded via POST");
        }
    }

    /**
     * Performs advanced security checks (called during validate()).
     *
     * @throws RuntimeException
     */
    private function performSecurityChecks(): void
    {
        // Ensure the temporary file exists and is readable
        if (!file_exists($this->tempPath) || !is_readable($this->tempPath)) {
            throw new RuntimeException('Temporary file is not readable');
        }

        // Check for dangerous double extensions
        $this->checkDoubleExtensions();

        // Check file content for dangerous signatures
        $this->checkDangerousSignatures();
    }

    /**
     * Validates the file size.
     *
     * @throws RuntimeException
     */
    private function validateSize(): void
    {
        if ($this->size <= 0) {
            throw new RuntimeException('File is empty or corrupted');
        }

        if ($this->size > $this->maxSize) {
            $maxSizeMB = round($this->maxSize / 1048576, 2);
            throw new RuntimeException("File exceeds the maximum allowed size of {$maxSizeMB}MB");
        }
    }

    /**
     * Validates the file MIME type.
     *
     * @throws RuntimeException
     */
    private function validateMimeType(): void
    {
        if (empty($this->allowedMimeTypes)) {
            return;
        }

        $realMimeType = $this->getMimeType();

        if (!in_array($realMimeType, $this->allowedMimeTypes, true)) {
            throw new RuntimeException(
                "MIME type '{$realMimeType}' is not allowed. Accepted types: " .
                implode(', ', $this->allowedMimeTypes)
            );
        }
    }

    /**
     * Validates the file extension.
     *
     * @throws RuntimeException
     */
    private function validateExtension(): void
    {
        $extension = $this->getExtension();

        // Check against forbidden extensions
        if (!$this->allowExecutableFiles && in_array($extension, $this->forbiddenExtensions, true)) {
            throw new RuntimeException("Extension '{$extension}' is forbidden for security reasons");
        }

        // Check against allowed extensions whitelist
        if (!empty($this->allowedExtensions) && !in_array($extension, $this->allowedExtensions, true)) {
            throw new RuntimeException(
                "Extension '{$extension}' is not allowed. Accepted extensions: " .
                implode(', ', $this->allowedExtensions)
            );
        }
    }

    /**
     * Validates the filename.
     *
     * @throws RuntimeException
     */
    private function validateFilename(): void
    {
        if (strlen($this->originalName) > $this->maxFilenameLength) {
            throw new RuntimeException("Filename too long (maximum: {$this->maxFilenameLength} characters)");
        }

        // Check for dangerous characters
        if (preg_match('/[<>:"|?*\\x00-\\x1f]/', $this->originalName)) {
            throw new RuntimeException('Filename contains invalid characters');
        }
    }

    /**
     * Validates the file content.
     *
     * @throws RuntimeException
     */
    private function validateFileContent(): void
    {
        // If the file claims to be an image, verify it actually is one
        if ($this->isImage()) {
            $imageInfo = getimagesize($this->tempPath);
            if ($imageInfo === false) {
                throw new RuntimeException('File is declared as an image but is not a valid image');
            }
        }
    }

    /**
     * Checks for dangerous double extensions in the filename.
     *
     * @throws RuntimeException
     */
    private function checkDoubleExtensions(): void
    {
        $parts = explode('.', $this->originalName);

        if (count($parts) > 2) {
            // Check each intermediate segment for forbidden extensions
            for ($i = 1; $i < count($parts) - 1; $i++) {
                if (in_array(strtolower($parts[$i]), $this->forbiddenExtensions, true)) {
                    throw new RuntimeException('Dangerous double extension detected in filename');
                }
            }
        }
    }

    /**
     * Scans the file content for dangerous byte signatures.
     *
     * @throws RuntimeException
     */
    private function checkDangerousSignatures(): void
    {
        $handle = fopen($this->tempPath, 'rb');
        if (!$handle) {
            return;
        }

        $header = fread($handle, 1024); // Read the first 1KB
        fclose($handle);

        foreach ($this->dangerousSignatures as $type => $signatures) {
            foreach ($signatures as $signature) {
                if (str_contains($header, $signature)) {
                    throw new RuntimeException("Dangerous signature detected: possible {$type} file");
                }
            }
        }
    }

    /**
     * Sanitizes a filename by removing unsafe characters.
     *
     * @param  string $filename
     * @return string
     */
    private function sanitizeFilename(string $filename): string
    {
        // Remove control characters and dangerous characters
        $filename = preg_replace('/[\x00-\x1f\x7f<>:"|?*]/', '', $filename);

        // Normalize multiple spaces
        $filename = preg_replace('/\s+/', ' ', trim($filename));

        // Enforce maximum length while preserving extension
        if (strlen($filename) > $this->maxFilenameLength) {
            $extension       = pathinfo($filename, PATHINFO_EXTENSION);
            $basename        = pathinfo($filename, PATHINFO_FILENAME);
            $maxBasenameLen  = $this->maxFilenameLength - strlen($extension) - 1;
            $filename        = substr($basename, 0, $maxBasenameLen) . ($extension ? '.' . $extension : '');
        }

        return $filename ?: 'unnamed';
    }

    /**
     * Resolves the final filename to use when saving the file.
     *
     * @param  string|null $filename
     * @param  bool        $preserveOriginalName
     * @return string
     */
    private function resolveFilename(?string $filename, bool $preserveOriginalName): string
    {
        if ($filename !== null) {
            return $this->sanitizeFilename($filename);
        }

        if ($preserveOriginalName) {
            return $this->originalName;
        }

        // Generate a unique name
        $extension = $this->getExtension();
        return uniqid('upload_', true) . ($extension ? '.' . $extension : '');
    }

    /**
     * Creates the destination directory if it does not exist.
     *
     * @param  string $directory
     * @return bool
     */
    private function createDirectoryIfNotExists(string $directory): bool
    {
        if (is_dir($directory)) {
            return is_writable($directory);
        }

        return mkdir($directory, 0755, true) && is_writable($directory);
    }

    /**
     * Generates a unique filename to avoid overwriting existing files.
     *
     * @param  string $directory
     * @param  string $filename
     * @return string
     */
    private function generateUniqueFilename(string $directory, string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $basename  = pathinfo($filename, PATHINFO_FILENAME);
        $counter   = 1;

        do {
            $newFilename = $basename . '_' . $counter . ($extension ? '.' . $extension : '');
            $path        = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $newFilename;
            $counter++;
        } while (file_exists($path));

        return $newFilename;
    }

    /**
     * Returns the expected file extension for a given MIME type.
     *
     * @param  string $mimeType
     * @return string|null
     */
    private function getExtensionFromMimeType(string $mimeType): ?string
    {
        $mimeToExt = [
            'image/jpeg'                => 'jpg',
            'image/png'                 => 'png',
            'image/gif'                 => 'gif',
            'image/webp'                => 'webp',
            'image/bmp'                 => 'bmp',
            'image/svg+xml'             => 'svg',
            'application/pdf'           => 'pdf',
            'text/plain'                => 'txt',
            'text/csv'                  => 'csv',
            'application/json'          => 'json',
            'application/xml'           => 'xml',
            'application/zip'           => 'zip',
            'application/x-rar-compressed' => 'rar',
            'video/mp4'                 => 'mp4',
            'video/mpeg'                => 'mpeg',
            'audio/mpeg'                => 'mp3',
            'audio/wav'                 => 'wav',
        ];

        return $mimeToExt[$mimeType] ?? null;
    }
}
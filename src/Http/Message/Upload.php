<?php

declare(strict_types=1);

namespace Slenix\Http\Message;

class Upload {

    protected array $file = [];

    public function __construct(array $file){

        if(!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK):
            throw new \RuntimeException('Upload error.');
        endif;

        $this->file = $file;
    }

    public function getOriginalName(): string {
        return $this->file['name'];
    }

    public function getOriginalExtension(): string {
        return pathinfo($this->file['name'], PATHINFO_EXTENSION);
    }

    public function getRealPath(): string {
        return $this->file['tmp_name'];
    }

    public function getSize(): int {
        return $this->file['size'];
    }

    public function getMimeType(): string {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $this->getRealPath());
        finfo_close($finfo);
        return $mime;
    }

    public function move(string $directory, ?string $name = null): string|bool {

        if(!is_dir($directory)):
            mkdir($directory, 0777, true);
        endif;

        $extension = $this->getOriginalExtension();
        $filename = $name ?? uniqid('', true). '.' .$extension;
        $destination = rtrim($directory, '/'). '/' .$filename;

        if(!move_uploaded_file($this->getRealPath(), $destination)):
            throw new \RuntimeException('Failded to move uploaded file.');
            return false;
        endif;

        return $destination;
        return true;
    }

}
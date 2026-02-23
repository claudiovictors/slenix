<?php

/*
|--------------------------------------------------------------------------
| Classe Upload Melhorada
|--------------------------------------------------------------------------
|
| Gerencia o upload de arquivos de forma robusta, fornecendo métodos para
| acessar informações do arquivo e movê-lo para um diretório de destino,
| com validações avançadas, tratamento de erros e recursos de segurança.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Uploads;

use RuntimeException;

class Upload
{
    // Dados do arquivo
    protected array $file = [];
    protected string $originalName;
    protected string $tempPath;
    protected int $size;
    protected int $error;
    protected ?string $clientMimeType;

    // Configurações de validação (opcionais)
    protected int $maxSize = 10485760; // 10MB por padrão
    protected array $allowedMimeTypes = [];
    protected array $allowedExtensions = [];
    protected array $forbiddenExtensions = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8',
        'exe', 'bat', 'cmd', 'scr', 'com', 'pif', 'vbs', 'js',
        'jar', 'sh', 'py', 'pl', 'rb', 'asp', 'aspx', 'jsp'
    ];

    // Cache interno
    private ?string $realMimeType = null;
    private ?string $extension = null;
    private ?array $imageInfo = null;
    private ?bool $isValid = null;

    // Erros de validação
    protected array $validationErrors = [];

    // Configurações de segurança
    protected bool $strictMimeValidation = true;
    protected bool $allowExecutableFiles = false;
    protected int $maxFilenameLength = 255;
    protected array $dangerousSignatures = [
        'executable' => ["\x4D\x5A"], // PE/COFF executável
        'php' => ['<?php', '<?=', '<script'],
        'html' => ['<script', '<iframe', '<object', '<embed'],
    ];

    /**
     * Construtor da classe Upload.
     *
     * @param array $file Dados do arquivo do $_FILES
     * @param array $options Opções de configuração opcionais
     */
    public function __construct(array $file, array $options = [])
    {
        $this->initializeFileData($file);
        $this->applyOptions($options);
        // Verificações básicas de segurança no construtor para robustez mínima
        $this->performBasicSecurityChecks();
    }

    /**
     * Valida o arquivo contra todas as regras configuradas.
     * O programador deve chamar este método explicitamente se desejar validação.
     *
     * @param bool $throwOnError Se deve lançar exceção em caso de erro (default: true)
     * @return bool
     * @throws RuntimeException Se validação falhar e $throwOnError for true
     */
    public function validate(bool $throwOnError = true): bool
    {
        if ($this->isValid !== null) {
            return $this->isValid;
        }

        $this->validationErrors = []; // Limpa erros anteriores

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
     * Verifica se o arquivo é válido sem lançar exceções.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->validate(false);
    }

    /**
     * Retorna os erros de validação da última tentativa.
     *
     * @return array<string>
     */
    public function getErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Limpa os erros de validação acumulados.
     *
     * @return self
     */
    public function clearErrors(): self
    {
        $this->validationErrors = [];
        $this->isValid = null; // Reset para nova validação
        return $this;
    }

    /**
     * Move o arquivo para o destino especificado.
     * Recomenda-se chamar validate() antes para robustez.
     *
     * @param string $directory Diretório de destino
     * @param string|null $filename Nome do arquivo (opcional)
     * @param bool $preserveOriginalName Se deve preservar o nome original
     * @return string Caminho completo do arquivo movido
     * @throws RuntimeException Se a movimentação falhar
     */
    public function move(string $directory, ?string $filename = null, bool $preserveOriginalName = false): string
    {
        $this->validate(); // Validação obrigatória para move() por segurança

        if (!$this->createDirectoryIfNotExists($directory)) {
            throw new RuntimeException("Não foi possível criar o diretório: {$directory}");
        }

        $finalFilename = $this->resolveFilename($filename, $preserveOriginalName);
        $destination = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $finalFilename;

        // Verifica se o arquivo de destino já existe
        if (file_exists($destination)) {
            $destination = $this->generateUniqueFilename($directory, $finalFilename);
        }

        if (!move_uploaded_file($this->tempPath, $destination)) {
            $error = error_get_last();
            throw new RuntimeException(
                'Falha ao mover o arquivo de upload: ' . ($error['message'] ?? 'Erro desconhecido')
            );
        }

        // Define permissões seguras
        chmod($destination, 0644);

        return $destination;
    }

    /**
     * Salva o arquivo com um nome único gerado automaticamente.
     * Recomenda-se chamar validate() antes para robustez.
     *
     * @param string $directory Diretório de destino
     * @param bool $useTimestamp Se deve incluir timestamp no nome
     * @return string Caminho completo do arquivo salvo
     */
    public function store(string $directory, bool $useTimestamp = true): string
    {
        $this->validate(); // Validação obrigatória para store() por segurança

        $extension = $this->getExtension();
        $prefix = $useTimestamp ? date('Y-m-d_His_') : '';
        $uniqueName = $prefix . bin2hex(random_bytes(8)) . ($extension ? '.' . $extension : '');
        
        return $this->move($directory, $uniqueName);
    }

    /**
     * Retorna o nome original do arquivo (sanitizado).
     *
     * @return string
     */
    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    /**
     * Retorna o nome original sem extensão.
     *
     * @return string
     */
    public function getBasename(): string
    {
        return pathinfo($this->originalName, PATHINFO_FILENAME);
    }

    /**
     * Retorna a extensão do arquivo (em minúsculas).
     *
     * @return string
     */
    public function getExtension(): string
    {
        if ($this->extension === null) {
            $extension = strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION)) ?: '';
            
            // Valida extensão baseada no MIME type real se configurado (cache)
            if ($this->strictMimeValidation) {
                $mimeExtension = $this->getExtensionFromMimeType($this->getMimeType());
                if ($mimeExtension && $extension !== $mimeExtension) {
                    // Log de segurança: extensão não corresponde ao tipo real
                    error_log("Upload security warning: Extension mismatch - claimed: {$extension}, actual: {$mimeExtension}");
                }
            }
            
            $this->extension = $extension;
        }
        
        return $this->extension;
    }

    /**
     * Retorna o caminho temporário do arquivo.
     *
     * @return string
     */
    public function getRealPath(): string
    {
        return $this->tempPath;
    }

    /**
     * Retorna o caminho temporário (alias para getRealPath).
     *
     * @return string
     */
    public function getTempPath(): string
    {
        return $this->getRealPath();
    }

    /**
     * Retorna o tamanho do arquivo em bytes.
     *
     * @return int
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * Retorna o tamanho formatado para humanos.
     *
     * @param int $precision Precisão decimal
     * @return string
     */
    public function getHumanSize(int $precision = 2): string
    {
        $size = $this->size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
            $size /= 1024;
        }
        
        return round($size, $precision) . ' ' . $units[$i];
    }

    /**
     * Retorna o tipo MIME real do arquivo (detectado pelo conteúdo).
     *
     * @return string
     */
    public function getMimeType(): string
    {
        if ($this->realMimeType === null) {
            if (!file_exists($this->tempPath)) {
                $this->realMimeType = 'application/octet-stream'; // Fallback robusto
                return $this->realMimeType;
            }

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo === false) {
                $this->realMimeType = 'application/octet-stream'; // Fallback
                return $this->realMimeType;
            }

            $mimeType = finfo_file($finfo, $this->tempPath);
            finfo_close($finfo);

            $this->realMimeType = $mimeType ?: 'application/octet-stream';
        }

        return $this->realMimeType;
    }

    /**
     * Retorna o tipo MIME enviado pelo cliente.
     *
     * @return string|null
     */
    public function getClientMimeType(): ?string
    {
        return $this->clientMimeType;
    }

    /**
     * Retorna o código de erro do upload.
     *
     * @return int
     */
    public function getError(): int
    {
        return $this->error;
    }

    /**
     * Retorna informações da imagem (se for uma imagem).
     *
     * @return array|null
     */
    public function getImageInfo(): ?array
    {
        if ($this->imageInfo === null && $this->isImage()) {
            $info = getimagesize($this->tempPath);
            if ($info !== false) {
                $this->imageInfo = [
                    'width' => $info[0],
                    'height' => $info[1],
                    'type' => $info[2],
                    'html' => $info[3],
                    'mime' => $info['mime'],
                    'channels' => $info['channels'] ?? null,
                    'bits' => $info['bits'] ?? null,
                ];
            }
        }

        return $this->imageInfo;
    }

    /**
     * Retorna o hash SHA-256 do arquivo.
     *
     * @return string
     */
    public function getHash(): string
    {
        return hash_file('sha256', $this->tempPath) ?: '';
    }

    /**
     * Verifica se o arquivo é uma imagem.
     *
     * @return bool
     */
    public function isImage(): bool
    {
        $imageMimes = [
            'image/jpeg', 'image/png', 'image/gif', 
            'image/webp', 'image/bmp', 'image/svg+xml'
        ];
        
        return in_array($this->getMimeType(), $imageMimes, true);
    }

    /**
     * Verifica se o arquivo é um documento.
     *
     * @return bool
     */
    public function isDocument(): bool
    {
        $documentMimes = [
            'application/pdf', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain', 'text/csv'
        ];
        
        return in_array($this->getMimeType(), $documentMimes, true);
    }

    /**
     * Verifica se o arquivo é um vídeo.
     *
     * @return bool
     */
    public function isVideo(): bool
    {
        return str_starts_with($this->getMimeType(), 'video/');
    }

    /**
     * Verifica se o arquivo é um áudio.
     *
     * @return bool
     */
    public function isAudio(): bool
    {
        return str_starts_with($this->getMimeType(), 'audio/');
    }

    /**
     * Verifica se o arquivo é executável (perigoso).
     *
     * @return bool
     */
    public function isExecutable(): bool
    {
        $extension = $this->getExtension();
        return in_array($extension, $this->forbiddenExtensions, true);
    }

    /**
     * Define o tamanho máximo permitido.
     *
     * @param int $size Tamanho em bytes
     * @return self
     */
    public function setMaxSize(int $size): self
    {
        $this->maxSize = $size;
        $this->isValid = null; // Reset validação
        return $this;
    }

    /**
     * Define tipos MIME permitidos.
     *
     * @param array $mimeTypes
     * @return self
     */
    public function setAllowedMimeTypes(array $mimeTypes): self
    {
        $this->allowedMimeTypes = $mimeTypes;
        $this->isValid = null; // Reset validação
        return $this;
    }

    /**
     * Define extensões permitidas.
     *
     * @param array $extensions
     * @return self
     */
    public function setAllowedExtensions(array $extensions): self
    {
        $this->allowedExtensions = array_map('strtolower', $extensions);
        $this->isValid = null; // Reset validação
        return $this;
    }

    /**
     * Habilita ou desabilita validação rigorosa de MIME.
     *
     * @param bool $strict
     * @return self
     */
    public function setStrictMimeValidation(bool $strict): self
    {
        $this->strictMimeValidation = $strict;
        return $this;
    }

    /**
     * Permite ou não arquivos executáveis.
     *
     * @param bool $allow
     * @return self
     */
    public function setAllowExecutableFiles(bool $allow): self
    {
        $this->allowExecutableFiles = $allow;
        $this->isValid = null; // Reset validação
        return $this;
    }

    /**
     * Cria instância a partir de array do $_FILES.
     *
     * @param array $file
     * @param array $options
     * @return self
     */
    public static function createFromArray(array $file, array $options = []): self
    {
        return new self($file, $options);
    }

    /**
     * Cria múltiplas instâncias a partir de $_FILES.
     *
     * @param array $files
     * @param array $options
     * @return array
     */
    public static function createMultiple(array $files, array $options = []): array
    {
        $uploads = [];
        
        foreach ($files as $key => $file) {
            if (is_array($file['name'])) {
                // Múltiplos arquivos no mesmo campo
                $count = count($file['name']);
                for ($i = 0; $i < $count; $i++) {
                    $singleFile = [
                        'name' => $file['name'][$i] ?? '',
                        'type' => $file['type'][$i] ?? '',
                        'tmp_name' => $file['tmp_name'][$i] ?? '',
                        'error' => $file['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $file['size'][$i] ?? 0,
                    ];
                    
                    try {
                        $uploads["{$key}_{$i}"] = new self($singleFile, $options);
                    } catch (RuntimeException $e) {
                        error_log("Falha ao criar Upload para {$key}_{$i}: " . $e->getMessage());
                    }
                }
            } else {
                try {
                    $uploads[$key] = new self($file, $options);
                } catch (RuntimeException $e) {
                    error_log("Falha ao criar Upload para {$key}: " . $e->getMessage());
                }
            }
        }
        
        return $uploads;
    }

    /**
     * Inicializa os dados do arquivo.
     *
     * @param array $file
     */
    private function initializeFileData(array $file): void
    {
        $this->file = $file;
        $this->originalName = $this->sanitizeFilename($file['name'] ?? '');
        $this->tempPath = $file['tmp_name'] ?? '';
        $this->size = (int) ($file['size'] ?? 0);
        $this->error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $this->clientMimeType = $file['type'] ?? null;
    }

    /**
     * Aplica opções de configuração.
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
     * Verificações básicas de segurança no construtor.
     */
    private function performBasicSecurityChecks(): void
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            error_log("Upload error code: {$this->error} for file: {$this->originalName}");
        }

        if (!is_uploaded_file($this->tempPath)) {
            error_log("Security warning: File {$this->originalName} not uploaded via POST");
        }
    }

    /**
     * Realiza verificações de segurança avançadas (chamado em validate()).
     *
     * @throws RuntimeException
     */
    private function performSecurityChecks(): void
    {
        // Verifica se o arquivo temporário existe e é legível
        if (!file_exists($this->tempPath) || !is_readable($this->tempPath)) {
            throw new RuntimeException('Arquivo temporário não é legível');
        }

        // Verifica extensões duplas perigosas
        $this->checkDoubleExtensions();

        // Verifica assinaturas perigosas no conteúdo
        $this->checkDangerousSignatures();
    }

    /**
     * Valida o tamanho do arquivo.
     *
     * @throws RuntimeException
     */
    private function validateSize(): void
    {
        if ($this->size <= 0) {
            throw new RuntimeException('Arquivo está vazio ou corrompido');
        }

        if ($this->size > $this->maxSize) {
            $maxSizeMB = round($this->maxSize / 1048576, 2);
            throw new RuntimeException("Arquivo excede o tamanho máximo de {$maxSizeMB}MB");
        }
    }

    /**
     * Valida o tipo MIME.
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
                "Tipo MIME '{$realMimeType}' não permitido. Tipos aceitos: " . 
                implode(', ', $this->allowedMimeTypes)
            );
        }
    }

    /**
     * Valida a extensão do arquivo.
     *
     * @throws RuntimeException
     */
    private function validateExtension(): void
    {
        $extension = $this->getExtension();

        // Verifica extensões proibidas
        if (!$this->allowExecutableFiles && in_array($extension, $this->forbiddenExtensions, true)) {
            throw new RuntimeException("Extensão '{$extension}' é proibida por motivos de segurança");
        }

        // Verifica extensões permitidas
        if (!empty($this->allowedExtensions) && !in_array($extension, $this->allowedExtensions, true)) {
            throw new RuntimeException(
                "Extensão '{$extension}' não permitida. Extensões aceitas: " . 
                implode(', ', $this->allowedExtensions)
            );
        }
    }

    /**
     * Valida o nome do arquivo.
     *
     * @throws RuntimeException
     */
    private function validateFilename(): void
    {
        if (strlen($this->originalName) > $this->maxFilenameLength) {
            throw new RuntimeException("Nome do arquivo muito longo (máximo: {$this->maxFilenameLength} caracteres)");
        }

        // Verifica caracteres perigosos
        if (preg_match('/[<>:"|?*\\x00-\\x1f]/', $this->originalName)) {
            throw new RuntimeException('Nome do arquivo contém caracteres inválidos');
        }
    }

    /**
     * Valida o conteúdo do arquivo.
     *
     * @throws RuntimeException
     */
    private function validateFileContent(): void
    {
        // Verifica se é realmente uma imagem quando declara ser
        if ($this->isImage()) {
            $imageInfo = getimagesize($this->tempPath);
            if ($imageInfo === false) {
                throw new RuntimeException('Arquivo declarado como imagem mas não é uma imagem válida');
            }
        }
    }

    /**
     * Verifica extensões duplas perigosas.
     *
     * @throws RuntimeException
     */
    private function checkDoubleExtensions(): void
    {
        $filename = $this->originalName;
        $parts = explode('.', $filename);
        
        if (count($parts) > 2) {
            // Verifica se alguma das extensões intermediárias é perigosa
            for ($i = 1; $i < count($parts) - 1; $i++) {
                if (in_array(strtolower($parts[$i]), $this->forbiddenExtensions, true)) {
                    throw new RuntimeException('Arquivo com extensão dupla perigosa detectado');
                }
            }
        }
    }

    /**
     * Verifica assinaturas perigosas no arquivo.
     *
     * @throws RuntimeException
     */
    private function checkDangerousSignatures(): void
    {
        $handle = fopen($this->tempPath, 'rb');
        if (!$handle) {
            return;
        }

        $header = fread($handle, 1024); // Lê os primeiros 1KB
        fclose($handle);

        foreach ($this->dangerousSignatures as $type => $signatures) {
            foreach ($signatures as $signature) {
                if (str_contains($header, $signature)) {
                    throw new RuntimeException("Assinatura perigosa detectada: possível arquivo {$type}");
                }
            }
        }
    }

    /**
     * Sanitiza o nome do arquivo.
     *
     * @param string $filename
     * @return string
     */
    private function sanitizeFilename(string $filename): string
    {
        // Remove caracteres de controle e caracteres perigosos
        $filename = preg_replace('/[\x00-\x1f\x7f<>:"|?*]/', '', $filename);
        
        // Remove espaços múltiplos e normaliza
        $filename = preg_replace('/\s+/', ' ', trim($filename));
        
        // Limita o comprimento
        if (strlen($filename) > $this->maxFilenameLength) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $basename = pathinfo($filename, PATHINFO_FILENAME);
            $maxBasenameLength = $this->maxFilenameLength - strlen($extension) - 1;
            $filename = substr($basename, 0, $maxBasenameLength) . ($extension ? '.' . $extension : '');
        }
        
        return $filename ?: 'unnamed';
    }

    /**
     * Resolve o nome final do arquivo.
     *
     * @param string|null $filename
     * @param bool $preserveOriginalName
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

        // Gera nome único
        $extension = $this->getExtension();
        return uniqid('upload_', true) . ($extension ? '.' . $extension : '');
    }

    /**
     * Cria diretório se não existir.
     *
     * @param string $directory
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
     * Gera nome único para evitar sobrescrita.
     *
     * @param string $directory
     * @param string $filename
     * @return string
     */
    private function generateUniqueFilename(string $directory, string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $counter = 1;

        do {
            $newFilename = $basename . '_' . $counter . ($extension ? '.' . $extension : '');
            $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $newFilename;
            $counter++;
        } while (file_exists($path));

        return $newFilename;
    }

    /**
     * Obtém extensão baseada no tipo MIME.
     *
     * @param string $mimeType
     * @return string|null
     */
    private function getExtensionFromMimeType(string $mimeType): ?string
    {
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'application/json' => 'json',
            'application/xml' => 'xml',
            'application/zip' => 'zip',
            'application/x-rar-compressed' => 'rar',
            'video/mp4' => 'mp4',
            'video/mpeg' => 'mpeg',
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
        ];

        return $mimeToExt[$mimeType] ?? null;
    }
}
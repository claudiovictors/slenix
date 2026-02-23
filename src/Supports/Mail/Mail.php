<?php

/*
 |--------------------------------------------------------------------------
 | Classe Email
 |--------------------------------------------------------------------------
 |
 | Classe responsável pelo envio de e-mails usando funções nativas do PHP
 | ou SMTP. Suporta envio com HTML, múltiplos destinatários, e anexos.
 | Facilita o envio de e-mails, abstraindo a complexidade da função mail() do PHP
 | e implementando conexão SMTP nativa. Permite a construção de e-mails de forma fluente.
 |
*/

declare(strict_types=1);

namespace Slenix\Supports\Mail;


class Mail
{
    /**
     * @var array<int, string> Lista de e-mails dos destinatários.
     */
    protected array $to = [];

    /**
     * @var string O e-mail do remetente.
     */
    protected string $from = '';

    /**
     * @var string O nome do remetente.
     */
    protected string $fromName = '';

    /**
     * @var string O assunto do e-mail.
     */
    protected string $subject = '';

    /**
     * @var string O corpo da mensagem do e-mail.
     */
    protected string $message = '';

    /**
     * @var bool Define se a mensagem é em formato HTML.
     */
    protected bool $isHtml = false;

    /**
     * @var array<int, string> Cabeçalhos adicionais do e-mail.
     */
    protected array $headers = [];

    /**
     * @var array<int, string> Caminhos dos arquivos a serem anexados.
     */
    protected array $attachments = [];

    /**
     * @var string Método de envio: 'mail' ou 'smtp'
     */
    protected string $method = 'mail';

    /**
     * @var array Configurações SMTP
     */
    protected array $smtpConfig = [
        'host' => 'localhost',
        'port' => 587,
        'username' => '',
        'password' => '',
        'encryption' => 'tls', // tls, ssl ou none
        'auth' => true,
        'timeout' => 30
    ];

    /**
     * @var resource|null Conexão SMTP
     */
    protected $smtpConnection = null;

    /**
     * @var string Log de debug
     */
    protected string $debugLog = '';

    /**
     * Construtor da classe Email.
     */
    public function __construct()
    {
        $this->headers[] = 'MIME-Version: 1.0';
        $this->loadConfig();
    }

    /**
     * Carrega configurações do ambiente
     * @return void
     */
    protected function loadConfig(): void
    {
        $envVars = [
            'SMTP_HOST' => 'host',
            'SMTP_PORT' => 'port',
            'SMTP_USERNAME' => 'username',
            'SMTP_PASSWORD' => 'password',
            'SMTP_ENCRYPTION' => 'encryption',
            'SMTP_AUTH' => 'auth',
            'SMTP_TIMEOUT' => 'timeout'
        ];

        foreach ($envVars as $envKey => $configKey) {
            $value = getenv($envKey);
            if ($value !== false) {
                if ($configKey === 'port' || $configKey === 'timeout') {
                    $this->smtpConfig[$configKey] = (int) $value;
                } elseif ($configKey === 'auth') {
                    $this->smtpConfig[$configKey] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                } else {
                    $this->smtpConfig[$configKey] = $value;
                }
            }
        }
    }

    /**
     * Define o método de envio
     * @param string $method
     * @return Mail
     */
    public function method(string $method): self
    {
        if (in_array($method, ['mail', 'smtp'])) {
            $this->method = $method;
        }
        return $this;
    }

    /**
     * Configura SMTP
     * @param array $config
     * @return Mail
     */
    public function smtp(array $config): self
    {
        $this->smtpConfig = array_merge($this->smtpConfig, $config);
        $this->method = 'smtp';
        return $this;
    }

    /**
     * Define o remetente do e-mail.
     * @param string $email
     * @param string $name
     * @return Mail
     */
    public function from(string $email, string $name = ''): self
    {
        $this->from = $email;
        $this->fromName = $name;
        return $this;
    }

    /**
     * Adiciona um destinatário ao e-mail.
     * @param string $email
     * @return Mail
     */
    public function to(string $email): self
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->to[] = $email;
        }
        return $this;
    }

    /**
     * Define o assunto do e-mail.
     * @param string $subject
     * @return Mail
     */
    public function subject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    /**
     * Define a mensagem (corpo) do e-mail.
     * @param string $message
     * @param bool $isHtml
     * @return Mail
     */
    public function message(string $message, bool $isHtml = false): self
    {
        $this->message = $message;
        $this->isHtml = $isHtml;
        return $this;
    }

    /**
     * Anexa um arquivo ao e-mail.
     * @param string $filePath
     * @return Mail
     */
    public function attach(string $filePath): self
    {
        if (file_exists($filePath)) {
            $this->attachments[] = $filePath;
        }
        return $this;
    }

    /**
     * Envia o e-mail usando o método configurado
     * @return bool
     */
    public function send(): bool
    {
        if (empty($this->to) || empty($this->from) || empty($this->subject) || empty($this->message)) {
            return false;
        }

        return $this->method === 'smtp' ? $this->sendSmtp() : $this->sendMail();
    }

    /**
     * Envia e-mail usando a função mail() nativa
     * @return bool
     */
    protected function sendMail(): bool
    {
        $to = implode(', ', $this->to);
        $from = $this->fromName ? "{$this->fromName} <{$this->from}>" : $this->from;

        $headers = $this->headers;
        $headers[] = "From: $from";

        if ($this->isHtml) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        }

        if (empty($this->attachments)) {
            return mail($to, $this->subject, $this->message, implode("\r\n", $headers));
        }

        return $this->sendMailWithAttachments($to, $headers);
    }

    /**
     * Envia e-mail com anexos usando mail()
     * @param string $to
     * @param array $headers
     * @return bool
     */
    protected function sendMailWithAttachments(string $to, array $headers): bool
    {
        $boundary = md5(uniqid((string) time()));
        $headers[] = "Content-Type: multipart/mixed; boundary=\"$boundary\"";

        $body = "--$boundary\r\n";
        $body .= "Content-Type: " . ($this->isHtml ? "text/html" : "text/plain") . "; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $body .= $this->message . "\r\n";

        foreach ($this->attachments as $filePath) {
            $filename = basename($filePath);
            $fileData = chunk_split(base64_encode(file_get_contents($filePath)));

            $body .= "--$boundary\r\n";
            $body .= "Content-Type: application/octet-stream; name=\"$filename\"\r\n";
            $body .= "Content-Disposition: attachment; filename=\"$filename\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= $fileData . "\r\n";
        }

        $body .= "--$boundary--";

        return mail($to, $this->subject, $body, implode("\r\n", $headers));
    }

    /**
     * Envia e-mail usando SMTP
     * @return bool
     */
    protected function sendSmtp(): bool
    {
        try {
            if (!$this->connectSmtp()) {
                return false;
            }

            if (!$this->authenticateSmtp()) {
                $this->disconnectSmtp();
                return false;
            }

            $success = $this->sendSmtpEmail();
            $this->disconnectSmtp();

            return $success;

        } catch (\Exception $e) {
            $this->debugLog .= "Erro SMTP: " . $e->getMessage() . "\n";
            $this->disconnectSmtp();
            return false;
        }
    }

    /**
     * Conecta ao servidor SMTP
     * @return bool
     */
    protected function connectSmtp(): bool
    {
        $host = $this->smtpConfig['host'];
        $port = $this->smtpConfig['port'];
        $timeout = $this->smtpConfig['timeout'];

        // Conecta com ou sem criptografia
        if ($this->smtpConfig['encryption'] === 'ssl') {
            $host = "ssl://$host";
        }

        $this->smtpConnection = fsockopen($host, $port, $errno, $errstr, $timeout);

        if (!$this->smtpConnection) {
            $this->debugLog .= "Erro ao conectar: $errno - $errstr\n";
            return false;
        }

        $response = $this->getSmtpResponse();
        if (!$this->checkSmtpResponse($response, 220)) {
            return false;
        }

        // EHLO
        $this->sendSmtpCommand("EHLO " . gethostname());
        $response = $this->getSmtpResponse();
        if (!$this->checkSmtpResponse($response, 250)) {
            return false;
        }

        // STARTTLS se necessário
        if ($this->smtpConfig['encryption'] === 'tls') {
            $this->sendSmtpCommand("STARTTLS");
            $response = $this->getSmtpResponse();
            if (!$this->checkSmtpResponse($response, 220)) {
                return false;
            }

            if (!stream_socket_enable_crypto($this->smtpConnection, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $this->debugLog .= "Erro ao habilitar TLS\n";
                return false;
            }

            // EHLO novamente após TLS
            $this->sendSmtpCommand("EHLO " . gethostname());
            $response = $this->getSmtpResponse();
            if (!$this->checkSmtpResponse($response, 250)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Autentica no servidor SMTP
     * @return bool
     */
    protected function authenticateSmtp(): bool
    {
        if (!$this->smtpConfig['auth']) {
            return true;
        }

        $this->sendSmtpCommand("AUTH LOGIN");
        $response = $this->getSmtpResponse();
        if (!$this->checkSmtpResponse($response, 334)) {
            return false;
        }

        $this->sendSmtpCommand(base64_encode($this->smtpConfig['username']));
        $response = $this->getSmtpResponse();
        if (!$this->checkSmtpResponse($response, 334)) {
            return false;
        }

        $this->sendSmtpCommand(base64_encode($this->smtpConfig['password']));
        $response = $this->getSmtpResponse();
        if (!$this->checkSmtpResponse($response, 235)) {
            return false;
        }

        return true;
    }

    /**
     * Envia o e-mail via SMTP
     * @return bool
     */
    protected function sendSmtpEmail(): bool
    {
        // MAIL FROM
        $this->sendSmtpCommand("MAIL FROM: <{$this->from}>");
        $response = $this->getSmtpResponse();
        if (!$this->checkSmtpResponse($response, 250)) {
            return false;
        }

        // RCPT TO
        foreach ($this->to as $recipient) {
            $this->sendSmtpCommand("RCPT TO: <$recipient>");
            $response = $this->getSmtpResponse();
            if (!$this->checkSmtpResponse($response, 250)) {
                return false;
            }
        }

        // DATA
        $this->sendSmtpCommand("DATA");
        $response = $this->getSmtpResponse();
        if (!$this->checkSmtpResponse($response, 354)) {
            return false;
        }

        // Cabeçalhos e corpo
        $email = $this->buildSmtpEmail();
        $this->sendSmtpCommand($email);
        $this->sendSmtpCommand(".");

        $response = $this->getSmtpResponse();
        return $this->checkSmtpResponse($response, 250);
    }

    /**
     * Constrói o e-mail para SMTP
     * @return string
     */
    protected function buildSmtpEmail(): string
    {
        $email = "";
        $email .= "From: " . ($this->fromName ? "{$this->fromName} <{$this->from}>" : $this->from) . "\r\n";
        $email .= "To: " . implode(', ', $this->to) . "\r\n";
        $email .= "Subject: {$this->subject}\r\n";
        $email .= "Date: " . date('r') . "\r\n";

        if (empty($this->attachments)) {
            $email .= "Content-Type: " . ($this->isHtml ? "text/html" : "text/plain") . "; charset=UTF-8\r\n";
            $email .= "\r\n";
            $email .= $this->message;
        } else {
            $boundary = md5(uniqid((string) time()));
            $email .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
            $email .= "\r\n";

            // Corpo da mensagem
            $email .= "--$boundary\r\n";
            $email .= "Content-Type: " . ($this->isHtml ? "text/html" : "text/plain") . "; charset=UTF-8\r\n";
            $email .= "\r\n";
            $email .= $this->message . "\r\n";

            // Anexos
            foreach ($this->attachments as $filePath) {
                $filename = basename($filePath);
                $fileData = chunk_split(base64_encode(file_get_contents($filePath)));

                $email .= "--$boundary\r\n";
                $email .= "Content-Type: application/octet-stream; name=\"$filename\"\r\n";
                $email .= "Content-Disposition: attachment; filename=\"$filename\"\r\n";
                $email .= "Content-Transfer-Encoding: base64\r\n";
                $email .= "\r\n";
                $email .= $fileData;
            }

            $email .= "--$boundary--";
        }

        return $email;
    }

    /**
     * Envia comando SMTP
     * @param string $command
     * @return void
     */
    protected function sendSmtpCommand(string $command): void
    {
        fwrite($this->smtpConnection, $command . "\r\n");
        $this->debugLog .= ">> $command\n";
    }

    /**
     * Obtém resposta do servidor SMTP
     * @return string
     */
    protected function getSmtpResponse(): string
    {
        $response = '';
        while (($line = fgets($this->smtpConnection, 515)) !== false) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        $this->debugLog .= "<< $response";
        return trim($response);
    }

    /**
     * Verifica resposta SMTP
     * @param string $response
     * @param int $expectedCode
     * @return bool
     */
    protected function checkSmtpResponse(string $response, int $expectedCode): bool
    {
        $code = (int) substr($response, 0, 3);
        return $code === $expectedCode;
    }

    /**
     * Desconecta do servidor SMTP
     * @return void
     */
    protected function disconnectSmtp(): void
    {
        if ($this->smtpConnection) {
            $this->sendSmtpCommand("QUIT");
            fclose($this->smtpConnection);
            $this->smtpConnection = null;
        }
    }

    /**
     * Obtém log de debug
     * @return string
     */
    public function getDebugLog(): string
    {
        return $this->debugLog;
    }

    /**
     * Limpa log de debug
     * @return void
     */
    public function clearDebugLog(): void
    {
        $this->debugLog = '';
    }
}
<?php

/*
 |--------------------------------------------------------------------------
 | Mail Class
 |--------------------------------------------------------------------------
 |
 | Responsible for sending emails using native PHP functions or SMTP.
 | Supports HTML emails, multiple recipients, and attachments.
 | Abstracts the complexity of PHP's mail() function and implements
 | a native SMTP connection. Allows building emails in a fluent style.
 |
*/

declare(strict_types=1);

namespace Slenix\Supports\Mail;

class Mail
{
    /**
     * @var array<int, string> List of recipient email addresses.
     */
    protected array $to = [];

    /**
     * @var string The sender's email address.
     */
    protected string $from = '';

    /**
     * @var string The sender's display name.
     */
    protected string $fromName = '';

    /**
     * @var string The email subject.
     */
    protected string $subject = '';

    /**
     * @var string The email message body.
     */
    protected string $message = '';

    /**
     * @var bool Whether the message is in HTML format.
     */
    protected bool $isHtml = false;

    /**
     * @var array<int, string> Additional email headers.
     */
    protected array $headers = [];

    /**
     * @var array<int, string> File paths of attachments.
     */
    protected array $attachments = [];

    /**
     * @var string Sending method: 'mail' or 'smtp'
     */
    protected string $method = 'mail';

    /**
     * @var array SMTP configuration
     */
    protected array $smtpConfig = [
        'host'       => '',
        'port'       => 587,
        'username'   => '',
        'password'   => '',
        'encryption' => 'tls', // tls, ssl or none
        'auth'       => true,
        'timeout'    => 30,
    ];

    /**
     * @var resource|null SMTP connection
     */
    protected $smtpConnection = null;

    /**
     * @var string Debug log
     */
    protected string $debugLog = '';

    /**
     * Mail class constructor.
     */
    public function __construct()
    {
        $this->headers[] = 'MIME-Version: 1.0';
        $this->loadConfig();
    }

    /**
     * Loads SMTP configuration from environment variables.
     *
     * @return void
     */
    protected function loadConfig(): void
    {
        $envVars = [
            'SMTP_HOST'       => 'host',
            'SMTP_PORT'       => 'port',
            'SMTP_USERNAME'   => 'username',
            'SMTP_PASSWORD'   => 'password',
            'SMTP_ENCRYPTION' => 'encryption',
            'SMTP_AUTH'       => 'auth',
            'SMTP_TIMEOUT'    => 'timeout',
        ];

        foreach ($envVars as $envKey => $configKey) {
            $value = getenv($envKey);
            if ($value === false) {
                continue;
            }

            if ($configKey === 'port' || $configKey === 'timeout') {
                $this->smtpConfig[$configKey] = (int) $value;
            } elseif ($configKey === 'auth') {
                $this->smtpConfig[$configKey] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } else {
                $this->smtpConfig[$configKey] = $value;
            }
        }
    }

    /**
     * Sets the sending method.
     *
     * @param  string $method 'mail' or 'smtp'
     * @return self
     */
    public function method(string $method): self
    {
        if (in_array($method, ['mail', 'smtp'], true)) {
            $this->method = $method;
        }
        return $this;
    }

    /**
     * Configures and enables SMTP sending.
     *
     * @param  array $config SMTP configuration overrides
     * @return self
     */
    public function smtp(array $config): self
    {
        $this->smtpConfig = array_merge($this->smtpConfig, $config);
        $this->method     = 'smtp';
        return $this;
    }

    /**
     * Sets the sender's email address and optional display name.
     *
     * @param  string $email
     * @param  string $name
     * @return self
     */
    public function from(string $email, string $name = ''): self
    {
        $this->from     = $email;
        $this->fromName = $name;
        return $this;
    }

    /**
     * Adds a recipient to the email.
     * Invalid email addresses are silently ignored.
     *
     * @param  string $email
     * @return self
     */
    public function to(string $email): self
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->to[] = $email;
        }
        return $this;
    }

    /**
     * Sets the email subject.
     *
     * @param  string $subject
     * @return self
     */
    public function subject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    /**
     * Sets the email message body.
     *
     * @param  string $message
     * @param  bool   $isHtml  Whether the body is HTML (default: false)
     * @return self
     */
    public function message(string $message, bool $isHtml = false): self
    {
        $this->message = $message;
        $this->isHtml  = $isHtml;
        return $this;
    }

    /**
     * Attaches a file to the email.
     * Non-existent files are silently ignored.
     *
     * @param  string $filePath Absolute path to the file
     * @return self
     */
    public function attach(string $filePath): self
    {
        if (file_exists($filePath)) {
            $this->attachments[] = $filePath;
        }
        return $this;
    }

    /**
     * Sends the email using the configured method.
     * Returns false if required fields (to, from, subject, message) are missing.
     *
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
     * Sends the email using PHP's native mail() function.
     *
     * @return bool
     */
    protected function sendMail(): bool
    {
        $to   = implode(', ', $this->to);
        $from = $this->fromName ? "{$this->fromName} <{$this->from}>" : $this->from;

        $headers   = $this->headers;
        $headers[] = "From: {$from}";
        $headers[] = $this->isHtml
            ? 'Content-Type: text/html; charset=UTF-8'
            : 'Content-Type: text/plain; charset=UTF-8';

        if (empty($this->attachments)) {
            return mail($to, $this->subject, $this->message, implode("\r\n", $headers));
        }

        return $this->sendMailWithAttachments($to, $headers);
    }

    /**
     * Sends a multipart email with attachments using mail().
     *
     * @param  string $to
     * @param  array  $headers
     * @return bool
     */
    protected function sendMailWithAttachments(string $to, array $headers): bool
    {
        $boundary  = md5(uniqid((string) time()));
        $headers[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";

        $body  = "--{$boundary}\r\n";
        $body .= 'Content-Type: ' . ($this->isHtml ? 'text/html' : 'text/plain') . "; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $body .= $this->message . "\r\n";

        foreach ($this->attachments as $filePath) {
            $filename = basename($filePath);
            $fileData = chunk_split(base64_encode((string) file_get_contents($filePath)));

            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: application/octet-stream; name=\"{$filename}\"\r\n";
            $body .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= $fileData . "\r\n";
        }

        $body .= "--{$boundary}--";

        return mail($to, $this->subject, $body, implode("\r\n", $headers));
    }

    /**
     * Sends the email via SMTP.
     *
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
            $this->debugLog .= "SMTP error: " . $e->getMessage() . "\n";
            $this->disconnectSmtp();
            return false;
        }
    }

    /**
     * Establishes a connection to the SMTP server.
     *
     * @return bool
     */
    protected function connectSmtp(): bool
    {
        $host    = $this->smtpConfig['host'];
        $port    = $this->smtpConfig['port'];
        $timeout = $this->smtpConfig['timeout'];

        // Prepend SSL wrapper if needed
        if ($this->smtpConfig['encryption'] === 'ssl') {
            $host = "ssl://{$host}";
        }

        $this->smtpConnection = fsockopen($host, $port, $errno, $errstr, $timeout);

        if (!$this->smtpConnection) {
            $this->debugLog .= "Connection error: {$errno} - {$errstr}\n";
            return false;
        }

        $response = $this->getSmtpResponse();
        if (!$this->checkSmtpResponse($response, 220)) {
            return false;
        }

        // Send EHLO
        $this->sendSmtpCommand("EHLO " . gethostname());
        $response = $this->getSmtpResponse();
        if (!$this->checkSmtpResponse($response, 250)) {
            return false;
        }

        // Upgrade to TLS if required
        if ($this->smtpConfig['encryption'] === 'tls') {
            $this->sendSmtpCommand("STARTTLS");
            $response = $this->getSmtpResponse();
            if (!$this->checkSmtpResponse($response, 220)) {
                return false;
            }

            if (!stream_socket_enable_crypto($this->smtpConnection, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $this->debugLog .= "Failed to enable TLS\n";
                return false;
            }

            // Re-send EHLO after TLS handshake
            $this->sendSmtpCommand("EHLO " . gethostname());
            $response = $this->getSmtpResponse();
            if (!$this->checkSmtpResponse($response, 250)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Authenticates with the SMTP server.
     *
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
     * Sends the email data through the established SMTP connection.
     *
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

        // RCPT TO (one per recipient)
        foreach ($this->to as $recipient) {
            $this->sendSmtpCommand("RCPT TO: <{$recipient}>");
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

        // Send headers, body and end-of-data marker
        $this->sendSmtpCommand($this->buildSmtpEmail());
        $this->sendSmtpCommand(".");

        $response = $this->getSmtpResponse();
        return $this->checkSmtpResponse($response, 250);
    }

    /**
     * Builds the full email message string for SMTP transmission.
     *
     * @return string
     */
    protected function buildSmtpEmail(): string
    {
        $from  = $this->fromName ? "{$this->fromName} <{$this->from}>" : $this->from;
        $email = "From: {$from}\r\n";
        $email .= "To: " . implode(', ', $this->to) . "\r\n";
        $email .= "Subject: {$this->subject}\r\n";
        $email .= "Date: " . date('r') . "\r\n";

        if (empty($this->attachments)) {
            $email .= 'Content-Type: ' . ($this->isHtml ? 'text/html' : 'text/plain') . "; charset=UTF-8\r\n";
            $email .= "\r\n";
            $email .= $this->message;
        } else {
            $boundary = md5(uniqid((string) time()));
            $email   .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n";

            // Message body part
            $email .= "--{$boundary}\r\n";
            $email .= 'Content-Type: ' . ($this->isHtml ? 'text/html' : 'text/plain') . "; charset=UTF-8\r\n\r\n";
            $email .= $this->message . "\r\n";

            // Attachment parts
            foreach ($this->attachments as $filePath) {
                $filename = basename($filePath);
                $fileData = chunk_split(base64_encode((string) file_get_contents($filePath)));

                $email .= "--{$boundary}\r\n";
                $email .= "Content-Type: application/octet-stream; name=\"{$filename}\"\r\n";
                $email .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n";
                $email .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $email .= $fileData;
            }

            $email .= "--{$boundary}--";
        }

        return $email;
    }

    /**
     * Sends a command to the SMTP server.
     *
     * @param  string $command
     * @return void
     */
    protected function sendSmtpCommand(string $command): void
    {
        fwrite($this->smtpConnection, $command . "\r\n");
        $this->debugLog .= ">> {$command}\n";
    }

    /**
     * Reads and returns the SMTP server's response.
     *
     * @return string
     */
    protected function getSmtpResponse(): string
    {
        $response = '';
        while (($line = fgets($this->smtpConnection, 515)) !== false) {
            $response .= $line;
            // A space in position 3 signals the last line of the response
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        $this->debugLog .= "<< {$response}";
        return trim($response);
    }

    /**
     * Checks whether the SMTP response matches the expected status code.
     *
     * @param  string $response
     * @param  int    $expectedCode
     * @return bool
     */
    protected function checkSmtpResponse(string $response, int $expectedCode): bool
    {
        return (int) substr($response, 0, 3) === $expectedCode;
    }

    /**
     * Sends QUIT and closes the SMTP connection.
     *
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
     * Returns the accumulated SMTP debug log.
     *
     * @return string
     */
    public function getDebugLog(): string
    {
        return $this->debugLog;
    }

    /**
     * Clears the SMTP debug log.
     *
     * @return void
     */
    public function clearDebugLog(): void
    {
        $this->debugLog = '';
    }
}
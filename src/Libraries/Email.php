<?php

/**
 * |--------------------------------------------------------------------------
 * | Classe Email
 * |--------------------------------------------------------------------------
 * |
 * | Classe responsável pelo envio de e-mails usando funções nativas do PHP.
 * | Suporta envio com HTML, múltiplos destinatários, e anexos.
 * |
 */

declare(strict_types=1);

namespace Slenix\Libraries;

/**
 * Class Email
 *
 * Facilita o envio de e-mails, abstraindo a complexidade da função mail() do PHP.
 * Permite a construção de e-mails de forma fluente (method chaining).
 */
class Email
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
     * Construtor da classe Email.
     *
     * Inicializa a classe e define cabeçalhos padrão essenciais para o envio.
     */
    public function __construct()
    {
        // Define cabeçalhos padrão
        $this->headers[] = 'MIME-Version: 1.0';
    }

    /**
     * Define o remetente do e-mail.
     *
     * @param string $email O endereço de e-mail do remetente.
     * @param string $name  O nome do remetente (opcional).
     * @return self Retorna a própria instância da classe para encadeamento de métodos.
     */
    public function from(string $email, string $name = ''): self
    {
        $this->from = $email;
        $this->fromName = $name;
        return $this;
    }

    /**
     * Adiciona um destinatário ao e-mail.
     *
     * @param string $email O endereço de e-mail do destinatário a ser adicionado.
     * @return self Retorna a própria instância da classe para encadeamento de métodos.
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
     *
     * @param string $subject O texto do assunto.
     * @return self Retorna a própria instância da classe para encadeamento de métodos.
     */
    public function subject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    /**
     * Define a mensagem (corpo) do e-mail.
     *
     * @param string $message O conteúdo da mensagem.
     * @param bool   $isHtml  Define se a mensagem deve ser interpretada como HTML. O padrão é `false`.
     * @return self Retorna a própria instância da classe para encadeamento de métodos.
     */
    public function message(string $message, bool $isHtml = false): self
    {
        $this->message = $message;
        $this->isHtml = $isHtml;

        if ($isHtml) {
            $this->headers[] = 'Content-Type: text/html; charset=UTF-8';
        } else {
            $this->headers[] = 'Content-Type: text/plain; charset=UTF-8';
        }

        return $this;
    }

    /**
     * Anexa um arquivo ao e-mail.
     *
     * @param string $filePath O caminho completo para o arquivo a ser anexado.
     * @return self Retorna a própria instância da classe para encadeamento de métodos.
     */
    public function attach(string $filePath): self
    {
        if (file_exists($filePath)) {
            $this->attachments[] = $filePath;
        }
        return $this;
    }

    /**
     * Envia o e-mail.
     *
     * Compila todas as informações (destinatários, remetente, assunto, mensagem e anexos)
     * e utiliza a função `mail()` do PHP para realizar o envio.
     *
     * @return bool Retorna `true` se o e-mail foi aceito para entrega, `false` caso contrário.
     */
    public function send(): bool
    {
        if (empty($this->to) || empty($this->from) || empty($this->subject) || empty($this->message)) {
            return false;
        }

        $to = implode(', ', $this->to);
        $from = $this->fromName ? "{$this->fromName} <{$this->from}>" : $this->from;
        $this->headers[] = "From: $from";

        // Se não houver anexos, envia um e-mail simples.
        if (empty($this->attachments)) {
            return mail($to, $this->subject, $this->message, implode("\r\n", $this->headers));
        }

        // Prepara para envio com anexos (multipart).
        $boundary = md5(uniqid((string)time()));

        $headers = $this->headers;
        $headers[] = "Content-Type: multipart/mixed; boundary=\"$boundary\"";

        // Corpo principal da mensagem
        $body = "--$boundary\r\n";
        $body .= "Content-Type: " . ($this->isHtml ? "text/html" : "text/plain") . "; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $body .= $this->message . "\r\n";

        // Adiciona cada anexo ao corpo do e-mail.
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
}
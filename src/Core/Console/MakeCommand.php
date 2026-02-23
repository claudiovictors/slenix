<?php

declare(strict_types=1);

namespace Slenix\Core\Console;


class MakeCommand extends Command
{
    private array $args;

    public function __construct(array $args)
    {
        $this->args = $args;
    }

    /**
     * Gera um APP_KEY seguro e salva no .env.
     *
     * Usa random_bytes(32) → base64 com prefixo "base64:" (padrão seguro).
     * Se o .env não existir, oferece clonar do .env.example.
     */
    public static function generateKey(): void
    {
        $envPath     = self::basePath('.env');
        $examplePath = self::basePath('.env.example');

        // Se o .env não existe, tenta clonar do .env.example
        if (!file_exists($envPath)) {
            if (file_exists($examplePath)) {
                if (!copy($examplePath, $envPath)) {
                    self::error("Não foi possível criar o .env a partir do .env.example.");
                    return;
                }
                self::info(".env criado a partir do .env.example");
            } else {
                self::error("Arquivo .env não encontrado. Crie-o primeiro.");
                return;
            }
        }

        // Gera chave criptograficamente segura
        $key = 'base64:' . base64_encode(random_bytes(32));

        $content = file_get_contents($envPath);
        if ($content === false) {
            self::error("Não foi possível ler o arquivo .env.");
            return;
        }

        // Substitui APP_KEY existente (com ou sem valor)
        if (preg_match('/^APP_KEY\s*=.*$/m', $content)) {
            $updated = preg_replace('/^(APP_KEY\s*=).*$/m', "APP_KEY={$key}", $content);
        } else {
            // Adiciona logo após APP_NAME se não existir
            $updated = preg_replace(
                '/^(APP_NAME\s*=.*)$/m',
                "$1\nAPP_KEY={$key}",
                $content,
                1
            );
        }

        if ($updated === null || $updated === $content) {
            self::error("Não foi possível atualizar o APP_KEY no .env.");
            return;
        }

        if (file_put_contents($envPath, $updated) === false) {
            self::error("Não foi possível escrever no .env.");
            return;
        }

        self::success("APP_KEY gerado e salvo no .env");
        self::info("Chave: {$key}");
    }


    public function makeModel(): void
    {
        if (count($this->args) < 3) {
            self::error('Nome do model é obrigatório.');
            self::info('Exemplo: php celestial make:model User');
            exit(1);
        }

        $modelName = ucfirst($this->args[2]);
        $tableName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $modelName)) . 's';
        $filePath = APP_PATH . '/Models/' . $modelName . '.php';

        $this->ensureFileDoesNotExist($filePath, $modelName, 'Model');

        $template = <<<EOT
<?php

declare(strict_types=1);

namespace App\Models;

use Slenix\Supports\Database\Model;

class {$modelName} extends Model
{
    protected string \$table = '{$tableName}';
    protected string \$primaryKey = 'id';
    protected array \$fillable = [];
}
EOT;

        $this->createFile($filePath, $template, $modelName, 'Model');
    }


    public function makeController(): void
    {
        if (count($this->args) < 3) {
            self::error('Nome do controller é obrigatório.');
            self::info('Exemplo: php celestial make:controller Home');
            exit(1);
        }

        // Remove a posição do --resource se existir para pegar o nome corretamente
        $controllerName = ucfirst($this->getControllerName());
        $filePath = APP_PATH . '/Controllers/' . $controllerName . '.php';

        $this->ensureFileDoesNotExist($filePath, $controllerName, 'Controller');

        $template = <<<EOT
<?php

declare(strict_types=1);

namespace App\Controllers;

use Slenix\Http\Request;
use Slenix\Http\Response;

class {$controllerName}
{
    public function index(Request \$request, Response \$response)
    {
        // A sua lógica a aplicação
    }
}
EOT;

        $this->createFile($filePath, $template, $controllerName, 'Controller');
    }

    /**
     * Obtém o nome do controller dos argumentos, ignorando flags
     */
    private function getControllerName(): string
    {
        // Procura por argumentos que não sejam flags (não começam com --)
        for ($i = 2; $i < count($this->args); $i++) {
            if (!str_starts_with($this->args[$i], '--')) {
                return $this->args[$i];
            }
        }

        self::error('Nome do controller é obrigatório.');
        self::info('Exemplo: php celestial make:controller Home');
        exit(1);
    }

    private function ensureFileDoesNotExist(string $path, string $name, string $type): void
    {
        if (file_exists($path)) {
            self::error("O {$type} '{$name}' já existe em {$path}.");
            exit(1);
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            self::error("Não foi possível criar o diretório {$dir}.");
            exit(1);
        }
    }

    public function makeMiddleware(): void
    {
        if (count($this->args) < 3) {
            self::error('Nome do middleware é obrigatório.');
            self::info('Exemplo: php celestial make:middleware Auth');
            exit(1);
        }

        $middlewareName = ucfirst($this->args[2]);
        if (!str_ends_with($middlewareName, 'Middleware')) {
            $middlewareName .= 'Middleware';
        }

        $filePath = APP_PATH . '/Middlewares/' . $middlewareName . '.php';

        $this->ensureFileDoesNotExist($filePath, $middlewareName, 'Middleware');

        $template = <<<EOT
<?php
/*
|--------------------------------------------------------------------------
| Classe {$middlewareName}
|--------------------------------------------------------------------------
|
| Este middleware [descreva a funcionalidade do middleware aqui].
|
*/

declare(strict_types=1);

namespace App\Middlewares;

use Slenix\Http\Request;
use Slenix\Http\Response;
use Slenix\Http\Middlewares\Middleware;

class {$middlewareName} implements Middleware
{
    /**
     * Handle da requisição através do middleware.
     *
     * @param Request \$request A requisição HTTP.
     * @param Response \$response A resposta HTTP.
     * @param array \$params Parâmetros da rota.
     * @return bool Retorna true para continuar, false para interromper.
     */
    public function handle(Request \$request, Response \$response, callable \$next): mixed
    {
        // Lógica do middleware aqui
        
        // Exemplo: verificar alguma condição
        // if (!\$someCondition) {
        //     \$response->status(403)->json(['error' => 'Forbidden']);
        //     return false;
        // }

        return \$next(\$request, \$response); // Continua a execução
    }
}
EOT;

        $this->createFile($filePath, $template, $middlewareName, 'Middleware');
    }

    private function createFile(string $path, string $content, string $name, string $type): void
    {
        if (file_put_contents($path, $content) === false) {
            self::error("Falha ao criar {$type} '{$name}' em {$path}.");
            exit(1);
        }

        self::success("{$type} '{$name}' criado com sucesso em:");
        echo "  {$path}" . PHP_EOL;
    }

    /**
     * Resolve caminho absoluto a partir da raiz do projeto.
     * @param string $relative
     * @return string
     */
    private static function basePath(string $relative): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . ltrim($relative, '/\\');
    }
}

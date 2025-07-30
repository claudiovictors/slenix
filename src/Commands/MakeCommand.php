<?php

declare(strict_types=1);

namespace Slenix\Commands;

class MakeCommand extends Command
{
    private array $args;

    public function __construct(array $args)
    {
        $this->args = $args;
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
        $filePath = __DIR__ .'/../../app/Models/' . $modelName . '.php';

        $this->ensureFileDoesNotExist($filePath, $modelName, 'Model');

        $template = <<<EOT
<?php

declare(strict_types=1);

namespace App\Models;

use Slenix\Database\Model;

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

        $controllerName = ucfirst($this->args[2]);
        $filePath = __DIR__ .'/../../app/Controllers/' . $controllerName . '.php';

        $this->ensureFileDoesNotExist($filePath, $controllerName, 'Controller');

        $template = <<<EOT
<?php

declare(strict_types=1);

namespace App\Controllers;

use Slenix\Http\Message\Request;
use Slenix\Http\Message\Response;

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

    private function createFile(string $path, string $content, string $name, string $type): void
    {
        if (file_put_contents($path, $content) === false) {
            self::error("Falha ao criar {$type} '{$name}' em {$path}.");
            exit(1);
        }

        self::success("{$type} '{$name}' criado com sucesso em:");
        echo "  {$path}" . PHP_EOL;
    }
}
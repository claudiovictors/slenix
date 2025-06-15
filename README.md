
<p align="center">
  <img src="public/logo.svg" alt="Slenix Logo" width="150">
</p>

<h1 align="center">Slenix Framework</h1>
<p align="center">Um microframework PHP leve e elegante baseado no padrão MVC</p>
<p align="center">
  <a href="https://github.com/claudiovictors/slenix.git"><img src="https://img.shields.io/github/stars/claudiovictors/slenix?style=social" alt="GitHub Stars"></a>
  <a href="https://packagist.org/packages/slenix/slenix"><img src="https://img.shields.io/packagist/v/slenix/slenix" alt="Packagist Version"></a>
  <a href="https://github.com/claudiovictors/slenix?tab=MIT-1-ov-file"><img src="https://img.shields.io/github/license/claudiovictors/slenix" alt="License"></a>
  <img src="https://img.shields.io/badge/PHP-8.0%2B-blue" alt="PHP Version">
</p>

---

## 📦 Sobre o Slenix

O **Slenix Framework** é um microframework PHP projetado para desenvolvedores que buscam simplicidade, desempenho e organização. Baseado na arquitetura MVC (Model-View-Controller), ele fornece recursos essenciais como roteamento dinâmico, ORM integrado, motor de templates, e uma poderosa CLI chamada **Celestial**.

---

## ✨ Recursos Principais

- **Roteamento simples**: Rotas com parâmetros dinâmicos e suporte a grupos.
- **ORM integrado**: Gerencie o banco de dados com modelos orientados a objetos.
- **Motor de Templates**: Crie views dinâmicas com uma sintaxe clara e elegante.
- **Celestial CLI**: Crie Controllers, Models e inicie o servidor com comandos simples.
- **Upload de arquivos**: Realize uploads de forma prática e segura.
- **Leve e rápido**: Sem dependências pesadas, ideal para projetos pequenos e médios.

---

## ✅ Pré-requisitos

- PHP 7.4 ou superior
- Extensão PDO habilitada
- Composer (recomendado)
- Servidor Web (ou use o servidor embutido com `celestial serve`)

---

## 🚀 Instalação

1. **Instalar via Composer:**
   ```bash
   composer require slenix/slenix
   ```

2. **Ou clonar o repositório:**
   ```bash
   git clone https://github.com/claudiovictors/slenix.git
   ```

3. **Criar projeto com Composer:**
   ```bash
   composer create-project slenix/slenix nome-do-projeto
   ```

4. **Instalar as dependências:**
   ```bash
   composer install
   ```

---

## 🔥 Iniciando o servidor embutido

Execute:

```bash
php celestial serve
```

Acesse `http://127.0.0.1:8080` no navegador.

> ⚠️ **Importante**: Caso utilize Apache ou Nginx, defina a pasta `public/` como raiz do documento.

---

## 🛠 Primeiros Passos

### Definindo Rotas

Edite `routes/web.php`:

```php
use Slenix\Http\Message\Router;

Router::get('/', function($request, $response, $param){
    $response->write('Hello, Slenix');
});

Router::get('/user/{id}', function ($request, $response, $params) {
    $response->json(['id' => $params['id'], 'name' => 'Slenix']);
});
```

### Grupos de Rotas

```php
Router::group(['prefix' => '/api'], function () {
    Router::get('/users', function ($request, $response) {
        $users = User::all();
        return $response->json(['users' => $users]);
    });
});
```

### Middlewares

```php
use Slenix\Middlewares\AuthMiddleware;

Router::get('/profile/{user_id}', function($request, $response, $param){
    $user = User::find($param['user_id']);

    if (!$user) {
        return $response->status(404)->json(['message' => 'Usuário não encontrado']);
    }

    return $response->json(['user' => $user]);
}, [AuthMiddleware::class]);
```

---

## 🖼 Motor de Templates

Crie views com sintaxe semelhante ao Blade:

```php
Router::get('/users/{user_id}', function ($req, $res, $args) {
    $user = User::find($args['user_id']);

    if (!$user) {
        return $res->status(404)->json(['message' => 'Usuário não encontrado']);
    }

    return view('pages.user', compact('user'));
});
```

**Exemplo de View (`views/pages/user.php`):**

```php
<h1>Perfil do Usuário</h1>

@if ($user)
    <h2>{{ $user->name }}</h2>
    <p>Email: {{ $user->email }}</p>
@else
    <p>Usuário não encontrado.</p>
@endif

@foreach ($user->posts as $post)
    <div>
        <h3>{{ $post->title }}</h3>
        <p>{{ $post->content }}</p>
    </div>
@endforeach
```

---

## 📧 Envio de E-mails

O Slenix suporta envio de e-mails via SMTP (ex: Gmail, Outlook). Requisitos recomendados:

- `msmtp` ou `postfix`

### Exemplo de envio:

```php
$email = new Email();

$enviado = $email
    ->form('contato@slenix.com', 'Equipe Slenix')
    ->to('user@example.com')
    ->subject('Bem-vindo ao Slenix!')
    ->message('<h1>Olá!</h1><p>Obrigado por escolher o Slenix.</p>')
    ->send();

if ($enviado) {
    echo 'E-mail enviado com sucesso!';
} else {
    echo 'Erro ao enviar e-mail!';
}
```

---

## ⚙️ Usando a Celestial CLI

Alguns comandos úteis:

- **Iniciar servidor:**
  ```bash
  php celestial serve
  ```

- **Criar Controller:**
  ```bash
  php celestial make:controller UserController
  ```

- **Criar Model:**
  ```bash
  php celestial make:model User
  ```

- **Listar comandos:**
  ```bash
  php celestial list
  ```

---

## 🗃 Configuração do Banco de Dados

Edite o arquivo `.env`:

```env
APP_DEBUG=true
APP_URL=http://localhost:8080

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_NAME=slenix_db
DB_USERNAME=root
DB_PASSWORD=senha
```

> 💡 Defina `APP_DEBUG=true` para exibir erros detalhados durante o desenvolvimento.

---

## 📄 Licença

Distribuído sob a licença MIT. Consulte o arquivo [LICENSE](https://github.com/claudiovictors/slenix/blob/main/LICENSE) para mais informações.

<p align="center">Feito com 🖤 por <a href="https://github.com/claudiovictors">Cláudio Victor</a></p>

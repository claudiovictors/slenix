<p align="center">
  <img src="public/logo.svg" alt="Slenix Logo" width="150">
</p>

<h1 align="center">Slenix Framework</h1>
<p align="center">Um micro framework PHP leve e elegante baseado no padrão MVC</p>
<p align="center">
  <a href="https://github.com/claudiovictors/slenix.git"><img src="https://img.shields.io/github/stars/claudiovictors/framework-luna?style=social" alt="GitHub Stars"></a>
  <a href="https://packagist.org/packages/slenix/slenix"><img src="https://img.shields.io/packagist/v/luna/luna.svg" alt="Packagist Version"></a>
  <a href="https://github.com/claudiovictors/slenix.git/blob/main/LICENSE"><img src="https://img.shields.io/github/license/claudiovictors/framework-luna" alt="License"></a>
  <img src="https://img.shields.io/badge/PHP-8.0%2B-blue" alt="PHP Version">
</p>

## Sobre o Slenix

O **Slenix Framework** é um micro framework PHP projetado para desenvolvedores que buscam simplicidade e desempenho. Com uma arquitetura MVC (Model-View-Controller), ele oferece ferramentas essenciais como roteamento dinâmico, ORM integrado, motor de templates personalizado e uma CLI chamada **Celestial** para agilizar o desenvolvimento de aplicações web e APIs.

### Recursos Principais
- **Roteamento Simples**: Defina rotas com suporte a parâmetros dinâmicos e grupos.
- **ORM Integrado**: Gerencie seu banco de dados com facilidade usando modelos intuitivos.
- **Templates**: Crie views dinâmicas com uma sintaxe limpa e poderosa.
- **Celestial CLI**: Crie Models, Controllers e inicie o servidor com um único comando.
- **Leve e Rápido**: Sem dependências pesadas, ideal para projetos de pequeno a médio porte.
- **Upload de Arquivos** Faça ulploads de arquivos com slenix de forma simples e fácil.
---

## Pré-requisitos

- PHP 8.0 ou superior
- Extensão PDO habilitada (para o ORM)
- Composer (opcional, mas recomendado para autoload)
- Servidor web (ou use o servidor embutido do PHP via `celestial serve`)

---

## Instalação

1. **Instale via Composer:**
   ```bash
   composer require slenix/slenix
2. **Clone o repositório:**
   ```bash
   git clone https://github.com/claudiovictors/slenix.git
---
3. **Criar projecto comcomposer:**
   ```bash
   composer create-project slenix/slenix [project-name]
---

4. **Configurar o Ambiente:**
   ```bash
   cp .env.example .env
---
O Slenix usa um arquivo `.env` para configurações de ambiente
Execute o comando acima para copiar o arquivo `.env.example para .env`:

5. **Instale as dependências:**
   ```bash
   composer install
---

## Inicie o servidor embutido

6. **Abra o seu terminal e execute esse comando:**
    ```bash
    php celestial serve
Acesse http://127.0.0.1:8080 no navegador para ver a página de boas-vindas.

**Nota**: *Se você estiver usando um servidor web como Apache ou Nginx, configure o diretório public/ como raiz do documento.*



## Primeiros Passos

### Definindo Rotas

Edite o arquivo `routes/web.php` para criar rotas simples e dinâmicas:

```php
use Slenix\Http\Message\Router;

Router::get('/', function($request, $response, $param){
    $response->write('Hello, Slenix');
});

Router::get('/user/{id}', function ($request, $response, $params) {
    $response->json(['id' => $params['id'], 'name' => 'Slenix']);
});
```

## Grupos de Rotas

Organize rotas relacionadas usando grupos com prefixos ou middlewares:

```php
Router::group(['prefix' => '/api'], function () {
    Router::get('/users', function ($request, $response) {
        $allUsers = User::all();
        return $response->json([
            'users' => $allUsers
        ]);
    });
});
```

## Rotas com Middlewares

Protegas as suas rotas com os `Middlewares`

```php
use Slenix\Http\Message\Router;
use Slenix\Middlewares\AuthMiddleware;

Router::get('/profile/{user_id}', function($request, $response, $param){
    $user = $param['user_id'];

    $user_id = User::find($user);

    if(!$user_id):
        $response->status(404)->json(['message' => 'User not Exist']);
    endif;

    $response->status(200)->json(['user' => $user_id]);

}, [AuthMiddleware::class]);
```
## Usando o Motor de Templates

O Slenix suporta um motor de templates com sintaxe inspirada no Blade. Crie views dinâmicas com facilidade.

**Exemplo de Rota com View:**

```php

Router::get('/users/{user_id}', function ($req, $res, $args) {
    $user = User::find($args['user_id']);

    if (!$user):
        $res->status(404)->json(['message' => 'Usuário não encontrado!']);
    endif;

    return view('pages.user', compact('user'));
});
```

Exemplo de View (`views/pages/user.php`):

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
## Usando a Celestial CLI

A **Celestial CLI** ajuda a agilizar o desenvolvimento com comandos úteis. Aqui estão alguns exemplos:

**Iniciar o Servidor**
```bash
php celestial serve
```
**Crie um Controller**
```bash
php celestial make:controller UserController
```
**Crie um Model**
```bash
php celestial make:model User
```

**Ver Todos os Comandos Disponíveis**
```bash
php celestial list
```

## Configuração do Banco de Dados

O Slenix suporta um ORM integrado para facilitar o acesso ao banco de dados. Configure sua conexão editando o arquivo `.env` na raiz do projeto:

```env
# Configurações Gerais
APP_DEBUG=false
APP_URL=http://localhost:8080

# Conexão com Banco de Dados
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_NAME=slenix_db
DB_USERNAME=seu_usuario
DB_PASSWORD=sua_senha
```
**Nota:** Configure o `APP_DEBUG` se estiver como `false` paçapara `true` para habilitar a tela de erros.

## Licença
Licenciado sob a MIT License (LICENSE).
<p align="center">Feito com 🖤 por <a href="https://github.com/claudiovictors">Cláudio Victor</a></p>


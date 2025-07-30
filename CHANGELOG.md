# Changelog

Todas as alterações notáveis deste projeto serão documentadas neste arquivo.

O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/), e este projeto adere ao [SemVer](https://semver.org/lang/pt-BR/).

## [1.0.0] - 2025-06-13
### Adicionado
- Primeira versão estável do Slenix.
- Suporte a MVC completo.
- Roteador com grupos e middlewares.
- ORM básico com suporte a MySQL.
- Motor de templates inspirado no Blade.
- CLI "Celestial" para criação de controllers, models e iniciar o servidor.
- Sistema de envio de e-mails via SMTP.
- Documentação inicial com exemplos práticos.

### Modificado
- Estrutura reorganizada para PSR-4 via Composer.
- Carregamento automático com `Helpers.php`.

### Corrigido
- Problemas menores de roteamento e namespaces no autoloader.

---

## [Unreleased]
- Suporte a validação de requisições.
- Testes automatizados.
- Integração com Redis e Cache.

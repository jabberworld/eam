# Правила проекта EAM

## Назначение

Standalone PHP-аналог WordPress-плагина `Ejabberd Account Tools`.
Оригинал: GPLv3, версия 2.11, `https://wordpress.org/plugins/ejabberd-account-tools/`.
Наш проект не использует WordPress и не копирует его код; реализация написана
самостоятельно на PHP 8.2+.

## Обязательные правила

1. Все пояснения пользователю — **на русском языке**. Код, имена файлов,
   команды и идентификаторы не переводить.
2. После изменений кода в build-сессии делать **коммит в локальный git**.
   Автор коммита: `opencode (deepseek-v4.1-flash)`.
   Настраивать через `git -c user.name='opencode (deepseek-v4.1-flash)' ...`
   или локальный `git config`, не глобальный.
3. Максимально сохранять промежуточные результаты: решения, вопросы и ответы
   пользователя, результаты проверок — в `docs/PROGRESS.md`.
   После каждого этапа обновлять этот файл.
4. Секреты (пароли ejabberd, SMTP, OAuth) — только в `_app/config/config.php`
   (или внешнем `../config.php`), которые исключены из git. В git попадает
   только `_app/config/config.example.php`.
5. Тестовые компоненты (static PHP, Mailpit, временные БД) — в `.testenv/`
   и `tests/tmp/`, оба каталога исключены из git.
6. Не удалять существующие аккаунты на тестовом ejabberd. Для экспериментов
   создавать только одноразовые `eamtest_*` (или `eamtest...`) и удалять
   только их.
7. Не логировать пароли, OAuth-токены и полные тексты писем. Email в логах
   маскировать.
8. Пользовательские строки — только через `_app/lang/*.php`; новые ключи добавлять
   во все четыре языка (en, ru, uk, pl) сразу.
9. HTML экранировать через `e()`. SQL — только через prepared statements
   SQLite3. Заголовки писем очищать от CR/LF.
10. Все изменяющие действия — POST + CSRF. Одноразовые токены хранить в виде
    HMAC-хеша; в ссылке — только сырой токен.

## Code Brain MCP

Правила workspace требуют использовать Code Brain MCP для существенных задач:
`start_task(runIntake=true)`/`neural_sync`, `agent_plan`, `memory_retrieve`,
`uncertainty_guard`, `memory_store`. Не дублировать внешние MCP.

## Команды

```bash
# синтаксис
.testenv/php/php -l index.php

# unit-тесты
.testenv/php/php tests/run.php

# integration-тесты (fake ejabberd + Mailpit)
tests/integration.sh

# установка в подкаталоге: EAM_BASE_PATH и /eam/api/captcha
tests/subdir.sh

# smoke против реального тестового ejabberd (нужен .testenv/config.real.php)
tests/real-smoke.sh

# dev-сервер
PHP_BIN=.testenv/php/php server/serve.sh
```

## Git

- Локальный репозиторий в корне проекта.
- Один коммит на логический этап.
- Сообщения на английском, кратко: `feat: ...`, `test: ...`, `docs: ...`.
- Не коммитить `_app/config/config.php`, `_app/storage/`, `.testenv/`, `tests/tmp/`.

<!-- CODE_BRAIN_MANDATORY -->
## Code Brain MCP - Mandatory when loaded

Use Code Brain MCP first for substantive tasks in this project.

- Start with `start_task(runIntake=true)` or `neural_sync`.
- Use `agent_plan` before `agent_code` for chunk and deep work.
- Use `memory_retrieve` at intake and `memory_store` at task end.
- Use `uncertainty_guard` before storing conclusions.
- Disable duplicate MCPs with `get_superseded_mcps`.
<!-- CODE_BRAIN_MANDATORY -->


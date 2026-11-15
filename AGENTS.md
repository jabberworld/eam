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
4. Секреты (пароли ejabberd, SMTP, OAuth) — только в `config/config.php`,
   который исключён из git. В git попадает только `config/config.example.php`.
5. Тестовые компоненты (static PHP, Mailpit, временные БД) — в `.testenv/`
   и `tests/tmp/`, оба каталога исключены из git.
6. Не удалять существующие аккаунты на тестовом ejabberd. Для экспериментов
   создавать только одноразовые `eamtest_*` (или `eamtest...`) и удалять
   только их.
7. Не логировать пароли, OAuth-токены и полные тексты писем. Email в логах
   маскировать.
8. Пользовательские строки — только через `lang/*.php`; новые ключи добавлять
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
.testenv/php/php -l public/index.php

# unit-тесты
.testenv/php/php tests/run.php

# integration-тесты (fake ejabberd + Mailpit)
tests/integration.sh

# shared-раскладка: _app, внешний/внутренний config.php, deny
tests/shared-layout.sh

# smoke против реального тестового ejabberd (нужен .testenv/config.real.php)
tests/real-smoke.sh

# сборка shared-пакета для public_html
bin/package-shared.sh

# dev-сервер
PHP_BIN=.testenv/php/php bin/serve.sh
```

## Git

- Локальный репозиторий в корне проекта.
- Один коммит на логический этап.
- Сообщения на английском, кратко: `feat: ...`, `test: ...`, `docs: ...`.
- Не коммитить `config/config.php`, `storage/`, `.testenv/`, `tests/tmp/`.

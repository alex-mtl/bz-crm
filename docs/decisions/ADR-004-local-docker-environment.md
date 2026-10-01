# ADR-004: Локальное окружение — собственный Docker Compose, тяжёлые каталоги на именованных томах

- **Статус:** принято
- **Дата:** 2026-09-30
- **Источник:** ТЗ §59–60

## Решение

Собственный `docker-compose.yml` вместо Laravel Sail. Сервисы по ТЗ §59:

| Сервис | Назначение |
|---|---|
| `app` | PHP 8.4-FPM (`docker/php/Dockerfile`) |
| `nginx` | веб-сервер, порт `APP_PORT` (8100) |
| `mysql` | MySQL 8.4, порт `FORWARD_DB_PORT` (3316), init-скрипт создаёт `bz_crm_testing` |
| `redis` | кэш, сессии, очереди, rate limiting; наружу не публикуется |
| `mailpit` | перехват исходящей почты, UI на `MAILPIT_UI_PORT` (8126) |
| `node` | Vite dev-сервер на `VITE_PORT` (5174) и сборка ассетов |
| `queue` | `queue:work redis` |
| `scheduler` | `schedule:work` |

`minio` и `meilisearch` не подключены: по ТЗ они опциональны и появятся в фазах, где понадобятся файлы и поиск.

### Почему не Sail
- Sail запускает приложение через `artisan serve` в одном контейнере; ТЗ §59 перечисляет отдельные `app` и `nginx`, что ближе к production.
- Скрипт `vendor/bin/sail` не работает в Git Bash на Windows.

### Почему `vendor/`, `node_modules/`, `storage/framework/` на именованных томах

Исходники bind-mount'ятся с диска Windows. Замеры до изменения:

| Операция | Bind-mount | Named volume |
|---|---|---|
| `php artisan --version` | 17,4 с (из них CPU 0,4 с) | 0,8 с |
| `GET /admin/login` (тёплый) | 6,5–20 с | 0,26–0,49 с |
| Полный прогон тестов | 93 с | 5–7 с |

Время уходило на файловые операции через границу Windows ↔ Linux VM, а не на CPU. Поэтому каталоги с тысячами файлов, которые не нужно редактировать руками, живут на томах Docker (Linux ФС):
- `vendor` — заполняется `composer install` при первом старте `app` (`docker/php/entrypoint.sh`); `queue`/`scheduler` ждут его готовности;
- `framework` — `storage/framework` (скомпилированные шаблоны, кэш, тестовые файлы);
- `node_modules` — для `node`.

Контейнер `node` монтирует тот же том `vendor` (read-only), потому что тема Filament импортирует CSS из `vendor/filament`.

## Последствия

- Каталог `vendor/` на хосте не используется контейнерами. Для автодополнения в IDE его можно обновить вручную (`composer install` на хосте не нужен — достаточно `docker compose cp app:/var/www/html/vendor ./`), либо настроить удалённый интерпретатор Docker в PhpStorm.
- Сброс зависимостей: `docker compose down` + `docker volume rm bz-crm-social_vendor`.
- Tailwind с явными `@source` (`source(none)`), чтобы не сканировать весь проект: иначе сборка шла 3 минуты вместо 1 секунды.

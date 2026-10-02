# Локальная разработка

Нужен только Docker Desktop. PHP, Composer, Node, MySQL, Redis на хост ставить не нужно.

## Первый запуск

```bash
cp .env.example .env
docker compose up -d
```

При первом старте контейнер `app` сам выполняет `composer install` в том `vendor` — это несколько минут. Готовность видна в логе:

```bash
docker compose logs -f app   # ждать «ready to handle connections»
```

Затем:

```bash
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

## Адреса

| Что | Адрес |
|---|---|
| Приложение / админ-панель | http://localhost:8100 → `/admin` |
| Состояние системы (JSON) | http://localhost:8100/health |
| Mailpit (перехваченная почта) | http://localhost:8126 |
| Vite dev-сервер | http://localhost:5174 |
| MySQL с хоста (для GUI-клиента) | `127.0.0.1:3316` |
| Reverb (WebSocket мессенджера) | `ws://localhost:8180` |

Порты меняются в `.env`: `APP_PORT`, `MAILPIT_UI_PORT`, `VITE_PORT`, `FORWARD_DB_PORT`, `REVERB_CLIENT_PORT`.

**Real-time (ADR-012).** В `.env` нужно заполнить `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` любыми случайными значениями (в `.env.example` они пустые) и перезапустить контейнеры `app` и `reverb`. Без Reverb поставьте `BROADCAST_CONNECTION=log` — мессенджер будет обновляться опросом раз в 20 секунд.

**Антивирус (ADR-012, Д-28).** По умолчанию отключён, контейнер не запускается. Чтобы включить: `docker compose --profile antivirus up -d clamav` (первый запуск скачивает базы сигнатур, несколько минут; контейнеру нужно около 1,5 ГБ памяти), затем под суперадмином — «Состояние системы» → «Антивирусная защита файлов» → «Включить». Отключается там же.

Локальный администратор создаётся сидером `LocalAdminSeeder` только в окружениях `local`/`testing`; логин и пароль — `SEED_ADMIN_EMAIL` / `SEED_ADMIN_PASSWORD` в `.env`.

## Повседневные команды

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed
docker compose exec app php artisan test
docker compose exec app composer check          # pint --test + phpstan + тесты
docker compose exec node npm run build          # production-сборка ассетов
docker compose logs -f queue                    # воркер очереди
```

`npm run dev` уже запущен в контейнере `node` (горячая перезагрузка).

## Особенности Windows

- Если в Git Bash команда получает абсолютный Linux-путь (`/var/www/...`), выполнять с `MSYS_NO_PATHCONV=1`, иначе Git Bash подменит его на Windows-путь.
- `vendor/`, `node_modules/`, `storage/framework/` лежат на томах Docker, а не на диске C: — иначе приложение работает в 20–30 раз медленнее (замеры — в [ADR-004](../decisions/ADR-004-local-docker-environment.md)). Папка `vendor/` на хосте контейнерами не используется.
- Сброс зависимостей: `docker compose down && docker volume rm bz-crm-social_vendor`.
- Полный сброс данных (БД, Redis, тома): `docker compose down -v`.

# API

> Требования: ТЗ §46–47.

Служебный эндпоинт — `GET /health` (см. [`architecture.md`](../architecture.md), раздел 6).

## API v1 (с фазы 4, [ADR-010](../decisions/ADR-010-social-visibility-groups-api.md))

Маршруты — `routes/api.php`, подключены в `bootstrap/app.php` с префиксом `/api/v1`, группой `web`, `auth` и троттлингом 120 запросов в минуту. Аутентификация пока — сеанс панели; токены для внешних клиентов появятся в фазе интеграций, контроллеры при этом не меняются.

| Метод | Путь | Что возвращает |
|---|---|---|
| `GET` | `/api/v1/feed` | Лента текущего пользователя. Параметры: `mode` (`all`, `important`, `groups`, `region`, `following`, `mine`), `search`, `group_id`, `territory_id`, `per_page` (1–50), `page`. Ответ: `{data: [пост…], meta: {page, per_page, has_more}}` |
| `GET` | `/api/v1/posts/{id}` | Один пост: `{data: пост}`. Невидимый или несуществующий пост — `404` (существование не раскрывается) |

Пост: `id`, `author {person_id, name}`, `body`, `status`, `visibility`, `audience[]`, `published_at`, `edited`, `hidden`, `repost_of`, `repost_unavailable`, `attachments[] {id, name, kind, size, url}`, `poll`, `reactions {код: число}`, `my_reaction`, `comments`, `pinned[]`.

### Приложение агитатора (с фазы 7, [ADR-013](../decisions/ADR-013-field-work-offline-maps.md))

| Метод | Путь | Что делает |
|---|---|---|
| `GET` | `/api/v1/field/session` | Кто вошёл и какой сейчас CSRF-токен — телефон спрашивает перед отправкой очереди |
| `GET` | `/api/v1/field/snapshot` | Дома, закреплённые за пользователем, с квартирами, статусы контакта, его собственные заметки. Нужно `geo.visits.create` |
| `POST` | `/api/v1/field/sync` | Очередь офлайн-операций: `{device_id, operations: [{operation_id, entity, entity_id, operation, payload, client_timestamp}]}`. Ответ по каждой: `applied` / `rejected`, признак `duplicate`. **Идемпотентно** по `operation_id` |
| `GET` | `/api/v1/field/location` | Идёт ли передача местоположения, до какого времени, разрешённые сроки |
| `POST` | `/api/v1/field/location/start`, `/stop` | Включить на `minutes` и остановить передачу — только свою |
| `POST` | `/api/v1/field/location` | Точка: `latitude`, `longitude`, `accuracy`, `recorded_at`. Принимается, только пока передача включена |

### Трекеры транспорта

`POST /api/v1/trackers/positions` — без сеанса; ключ трекера в заголовке `Authorization: Bearer trk_…`. Тело — одна точка (`latitude`, `longitude`, `recorded_at`, `accuracy`) или `{points: [...]}`. Ответ: `{stored: N}`; неизвестный ключ — `401`. Повторная отправка тех же точек ничего не добавляет. Лимит — 600 запросов в минуту.

Ошибки — JSON: `401` без сеанса, `403` без права `posts.read`, `422` при неверных параметрах, `429` при превышении лимита.

Правила для будущих эндпоинтов:
- префикс `/api/v1/`;
- аутентификация, авторизация через `AuthorizationService`, валидация через Form Requests, rate limiting, идемпотентность для изменяющих запросов, единый формат ошибок;
- эндпоинт отражает бизнес-возможность, а не таблицу БД;
- публичный сайт работает только через API, без доступа к БД.

Спецификация OpenAPI — в фазе 10, вместе с публичными формами и вебхуками; до тех пор эндпоинты описаны в этой таблице.

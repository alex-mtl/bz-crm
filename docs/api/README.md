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

Ошибки — JSON: `401` без сеанса, `403` без права `posts.read`, `422` при неверных параметрах, `429` при превышении лимита.

Правила для будущих эндпоинтов:
- префикс `/api/v1/`;
- аутентификация, авторизация через `AuthorizationService`, валидация через Form Requests, rate limiting, идемпотентность для изменяющих запросов, единый формат ошибок;
- эндпоинт отражает бизнес-возможность, а не таблицу БД;
- публичный сайт работает только через API, без доступа к БД.

Спецификация OpenAPI — в фазе 10, вместе с публичными формами и вебхуками; до тех пор эндпоинты описаны в этой таблице.

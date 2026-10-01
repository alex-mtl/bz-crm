# ADR-001: Laravel modular monolith

- **Статус:** принято
- **Дата:** 2026-09-30
- **Источник:** ТЗ §1, §2, §9, §10, §72, §85

## Контекст

Платформа объединяет CRM, мини-ERP, корпоративную соцсеть и мессенджер (ФО §0) с единой многослойной моделью доступа. ТЗ фиксирует архитектуру как Laravel modular monolith и прямо запрещает строить ядро на Frappe, ERPNext, OroCRM, WordPress, HumHub, Gameplan, Rocket.Chat, Moodle, Matrix или Chatwoot.

## Решение

- Одно Laravel-приложение, одна основная реляционная БД.
- Бизнес-модули — `app/Domain/<Module>` (21 модуль по ТЗ §9–10), адаптеры внешних систем — `app/Infrastructure/<Adapter>`, общий код — `app/Support`.
- Внутри модуля по мере роста: `Models`, `Actions`, `Services`, `Policies`, `Events`, `Listeners`, `Jobs`, `Notifications`, `Queries`, `Data`, `Enums`, `Exceptions`, `Filament`, `Http`, `Tests` (ТЗ §9).
- Filament, Livewire, HTTP-контроллеры — только слой представления; бизнес-логика — в Actions/Services/Query-классах (ТЗ §8, §61–63).
- Внешние системы — адаптеры, а не источники истины (ТЗ §2, §85).

## Как соблюдается автоматически

Архитектурные тесты `tests/Architecture/ModuleBoundariesTest.php`:
- `App\Domain` и `App\Infrastructure` не используют `App\Filament`, `App\Http`, `Filament`, `Livewire`;
- `App\Support` не зависит от `App\Domain`;
- strict types в `Domain`/`Infrastructure`/`Support`;
- запрет отладочных функций.

По мере появления модулей тесты расширяются правилами межмодульных зависимостей из [`docs/modules/dependency-graph.md`](../modules/dependency-graph.md).

## Последствия

- Нет сетевых границ между модулями — межмодульные вызовы обычные, поэтому дисциплину границ держат тесты и ревью, а не инфраструктура.
- Выделение модуля в отдельный сервис остаётся возможным (ТЗ §1), но не планируется в MVP.

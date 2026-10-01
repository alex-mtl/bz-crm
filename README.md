# BZ-CRM-Social

Внутренняя платформа организации: CRM, мини-ERP, корпоративная соцсеть и мессенджер с многоуровневой системой доступа.

Laravel 13 modular monolith · Filament 5 · Livewire 4 · MySQL 8.4 · Redis 7 · локальная разработка в Docker.

## Быстрый старт

```bash
cp .env.example .env
docker compose up -d                              # первый старт: composer install в контейнере, несколько минут
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

Приложение: http://localhost:8100 · Mailpit: http://localhost:8126

Подробно — [`docs/deployment/local-development.md`](docs/deployment/local-development.md).

## Проверки

```bash
docker compose exec app composer check   # Pint + Larastan + Pest
```

## Документация

| Документ | Что внутри |
|---|---|
| [`docs/functional-spec.md`](docs/functional-spec.md) | функциональное описание — ЧТО делает система |
| [`docs/technical-architecture-v1.md`](docs/technical-architecture-v1.md) | техническое задание — КАК она реализуется |
| [`docs/requirements-addendum.md`](docs/requirements-addendum.md) | решения заказчика после передачи ТЗ (регион = территория, демо-мир) |
| [`IMPLEMENTATION-PLAN.md`](IMPLEMENTATION-PLAN.md) | план реализации по фазам |
| [`docs/architecture.md`](docs/architecture.md) | текущая архитектура, стек, слои |
| [`docs/decisions/`](docs/decisions/) | архитектурные решения (ADR) |
| [`docs/modules/`](docs/modules/) | граф зависимостей модулей, логическая модель данных |
| [`docs/open-questions.md`](docs/open-questions.md) | неоднозначности и открытые вопросы |
| [`docs/security/`](docs/security/) | меры безопасности |

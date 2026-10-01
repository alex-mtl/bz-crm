# Логическая модель данных (первичная)

> Источник: ТЗ §11–12, §16, §18–28, §31, §35–36, §41–42; ФО §6–8. Это **логическая** модель: сущности и связи. Физическая схема (колонки, индексы) проектируется в фазе, где модуль реализуется, и фиксируется миграциями.

## Ядро: люди, учётные записи, территории, оргструктура, доступ, аудит

```mermaid
erDiagram
    PERSON ||--o| USER : "0..1 учётная запись"
    PERSON ||--o{ PROFILE_LAYER : "слои профиля"
    PERSON }o--o| ORG_UNIT : "0..1 подразделение (Д-11)"
    PERSON |o--o{ PERSON : "непосредственный начальник (Д-11)"
    PERSON ||--o{ TERRITORY_GRANT : "прямые назначения (Д-3)"
    TERRITORY ||--o{ TERRITORY_GRANT : ""
    USER ||--o{ TERRITORY_GRANT : "выдал / отозвал"
    ORG_UNIT ||--o{ ORG_UNIT : "дерево"
    ORG_UNIT }o--o{ TERRITORY : "0..N территорий (Д-3)"
    TERRITORY ||--o{ TERRITORY : "страна → регион → район → сектор → участок"
    USER ||--o{ USER_ROLE : ""
    ROLE ||--o{ USER_ROLE : ""
    ROLE ||--o{ ROLE_PERMISSION : ""
    PERMISSION ||--o{ ROLE_PERMISSION : ""
    USER_ROLE }o--o| SCOPE : "область действия"
    USER ||--o{ DELEGATION : "делегирование"
    USER ||--o{ EVENT_JOURNAL : "actor"

    PERSON {
        id id
        string type "сотрудник, волонтёр, кандидат, сторонник, заявитель, партнёр"
    }
    USER {
        id id
        id person_id "nullable, unique"
        string status "pending_approval, active, deactivated"
    }
    PROFILE_LAYER {
        string kind "public, internal, hr, psychology, security, feedback360 (тип: отзыв или личная заметка, Д-14)"
    }
    SCOPE {
        string axis "territory, org_unit, organization, group, own, subordinates"
        id root_id "узел Territory или OrgUnit, поддерево которого покрывается"
    }
    TERRITORY {
        string level "код из справочника уровней (Д-7): country, macro_region, unit, locality, sector, electoral_area…"
        string name_ro
        string name_ru "официальное, по Закону 764/2001 (Д-9)"
        string name_en
        string search_aliases "привычные формы, только для поиска"
        string source_key "slug / geonameid — ключ идемпотентного импорта"
    }
    TERRITORY_GRANT {
        id person_id
        id territory_id
        id granted_by_user_id
        string reason "обязательна (Д-3)"
        datetime expires_at "nullable; по сроку — автоотзыв (Д-3)"
        id revoked_by_user_id
        datetime revoked_at
    }
    EVENT_JOURNAL {
        string event_type "код из каталога"
        string category
        string severity
        string actor_type "user, system, job, automation, ai"
        id actor_user_id
        id actor_person_id
        string acting_as "own, delegation, impersonation"
        string subject_type
        id subject_id
        json old_values "с маскировкой"
        json new_values "с маскировкой"
        json context
        string ip_address
        string user_agent
        string request_id
        string correlation_id
        datetime occurred_at
    }
```

Ключевые правила:
- «Регион / район / сектор» — узлы `Territory`, а не подразделения (Д-1). Области прав имеют две независимые оси: территориальную и оргструктурную.
- `OrgUnit` привязывается к нескольким территориям или ни к одной (Д-3).
- Действующий территориальный доступ человека = территории его подразделения (сотрудник состоит ровно в одном, Д-11) ∪ действующие `TERRITORY_GRANT` (не отозванные и не истёкшие). Это вычисляемое значение, не хранимое поле; для быстрых выборок допустим кэш с инвалидацией по событиям (ТЗ §55).
- `EVENT_JOURNAL` — журнал событий ядра (Д-4, ADR-006): только добавление; запись — в одной транзакции с действием.
- `Person` существует без `User`; обратное невозможно после фазы 1 — у каждой учётной записи есть человек (самостоятельная регистрация всегда создаёт новую карточку, привязка к существующей — только вручную, Д-10).
- Конфиденциальные слои — отдельные сущности (`PersonInternalProfile`, `PersonHrProfile`, `PersonPsychologyNote`, `PersonSecurityNote`, `PersonFeedback360`), а не поля `people` (ТЗ §16). На схеме они обобщены как `PROFILE_LAYER`.

## Совместная работа

```mermaid
erDiagram
    PROJECT ||--o{ PROJECT_PHASE : ""
    PROJECT ||--o{ TASK : ""
    PROJECT_PHASE ||--o{ TASK : ""
    TASK ||--o{ TASK : "подзадачи"
    TASK ||--o{ TASK_ASSIGNEE : "1..N"
    TASK ||--o{ TASK_WATCHER : ""
    TASK ||--o{ CHECKLIST_ITEM : ""
    TASK ||--o{ TASK_STATUS_HISTORY : ""
    TASK ||--o{ TIME_ENTRY : ""
    TASK }o--o| CHAT : "обсуждение"
    CHAT ||--o{ CHAT_MEMBER : ""
    CHAT ||--o{ MESSAGE : ""
    MESSAGE ||--o{ MESSAGE : "parent_id (треды)"
    GROUP ||--o{ GROUP_MEMBERSHIP : ""
    GROUP }o--o| CHAT : "чат группы"
    POST ||--o{ COMMENT : ""
    COMMENT ||--o{ COMMENT : "вложенность"
    POST ||--o{ REACTION : ""
    POST ||--o{ POST_VISIBILITY : "scope"
    EVENT ||--o{ EVENT_ATTENDEE : "RSVP"
```

- Исполнители и наблюдатели задачи — `Person` с активной учётной записью `User` (Д-15); человек без учётной записи может быть только предметом задачи через `TaskRelation`.
- Задача может ссылаться на `Person`, `Project`, `ProjectPhase`, `House`, `Apartment`, `Event`, `Appeal`, `Lead`, `CustomObjectRecord` (ТЗ §24) — полиморфная связь `TaskRelation`.
- Лента — проекция над `Post`, без копий (ТЗ §18).

## CRM и коммуникации

```mermaid
erDiagram
    PERSON ||--o{ LEAD : ""
    PIPELINE ||--o{ PIPELINE_STAGE : ""
    PIPELINE_STAGE ||--o{ LEAD : ""
    LEAD ||--o{ LEAD_STAGE_HISTORY : ""
    PERSON ||--o{ APPEAL : ""
    PERSON ||--o{ INTERACTION : ""
    CHANNEL_ACCOUNT ||--o{ CONVERSATION : ""
    CONVERSATION ||--o{ CHANNEL_MESSAGE : ""
    PERSON ||--o{ CONVERSATION : ""
```

## Гео, обучение, геймификация, платформа

Дерево `Territory` относится к ядру (см. выше, фаза 2). Полевая часть Geo добавляет к нему:

```mermaid
erDiagram
    TERRITORY ||--o{ HOUSE : "дома в избирательном участке"
    HOUSE ||--o{ APARTMENT : ""
    APARTMENT ||--o{ VISIT : ""
    GEO_ZONE }o--o{ TERRITORY : ""
    COURSE ||--o{ LESSON : ""
    COURSE ||--o{ COURSE_ENROLLMENT : ""
    QUIZ ||--o{ QUIZ_ATTEMPT : ""
    PERSON ||--o{ XP_EVENT : ""
    PERSON ||--o{ MENTORSHIP : "наставник / новичок"
    AUTOMATION_RULE ||--o{ AUTOMATION_RUN : ""
    AUTOMATION_RUN ||--o{ AUTOMATION_STEP_RUN : ""
    CUSTOM_OBJECT_TYPE ||--o{ CUSTOM_FIELD : ""
    CUSTOM_OBJECT_TYPE ||--o{ CUSTOM_OBJECT_RECORD : ""
```

- `XpEvent` всегда хранит `source_event`, `actor`, `reason`, `amount` (ТЗ §36).
- `DataEvent` (структурированные факты, ФО §5.3) привязывается к любому объекту полиморфно.

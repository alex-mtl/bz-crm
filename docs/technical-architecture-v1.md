# BZ-CRM-Social
## Technical Specification for Claude Code
### Laravel Modular Monolith Architecture

**Version:** 1.0  
**Date:** 2026-09-26  
**Status:** Development specification  
**Functional source:** `functional-spec.md` v0.1  
**Architecture:** Laravel Modular Monolith  
**Primary database:** MySQL 8.x  
**Primary language:** PHP 8.3+  
**Target development workflow:** Claude Code / AI-assisted development

---

# 0. IMPORTANT INSTRUCTIONS FOR CLAUDE CODE

This document defines the technical architecture and implementation rules for BZ-CRM-Social.

The file `functional-spec.md` is the authoritative source for product functionality.

This document defines the technical implementation.

If there is a conflict:

1. `functional-spec.md` defines WHAT the system must do.
2. This document defines HOW it should be implemented.
3. Do not silently remove, simplify, or reinterpret functionality from `functional-spec.md`.
4. If a requirement is technically ambiguous, stop and identify the ambiguity instead of inventing business behavior.
5. Do not replace the architecture with another framework or platform without an explicit architectural decision.

The project MUST NOT be implemented on Frappe, ERPNext, OroCRM, WordPress, or another full application platform.

The application is a custom Laravel modular monolith.

---

# 1. ARCHITECTURAL DECISION

## 1.1 Primary architecture

Use:

```text
Laravel 13
PHP 8.3+
MySQL 8.x
Redis
Filament 5
Livewire 4
Alpine.js
Vite
Tailwind CSS
```

The system is a **modular monolith**.

There is one Laravel application and one primary relational database.

Do NOT create microservices for business modules during MVP development.

Do NOT split CRM, Social, Tasks, People, Events, etc. into separate applications.

The architecture must nevertheless maintain strong internal module boundaries so individual subsystems can later be extracted if there is a genuine technical reason.

---

# 2. CORE ARCHITECTURAL PRINCIPLE

The platform owns its business domain.

External OSS products may provide infrastructure or specialized subsystems, but they must NOT become authoritative owners of core business entities.

For example:

```text
Person
Organization
Task
Project
Post
Group
Event
Territory
Lead
Appeal
```

are owned by the BZ Laravel application.

External systems such as Chatwoot, object storage, search engines, or optional Matrix infrastructure are adapters/services around the core.

The Laravel application remains the source of truth.

---

# 3. TECHNOLOGY STACK

## 3.1 Backend

```text
Laravel 13
PHP 8.3+
Composer
Eloquent ORM
Laravel Events
Laravel Notifications
Laravel Queues
Laravel Scheduler
Laravel Policies / Gates
Laravel Cache
Laravel Filesystem
Laravel Broadcasting
Laravel HTTP Client
Laravel Mail
Laravel Validation
Laravel Rate Limiting
```

Laravel 13 requires PHP 8.3 or newer. Use the latest supported PHP version available in the deployment environment unless a specific dependency requires otherwise.

---

# 4. DATABASE

## 4.1 Initial database

Use:

```text
MySQL 8.x
```

for:

- local development;
- automated tests where practical;
- staging;
- production.

Configure Laravel through:

```env
DB_CONNECTION=mysql
```

All ordinary application persistence must use Eloquent and Laravel migrations.

Raw SQL is allowed only when:

1. Eloquent/query builder cannot reasonably express the operation;
2. there is a measured performance reason;
3. the SQL is isolated in a dedicated repository/query class;
4. the implementation is documented.

---

# 5. DATABASE PORTABILITY REQUIREMENT

The application must maintain reasonable future portability to PostgreSQL.

A future MySQL → PostgreSQL migration is considered a possible infrastructure evolution after MVP acceptance.

Therefore:

DO:

```php
Person::query()
    ->where('status', 'active')
    ->get();
```

DO NOT spread database-specific SQL throughout business logic.

Avoid unnecessary use of:

```text
MySQL-specific functions
MySQL-specific JSON expressions
MySQL-specific ENUM behavior
MySQL-specific string aggregation
MySQL-specific date functions
vendor-specific full-text syntax
```

Database-specific operations must be isolated behind infrastructure/query abstractions.

Do not use native database ENUMs for business state unless there is a documented reason.

Prefer:

```text
PHP Enum
+
database string/code
+
configuration/reference data
```

---

# 6. FUTURE POSTGRESQL / POSTGIS PREPARATION

The initial MVP does NOT require PostgreSQL.

However, the Geo module must isolate spatial operations behind a `GeoService` abstraction.

Example conceptual API:

```php
GeoService::containsPoint(...)
GeoService::findNearby(...)
GeoService::intersects(...)
GeoService::assignTerritory(...)
```

The initial implementation may use MySQL spatial functionality or ordinary coordinates.

Do not spread raw spatial SQL through controllers or domain services.

If the product is accepted after PoC/MVP and advanced GIS requirements justify PostgreSQL/PostGIS, the Geo infrastructure can be replaced independently.

---

# 7. FRONTEND ARCHITECTURE

Use:

```text
Blade
Livewire
Alpine.js
Tailwind CSS
Vite
```

Filament is the primary framework for administration and data-heavy business interfaces.

Do NOT use React or Vue unless a specific interaction genuinely requires it.

The default implementation strategy is:

```text
Laravel
    ↓
Livewire
    ↓
Alpine.js
    ↓
Tailwind
```

This keeps the application in one language/runtime and is particularly suitable for AI-assisted development.

---

# 8. FILAMENT

Use **Filament 5** for:

- administration;
- CRUD;
- configuration;
- tables;
- filters;
- forms;
- dashboards;
- reports;
- resource management;
- internal business interfaces;
- role administration;
- system settings.

Filament 5 is compatible with Laravel 13.

Do not treat Filament as the domain architecture.

Filament is the presentation/admin layer.

Business logic MUST NOT live inside Filament Resource classes.

Bad:

```text
Filament Resource
    → contains all business logic
```

Good:

```text
Filament Resource
    ↓
Action / Service
    ↓
Domain
    ↓
Model / Repository
```

---

# 9. MODULAR DIRECTORY STRUCTURE

Use a modular application structure.

Recommended structure:

```text
app/
    Domain/
        Identity/
        People/
        Organization/
        Access/
        Social/
        Groups/
        Messaging/
        Events/
        Projects/
        Tasks/
        CRM/
        Communications/
        Geo/
        Training/
        Gamification/
        Notifications/
        Reporting/
        Files/
        Automation/
        Audit/
        CustomObjects/

    Infrastructure/
        Chatwoot/
        Search/
        Storage/
        Maps/
        AI/
        Calendar/
        ExternalAuth/

    Support/
        Actions/
        Data/
        Enums/
        Exceptions/
        Helpers/

    Http/
        Controllers/
        Middleware/
        Requests/
        Resources/

    Console/

    Providers/
```

If module size becomes significant, each domain module may contain:

```text
Domain/People/
    Models/
    Actions/
    Services/
    Policies/
    Events/
    Listeners/
    Jobs/
    Notifications/
    Queries/
    Data/
    Enums/
    Exceptions/
    Filament/
    Http/
    Tests/
```

---

# 10. DOMAIN MODULES

The initial architecture consists of the following modules.

## Core modules

```text
Identity
People
 Organization
Access
Audit
```

## Collaboration

```text
Social
Groups
Messaging
Events
Projects
Tasks
```

## CRM

```text
CRM
Communications
```

## Field operations

```text
Geo
```

## Engagement

```text
Training
Gamification
```

## Platform

```text
Notifications
Reporting
Files
Automation
CustomObjects
```

---

# 11. CANONICAL DOMAIN MODEL

The following entities must be treated as first-class domain objects.

```text
User
Person

OrgUnit
Role
Permission
Scope

ProfileLayer

Post
Comment
Reaction

Group
GroupMembership
GroupRole

Chat
ChatMember
Message
MessageReaction

Event
EventAttendee
EventReminder

Project
ProjectPhase
Task
Subtask
Checklist
ChecklistItem
TimeEntry
Resource

Pipeline
PipelineStage
Lead
Appeal

ChannelAccount
ChannelMessage
Conversation

Territory
GeoZone
Address
House
Apartment
Visit

Course
Lesson
Quiz
QuizAttempt
Achievement
XpEvent
Mentorship

DataEvent

AutomationRule
AutomationRun
ConfigPackage

Notification
NotificationPreference

Attachment
FileRecord

AuditLog

CustomObjectType
CustomField
CustomObjectRecord
```

The functional specification defines the same logical core, including the distinction between `User` and `Person`, profile layers, organizational structure, social entities, messaging, projects/tasks, CRM, training/gamification, automation, notifications, audit, and custom objects.

---

# 12. USER VS PERSON

This distinction is mandatory.

```text
User
    =
authentication/account

Person
    =
real-world human
```

A Person may exist without a User.

Relationship:

```text
Person 1 ←→ 0..1 User
```

Never create separate CRM contacts for the same Person.

All modules must reference `person_id` where the real-world human is involved.

Examples:

```text
Task.assignee → Person/User relationship
Lead.person → Person
EventAttendee.person → Person
GroupMembership.person → Person
Mentorship.mentor → Person
Mentorship.mentee → Person
```

The one-person-one-card principle is a core functional requirement.

---

# 13. IDENTITY

Use Laravel's authentication system as the base.

Requirements:

- email/password authentication;
- invitation registration;
- email verification;
- password reset;
- account activation/deactivation;
- session management;
- forced logout;
- optional social/OIDC providers;
- TOTP 2FA;
- login history;
- rate limiting;
- suspicious-login protection.

External authentication providers must be implemented behind an authentication abstraction.

Do not hard-code the assumption that Google/Facebook are the only providers.

The provider configuration must be extensible.

---

# 14. ROLE AND PERMISSION SYSTEM

Do NOT implement the authorization model as a collection of hard-coded `if` statements.

The functional specification requires:

```text
module
→ object
→ field
→ action
→ scope
```

with:

```text
organization
region
department
group
own
subordinates
```

and temporary roles/delegation.

Use a dedicated authorization subsystem.

Recommended building blocks:

```text
Role
Permission
RolePermission
UserRole
RoleScope
PermissionScope
Delegation
```

A permission should look conceptually like:

```text
people.read
people.create
people.update
people.delete
people.export
people.assign
```

and can be scoped.

---

# 15. CENTRAL AUTHORIZATION SERVICE

All access checks must ultimately pass through one authorization layer.

Example:

```php
$authorization->can(
    $user,
    'people.read',
    $person
);
```

The same authorization rules must be respected by:

```text
Web
Livewire
Filament
API
Search
Reports
Exports
Jobs
Notifications
Webhooks
Background processes
```

Never rely only on hiding a button.

A user who cannot see a Person must not receive that Person through:

- API;
- export;
- search;
- report;
- relationship endpoint;
- notification;
- autocomplete;
- bulk action.

---

# 16. CONFIDENTIAL PROFILE LAYERS

Confidential profile information MUST NOT be stored merely as ordinary fields on `people`.

Implement separate entities/layers.

Examples:

```text
PersonPublicProfile
PersonInternalProfile
PersonHrProfile
PersonPsychologyNote
PersonSecurityNote
PersonFeedback360
```

Each has its own authorization policy.

This follows the functional requirement that confidential layers be physically separate domain objects rather than ordinary profile fields.

Every access to confidential data must generate an audit event.

---

# 17. AUDIT

Implement append-only audit logging.

Minimum data:

```text
id
actor_user_id
actor_person_id
action
entity_type
entity_id
old_values
new_values
ip_address
user_agent
created_at
```

Audit records must not be editable through the normal application.

Audit must cover:

- login;
- logout where appropriate;
- permission changes;
- role changes;
- confidential-data access;
- exports;
- imports;
- object creation;
- object modification;
- deletion;
- status changes;
- moderation;
- administrative operations;
- integration changes.

The functional specification requires an append-only audit trail and explicit logging of access to confidential layers.

---

# 18. SOCIAL MODULE

Implement social functionality natively inside Laravel.

Entities:

```text
Post
Comment
Reaction
PostAttachment
PostVisibility
PostRevision
ModerationReport
ModerationAction
```

Supported visibility:

```text
global
region
organization_unit
group
private
targeted
```

Visibility must be enforced server-side.

A post invisible to a user must not appear in:

- feed;
- search;
- API;
- notifications;
- reports;
- autocomplete.

The feed is a projection/query over canonical Post data.

Do not create copies of posts for different feeds.

---

# 19. GROUPS

Groups are native domain objects.

Support:

```text
open
closed
secret
```

Group roles:

```text
owner
admin
moderator
member
```

Groups may have:

- feed;
- chat;
- files;
- events;
- membership requests;
- invitations.

Group visibility must be integrated with the central authorization system.

---

# 20. MESSAGING

Implement basic internal messaging natively.

Entities:

```text
Chat
ChatMember
Message
MessageAttachment
MessageReaction
MessageRead
```

Messages must support:

- direct chats;
- group chats;
- project/task chats;
- replies;
- threads;
- mentions;
- reactions;
- attachments;
- read state;
- search.

Message threading uses:

```text
messages.parent_id
```

and must support arbitrary tree depth subject to configurable limits.

Use Laravel broadcasting/WebSockets for real-time updates.

Evaluate Laravel Reverb as the default first-party real-time layer.

Do not introduce Matrix unless E2EE becomes a confirmed product requirement.

---

# 21. CHAT ARCHITECTURE

A Task or Project discussion may be linked to a Chat.

Example:

```text
Task
  ↓
TaskDiscussion
  ↓
Chat
```

Creating a task may optionally create its discussion chat.

This must NOT duplicate messages into the Task table.

The Task references the chat.

---

# 22. EVENTS

Implement:

```text
Event
EventAttendee
EventReminder
```

Features:

- start/end;
- location;
- geo point;
- visibility;
- invitations;
- RSVP;
- attendance;
- reminders;
- recurring events;
- attachments;
- post-event results.

Calendar export:

```text
Google Calendar URL
ICS
iCal feed
```

Google Calendar integration must initially be one-way export unless two-way synchronization is explicitly approved.

---

# 23. PROJECTS

Implement:

```text
Project
ProjectPhase
ProjectMember
ProjectDependency
ProjectTemplate
```

Project views:

```text
List
Kanban
Calendar
Gantt
Timeline
Dashboard
```

Projects must support:

- phases;
- dependencies;
- deadlines;
- participants;
- resources;
- budgets;
- visibility;
- health state.

Do not create a separate project-management product.

---

# 24. TASK ENGINE

Tasks are a major reusable domain primitive.

Implement:

```text
Task
TaskAssignee
TaskWatcher
TaskComment
TaskChecklist
TaskDependency
TaskStatusHistory
TaskRelation
TimeEntry
```

A task may reference:

```text
Person
Project
ProjectPhase
House
Apartment
Event
Appeal
Lead
CustomObject
```

Tasks support 1..N assignees.

---

# 25. TASK STATUS MODEL

Default statuses:

```text
draft
backlog
todo
in_progress
blocked
on_hold
in_review
done
canceled
```

Optional:

```text
rejected
feedback
duplicate
```

Overdue MUST NOT be a lifecycle status.

It is a computed flag.

Status transition rules must be configurable.

Every transition generates:

```text
TaskStatusChanged
```

and an audit record.

---

# 26. CRM

The CRM is a native module.

Do not install a complete external CRM as the application foundation.

Core CRM entities:

```text
Person
Pipeline
PipelineStage
Lead
Appeal
Interaction
Segment
SavedFilter
```

A Lead references a Person.

A Lead is not another Person.

Conceptually:

```text
Person
   ↑
   │
 Lead
   │
Pipeline
```

---

# 27. PEOPLE CRM

People features:

- unified person record;
- contacts;
- skills;
- languages;
- organizational relationships;
- geographic relationship;
- interactions;
- deduplication;
- merge;
- import;
- export;
- custom fields;
- relationship graph.

Deduplication candidates:

```text
phone
email
name
```

Merging must preserve historical references.

Never physically delete historical data merely because two Person records are merged.

---

# 28. PIPELINES

Pipelines are configurable.

Example:

```text
New
→ Contacted
→ Meeting
→ Training
→ Mentor Assigned
→ Active
```

Pipeline implementation:

```text
Pipeline
PipelineStage
Lead
LeadStageHistory
```

Stage changes may trigger Automation Rules.

---

# 29. MULTICHANNEL COMMUNICATIONS

Do NOT implement 10+ messaging providers from scratch.

Use an adapter architecture.

Primary integration candidate:

```text
Chatwoot
```

Chatwoot is an external communication subsystem.

Laravel remains the owner of:

```text
Person
Lead
Appeal
Task
Conversation linkage
internal permissions
```

Conceptual architecture:

```text
External Channel
       ↓
   Chatwoot
       ↓
Webhook/API
       ↓
Laravel Communications
       ↓
Person
       ↓
Lead / Appeal
       ↓
Task / Conversation
```

The functional specification explicitly defines the processing chain as:

```text
channel
→ inbox
→ person
→ lead/appeal
→ responsible person
→ pipeline
→ task/conversation
```



---

# 30. WEBHOOK SECURITY

All incoming provider webhooks must support:

```text
signature validation
HMAC where supported
timing-safe comparison
idempotency
provider event ID
retry handling
```

Duplicate webhook delivery MUST NOT create:

- duplicate Person;
- duplicate Conversation;
- duplicate Message;
- duplicate Lead;
- duplicate Appeal.

---

# 31. GEO MODULE

The initial database remains MySQL.

Implement a dedicated Geo domain:

```text
Territory
GeoZone
Address
House
Apartment
Visit
```

Hierarchy:

```text
Country
  ↓
Region
  ↓
District
  ↓
Sector
  ↓
Electoral Area
  ↓
House
  ↓
Apartment
```

This follows the functional specification.

---

# 32. GEO ABSTRACTION

All spatial calculations go through:

```text
GeoService
```

Do not call vendor-specific spatial SQL directly from:

```text
Controllers
Livewire
Filament
Models
```

The Geo module must be replaceable.

Initial implementation may use MySQL spatial columns and indexes where useful.

Future implementation may use:

```text
PostgreSQL
+
PostGIS
```

without changing the business API of the Geo module.

---

# 33. MOBILE FIELD WORK

The application must be mobile-first for field scenarios.

Priority workflow:

```text
My territories
    ↓
My houses
    ↓
House
    ↓
Apartments
    ↓
Quick status
    ↓
Note
    ↓
Save
```

Avoid large forms.

Use:

- tap-friendly controls;
- optimistic UI where safe;
- local draft storage;
- retry queue;
- synchronization.

---

# 34. OFFLINE ARCHITECTURE

The first MVP implementation should support offline-safe drafts for critical field operations.

Use:

```text
PWA
Service Worker
IndexedDB
Sync queue
```

Each offline operation should have:

```text
operation_id
device_id
entity
entity_id
operation
payload
client_timestamp
```

The server must process operations idempotently.

Do not attempt full offline replication of the entire database.

Only explicitly defined field workflows are offline-capable.

---

# 35. TRAINING

Implement native Training module.

Entities:

```text
Course
Lesson
Quiz
QuizQuestion
QuizAttempt
CourseEnrollment
CourseCompletion
```

Support:

- text;
- video;
- files;
- tests;
- mandatory courses;
- optional courses;
- onboarding sequences;
- progress.

Do not introduce Moodle during MVP unless the functional requirements grow beyond the native module.

---

# 36. GAMIFICATION

Native module:

```text
XpEvent
Level
Achievement
Badge
Leaderboard
```

XP must be generated from verified domain events.

Examples:

```text
TaskCompleted
CourseCompleted
VisitCompleted
EventAttended
```

Do NOT award XP merely because a frontend button was clicked.

Every XP award must have:

```text
source_event
actor
reason
amount
created_at
```

This also prevents simple XP manipulation.

---

# 37. NOTIFICATIONS

Use Laravel Notifications.

Channels:

```text
database/in-app
email
push
```

Notification preferences:

```text
NotificationPreference
```

Notifications must respect authorization.

Do not send a notification revealing information the recipient is not allowed to access.

---

# 38. FILES

Use:

```text
Laravel Filesystem
+
S3-compatible object storage
```

Development may use local storage.

Production should support:

```text
S3
MinIO
```

Use Spatie Media Library where it provides clear value for attachment/media management rather than building file lifecycle handling from scratch. The package currently supports Laravel 13.

Attachments should reference canonical business objects through a generic attachment model.

---

# 39. SEARCH

Do not make MySQL full-text search the permanent search architecture.

Implement a search abstraction:

```text
SearchService
Searchable
SearchIndex
```

Initial MVP may use MySQL where sufficient.

When cross-module search requirements become significant, introduce:

```text
Meilisearch
```

or:

```text
OpenSearch
```

without changing application-level search interfaces.

All indexed records MUST be filtered according to the same authorization rules.

---

# 40. REPORTING

Reports are projections over canonical domain data.

Implement:

```text
ReportDefinition
ReportFilter
ReportColumn
ReportGroup
Dashboard
DashboardWidget
```

Support:

- tables;
- charts;
- grouping;
- filtering;
- drill-down;
- exports.

Every query used by reports must pass through authorization scopes.

Never implement:

```text
"report admin bypasses permissions"
```

unless explicitly defined as a separate privileged permission.

---

# 41. AUTOMATION ENGINE

Implement a generic:

```text
Trigger
→ Conditions
→ Actions
```

engine.

Triggers:

```text
object.created
object.updated
status.changed
date.reached
message.received
data_event.created
```

Actions:

```text
create_object
update_object
create_task
change_status
assign_user
send_notification
send_email
start_workflow
create_event
```

Automations must run through queued jobs where appropriate.

Every automation execution must have:

```text
AutomationRun
AutomationStepRun
status
started_at
finished_at
error
```

Failed automations must be retryable.

---

# 42. CUSTOM OBJECTS

The functional specification requires a configurable low-code layer.

Implement:

```text
CustomObjectType
CustomField
CustomObjectRecord
CustomObjectRelation
```

Supported initial field types:

```text
text
long_text
number
boolean
date
datetime
select
multi_select
user
person
organization_unit
relation
file
geo_point
```

Do NOT attempt to build a full generic database engine.

The custom-object system must remain constrained enough to preserve:

- authorization;
- reporting;
- validation;
- audit;
- search;
- migrations;
- performance.

Custom objects must not bypass the global authorization system.

---

# 43. CONFIGURATION PACKAGES

Implement the concept of:

```text
ConfigPackage
```

A package may contain:

```text
CustomObjectTypes
CustomFields
Statuses
Pipelines
AutomationRules
Templates
Reports
```

Packages must be exportable/importable.

Format should be versioned JSON/YAML.

Example:

```text
candidate-onboarding-v1
field-operations-v1
regional-project-v1
```

Imported packages must be validated before applying changes.

---

# 44. DOMAIN EVENTS

Important state changes should generate Laravel domain events.

Examples:

```text
PersonCreated
PersonMerged
TaskCreated
TaskAssigned
TaskStatusChanged
ProjectCompleted
PostPublished
PostModerated
MessageSent
EventCreated
EventAttended
LeadStageChanged
AppealCreated
CourseCompleted
XpAwarded
```

Events must not automatically imply synchronous execution.

Use queues for expensive secondary operations.

---

# 45. AI

AI is an optional subsystem.

AI must never become a hidden source of business truth.

Architecture:

```text
Business Object
    ↓
AI Service
    ↓
Structured Result
    ↓
Validation
    ↓
Human/business workflow
```

Example:

```text
Incoming Message
    ↓
AI extraction
    ↓
PersonCandidate
    ↓
validated fields
    ↓
Person / Lead / Appeal
```

AI output must be treated as untrusted input.

Never allow an AI model to directly execute unrestricted database mutations.

All AI actions must be constrained by explicit tools/commands and authorization.

Laravel 13 provides a first-party AI SDK, which may be used behind an application-level `AiService` abstraction so that the domain is not coupled to one provider.

---

# 46. API

Expose REST API for:

- public forms;
- integrations;
- mobile/PWA synchronization;
- external website;
- webhooks;
- future applications.

API must use:

```text
versioning
authentication
authorization
validation
rate limiting
idempotency
consistent error format
```

Recommended initial prefix:

```text
/api/v1/
```

Do not create API endpoints merely because a database table exists.

API resources represent business capabilities.

---

# 47. PUBLIC WEBSITE INTEGRATION

The public website remains external.

The BZ platform provides:

```text
public forms
chat widget integration
API
webhooks
```

The public website must never directly access the MySQL database.

All interaction goes through controlled API endpoints.

---

# 48. INTERNATIONALIZATION

Languages:

```text
ro
ru
en
```

Default:

```text
ro
```

All system strings must use Laravel localization.

Never hard-code UI text inside:

```text
Controllers
Models
Actions
Filament Resources
Blade
Livewire
```

unless the text is explicitly user-generated content.

Database codes remain language-neutral:

```text
in_progress
done
canceled
```

Translations provide display labels.

---

# 49. SECURITY

Mandatory:

```text
CSRF protection
XSS protection
SQL injection protection
IDOR protection
rate limiting
secure sessions
password hashing
2FA
secret encryption
secure cookies
authorization checks
webhook signature validation
audit logging
```

Sensitive integration credentials must be encrypted.

Never expose secret values in:

- logs;
- API responses;
- debug pages;
- audit records;
- Filament tables.

The functional specification explicitly requires OWASP-oriented security, encrypted integration secrets, strong authentication, session control, backups, and auditability.

---

# 50. DATA PRIVACY

The platform processes sensitive personal information.

Treat political affiliation, organizational membership, personal contact information, HR information, psychological notes, security notes, and other sensitive data as high-risk data.

The implementation must minimize exposure.

Do not:

- log sensitive payloads unnecessarily;
- send sensitive data to third-party AI providers by default;
- expose sensitive fields to frontend payloads unless required;
- include sensitive information in generic search indexes;
- include restricted information in notifications unless authorized.

The legal requirements for the applicable jurisdiction must be reviewed separately before production deployment.

---

# 51. TESTING

Testing is part of Definition of Done.

Minimum:

```text
Unit tests
Feature tests
Authorization tests
API tests
Browser/E2E tests
Security tests
```

Critical E2E scenarios:

```text
login
invitation
approval
role assignment
Person creation
restricted profile access
post visibility
group visibility
task creation
task assignment
task status transition
event RSVP
CRM pipeline movement
webhook idempotency
field/offline synchronization
report authorization
export authorization
```

---

# 52. AUTHORIZATION TEST MATRIX

For every sensitive module create tests like:

```text
user A can see X
user B cannot see X
user A cannot obtain X through API
user A cannot obtain X through search
user A cannot export X
user A cannot infer X through counts/reports
```

Do not consider a permission system complete merely because buttons disappear.

---

# 53. PERFORMANCE

Target:

```text
Key screens <= 2 seconds
```

under normal expected load.

Avoid:

```text
N+1 queries
loading entire tables
unbounded relationship queries
large synchronous imports
large synchronous exports
large synchronous reports
```

Use:

```text
pagination
lazy/eager loading
query scopes
indexes
cache
queues
batch processing
```

---

# 54. QUEUES

Use Redis-backed Laravel queues.

Queue:

```text
emails
notifications
imports
exports
AI operations
search indexing
webhook processing
report generation
automation
media processing
```

Never block an HTTP request for a long-running operation if it can safely be asynchronous.

---

# 55. CACHE

Use Redis for:

```text
cache
sessions
queues
rate limiting
temporary state
```

Do not use application cache as the source of truth for business data.

Every cache must have an invalidation strategy.

---

# 56. SCHEDULER

Use Laravel Scheduler for:

```text
reminders
recurring tasks
digests
expired delegations
automation triggers
cleanup
maintenance
scheduled reports
```

Scheduled operations must be idempotent.

---

# 57. OBSERVABILITY

Implement:

```text
structured application logs
queue monitoring
failed job tracking
health endpoint
database monitoring
error reporting
basic metrics
```

Production must provide an administrator-facing health/status page.

Do not expose sensitive data through logs or monitoring systems.

---

# 58. BACKUPS

Production:

```text
daily database backup
daily file backup
off-server backup
backup retention policy
monthly restore verification
```

Target:

```text
RPO <= 24h
RTO <= 4h
```

unless the client later specifies stricter targets.

---

# 59. CONTAINERIZED DEVELOPMENT

Use Docker for development.

Recommended services:

```text
app
nginx
mysql
redis
mailpit
node
```

Optional:

```text
minio
meilisearch
```

Do not require developers to install MySQL/Redis locally.

Claude Code must be able to run the project from the documented Docker environment.

---

# 60. DEVELOPMENT COMMANDS

The repository must document commands equivalent to:

```bash
docker compose up -d

php artisan migrate

php artisan db:seed

php artisan test

npm run build

npm run dev
```

The exact container orchestration may use Laravel Sail or a project-specific Docker Compose configuration.

Laravel's current Sail tooling supports PHP 8.3–8.5 runtimes.

---

# 61. CODE STYLE

Use:

```text
PSR-12
Laravel conventions
strict typing where practical
PHP enums
DTO/Data objects where useful
Form Requests
Policies
Actions
Services
Events
Jobs
```

Avoid:

```text
God classes
God controllers
business logic in Blade
business logic in Filament Resources
raw SQL scattered through application
global helper abuse
static state
hidden side effects
```

---

# 62. ACTION CLASSES

Business mutations should preferably be represented by explicit Actions.

Examples:

```text
CreatePerson
MergePersons
AssignPersonToOrganization
CreateTask
AssignTask
ChangeTaskStatus
PublishPost
ModeratePost
CreateEvent
RegisterEventAttendance
MoveLeadToStage
CreateAppeal
AwardXp
```

This makes business behavior:

- testable;
- reusable;
- discoverable by Claude Code;
- independent of UI.

---

# 63. QUERY CLASSES

Complex read operations should use dedicated query classes.

Examples:

```text
VisiblePostsQuery
AccessiblePeopleQuery
RegionalTasksQuery
ProjectHealthQuery
TerritorySummaryQuery
PersonInteractionTimelineQuery
```

This is especially important for authorization-aware queries.

---

# 64. NO DUPLICATION OF BUSINESS RULES

The following MUST NOT contain independent authorization logic:

```text
Filament
API
Livewire
CLI
Jobs
Exports
Reports
```

All must use shared domain authorization.

Similarly, status transitions must be implemented once and reused everywhere.

---

# 65. ADMIN UI

Filament should expose administrative configuration for:

```text
Users
People
Roles
Permissions
Organizations
Regions
Groups
Pipelines
Statuses
Automation
Custom Objects
Custom Fields
Integrations
Notifications
Reports
Audit
System Settings
```

Not every internal feature must be a Filament CRUD.

Use custom Livewire pages where the workflow is more complex.

---

# 66. USER-FACING UI

The user-facing application should provide:

```text
Dashboard
People
CRM
Tasks
Projects
Calendar
Social Feed
Groups
Messenger
Territories
Training
Profile
Notifications
Search
```

The interface must be responsive.

Mobile-first priority applies especially to:

```text
Tasks
Messages
Events
Territories
House/Apartment field work
Notifications
```

---

# 67. SEARCH UX

Global search should eventually support:

```text
People
Posts
Tasks
Projects
Groups
Events
Documents
Appeals
Leads
```

But results must be authorization-filtered before display.

Never return:

```text
"X results found"
```

where the count itself leaks restricted information.

---

# 68. IMPORT / EXPORT

Support:

```text
CSV
XLSX
```

for authorized modules.

Imports must support:

```text
preview
validation
dry-run
error report
duplicate detection
rollback strategy
```

Large imports run through queues.

Exports run asynchronously for large datasets.

Sensitive export permissions must be explicit.

---

# 69. FILE VERSIONING

Documents must support:

```text
version
uploader
timestamp
download permissions
```

Deleted files must respect retention and audit requirements.

---

# 70. EXTERNAL OSS POLICY

Before installing any third-party package:

Claude Code MUST evaluate:

1. license;
2. current maintenance;
3. Laravel 13 compatibility;
4. PHP compatibility;
5. security history;
6. dependency weight;
7. whether it creates architectural lock-in;
8. whether the functionality is actually complex enough to justify the dependency.

Do not install packages simply because they exist.

Prefer Laravel first-party functionality where sufficient.

Prefer small, mature packages over entire external platforms.

---

# 71. APPROVED INITIAL OSS BUILDING BLOCKS

Initial preferred stack:

```text
Laravel 13
Filament 5
Livewire 4
Alpine.js
Tailwind CSS
Redis
MySQL 8
Spatie Laravel Permission
Spatie Laravel Activitylog
Spatie Laravel Media Library
Laravel Reverb
```

Additional components only when required:

```text
Chatwoot
Meilisearch
MinIO/S3
Keycloak
MapLibre
PostGIS
Matrix
```

The latter group is NOT automatically required for MVP.

Each addition requires an explicit architectural decision.

---

# 72. WHAT NOT TO USE AS THE CORE

Do not use:

```text
Frappe
ERPNext
OroCRM
WordPress
HumHub
Gameplan
Rocket.Chat
Moodle
Matrix
Chatwoot
```

as the core application framework.

They may be integrated as external subsystems later if a concrete requirement justifies them.

---

# 73. CHATWOOT BOUNDARY

If Chatwoot is deployed, it owns:

```text
external channel transport
external conversations
provider-specific channel logic
```

Laravel owns:

```text
Person
Lead
Appeal
Task
organization
permissions
business workflow
```

Do not duplicate the complete CRM model inside Chatwoot.

---

# 74. OPTIONAL MATRIX BOUNDARY

Matrix should only be introduced if the product requires true E2EE or Matrix-compatible federation.

If used:

```text
Laravel = business identity/context
Matrix = secure message transport
```

Do not make Matrix the canonical source for CRM/business entities.

---

# 75. DEVELOPMENT PHASES

Claude Code MUST implement incrementally.

Do not attempt to build the whole platform in one pass.

---

## Phase 0 — Architecture Foundation

Implement:

```text
Laravel 13
Docker
MySQL
Redis
Filament
Livewire
Tailwind
authentication
testing
CI
base module structure
logging
health check
```

Deliverable:

A running empty platform with CI and architecture.

---

## Phase 1 — Identity + Person + Organization

Implement:

```text
User
Person
OrgUnit
roles
permissions
scopes
invitation
approval
profile
```

This phase must establish the central authorization architecture.

---

## Phase 2 — Confidential Profile Layers + Audit

Implement:

```text
profile layers
audit
access logging
exports
security tests
```

Do not proceed until access-control tests pass.

---

## Phase 3 — Tasks + Projects

Implement:

```text
Project
Phase
Task
Subtask
Checklist
TimeEntry
Resources
Kanban
Calendar
Gantt
```

---

## Phase 4 — CRM

Implement:

```text
People CRM
Pipelines
Leads
Appeals
Interactions
Segments
deduplication
import/export
```

---

## Phase 5 — Social + Groups

Implement:

```text
Posts
Comments
Reactions
Visibility
Groups
Moderation
Feed
```

---

## Phase 6 — Messaging

Implement:

```text
Chat
Messages
Threads
Reactions
Attachments
Read states
real-time updates
```

---

## Phase 7 — Events + Notifications

Implement:

```text
Events
RSVP
reminders
calendar export
notification center
email
```

---

## Phase 8 — Geo + Field Operations

Implement:

```text
Territory
GeoZone
Address
House
Apartment
Visit
mobile field workflow
offline drafts
```

Do not prematurely migrate to PostgreSQL.

---

## Phase 9 — Training + Gamification

Implement:

```text
Courses
Lessons
Quizzes
Onboarding
XP
Levels
Achievements
Leaderboards
Mentorship
```

---

## Phase 10 — Automation + Reporting

Implement:

```text
AutomationRule
AutomationRun
ReportBuilder
Dashboards
scheduled reports
structured DataEvents
```

---

## Phase 11 — External Communications

Integrate:

```text
Chatwoot
Email
Telegram
website
other approved providers
```

Only implement providers actually required by the approved MVP.

---

## Phase 12 — AI

Implement AI capabilities only after the underlying structured workflows are stable.

AI must consume and produce structured data through explicit interfaces.

---

# 76. DEFINITION OF DONE

A feature is NOT complete when the UI works.

A feature is complete only when:

```text
Database
Model
Validation
Authorization
Business logic
UI
API where required
Audit
Events
Notifications where required
Tests
Translations
Documentation
```

are implemented as applicable.

---

# 77. CLAUDE CODE DEVELOPMENT RULE

For every phase:

1. Inspect the existing code.
2. Read relevant architecture documentation.
3. Identify existing reusable components.
4. Create/update the database model.
5. Implement domain logic.
6. Implement authorization.
7. Implement UI.
8. Implement tests.
9. Run tests.
10. Run static analysis.
11. Run formatting.
12. Review for security.
13. Update documentation.
14. Only then proceed.

Do not silently skip failed tests.

Do not disable tests merely to make a phase pass.

Do not weaken authorization to make UI development easier.

---

# 78. CLAUDE CODE MUST MAINTAIN ARCHITECTURAL MEMORY

Maintain:

```text
docs/
    architecture.md
    decisions/
        ADR-001-...
        ADR-002-...
    modules/
    api/
    security/
    deployment/
```

Every major architectural deviation requires an ADR.

Examples:

```text
ADR-001 Laravel modular monolith
ADR-002 MySQL initial database
ADR-003 Authorization architecture
ADR-004 Chatwoot integration
ADR-005 Search engine
ADR-006 Geo abstraction
```

---

# 79. DATABASE MIGRATION POLICY

Every schema change MUST use Laravel migrations.

Never manually modify production schema without a migration.

Every migration must be:

```text
repeatable in fresh installation
reviewable
tested
backward-aware where required
```

Do not modify an already-applied migration in order to change production schema.

Create a new migration.

---

# 80. FUTURE MYSQL → POSTGRESQL MIGRATION

The project must remain reasonably portable.

If migration is later approved:

```text
1. Freeze schema changes temporarily.
2. Inventory MySQL-specific features.
3. Inventory raw SQL.
4. Inventory JSON queries.
5. Inventory spatial queries.
6. Create PostgreSQL schema.
7. Migrate data.
8. Validate row counts.
9. Validate foreign keys.
10. Validate critical business aggregates.
11. Run complete test suite.
12. Run authorization tests.
13. Run performance tests.
14. Switch staging.
15. Validate staging.
16. Production cutover.
```

Business/domain code must not be rewritten merely because the database changes.

---

# 81. INITIAL DATABASE PORTABILITY RULES

Claude Code must avoid unnecessary:

```text
DB::statement(...)
DB::raw(...)
database-specific JSON operators
database-specific functions
database-specific ENUMs
database-specific full-text search
```

If one is required, isolate it and document it.

---

# 82. MVP STRATEGY

The MVP is not required to contain every future subsystem.

The architecture must support the complete target platform, but implementation must be incremental.

Priority is:

```text
Identity
People
Organization
Authorization
Tasks
CRM
Social
Messaging
```

then:

```text
Events
Geo
Training
Gamification
Automation
Reporting
External communications
AI
```

The final priority must be confirmed against the client's approved MVP scope.

---

# 83. SUCCESS CRITERIA FOR THE ARCHITECTURE

The architecture is successful if:

1. A new business entity can be added without modifying unrelated modules.
2. A new role can be added without code changes to every module.
3. A new permission can be added without rewriting authorization logic.
4. Social visibility uses the same authorization engine as CRM.
5. Reports use the same canonical data as operational screens.
6. Tasks can be linked to any relevant domain object.
7. Person remains the canonical human entity.
8. External communication channels can be added through adapters.
9. MySQL can eventually be replaced without rewriting domain logic.
10. Claude Code can work on one module without needing to understand the entire application.

---

# 84. FINAL ARCHITECTURE

The target architecture is:

```text
                         BZ-CRM-Social
                               │
                 ┌─────────────┴─────────────┐
                 │       Laravel 13          │
                 │     Modular Monolith      │
                 └─────────────┬─────────────┘
                               │
        ┌──────────────────────┼──────────────────────┐
        │                      │                      │
      Domain               Platform              Interfaces
        │                      │                      │
 People / Org             Authorization          Filament
 CRM                      Audit                  Livewire
 Tasks / Projects         Notifications          API
 Social / Groups          Automation              PWA
 Messaging                Reporting
 Events                   Files
 Geo                      Search
 Training
 Gamification
        │
        └──────────────────────┬──────────────────────┘
                               │
                ┌──────────────┼──────────────┐
                │              │              │
              MySQL          Redis        Object Storage
                │
                │
          future option
                │
             PostgreSQL
              + PostGIS

External integrations:

        Chatwoot ─────── External channels
        Keycloak ─────── optional SSO
        Meilisearch ──── optional search
        Map provider ─── maps
        Matrix ───────── optional E2EE
        AI provider ──── AI services
        Google Calendar ─ calendar export
```

---

# 85. MOST IMPORTANT ARCHITECTURAL RULE

The project must NOT become a collection of unrelated products.

The target is:

> **One coherent business platform implemented as a Laravel modular monolith, with specialized OSS products used only where they solve genuinely specialized infrastructure problems.**

Laravel owns the domain.

MySQL owns initial persistence.

Redis owns queues/cache/session infrastructure.

Filament owns the administrative/business UI foundation.

External systems are adapters, not competing sources of truth.

---

# 86. FIRST TASK FOR CLAUDE CODE

Before writing business functionality:

1. Read `functional-spec.md`.
2. Read this document completely.
3. Inspect the existing repository.
4. Produce `docs/architecture.md`.
5. Produce the initial ADR set.
6. Produce the proposed module dependency graph.
7. Produce the initial database ERD/logical model.
8. Identify all ambiguities in `functional-spec.md`.
9. Identify requirements that cannot safely be implemented without clarification.
10. Do NOT start implementing Phase 1 until this architecture review is complete.

After the architecture review, implement Phase 0 only.

At the end of Phase 0, stop and report:

```text
Implemented
Tests
Architecture decisions
Open questions
Known risks
Next phase
```

Do not automatically continue to Phase 1 unless explicitly instructed.
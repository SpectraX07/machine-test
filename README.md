# Machine Test API (CodeIgniter 4)

JWT-authenticated REST API built on CodeIgniter 4 for user registration, login, token lifecycle (refresh / revoke / logout), RBAC access control, and CRUD user management with cursor pagination.

---

## Table of contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Request lifecycle](#request-lifecycle)
4. [Authentication & tokens](#authentication--tokens)
5. [RBAC (roles & permissions)](#rbac-roles--permissions)
6. [API endpoints](#api-endpoints)
7. [Response format](#response-format)
8. [Database schema](#database-schema)
9. [Layer-by-layer guide](#layer-by-layer-guide)
10. [Configuration & setup](#configuration--setup)
11. [Testing](#testing)
12. [Security notes](#security-notes)

---

## Overview

| Concern | Implementation |
|--------|----------------|
| Framework | CodeIgniter 4 (`^4.7`), PHP `^8.2` |
| Auth | JWT access tokens (HS256) + opaque refresh tokens |
| Authorization | RBAC — roles → permissions; permissions are seeded only |
| JWT library | `firebase/php-jwt` |
| Access revocation | JWT denylist by `jti` |
| Refresh storage | SHA-256 hashed tokens in `refresh_tokens` |
| API style | Versioned under `/api/v1`, JSON in/out |
| Pagination | Keyset / cursor (`id > cursor`), not OFFSET |

Public routes: `register`, `login`, `auth/refresh`, `auth/revoke`.  
Protected routes require `Authorization: Bearer <access_token>`; most also require a permission via the `permission:` filter.

---

## Architecture

The app follows a thin-controller / service / model layout, versioned under `V1`:

```
Request
  → Filters (CORS → JwtAuth → PermissionAuth)
  → Controller (validate JSON, call service, map to HTTP)
  → Service (business rules, orchestration)
  → Model (persistence, hashing callbacks)
  → Response (ApiResponse envelope)
```

### Directory map

| Path | Role |
|------|------|
| `app/Config/Routes/ApiV1.php` | API route definitions |
| `app/Config/Rbac.php` | Seeded permission + role catalog |
| `app/Controllers/Api/V1/` | HTTP layer (`ApiController`, `AuthController`, `UserController`, `RoleController`, `PermissionController`) |
| `app/Services/V1/` | Business logic (`AuthService`, `UserService`, `RbacService`) |
| `app/Services/AuthContext.php` | Request-scoped identity, roles, permissions |
| `app/Models/V1/` | `User`, `RefreshToken`, `JwtDenylist`, `Role`, `Permission` |
| `app/Libraries/` | `JWTService`, `ApiResponse` |
| `app/Filters/JwtAuth.php` | Bearer token verification + denylist + load RBAC |
| `app/Filters/PermissionAuth.php` | Require permission slug(s) on a route |
| `app/Validation/V1/` | Validation rule sets |
| `app/Exceptions/` | Domain exceptions mapped to HTTP statuses |
| `app/Database/Migrations/` | Schema |
| `app/Database/Seeds/RbacSeeder.php` | Seeds permissions/roles from config |

### Dependency wiring

Services are registered in `app/Config/Services.php`:

- `Services::userService()` → `UserService`
- `Services::authService()` → `AuthService`
- `Services::rbacService()` → `RbacService`
- `Services::jwtService()` → `JWTService`
- `Services::authContext()` → `AuthContext` (shared per request)

Filter aliases in `app/Config/Filters.php`: `jwt`, `permission`.

---

## Request lifecycle

### 1. Routing

`app/Config/Routes.php` loads `Config/Routes/Api.php`, which loads `ApiV1.php`.

For any URL containing `/api/`, a custom 404 override returns a JSON `ApiResponse` error (not HTML).

### 2. Global filters

Before every request:

- `cors` — allows cross-origin calls (`allowedOrigins: ['*']` by default)
- `forcehttps` / `pagecache` — framework required filters

Protected routes also run the `jwt` filter before the controller.

### 3. Controllers

Every action wraps work in `ApiController::handleApi()`:

1. Parse JSON via `getJsonPayload()` (invalid JSON → `400 BadRequestException`)
2. Validate with `validateData(...)` (failure → `422` with field errors)
3. Call the service
4. Return `success` / `created` / etc.

`handleApi()` catches:

| Exception | HTTP |
|-----------|------|
| `UnauthorizedException` | 401 |
| `ForbiddenException` | 403 |
| `NotFoundException` | 404 |
| `BadRequestException` | 400 |
| `ApiException` | custom status |
| other `Throwable` | 500 (logged) |

### 4. JwtAuth filter (protected routes)

1. Read `Authorization: Bearer <token>`
2. Verify signature / expiry via `JWTService::verify()`
3. Reject if `jti` is missing or present in the denylist
4. Populate `AuthContext` from claims (`uid`, `email`, `jti`, `exp`)
5. Load the user's role + permission slugs from the DB into `AuthContext`
6. After the response, `AuthContext::reset()` clears request state

### 5. PermissionAuth filter

Applied per-route after `jwt`, e.g. `permission:users.list`.

- Reads required permission slug(s) from filter arguments (comma-separated = OR)
- Checks `AuthContext::hasPermission(...)`
- Missing permission → `403`

---

## Authentication & tokens

### Token types

| Token | Form | Lifetime (default) | Storage |
|-------|------|--------------------|---------|
| **Access** | JWT (HS256) | 900s (15 min) | Client only; `jti` tracked on refresh row + denylist on revoke |
| **Refresh** | Opaque hex string (128 chars) | 604800s (7 days) | DB as SHA-256 hash |

Access JWT claims:

```json
{
  "iss": "<base_url>",
  "iat": 1710000000,
  "exp": 1710000900,
  "jti": "<32-char hex>",
  "uid": 1,
  "email": "user@example.com"
}
```

### Login flow

```
POST /api/v1/login { email, password }
  → AuthService::login()
      → find user by email
      → password_verify
      → reject if status ≠ Active
      → issueTokens()
```

`issueTokens()`:

1. Generate access JWT (`jti` + `exp`)
2. Generate random refresh token
3. Upsert into `refresh_tokens` (one row per user currently — update if exists)
4. Store plain refresh value only in the response; DB stores `hash('sha256', token)`
5. Also store `access_jti` + `access_expires_at` on that refresh row (so later revoke/refresh can denylist the paired access token)

Response `data`:

```json
{
  "user": {
    "id": 1,
    "name": "...",
    "email": "...",
    "phone": "...",
    "status": "Active",
    "role": "user",
    "permissions": ["users.view"],
    "created_at": "...",
    "updated_at": "..."
  },
  "access_token": "...",
  "refresh_token": "...",
  "token_type": "Bearer",
  "expires_in": 900
}
```

New registrations automatically receive the default role from `Config\Rbac::$defaultRole` (`user`) via `users.role_id`.

### Refresh (rotation)

```
POST /api/v1/auth/refresh { refresh_token }
```

1. Look up by SHA-256 of the plain token
2. Ensure not revoked and not past `expires_at`
3. Denylist the old access `jti` (from the refresh row)
4. Revoke the old refresh row
5. Issue a **new** access + refresh pair

Reusing an old refresh token fails with `401`. The previous access JWT is rejected immediately via denylist.

### Revoke

```
POST /api/v1/auth/revoke { refresh_token }
```

No access token required. Finds the refresh row, denylists its paired access JWT, marks refresh as revoked.

### Logout (authenticated)

```
POST /api/v1/auth/logout
Authorization: Bearer <access_token>
Body (optional): { "refresh_token": "..." }
```

Always denylists the **current** access token (`jti` + `exp` from `AuthContext`).

Then:

- If `refresh_token` is provided → revoke that specific refresh (+ denylist its linked access if different)
- If omitted / empty body → `revokeAll()` for the user (all active refresh rows + their linked access JTIs)

### Why a denylist?

JWTs are self-contained; they remain valid until `exp` unless the server tracks revocation. On logout/refresh/revoke, the access token’s `jti` is inserted into `jwt_denylist` until its natural expiry. The `JwtAuth` filter checks this on every protected request. Expired denylist rows are purged opportunistically on insert.

---

## RBAC (roles & permissions)

Access is **role-based**: users are assigned roles; roles grant permissions; routes require permission slugs.

### Design rules

| Rule | Behavior |
|------|----------|
| Permissions | Defined in `app/Config/Rbac.php` and **seeded only** — no create/update/delete API |
| Roles | Same — seeded from config; no role CRUD API |
| Assign access | Set the user's single role via `PUT /api/v1/users/{id}/role` |
| Default role | New users get `user` on register (`users.role_id`) |
| Enforcement | Route filter `permission:<slug>` (OR if multiple slugs) |

### Seeded permissions

| Slug | Meaning |
|------|---------|
| `users.list` | List users |
| `users.view` | View one user |
| `users.update` | Update a user |
| `users.delete` | Soft-delete a user |
| `roles.list` | List roles |
| `roles.view` | View a role (+ its permissions) |
| `roles.assign` | Assign roles to a user |
| `permissions.list` | List the permission catalog |

### Seeded roles

| Role | Permissions |
|------|-------------|
| `admin` | All permissions (`*`) |
| `manager` | `users.list`, `users.view`, `users.update`, `roles.list`, `roles.view`, `permissions.list` |
| `user` | `users.view` |

To change the catalog, edit `Config\Rbac` and re-run:

```bash
php spark db:seed RbacSeeder
```

The seeder upserts by slug and rebuilds `role_permissions` (safe to re-run).

### How enforcement works

1. `JwtAuth` loads the caller's permission slugs into `AuthContext` on every request (from DB — not embedded in the JWT).
2. Route declares `['filter' => 'permission:users.delete']`.
3. `PermissionAuth` allows the request only if the context has that permission; otherwise `403`.

Role changes take effect on the **next** request (no token re-issue required), because permissions are resolved from the database each time.

### Bootstrapping the first admin

Registration always assigns `user`. Promote the first account outside the API (or temporarily from a one-off script):

```bash
php spark tinker
# or in a temporary seeder / CLI:
```

```php
\Config\Services::rbacService()->assignRole($userId, 'admin');
```

After that, use `PUT /api/v1/users/{id}/role` (requires `roles.assign`) for further assignments.

### Assigning a role

```http
PUT /api/v1/users/5/role
Authorization: Bearer <access_token>
Content-Type: application/json

{ "role_slug": "manager" }
```

Requires `roles.assign`. Sets the user's single `role_id` (replaces any previous role).

---

## API endpoints

Base path: `/api/v1`

### Public

| Method | Path | Body | Description |
|--------|------|------|-------------|
| `POST` | `/register` | name, phone, email, status, password | Create user (`201`) |
| `POST` | `/login` | email, password | Issue tokens |
| `POST` | `/auth/refresh` | refresh_token | Rotate tokens |
| `POST` | `/auth/revoke` | refresh_token | Revoke refresh (+ linked access) |

### Protected (`jwt` filter; permission noted per route)

| Method | Path | Permission | Description |
|--------|------|------------|-------------|
| `GET` | `/users` | `users.list` | List users (cursor pagination) |
| `GET` | `/users/{id}` | `users.view` | Show one user (includes `role` + `permissions`) |
| `PUT` / `PATCH` | `/users/{id}` | `users.update` | Update user |
| `DELETE` | `/users/{id}` | `users.delete` | Soft-delete user |
| `PUT` | `/users/{id}/role` | `roles.assign` | Set the user's single role (`role_slug`) |
| `GET` | `/roles` | `roles.list` | List roles (`?with_permissions=1` optional) |
| `GET` | `/roles/{id}` | `roles.view` | Show role with permissions |
| `GET` | `/permissions` | `permissions.list` | List seeded permissions (read-only) |
| `POST` | `/auth/logout` | *(authenticated only)* | Denylist access; revoke refresh token(s) |

### Register / update validation

**Register**

| Field | Rules |
|-------|-------|
| `name` | required, 3–100 chars |
| `phone` | required, numeric, exact 10, unique |
| `email` | required, valid email, unique |
| `status` | required, `Active` or `Inactive` |
| `password` | required, min 8 |

**Update** — all fields `permit_empty`; uniqueness ignores current user id; empty payload → `400`.

**Login** — email + password (min 8).  
**Refresh / revoke** — `refresh_token` length 64–128.

### List users query

```
GET /api/v1/users?cursor=<last_id>&per_page=25
```

| Param | Default | Max | Notes |
|-------|---------|-----|-------|
| `cursor` | none (start) | — | Return rows with `id > cursor` |
| `per_page` | 25 | 100 | Fetches `per_page + 1` to set `has_more` |

Response `data` shape:

```json
{
  "items": [ /* public user objects */ ],
  "pagination": {
    "per_page": 25,
    "next_cursor": 42,
    "has_more": true
  }
}
```

Pass `next_cursor` as the next request’s `cursor` until `has_more` is `false`.

---

## Response format

All JSON responses use `App\Libraries\ApiResponse`:

**Success**

```json
{
  "success": true,
  "message": "Login successful.",
  "data": { },
  "errors": null,
  "timestamp": "2026-07-15T15:00:00+00:00",
  "status": 200
}
```

**Error**

```json
{
  "success": false,
  "message": "Validation failed",
  "data": null,
  "errors": { "email": "..." },
  "timestamp": "2026-07-15T15:00:00+00:00",
  "status": 422
}
```

Common statuses: `200`, `201`, `400`, `401`, `404`, `422`, `500`.

Passwords and `deleted_at` are stripped via `User::toPublic()` before leaving the API.

---

## Database schema

### `users`

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT PK | Auto-increment |
| `name` | VARCHAR(100) | |
| `email` | VARCHAR(255) | Unique |
| `phone` | VARCHAR(10) | Unique |
| `status` | ENUM(`Active`,`Inactive`) | Default `Inactive` |
| `password` | VARCHAR(255) | Hashed on insert/update (`PASSWORD_DEFAULT`) |
| `created_at` / `updated_at` | DATETIME | |
| `deleted_at` | DATETIME | Soft deletes |

Index `(deleted_at, id)` supports cursor pagination.

### `refresh_tokens`

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT PK | |
| `user_id` | BIGINT FK → `users.id` | CASCADE |
| `refresh_token` | VARCHAR(255) | **SHA-256 hash** of plain token (unique) |
| `access_jti` | VARCHAR(64) | Linked access JWT id |
| `access_expires_at` | DATETIME | When that access JWT expires |
| `expires_at` | DATETIME | Refresh expiry |
| `revoked` | TINYINT | 0/1 |
| timestamps | | |

`RefreshToken` model hashes on `beforeInsert` / `beforeUpdate`. Lookup always hashes the client plain token first.

Current `saveToken()` keeps **one row per user** (update existing, else insert).

### JWT denylist table

Migration creates `jwt_denylist` with:

| Column | Notes |
|--------|-------|
| `jti` | Unique access-token id |
| `user_id` | Optional |
| `expires_at` | Row meaningful until this time |
| `created_at` | |

### `roles` / `permissions`

| Table | Key columns |
|-------|-------------|
| `roles` | `id`, `name`, `slug` (unique), `description`, timestamps |
| `permissions` | `id`, `name`, `slug` (unique), `description`, timestamps |
| `role_permissions` | composite PK `(role_id, permission_id)` |
| `users.role_id` | FK-style pointer to the user's single role (nullable until assigned) |

---

## Layer-by-layer guide

### Controllers

- **`ApiController`** — shared JSON helpers, validation error shaping, exception → HTTP mapping.
- **`AuthController`** — login, refresh, revoke, logout; delegates to `AuthService`; logout reads identity from `AuthContext`.
- **`UserController`** — register + resource CRUD + `assignRole`; list builds cursor/`per_page` from query string.
- **`RoleController`** — read-only list/show (seeded roles).
- **`PermissionController`** — read-only list (seeded permissions).

### Services

- **`AuthService`** — credential check, token issue/rotate/revoke, denylist coordination; login/refresh return RBAC-enriched user.
- **`UserService`** — register (assigns default role)/find/list/update/delete; cursor pagination; role sync.
- **`RbacService`** — list roles/permissions, assign default/single role, enrich user with role/permissions.
- **`AuthContext`** — holds authenticated `id`, `email`, `jti`, `tokenExp`, `role`, `permissions` for the current request only.

### Models

- **`User`** — soft deletes, password hashing callback, `findByEmail`, `paginateByCursor`, `toPublic`.
- **`RefreshToken`** — hash-on-save, find/validate/revoke helpers.
- **`JwtDenylist`** — `deny()`, `isDenied()`, `purgeExpired()`.
- **`Role` / `Permission`** — RBAC catalog; user role lives on `users.role_id`.

### Libraries

- **`JWTService`** — encode/decode access JWT; generate refresh entropy (`bin2hex(random_bytes(64))`).
- **`ApiResponse`** — success/error envelope.

### Exceptions

Hierarchy under `ApiException`:

- `UnauthorizedException` (401)
- `ForbiddenException` (403)
- `NotFoundException` (404)
- `BadRequestException` (400, optional `errors` payload)

---

## Configuration & setup

### Requirements

- PHP 8.2+
- Extensions: `intl`, `mbstring`, `json`, MySQL (`mysqlnd`) recommended
- Composer

### Install

```bash
composer install
cp env .env
```

Edit `.env` at least:

```ini
CI_ENVIRONMENT = development

app.baseURL = 'http://localhost:8080/'

database.default.hostname = localhost
database.default.database = your_db
database.default.username = your_user
database.default.password = your_pass
database.default.DBDriver = MySQLi

jwt.secret = a-long-random-secret-at-least-32-chars
```

JWT timings live in `app/Config/JWT.php` (defaults: access `900`, refresh `604800`). Secret is loaded from `env('jwt.secret')`.

### Migrate & seed

```bash
php spark migrate
php spark db:seed RbacSeeder
```

`DatabaseSeeder` also calls `RbacSeeder` (`php spark db:seed`).

### Serve

Point the web server document root at `public/` (not the project root). Example:

```bash
php spark serve
```

Or use the included `Caddyfile` / FrankenPHP worker as preferred for your environment.

### Typical client flow

1. `POST /api/v1/register` → create user (`status: Active` if they should be able to log in); receives default `user` role
2. `POST /api/v1/login` → store `access_token` + `refresh_token`; inspect `user.permissions`
3. Call protected APIs with `Authorization: Bearer <access_token>` (must hold the route permission)
4. An admin with `roles.assign` promotes users via `PUT /api/v1/users/{id}/role`
5. On `401` “expired” → `POST /api/v1/auth/refresh` with refresh token; replace both tokens
6. On logout → `POST /api/v1/auth/logout` with Bearer header (optionally include refresh token)

---

## Testing

Feature coverage:

- `tests/feature/AuthApiTest.php` — register/login, tokens, user CRUD (with elevated roles where needed), refresh/revoke/logout
- `tests/feature/RbacApiTest.php` — default user `403`s, admin role/permission listing, role assignment, manager vs admin capability split

Both seed `RbacSeeder` via `DatabaseTestTrait`.

Run:

```bash
composer test
# or
vendor/bin/phpunit
```

Ensure `database.tests.*` in `.env` points at a dedicated test database (SQLite `:memory:` is configured by default under the `tests` group).

---

## Security notes

1. **Never log or persist plain refresh tokens** — only SHA-256 hashes are stored.
2. **Access tokens are short-lived**; use refresh rotation rather than long-lived JWTs.
3. **Logout / refresh / revoke denylist the access `jti`** so stolen tokens stop working before natural expiry.
4. **Inactive users cannot log in** even with a valid password.
5. **Passwords** use PHP `password_hash` / `password_verify` via model callbacks.
6. Set a strong unique `jwt.secret` in production; do not commit `.env`.
7. Soft-deleted users remain in DB but are excluded from cursor listing (`deleted_at IS NULL`).
8. CORS is wide open by default (`*`) — tighten `app/Config/Cors.php` for production frontends.
9. **Permissions are not editable via API** — change `Config\Rbac` and re-seed.
10. **Authorization is checked every request** from DB-backed roles (JWT does not embed permissions).

---

## Quick reference: key files

```
app/Config/Routes/ApiV1.php          # Routes + permission filters
app/Config/Rbac.php                  # Permission/role catalog (source of seed)
app/Config/JWT.php                   # Secret + TTLs
app/Config/Services.php              # DI factories
app/Config/Filters.php               # jwt + permission aliases
app/Filters/JwtAuth.php              # Bearer + denylist + load RBAC
app/Filters/PermissionAuth.php       # Permission gate
app/Controllers/Api/V1/AuthController.php
app/Controllers/Api/V1/UserController.php
app/Controllers/Api/V1/RoleController.php
app/Controllers/Api/V1/PermissionController.php
app/Services/V1/AuthService.php
app/Services/V1/UserService.php
app/Services/V1/RbacService.php
app/Libraries/JWTService.php
app/Models/V1/{User,RefreshToken,JwtDenylist,Role,Permission}.php
app/Database/Seeds/RbacSeeder.php
tests/feature/AuthApiTest.php
tests/feature/RbacApiTest.php
```

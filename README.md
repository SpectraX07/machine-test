# Machine Test API (CodeIgniter 4)

JWT-authenticated REST API built on CodeIgniter 4 for user registration, login, token lifecycle (refresh / revoke / logout), and CRUD user management with cursor pagination.

---

## Table of contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Request lifecycle](#request-lifecycle)
4. [Authentication & tokens](#authentication--tokens)
5. [API endpoints](#api-endpoints)
6. [Response format](#response-format)
7. [Database schema](#database-schema)
8. [Layer-by-layer guide](#layer-by-layer-guide)
9. [Configuration & setup](#configuration--setup)
10. [Testing](#testing)
11. [Security notes](#security-notes)

---

## Overview

| Concern | Implementation |
|--------|----------------|
| Framework | CodeIgniter 4 (`^4.7`), PHP `^8.2` |
| Auth | JWT access tokens (HS256) + opaque refresh tokens |
| JWT library | `firebase/php-jwt` |
| Access revocation | JWT denylist by `jti` |
| Refresh storage | SHA-256 hashed tokens in `refresh_tokens` |
| API style | Versioned under `/api/v1`, JSON in/out |
| Pagination | Keyset / cursor (`id > cursor`), not OFFSET |

Public routes: `register`, `login`, `auth/refresh`, `auth/revoke`.  
Protected routes (require `Authorization: Bearer <access_token>`): user CRUD + `auth/logout`.

---

## Architecture

The app follows a thin-controller / service / model layout, versioned under `V1`:

```
Request
  → Filters (CORS, then optional JwtAuth)
  → Controller (validate JSON, call service, map to HTTP)
  → Service (business rules, orchestration)
  → Model (persistence, hashing callbacks)
  → Response (ApiResponse envelope)
```

### Directory map

| Path | Role |
|------|------|
| `app/Config/Routes/ApiV1.php` | API route definitions |
| `app/Controllers/Api/V1/` | HTTP layer (`ApiController`, `AuthController`, `UserController`) |
| `app/Services/V1/` | Business logic (`AuthService`, `UserService`) |
| `app/Services/AuthContext.php` | Request-scoped JWT identity (set by filter) |
| `app/Models/V1/` | `User`, `RefreshToken`, `JwtDenylist` |
| `app/Libraries/` | `JWTService`, `ApiResponse` |
| `app/Filters/JwtAuth.php` | Bearer token verification + denylist check |
| `app/Validation/V1/` | Validation rule sets |
| `app/Exceptions/` | Domain exceptions mapped to HTTP statuses |
| `app/Database/Migrations/` | Schema |

### Dependency wiring

Services are registered in `app/Config/Services.php`:

- `Services::userService()` → `UserService`
- `Services::authService()` → `AuthService`
- `Services::jwtService()` → `JWTService`
- `Services::authContext()` → `AuthContext` (shared per request)

The `jwt` filter alias is registered in `app/Config/Filters.php` and applied to protected route groups.

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
| `NotFoundException` | 404 |
| `BadRequestException` | 400 |
| `ApiException` | custom status |
| other `Throwable` | 500 (logged) |

### 4. JwtAuth filter (protected routes)

1. Read `Authorization: Bearer <token>`
2. Verify signature / expiry via `JWTService::verify()`
3. Reject if `jti` is missing or present in the denylist
4. Populate `AuthContext` from claims (`uid`, `email`, `jti`, `exp`)
5. After the response, `AuthContext::reset()` clears request state

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
  "user": { "id", "name", "email", "phone", "status", "created_at", "updated_at" },
  "access_token": "...",
  "refresh_token": "...",
  "token_type": "Bearer",
  "expires_in": 900
}
```

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

## API endpoints

Base path: `/api/v1`

### Public

| Method | Path | Body | Description |
|--------|------|------|-------------|
| `POST` | `/register` | name, phone, email, status, password | Create user (`201`) |
| `POST` | `/login` | email, password | Issue tokens |
| `POST` | `/auth/refresh` | refresh_token | Rotate tokens |
| `POST` | `/auth/revoke` | refresh_token | Revoke refresh (+ linked access) |

### Protected (`jwt` filter)

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/users` | List users (cursor pagination) |
| `GET` | `/users/{id}` | Show one user |
| `PUT` / `PATCH` | `/users/{id}` | Update user |
| `DELETE` | `/users/{id}` | Soft-delete user |
| `POST` | `/auth/logout` | Denylist access; revoke one or all refresh tokens |

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

The model (`JwtDenylist`) uses table name `jwt_denylists` — keep model and migration table names aligned when deploying.

---

## Layer-by-layer guide

### Controllers

- **`ApiController`** — shared JSON helpers, validation error shaping, exception → HTTP mapping.
- **`AuthController`** — login, refresh, revoke, logout; delegates to `AuthService`; logout reads identity from `AuthContext`.
- **`UserController`** — register + resource CRUD; list builds cursor/`per_page` from query string.

### Services

- **`AuthService`** — credential check, token issue/rotate/revoke, denylist coordination.
- **`UserService`** — register/find/list/update/delete; cursor pagination (`DEFAULT_PER_PAGE=25`, `MAX=100`).
- **`AuthContext`** — holds authenticated `id`, `email`, `jti`, `tokenExp` for the current request only.

### Models

- **`User`** — soft deletes, password hashing callback, `findByEmail`, `paginateByCursor`, `toPublic`.
- **`RefreshToken`** — hash-on-save, find/validate/revoke helpers.
- **`JwtDenylist`** — `deny()`, `isDenied()`, `purgeExpired()`.

### Libraries

- **`JWTService`** — encode/decode access JWT; generate refresh entropy (`bin2hex(random_bytes(64))`).
- **`ApiResponse`** — success/error envelope.

### Exceptions

Hierarchy under `ApiException`:

- `UnauthorizedException` (401)
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

### Migrate

```bash
php spark migrate
```

### Serve

Point the web server document root at `public/` (not the project root). Example:

```bash
php spark serve
```

Or use the included `Caddyfile` / FrankenPHP worker as preferred for your environment.

### Typical client flow

1. `POST /api/v1/register` → create user (`status: Active` if they should be able to log in)
2. `POST /api/v1/login` → store `access_token` + `refresh_token`
3. Call protected APIs with `Authorization: Bearer <access_token>`
4. On `401` “expired” → `POST /api/v1/auth/refresh` with refresh token; replace both tokens
5. On logout → `POST /api/v1/auth/logout` with Bearer header (optionally include refresh token)

---

## Testing

Feature coverage lives in `tests/feature/AuthApiTest.php` (migrations refreshed per test):

- Register validation / register+login
- Invalid credentials → 401
- Protected route without token → 401
- Show / update / soft-delete user
- Cursor pagination across pages
- Refresh rotation (old refresh + old access rejected; new access works)
- Revoke blocks refresh
- Logout denylists access and invalidates refresh

Run:

```bash
composer test
# or
vendor/bin/phpunit
```

Ensure `database.tests.*` in `.env` points at a dedicated test database.

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

---

## Quick reference: key files

```
app/Config/Routes/ApiV1.php          # Routes
app/Config/JWT.php                   # Secret + TTLs
app/Config/Services.php              # DI factories
app/Config/Filters.php               # jwt alias + globals
app/Filters/JwtAuth.php              # Bearer + denylist gate
app/Controllers/Api/V1/AuthController.php
app/Controllers/Api/V1/UserController.php
app/Services/V1/AuthService.php      # Token lifecycle
app/Services/V1/UserService.php
app/Libraries/JWTService.php
app/Models/V1/{User,RefreshToken,JwtDenylist}.php
tests/feature/AuthApiTest.php
```

# Changelog

All notable changes to `auth-kit-permissions` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Initial roles & permissions plugin with a hybrid access-control model.
- `AccessControl` static primitive (statement → roles), in-memory, zero-query
  checks (mirrors better-auth's `createAccessControl`).
- Dynamic (database) roles/permissions/assignments with a single-query,
  per-request-memoized resolver.
- Polymorphic scoping (`scope_type` + `scope_id`) — organization/team/project
  ready; `null` = global. Bridge to the planned `auth-kit-organization` plugin.
- Unified `PermissionManager::check()` API (static fast path + dynamic fallback).
- `HasRoles` trait, `RoleService` for managing dynamic roles/assignments.
- `POST /auth-kit/permissions/check` endpoint (mirrors better-auth hasPermission).
- `before:permission.check` core hook so other plugins can observe/veto.
- Real benchmark vs `spatie/laravel-permission` (~65× faster on the static path).

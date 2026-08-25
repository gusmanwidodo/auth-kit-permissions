<?php

declare(strict_types=1);

namespace Gusmanwidodo\AuthKitPermissions\Support;

/**
 * A polymorphic authorization scope: (type, id). A null Scope means "global".
 *
 * This is the bridge to the future organization plugin: it will build scopes
 * with Scope::for('organization', $org->id) and everything below (assignments,
 * checks, memoization) already understands them — no schema or API change.
 */
final class Scope
{
    private function __construct(
        public readonly ?string $type,
        public readonly ?int $id,
    ) {
    }

    /** The global scope (no tenant/organization/team). */
    public static function global(): self
    {
        return new self(null, null);
    }

    public static function for(string $type, int $id): self
    {
        return new self($type, $id);
    }

    public function isGlobal(): bool
    {
        return $this->type === null && $this->id === null;
    }

    /** Stable cache key fragment for per-request memoization. */
    public function key(): string
    {
        return $this->isGlobal() ? 'global' : $this->type . ':' . $this->id;
    }
}

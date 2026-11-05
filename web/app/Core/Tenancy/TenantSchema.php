<?php

namespace App\Core\Tenancy;

use Illuminate\Database\Schema\Blueprint;

/**
 * Migration helpers for tenant tables.
 *
 * ref() adds a UUID column plus a composite foreign key (tenant_id, column) → (tenant_id, id),
 * so a row can only point at a row of the same institution, even if application code is wrong.
 */
final class TenantSchema
{
    public static function base(Blueprint $table, bool $uuid = true): void
    {
        $uuid ? $table->uuid('id')->primary() : $table->id();
        $table->foreignUuid('tenant_id')->constrained('tenants');
    }

    public static function ref(Blueprint $table, string $column, string $references, bool $nullable = false, bool $cascade = false): void
    {
        $col = $table->uuid($column);
        if ($nullable) {
            $col->nullable();
        }

        $name = substr($table->getTable().'_'.$column, 0, 58).'_tfk';
        $fk = $table->foreign(['tenant_id', $column], $name)->references(['tenant_id', 'id'])->on($references);
        if ($cascade) {
            $fk->cascadeOnDelete();
        }
    }

    /** Unique (tenant_id, id): the target of composite foreign keys. */
    public static function key(Blueprint $table): void
    {
        $table->unique(['tenant_id', 'id'], $table->getTable().'_tenant_id_id_unique');
    }
}

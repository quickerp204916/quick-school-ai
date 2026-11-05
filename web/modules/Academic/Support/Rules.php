<?php

namespace Modules\Academic\Support;

use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/** Validation helpers that stay inside the current institution. */
final class Rules
{
    public static function exists(string $table, array $where = []): Exists
    {
        $rule = (new Exists($table, 'id'))->where('tenant_id', TenantContext::id());
        // A closure binds values with their real type; a plain where() writes booleans as "" (rejected by PostgreSQL).
        foreach ($where as $col => $val) {
            $rule->where(fn ($q) => $q->where($col, $val));
        }

        return $rule;
    }

    public static function unique(string $table, string $column, ?string $ignoreId = null, array $where = []): Unique
    {
        $rule = (new Unique($table, $column))->where('tenant_id', TenantContext::id());
        foreach ($where as $col => $val) {
            $rule->where(fn ($q) => $q->where($col, $val));
        }

        return $ignoreId ? $rule->ignore($ignoreId) : $rule;
    }

    /** Validate and return only validated keys. Throws ValidationException (422 on the API, redirect back on web). */
    public static function check(array $data, array $rules, array $messages = [], ?callable $after = null): array
    {
        $v = Validator::make($data, $rules, $messages + self::messages());
        if ($after) {
            $v->after($after);
        }

        return $v->validate();
    }

    public static function messages(): array
    {
        return [
            'phone.regex' => 'Enter a 10-digit Indian mobile number.',
            '*.phone.regex' => 'Enter a 10-digit Indian mobile number.',
            'pincode.regex' => 'Enter a 6-digit PIN code.',
            'aadhaar_last4.digits' => 'Enter only the last 4 digits of the Aadhaar number.',
            'exists' => 'The selected :attribute was not found in this institution.',
        ];
    }

    public const PHONE = 'regex:/^[6-9]\d{9}$/';
}

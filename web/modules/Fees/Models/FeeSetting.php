<?php

namespace Modules\Fees\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Academic\Models\TenantModel;

class FeeSetting extends TenantModel
{
    protected $table = 'fee_settings';

    protected function casts(): array
    {
        return ['gateways' => 'array', 'razorpay_key_secret' => 'encrypted', 'razorpay_webhook_secret' => 'encrypted', 'cashfree_secret_key' => 'encrypted',
            'reminder_days_before' => 'array', 'reminder_days_after' => 'array', 'reminders_enabled' => 'boolean'];
    }

    protected $hidden = ['razorpay_key_secret', 'razorpay_webhook_secret', 'cashfree_secret_key'];

    public static function current(): self
    {
        return self::firstOrCreate([], ['gateways' => [], 'reminder_days_before' => [7, 1], 'reminder_days_after' => [1, 7, 15]]);
    }

    public function enabled(string $gateway): bool
    {
        return in_array($gateway, $this->gateways ?? [], true) && match ($gateway) {
            'razorpay' => filled($this->razorpay_key_id) && filled($this->razorpay_key_secret),
            'cashfree' => filled($this->cashfree_app_id) && filled($this->cashfree_secret_key),
            default => false,
        };
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Platform\Models\Plan;

/**
 * SAMPLE plans and prices. Change them in the console (Plans) before launch.
 * Re-running is safe: plans are matched by code.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            'starter' => [
                'name' => 'Starter', 'description' => 'Single-campus schools up to 300 students.',
                'price_monthly' => '2999.00', 'price_yearly' => '29990.00', 'trial_days' => 14, 'is_public' => true, 'sort_order' => 1,
                'features' => [
                    'students_max' => 300, 'branches_max' => 1, 'staff_max' => 40, 'ai_credits_monthly' => 0,
                    'ai_assistant' => false, 'whatsapp' => true, 'transport' => false, 'library_inventory' => false,
                    'multi_branch' => false, 'custom_domain' => false, 'api_access' => false, 'voice_agent' => false,
                ],
            ],
            'growth' => [
                'name' => 'Growth', 'description' => 'Growing schools with AI and transport, up to 1,500 students.',
                'price_monthly' => '7999.00', 'price_yearly' => '79990.00', 'trial_days' => 14, 'is_public' => true, 'sort_order' => 2,
                'features' => [
                    'students_max' => 1500, 'branches_max' => 2, 'staff_max' => 150, 'ai_credits_monthly' => 2000,
                    'ai_assistant' => true, 'whatsapp' => true, 'transport' => true, 'library_inventory' => true,
                    'multi_branch' => true, 'custom_domain' => false, 'api_access' => false, 'voice_agent' => false,
                ],
            ],
            'enterprise' => [
                'name' => 'Enterprise', 'description' => 'School groups with many branches. Priced per agreement.',
                'price_monthly' => '19999.00', 'price_yearly' => '199990.00', 'trial_days' => 30, 'is_public' => false, 'sort_order' => 3,
                'features' => [
                    'students_max' => 10000, 'branches_max' => 20, 'staff_max' => 1000, 'ai_credits_monthly' => 10000,
                    'ai_assistant' => true, 'whatsapp' => true, 'transport' => true, 'library_inventory' => true,
                    'multi_branch' => true, 'custom_domain' => true, 'api_access' => true, 'voice_agent' => true,
                ],
            ],
        ];

        foreach ($plans as $code => $data) {
            $features = $data['features'];
            unset($data['features']);

            $plan = Plan::updateOrCreate(['code' => $code], $data + ['currency' => 'INR', 'is_active' => true]);

            foreach ($features as $feature => $value) {
                $isBool = config("platform.features.{$feature}.type") === 'bool';
                $plan->features()->updateOrCreate(['feature' => $feature], [
                    'enabled' => $isBool ? (bool) $value : true,
                    'limit_value' => $isBool ? null : $value,
                ]);
            }
        }
    }
}

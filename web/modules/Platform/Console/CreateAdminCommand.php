<?php

namespace Modules\Platform\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Modules\Platform\Models\PlatformAdmin;
use Modules\Platform\Services\PlatformAudit;

class CreateAdminCommand extends Command
{
    protected $signature = 'platform:create-admin {email} {name} {--role=super_admin : super_admin, billing or support}';

    protected $description = 'Create a Super Admin console user (asks for the password)';

    public function handle(): int
    {
        $data = ['email' => strtolower($this->argument('email')), 'name' => $this->argument('name'), 'role' => $this->option('role')];

        $validator = Validator::make($data, [
            'email' => ['required', 'email', 'unique:platform_admins,email'],
            'name' => ['required', 'max:150'],
            'role' => ['required', 'in:'.implode(',', array_keys(config('platform.roles')))],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $password = $this->secret('Password (at least 12 characters)');
        if (! is_string($password) || strlen($password) < 12) {
            $this->error('The password must be at least 12 characters.');

            return self::FAILURE;
        }

        $admin = PlatformAdmin::create($data + ['password' => $password, 'is_active' => true]);
        PlatformAudit::record('admin.created', $admin, null, ['email' => $admin->email, 'role' => $admin->role]);

        $this->info("Created {$admin->roleLabel()} {$admin->email}. They set up two-factor sign-in at first login.");

        return self::SUCCESS;
    }
}

<?php

namespace Modules\Academic\Models;

class CertificateTemplate extends TenantModel
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}

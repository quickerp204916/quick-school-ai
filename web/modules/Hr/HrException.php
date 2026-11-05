<?php

namespace Modules\Hr;

use Modules\Academic\Exceptions\AcademicRuleException;

/** An HR or payroll rule refused the action: a form error on the web, 422 on the API. */
class HrException extends AcademicRuleException
{
}

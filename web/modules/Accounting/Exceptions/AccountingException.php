<?php

namespace Modules\Accounting\Exceptions;

use Modules\Academic\Exceptions\AcademicRuleException;

/** An accounting rule refused the action. Rendered like other rule errors: a form error on web, 422 on the API. */
class AccountingException extends AcademicRuleException
{
}

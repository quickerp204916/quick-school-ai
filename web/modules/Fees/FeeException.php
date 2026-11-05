<?php

namespace Modules\Fees;

use Modules\Academic\Exceptions\AcademicRuleException;

/** A fee rule refused the action: a form error on the web, 422 on the API. */
class FeeException extends AcademicRuleException
{
}

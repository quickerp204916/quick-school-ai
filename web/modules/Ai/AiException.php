<?php

namespace Modules\Ai;

use Modules\Academic\Exceptions\AcademicRuleException;

/** An AI rule refused the action (disabled agent, no credits, service unreachable): a form error on the web, 422 on the API. */
class AiException extends AcademicRuleException
{
}

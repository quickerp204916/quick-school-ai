<?php

namespace Modules\Academic\Exceptions;

use RuntimeException;

/**
 * A business rule refused the action (for example "Class 5 – A is full").
 * Shown to the user as a form error on web pages and as HTTP 422 on the API.
 */
class AcademicRuleException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'rule')
    {
        parent::__construct($message);
    }
}

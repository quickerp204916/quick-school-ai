<?php

namespace Modules\Platform\Exceptions;

use RuntimeException;

/** A business rule stopped the action. The message is safe to show to platform staff. */
class PlatformRuleException extends RuntimeException
{
}

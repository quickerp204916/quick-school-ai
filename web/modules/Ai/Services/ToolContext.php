<?php

namespace Modules\Ai\Services;

/** The chat a tool call belongs to, while the AI service is calling tools for it (set by the tool endpoint). */
final class ToolContext
{
    public ?string $conversationId = null;
}

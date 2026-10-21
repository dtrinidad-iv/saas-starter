<?php

namespace App\Enums;

/**
 * How a chat message ended. User messages are always complete; an assistant
 * answer is stopped when the user cancelled it and failed when the AI call broke
 * off (both keep the text that arrived).
 */
enum ChatMessageStatus: string
{
    case Complete = 'complete';
    case Failed = 'failed';
    case Stopped = 'stopped';
}

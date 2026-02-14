<?php

namespace App\Enums;

enum WebhookResponseMode: string
{
    case Immediate = 'immediate';
    case Wait = 'wait';
}

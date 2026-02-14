<?php

namespace App\Enums;

enum TriggerType: string
{
    case Manual = 'manual';
    case Webhook = 'webhook';
    case Schedule = 'schedule';
    case Event = 'event';
}

<?php

namespace App\Enums;

enum ExecutionMode: string
{
    case Manual = 'manual';
    case Webhook = 'webhook';
    case Schedule = 'schedule';
    case Retry = 'retry';
}

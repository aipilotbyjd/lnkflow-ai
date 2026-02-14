<?php

namespace App\Enums;

enum WebhookAuthType: string
{
    case None = 'none';
    case Header = 'header';
    case Basic = 'basic';
    case Bearer = 'bearer';
}

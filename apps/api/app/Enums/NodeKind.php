<?php

namespace App\Enums;

enum NodeKind: string
{
    case Trigger = 'trigger';
    case Action = 'action';
    case Logic = 'logic';
    case Transform = 'transform';
}

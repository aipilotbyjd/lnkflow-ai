<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case Trialing = 'trialing';
    case Incomplete = 'incomplete';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Expired = 'expired';
}

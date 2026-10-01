<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case Planned = 'planned';
    case Pending = 'pending';
    case Paid = 'paid';
    case Received = 'received';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';
}

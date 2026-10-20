<?php

namespace App\Enums;

enum ProjectSummaryStatus: string
{
    case Pending = 'pending';
    case Draft = 'draft';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Failed = 'failed';
}

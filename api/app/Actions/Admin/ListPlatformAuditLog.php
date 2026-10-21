<?php

namespace App\Actions\Admin;

use App\Models\PlatformAuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListPlatformAuditLog
{
    /**
     * @return LengthAwarePaginator<int, PlatformAuditLog>
     */
    public function handle(): LengthAwarePaginator
    {
        return PlatformAuditLog::query()
            ->with('actor:id,name,email')
            ->latest('id')
            ->paginate(25);
    }
}

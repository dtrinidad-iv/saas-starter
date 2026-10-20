<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\AuditLog\IndexAuditLogRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * The current organization's audit log, newest first.
 */
class AuditLogController extends Controller
{
    public function index(IndexAuditLogRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AuditLog::class);

        $entries = AuditLog::query()
            ->with('actor:id,name')
            ->when($request->validated('action'), fn ($query, $action) => $query->where('action', $action))
            ->when($request->validated('actor_id'), fn ($query, $actorId) => $query->where('actor_id', $actorId))
            ->when($request->validated('from'), fn ($query, $from) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($request->validated('to'), fn ($query, $to) => $query->where('created_at', '<', Carbon::parse($to)->addDay()->startOfDay()))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return AuditLogResource::collection($entries);
    }

    /**
     * The action catalogue, for the filter.
     */
    public function actions(): JsonResponse
    {
        Gate::authorize('viewAny', AuditLog::class);

        return response()->json([
            'data' => array_map(fn (AuditAction $action) => [
                'value' => $action->value,
                'group' => $action->group(),
                'label' => $action->label(),
            ], AuditAction::cases()),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The permission catalogue, for building role forms.
 */
class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn (Permission $permission) => [
                'value' => $permission->value,
                'group' => $permission->group(),
                'label' => $permission->label(),
            ], Permission::cases()),
        ]);
    }
}

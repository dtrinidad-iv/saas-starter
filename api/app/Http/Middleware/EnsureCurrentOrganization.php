<?php

namespace App\Http\Middleware;

use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For routes that act on the current organization: refuse when the user has none
 * (or is no longer a member of it).
 */
class EnsureCurrentOrganization
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_if($this->currentOrganization->get() === null, 403, 'No active organization.');

        return $next($request);
    }
}

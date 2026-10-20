<?php

namespace App\Ai\Contracts;

use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Exceptions\AiRequestFailed;
use App\Ai\Exceptions\AiUnavailable;
use Closure;

/**
 * The only way the app talks to an AI provider. Resolved from the container,
 * already wrapped so every call is recorded in ai_usage.
 */
interface AiClient
{
    /**
     * @throws AiUnavailable when retrying later may work (rate limit, 5xx, timeout)
     * @throws AiRequestFailed when retrying won't help (bad request, refusal, invalid output)
     */
    public function generate(AiRequest $request): AiResponse;

    /**
     * Plain-text output, passed to $onText piece by piece as it arrives. Return false
     * from $onText to stop early (e.g. the browser went away): the response then has
     * stop reason "aborted" and the tokens counted so far. Requests with a schema
     * aren't supported.
     *
     * @param  Closure(string): (bool|void)  $onText
     *
     * @throws AiUnavailable when retrying later may work; $response holds what arrived before it broke off
     * @throws AiRequestFailed when retrying won't help
     */
    public function stream(AiRequest $request, Closure $onText): AiResponse;
}

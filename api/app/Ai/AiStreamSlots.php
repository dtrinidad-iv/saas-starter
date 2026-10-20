<?php

namespace App\Ai;

use App\Models\Organization;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Cache;

/**
 * Limits how many streamed answers run at once per organization (config
 * ai.concurrent_streams). Each holds a PHP worker until it ends, which the
 * per-minute rate limit alone doesn't bound.
 */
class AiStreamSlots
{
    /**
     * Take a free slot; release the returned lock when the stream ends. A slot
     * that is never released frees itself after the stream timeout.
     *
     * @throws ThrottleRequestsException 429 when every slot is taken
     */
    public function acquire(Organization $organization): Lock
    {
        $seconds = config('ai.stream_timeout') + 30;

        for ($slot = 0; $slot < config('ai.concurrent_streams'); $slot++) {
            $lock = Cache::lock("ai-stream:{$organization->id}:{$slot}", $seconds);

            if ($lock->get()) {
                return $lock;
            }
        }

        throw new ThrottleRequestsException('Too many AI answers are being written for this organization. Try again when one finishes.');
    }
}

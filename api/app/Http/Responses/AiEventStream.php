<?php

namespace App\Http\Responses;

use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Contracts\AiClient;
use App\Ai\Exceptions\AiBudgetExceeded;
use App\Ai\Exceptions\AiRequestFailed;
use App\Ai\Exceptions\AiUnavailable;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Support\Responsable;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Streams one AI answer as server-sent events:
 *  - delta {text}: the next piece of text;
 *  - done {model}: the answer is complete;
 *  - error {message}: it failed part-way (the text so far is not a valid answer).
 *
 * The status is always 200 once streaming starts, so check permissions, validation
 * and the budget before returning this. It runs as the organization that authorized
 * it (even if the user switches meanwhile) and releases its slot (AiStreamSlots) at the end. Not Laravel's eventStream(): that needs a
 * generator, and the AI client reports text through a callback.
 */
class AiEventStream implements Responsable
{
    /**
     * @param  int|null  $maxLength  Characters; a longer answer ends with an error instead of done.
     */
    public function __construct(
        private readonly Organization $organization,
        private readonly Lock $slot,
        private readonly AiRequest $request,
        private readonly ?int $maxLength = null,
    ) {}

    public function toResponse($request): StreamedResponse
    {
        return response()->stream(fn () => $this->run(), 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function run(): void
    {
        try {
            app(CurrentOrganization::class)->runAs($this->organization, fn () => $this->streamAnswer());
        } finally {
            $this->slot->release();
        }
    }

    private function streamAnswer(): void
    {
        // Keep running when the browser goes away, so the call is stopped and recorded
        // (ai_usage) by us instead of PHP ending the script mid-call.
        $ignoreAbort = ignore_user_abort(true);

        try {
            $response = app(AiClient::class)->stream($this->request, function (string $text) {
                $this->send('delta', ['text' => $text]);

                return connection_aborted() === 0;
            });

            $this->finish($response);
        } catch (AiBudgetExceeded) {
            $this->send('error', ['message' => 'This organization\'s monthly AI budget is used up.']);
        } catch (AiUnavailable) {
            $this->send('error', ['message' => 'The AI service is busy or unreachable. Try again in a moment.']);
        } catch (AiRequestFailed $e) {
            $this->send('error', ['message' => match ($e->errorCode) {
                'refusal' => 'The AI declined this request.',
                'max_tokens' => 'The answer was too long. Try again with shorter text.',
                default => 'The AI request failed.',
            }]);
        } catch (Throwable $e) {
            report($e);
            $this->send('error', ['message' => 'Something went wrong. Please try again.']);
        } finally {
            ignore_user_abort($ignoreAbort);
        }
    }

    private function finish(AiResponse $response): void
    {
        if ($response->stopReason === 'aborted') {
            return;
        }

        if ($this->maxLength !== null && mb_strlen(trim($response->text)) > $this->maxLength) {
            $this->send('error', ['message' => "The answer was longer than {$this->maxLength} characters. Try again."]);

            return;
        }

        $this->send('done', ['model' => $response->model]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(string $event, array $data): void
    {
        echo "event: {$event}\ndata: ".json_encode($data, JSON_THROW_ON_ERROR)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}

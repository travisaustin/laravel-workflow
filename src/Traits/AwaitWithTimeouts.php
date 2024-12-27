<?php

declare(strict_types=1);

namespace Workflow\Traits;

use Carbon\CarbonInterval;
use Illuminate\Database\QueryException;
use React\Promise\PromiseInterface;
use Workflow\Serializers\SerializerInterface;
use function React\Promise\resolve;
use Workflow\Signal;

trait AwaitWithTimeouts
{
    public static function awaitWithTimeout($seconds, $condition): PromiseInterface
    {
        $log = self::$context->storedWorkflow->logs()
            ->whereIndex(self::$context->index)
            ->first();

        if ($log) {
            ++self::$context->index;
            return resolve(app(SerializerInterface::class)->unserialize($log->result));
        }

        if (is_string($seconds)) {
            $seconds = CarbonInterval::fromString($seconds)->totalSeconds;
        }

        $result = $condition();

        if ($result === true) {
            if (! self::$context->replaying) {
                try {
                    self::$context->storedWorkflow->logs()
                        ->create([
                            'index' => self::$context->index,
                            'now' => self::$context->now,
                            'class' => Signal::class,
                            'result' => app(SerializerInterface::class)->serialize($result),
                        ]);
                } catch (QueryException $exception) {
                    $log = self::$context->storedWorkflow->logs()
                        ->whereIndex(self::$context->index)
                        ->first();

                    if ($log) {
                        ++self::$context->index;
                        return resolve(app(SerializerInterface::class)->unserialize($log->result));
                    }
                }
            }
            ++self::$context->index;
            return resolve($result);
        }

        return self::timer($seconds)->then(static fn ($completed): bool => ! $completed);
    }
}

<?php

use App\Kafka\DeliveryFailed;
use App\Kafka\Producer;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// Public data plane (APP_ROLE=ingest): form page, definition JSON, submission intake.

// TEMP: removed when the submission endpoint lands. Proves ack-after-durability inside Octane workers.
if (app()->environment('local')) {
    Route::get('/internal/kafka-ping', function (Producer $producer) {
        $start = hrtime(true);

        try {
            $producer->send(
                config('kafka.topics.submissions'),
                (string) Str::uuid7(),
                json_encode(['ping' => true, 'sent_at' => now()->toIso8601String()]),
            );
        } catch (DeliveryFailed $e) {
            return response()->json(['error' => $e->getMessage()], 503, ['Retry-After' => '5']);
        }

        return response()->json(['acked_ms' => round((hrtime(true) - $start) / 1e6, 2)]);
    });
}

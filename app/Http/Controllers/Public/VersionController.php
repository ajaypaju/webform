<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Ingest\VersionStore;
use Illuminate\Http\JsonResponse;

final class VersionController extends Controller
{
    public function show(VersionStore $store, string $form, string $version): JsonResponse
    {
        $record = $store->version($form, $version) ?? abort(404);
        unset($record['tenant_id']);

        // WHY: versions never change, so a CDN and the browser may keep this for a year.
        return response()->json($record)->header('Cache-Control', 'public, max-age=31536000, immutable');
    }

    public function current(VersionStore $store, string $form): JsonResponse
    {
        $state = $store->form($form);

        if ($state === null || $state['current_version_id'] === null) {
            abort(404);
        }

        return response()->json(['current_version_id' => $state['current_version_id']])
            ->header('Cache-Control', 'public, max-age=30, stale-while-revalidate=60');
    }
}

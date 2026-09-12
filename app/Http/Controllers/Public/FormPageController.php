<?php

namespace App\Http\Controllers\Public;

use App\Forms\Visibility;
use App\Http\Controllers\Controller;
use App\Ingest\RenderToken;
use App\Ingest\VersionStore;
use Illuminate\Http\Response;

final class FormPageController extends Controller
{
    public function __invoke(VersionStore $store, RenderToken $tokens, string $form): Response
    {
        $state = $store->form($form);

        if ($state === null || $state['status'] !== 'published' || $state['current_version_id'] === null) {
            abort(404);
        }

        $version = $store->version($form, $state['current_version_id']) ?? abort(404);
        $fields = $version['definition']['fields'];

        // WHY: no-store because the page carries a per-render token (I13). A cacheable shell plus a token endpoint
        // is the designed CDN path (ARCHITECTURE §4).
        return response()->view('form.page', [
            'formId' => $form,
            'version' => $version,
            'fields' => $fields,
            'visible' => Visibility::evaluate($fields, []),
            'token' => $tokens->issue($form, $version['id']),
        ])->header('Cache-Control', 'no-store');
    }
}

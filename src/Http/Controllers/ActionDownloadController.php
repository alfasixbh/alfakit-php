<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers;

use AlfaSix\Alfakit\Support\ActionDownload;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET (signed): the file an action handler returned, once, to the user who ran the action (see ActionDownload).
 */
class ActionDownloadController
{
    public function __invoke(Request $request): BinaryFileResponse
    {
        // By name: tenant panels have a `{tenant}` parameter before it.
        $download = (string) $request->route('download');

        abort_unless(hash_equals(ActionDownload::ownerKey(), (string) $request->query('owner', '')), 403);
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $download) === 1, 404);

        $path = ActionDownload::path($download);

        abort_unless(is_file($path), 404);

        $name = (string) $request->query('name', 'download');

        return response()
            ->download($path, $name !== '' ? $name : 'download', ['Content-Type' => (string) $request->query('type', 'application/octet-stream')])
            ->deleteFileAfterSend();
    }
}

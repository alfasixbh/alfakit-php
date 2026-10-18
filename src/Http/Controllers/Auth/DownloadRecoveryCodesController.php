<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers\Auth;

use AlfaSix\Alfakit\Auth\MultiFactor\MultiFactorAuthentication;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Downloads the recovery codes generated in this session (they are stored hashed, so they
 * can only be shown right after they are generated).
 */
class DownloadRecoveryCodesController
{
    public function __invoke(): Response
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();
        $codes = MultiFactorAuthentication::getFlashedRecoveryCodes($panel);

        abort_if($codes === null, 404);

        $name = Str::slug($panel->getBrandName()).'-recovery-codes.txt';

        return response(implode("\n", $codes)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}

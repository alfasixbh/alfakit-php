<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\Pages\Concerns;

use Illuminate\Support\Arr;
use Inertia\Inertia;

/**
 * `refreshFormData(['status'])` on Edit and View pages: after an action changed the record,
 * copy those attributes from `$this->record` into the form the user sees, without
 * touching the fields they are editing. Call `$this->record->refresh()` first when the
 * record was changed through another instance.
 */
trait CanRefreshFormData
{
    /** Flash key carrying `{component, state}` to the renderer's page form. */
    public const FORM_REFRESH_FLASH = 'alfakitFormRefresh';

    /** @var array<string, mixed> State paths refreshed during this request, with their new values. */
    protected array $refreshedFormData = [];

    /**
     * @param  array<int, string>  $statePaths  Attributes (state paths) to copy from the record
     */
    public function refreshFormData(array $statePaths): void
    {
        // Rebuild the form from the record as it is now (the action may have changed it).
        $this->cachedForm = null;
        $form = $this->getForm();

        if ($form === null) {
            return;
        }

        $state = $form->getRawState();
        $hidden = $form->getHiddenStatePaths();

        foreach ($statePaths as $path) {
            // Hidden fields' values never reach the browser.
            if (in_array($path, $hidden, true) || ! Arr::has($state, $path)) {
                continue;
            }

            $this->refreshedFormData[$path] = Arr::get($state, $path);
        }

        if ($this->refreshedFormData !== []) {
            Inertia::flash(self::FORM_REFRESH_FLASH, ['component' => 'form', 'state' => $this->refreshedFormData]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getRefreshedFormData(): array
    {
        return $this->refreshedFormData;
    }
}

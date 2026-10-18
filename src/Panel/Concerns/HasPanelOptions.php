<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

/**
 * Layout, SPA, error notification, transaction and authorisation options of a panel.
 */
trait HasPanelOptions
{
    use HasDatabaseTransactions;
    use HasErrorNotifications;
    use HasLayoutOptions;
    use HasSpaUrlExceptions;
    use HasStrictAuthorization;

    /**
     * @return array<string, mixed>
     */
    protected function getPanelOptionsSchema(): array
    {
        return [
            ...$this->getLayoutOptionsSchema(),
            ...$this->getSpaOptionsSchema(),
            ...$this->getErrorNotificationsSchema(),
        ];
    }
}

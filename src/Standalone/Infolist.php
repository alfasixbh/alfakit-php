<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Standalone;

/**
 * A read-only infolist outside a panel, usually bound to a record:
 * `OrderDetails::make(['record' => $order])`. Entries read the record, and `actions()`
 * can offer buttons (with modals) next to it.
 */
abstract class Infolist extends Component {}

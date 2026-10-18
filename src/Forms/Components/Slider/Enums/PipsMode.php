<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\Slider\Enums;

/**
 * Where a slider's labelled pips go (`Slider::pips(PipsMode::Count)`), with
 * `pipsValues()` giving the details.
 */
enum PipsMode: string
{
    /** A labelled pip at every step of the track (range points get large labels). */
    case Steps = 'steps';

    /** Pips at the percentages in `pipsValues([0, 25, 50, 75, 100])`. */
    case Positions = 'positions';

    /** `pipsValues(5)` pips spread evenly over the track. */
    case Count = 'count';

    /** Pips at the values in `pipsValues([10, 20, 50])`. */
    case Values = 'values';
}

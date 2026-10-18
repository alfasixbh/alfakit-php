<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\Slider\Enums;

/**
 * How a slider responds to the pointer (`Slider::behavior([Behavior::Tap, Behavior::Drag])`).
 * The default is `[Behavior::Tap]`; `behavior(null)` turns everything off (thumbs can
 * still be dragged and moved with the keyboard).
 */
enum Behavior: string
{
    /** Pressing the track moves the nearest thumb there. */
    case Tap = 'tap';

    /** Dragging the filled range between two thumbs moves them together. */
    case Drag = 'drag';

    /** With `Drag`: the distance between the thumbs never changes. */
    case Fixed = 'fixed';

    /** Like `Tap` (the thumb jumps to the pressed spot). */
    case Snap = 'snap';

    /** Thumbs may be dragged past each other. */
    case Unconstrained = 'unconstrained';

    /** Thumbs move freely while dragged and snap to the nearest step on release. */
    case SmoothSteps = 'smooth-steps';
}

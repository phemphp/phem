<?php

declare(strict_types=1);

namespace Phem\Ui\Base;

/** The side of the anchor where a popup goes. */
enum Side: string
{
    case Top = 'top';
    case Bottom = 'bottom';
    case Left = 'left';
    case Right = 'right';
    case InlineStart = 'inline-start';
    case InlineEnd = 'inline-end';
}

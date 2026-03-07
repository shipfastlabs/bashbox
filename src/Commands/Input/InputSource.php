<?php

declare(strict_types=1);

namespace BashBox\Commands\Input;

enum InputSource
{
    case STDIN;
    case SINGLE_FILE;
    case MULTIPLE_FILES;
}

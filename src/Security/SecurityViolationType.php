<?php

declare(strict_types=1);

namespace BashBox\Security;

enum SecurityViolationType: string
{
    case PATH_TRAVERSAL = 'path_traversal';
    case NULL_BYTE = 'null_byte';
    case SYMLINK_ESCAPE = 'symlink_escape';
    case COMMAND_INJECTION = 'command_injection';
    case EXECUTION_LIMIT = 'execution_limit';
    case OUTPUT_LIMIT = 'output_limit';
    case NETWORK_DENIED = 'network_denied';
    case FORBIDDEN_BUILTIN = 'forbidden_builtin';
}

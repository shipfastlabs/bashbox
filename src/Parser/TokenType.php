<?php

declare(strict_types=1);

namespace BashBox\Parser;

enum TokenType: string
{
    case EOF = 'EOF';

    // Newlines and separators
    case NEWLINE = 'NEWLINE';
    case SEMICOLON = 'SEMICOLON';
    case AMP = 'AMP';

    // Operators
    case PIPE = 'PIPE';
    case PIPE_AMP = 'PIPE_AMP';
    case AND_AND = 'AND_AND';
    case OR_OR = 'OR_OR';
    case BANG = 'BANG';

    // Redirections
    case LESS = 'LESS';
    case GREAT = 'GREAT';
    case DLESS = 'DLESS';
    case DGREAT = 'DGREAT';
    case LESSAND = 'LESSAND';
    case GREATAND = 'GREATAND';
    case LESSGREAT = 'LESSGREAT';
    case DLESSDASH = 'DLESSDASH';
    case CLOBBER = 'CLOBBER';
    case TLESS = 'TLESS';
    case AND_GREAT = 'AND_GREAT';
    case AND_DGREAT = 'AND_DGREAT';

    // Grouping
    case LPAREN = 'LPAREN';
    case RPAREN = 'RPAREN';
    case LBRACE = 'LBRACE';
    case RBRACE = 'RBRACE';

    // Special
    case DSEMI = 'DSEMI';
    case SEMI_AND = 'SEMI_AND';
    case SEMI_SEMI_AND = 'SEMI_SEMI_AND';

    // Compound commands
    case DBRACK_START = 'DBRACK_START';
    case DBRACK_END = 'DBRACK_END';
    case DPAREN_START = 'DPAREN_START';
    case DPAREN_END = 'DPAREN_END';

    // Reserved words
    case IF = 'IF';
    case THEN = 'THEN';
    case ELSE = 'ELSE';
    case ELIF = 'ELIF';
    case FI = 'FI';
    case FOR = 'FOR';
    case WHILE = 'WHILE';
    case UNTIL = 'UNTIL';
    case DO = 'DO';
    case DONE = 'DONE';
    case CASE = 'CASE';
    case ESAC = 'ESAC';
    case IN = 'IN';
    case FUNCTION = 'FUNCTION';
    case SELECT = 'SELECT';
    case TIME = 'TIME';
    case COPROC = 'COPROC';

    // Words and identifiers
    case WORD = 'WORD';
    case NAME = 'NAME';
    case NUMBER = 'NUMBER';
    case ASSIGNMENT_WORD = 'ASSIGNMENT_WORD';
    case FD_VARIABLE = 'FD_VARIABLE';

    // Comments
    case COMMENT = 'COMMENT';

    // Here-document content
    case HEREDOC_CONTENT = 'HEREDOC_CONTENT';
}

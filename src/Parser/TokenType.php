<?php

declare(strict_types=1);

namespace BashBox\Parser;

enum TokenType
{
    case EOF;

    case NEWLINE;
    case SEMICOLON;
    case AMP;

    case PIPE;
    case PIPE_AMP;
    case AND_AND;
    case OR_OR;
    case BANG;

    case LESS;
    case GREAT;
    case DLESS;
    case DGREAT;
    case LESSAND;
    case GREATAND;
    case LESSGREAT;
    case DLESSDASH;
    case CLOBBER;
    case TLESS;
    case AND_GREAT;
    case AND_DGREAT;

    case LPAREN;
    case RPAREN;
    case LBRACE;
    case RBRACE;

    case DSEMI;
    case SEMI_AND;
    case SEMI_SEMI_AND;

    case DBRACK_START;
    case DBRACK_END;
    case DPAREN_START;
    case DPAREN_END;

    case IF;
    case THEN;
    case ELSE;
    case ELIF;
    case FI;
    case FOR;
    case WHILE;
    case UNTIL;
    case DO;
    case DONE;
    case CASE;
    case ESAC;
    case IN;
    case FUNCTION;
    case SELECT;
    case TIME;
    case COPROC;

    case WORD;
    case NAME;
    case NUMBER;
    case ASSIGNMENT_WORD;
    case FD_VARIABLE;

    case COMMENT;

    case HEREDOC_CONTENT;
}

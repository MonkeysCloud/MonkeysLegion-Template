<?php

declare(strict_types=1);

namespace MonkeysLegion\Template\Lexer;

/**
 * Token types recognized by the MLView template lexer.
 */
enum TokenType: string
{
    /** Raw text/HTML content between template constructs */
    case TEXT = 'TEXT';

    /** Escaped echo: {{ */
    case ECHO_OPEN = 'ECHO_OPEN';

    /** Escaped echo close: }} */
    case ECHO_CLOSE = 'ECHO_CLOSE';

    /** Raw echo: {!! */
    case RAW_ECHO_OPEN = 'RAW_ECHO_OPEN';

    /** Raw echo close: !!} */
    case RAW_ECHO_CLOSE = 'RAW_ECHO_CLOSE';

    /** Comment open: {{-- */
    case COMMENT_OPEN = 'COMMENT_OPEN';

    /** Comment close: --}} */
    case COMMENT_CLOSE = 'COMMENT_CLOSE';

    /** A @directive with optional arguments */
    case DIRECTIVE = 'DIRECTIVE';

    /** Component opening tag: <x-name> */
    case COMPONENT_OPEN = 'COMPONENT_OPEN';

    /** Component closing tag: </x-name> */
    case COMPONENT_CLOSE = 'COMPONENT_CLOSE';

    /** Self-closing component: <x-name /> */
    case COMPONENT_SELF_CLOSE = 'COMPONENT_SELF_CLOSE';

    /** End of file marker */
    case EOF = 'EOF';
}

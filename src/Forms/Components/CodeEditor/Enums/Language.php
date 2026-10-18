<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\CodeEditor\Enums;

/**
 * Languages the code editor highlights. Others can be passed as plain strings; they are
 * shown without highlighting.
 */
enum Language: string
{
    case Cpp = 'cpp';
    case Css = 'css';
    case Go = 'go';
    case Html = 'html';
    case Java = 'java';
    case JavaScript = 'javascript';
    case Json = 'json';
    case Markdown = 'markdown';
    case Php = 'php';
    case Python = 'python';
    case Sql = 'sql';
    case TypeScript = 'typescript';
    case Xml = 'xml';
    case Yaml = 'yaml';
}

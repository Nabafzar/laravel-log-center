<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Enums;

enum MessageFormatEnum: string
{
    case PLAIN = 'plain';
    case JSON = 'json';
    case MARKDOWN = 'markdown';
    case TELEGRAM_HTML = 'telegram_html';
    case BALE_MARKDOWN = 'bale_markdown';
}

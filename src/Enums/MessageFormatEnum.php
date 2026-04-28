<?php

namespace Nabafzar\LogCenter\Enums;

enum MessageFormatEnum: string
{
    case PLAIN = 'plain';
    case JSON = 'json';
    case MARKDOWN = 'markdown';
    case TELEGRAM_HTML = 'telegram_html';
    case SLACK_BLOCK = 'slack_block';
}
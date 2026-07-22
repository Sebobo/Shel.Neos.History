<?php

declare(strict_types=1);

namespace Shel\Neos\History\Domain\Dto;

enum ChangeType: string
{
    case ASSET = 'asset';
    case IMAGE = 'image';
    case TEXT = 'text';
    case DATETIME = 'datetime';
    case OTHER = 'other';
    case NODE = 'node';
    case ARRAY = 'array';
    case BOOLEAN = 'boolean';
}

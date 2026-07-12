<?php

declare(strict_types=1);

namespace DockerCli\Framework\Description;

enum FrameworkCodeName: string
{
    case Bitrix24 = 'bitrix24';
    case Bitrix = 'bitrix';
    case Laravel = 'laravel';
    case Symfony = 'symfony';
}

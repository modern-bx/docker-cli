<?php

declare(strict_types=1);

namespace DockerCli\Framework\Description;

enum FrameworkName: string
{
    case Bitrix24 = 'Bitrix24';
    case Bitrix = 'Bitrix';
    case Laravel = 'Laravel';
    case Symfony = 'Symfony';
}

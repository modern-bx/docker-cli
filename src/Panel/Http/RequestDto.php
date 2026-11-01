<?php

declare(strict_types=1);

namespace DockerCli\Panel\Http;

interface RequestDto
{
    public static function fromRequest(RequestData $request): static;
}

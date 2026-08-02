<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto;

final readonly class BackupsSettingsDto implements \JsonSerializable
{
    /**
     * @param list<array{path: string, code: string, default: bool}> $locations
     * @param list<array{name: string, code: string, include: list<string>, exclude: list<string>}> $fileStrategies
     */
    public function __construct(public array $locations, public array $fileStrategies, public array $databaseStrategies)
    {
    }

    /** @return array{locations: list<array{path: string, code: string, default: bool}>, fileStrategies: list<array{name: string, code: string, include: list<string>, exclude: list<string>}>} */
    public function jsonSerialize(): array
    {
        return ['locations' => $this->locations, 'fileStrategies' => $this->fileStrategies, 'databaseStrategies' => $this->databaseStrategies];
    }
}

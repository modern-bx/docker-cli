<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

/** JSON request accepted by POST /api/projects/{project}/update. */
final readonly class ProjectUpdateRequestDto implements RequestDto
{
    /** @param list<string>|null $dedicatedDatabases */
    public function __construct(
        public string $project,
        public ?string $name,
        public ?string $language,
        public ?string $languageVersion,
        public ?string $framework,
        public ?bool $external,
        public ?int $externalPort,
        public ?array $dedicatedDatabases,
        public string $locationMysql,
        public string $locationPostgres,
    ) {
    }

    public static function fromRequest(RequestData $request): static
    {
        foreach (["name", "language", "languageVersion", "framework"] as $field) {
            if (array_key_exists($field, $request->body) && !is_string($request->body[$field])) {
                throw new RequestValidationException("Параметры проекта должны быть строками.");
            }
        }
        $dedicated = $request->body["dedicatedDatabases"] ?? null;
        $external = $request->body["external"] ?? null;
        if ($external !== null && !is_bool($external)) {
            throw new RequestValidationException("Признак внешнего сервиса должен быть булевым значением.");
        }
        $externalPort = $request->body["externalPort"] ?? null;
        if ($externalPort !== null && (!is_int($externalPort) || $externalPort < 1 || $externalPort > 65535)) {
            throw new RequestValidationException("Порт внешнего сервиса должен быть числом от 1 до 65535.");
        }
        if (
            $dedicated !== null &&
            (!is_array($dedicated) ||
                !array_is_list($dedicated) ||
                array_filter(
                    $dedicated,
                    static fn (mixed $item): bool => !is_string($item) || !in_array($item, ["mysql", "postgres"], true),
                ) !== [] ||
                count(array_unique($dedicated)) !== count($dedicated))
        ) {
            throw new RequestValidationException("Некорректный список выделенных СУБД.");
        }
        $locationMysql = $request->body["locationMysql"] ?? "system";
        $locationPostgres = $request->body["locationPostgres"] ?? "system";
        if (!is_string($locationMysql) || !is_string($locationPostgres)) {
            throw new RequestValidationException("Некорректное расположение БД.");
        }
        return new static(
            rawurldecode($request->route["name"]),
            isset($request->body["name"]) && $request->body["name"] !== "" ? $request->body["name"] : null,
            isset($request->body["language"]) && $request->body["language"] !== "" ? $request->body["language"] : null,
            isset($request->body["languageVersion"]) && $request->body["languageVersion"] !== ""
                ? $request->body["languageVersion"]
                : null,
            array_key_exists("framework", $request->body) ? $request->body["framework"] : null,
            $external,
            $externalPort,
            $dedicated,
            $locationMysql,
            $locationPostgres,
        );
    }
}

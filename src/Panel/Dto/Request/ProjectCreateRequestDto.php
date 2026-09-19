<?php

declare(strict_types=1);

namespace DockerCli\Panel\Dto\Request;

use DockerCli\Panel\Http\RequestData;
use DockerCli\Panel\Http\RequestDto;
use DockerCli\Panel\Http\RequestValidationException;

final readonly class ProjectCreateRequestDto implements RequestDto
{
    /** @param array<string, mixed> $deploymentArguments */
    /** @param list<string> $dedicatedDatabases */
    public function __construct(
        public ?string $code,
        public string $location,
        public string $language,
        public ?string $languageVersion,
        public ?string $framework,
        public ?int $externalPort,
        public ?string $deploymentScript,
        public array $deploymentArguments,
        public array $dedicatedDatabases,
        public string $locationMysql,
        public string $locationPostgres,
    ) {
    }

    public static function fromRequest(RequestData $request): static
    {
        $code = $request->body["code"] ?? null;
        $location = $request->body["location"] ?? null;
        $language = $request->body["language"] ?? null;
        $languageVersion = $request->body["languageVersion"] ?? null;
        $framework = $request->body["framework"] ?? null;
        $externalPort = $request->body["externalPort"] ?? null;
        $deploymentScript = $request->body["deploymentScript"] ?? null;
        $deploymentArguments = $request->body["deploymentArguments"] ?? [];
        $dedicatedDatabases = $request->body["dedicatedDatabases"] ?? [];
        $locationMysql = $request->body["locationMysql"] ?? "system";
        $locationPostgres = $request->body["locationPostgres"] ?? "system";
        if (
            ($code !== null && !is_string($code)) ||
            !is_string($location) ||
            !is_string($language) ||
            ($languageVersion !== null && !is_string($languageVersion)) ||
            ($framework !== null && !is_string($framework)) ||
            ($externalPort !== null && (!is_int($externalPort) || $externalPort < 1 || $externalPort > 65535)) ||
            ($deploymentScript !== null && !is_string($deploymentScript)) ||
            !is_array($deploymentArguments) ||
            (array_is_list($deploymentArguments) && $deploymentArguments !== []) ||
            !is_array($dedicatedDatabases) ||
            !array_is_list($dedicatedDatabases) ||
            array_diff($dedicatedDatabases, ["mysql", "postgres"]) !== [] ||
            count(array_unique($dedicatedDatabases)) !== count($dedicatedDatabases) ||
            !is_string($locationMysql) ||
            !is_string($locationPostgres)
        ) {
            throw new RequestValidationException("Некорректные данные проекта.");
        }
        $code = is_string($code) && trim($code) !== "" ? trim($code) : null;
        $deploymentScript = is_string($deploymentScript) && $deploymentScript !== "" ? $deploymentScript : null;
        return new static(
            $code,
            $location,
            $language,
            is_string($languageVersion) && $languageVersion !== "" ? $languageVersion : null,
            is_string($framework) && $framework !== "" ? $framework : null,
            $externalPort,
            $deploymentScript,
            $deploymentArguments,
            $dedicatedDatabases,
            $locationMysql,
            $locationPostgres,
        );
    }
}

<?php

declare(strict_types=1);

namespace DockerCli\Config;

final class SystemCompose
{
    public const PROJECT_NAME = 'docker-cli';
    public const CONFIG_RELATIVE_PATH = '.config/docker-cli/compose/system';
    public const COMPOSE_FILE = 'compose.yaml';
    public const ENV_FILE = '.env';

    public function directory(): string
    {
        $home = getenv('HOME') ?: throw new \RuntimeException('HOME environment variable is not set.');

        return rtrim($home, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::CONFIG_RELATIVE_PATH;
    }

    public function composeFile(): string
    {
        return $this->directory() . DIRECTORY_SEPARATOR . self::COMPOSE_FILE;
    }

    public function envFile(): string
    {
        return $this->directory() . DIRECTORY_SEPARATOR . self::ENV_FILE;
    }

    public function ensure(): void
    {
        $directory = $this->directory();
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create config directory "%s".', $directory));
        }

        if (!file_exists($this->envFile())) {
            file_put_contents($this->envFile(), $this->defaultEnv());
        }

        if (!file_exists($this->composeFile())) {
            file_put_contents($this->composeFile(), $this->composeYaml());
        }
    }

    /** @return list<string> */
    public function dockerComposeCommand(string $operation): array
    {
        return [
            'docker',
            'compose',
            '--project-name',
            self::PROJECT_NAME,
            '--env-file',
            $this->envFile(),
            '--file',
            $this->composeFile(),
            $operation,
        ];
    }

    private function defaultEnv(): string
    {
        return <<<'ENV'
BASE_HOST=local.kubehut.top
CLOUDFLARE_DNS_API_TOKEN=change-me
ACME_EMAIL=admin@local.kubehut.top
ENV;
    }

    private function composeYaml(): string
    {
        return <<<'YAML'
name: docker-cli

services:
  dnsdock:
    image: aacebedo/dnsdock:latest
    container_name: dnsdock
    command: ["--domain", "${BASE_HOST}"]
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
    ports:
      - "172.17.0.1:53:53/udp"
    networks:
      docker-cli:
        aliases:
          - dnsdock.system.${BASE_HOST}
    restart: unless-stopped

  traefik:
    image: traefik:v3.6
    container_name: traefik
    command:
      - --api.dashboard=true
      - --providers.docker=true
      - --providers.docker.exposedbydefault=false
      - --providers.docker.network=docker-cli
      - --entrypoints.web.address=:80
      - --entrypoints.websecure.address=:443
      - --certificatesresolvers.cloudflare.acme.email=${ACME_EMAIL}
      - --certificatesresolvers.cloudflare.acme.storage=/letsencrypt/acme.json
      - --certificatesresolvers.cloudflare.acme.dnschallenge=true
      - --certificatesresolvers.cloudflare.acme.dnschallenge.provider=cloudflare
    environment:
      CLOUDFLARE_DNS_API_TOKEN: ${CLOUDFLARE_DNS_API_TOKEN}
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
      - traefik-letsencrypt:/letsencrypt
    networks:
      docker-cli:
        aliases:
          - traefik.system.${BASE_HOST}
    restart: unless-stopped

  dockge:
    image: louislam/dockge:1
    container_name: dockge
    environment:
      DOCKGE_STACKS_DIR: /opt/stacks
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
      - dockge-data:/app/data
      - ./stacks:/opt/stacks
    labels:
      traefik.enable: "true"
      traefik.http.routers.dockge.rule: Host(`dockge.system.${BASE_HOST}`)
      traefik.http.routers.dockge.entrypoints: websecure
      traefik.http.routers.dockge.tls.certresolver: cloudflare
      traefik.http.services.dockge.loadbalancer.server.port: "5001"
    networks:
      docker-cli:
        aliases:
          - dockge.system.${BASE_HOST}
    restart: unless-stopped

networks:
  docker-cli:
    name: docker-cli
    driver: bridge

volumes:
  dockge-data:
  traefik-letsencrypt:
YAML;
    }
}

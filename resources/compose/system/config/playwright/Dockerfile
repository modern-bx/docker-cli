ARG PLAYWRIGHT_IMAGE=mcr.microsoft.com/playwright:v1.61.0-noble
FROM ${PLAYWRIGHT_IMAGE}

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends novnc websockify x11vnc xvfb \
    && rm -rf /var/lib/apt/lists/*

COPY entrypoint.sh /usr/local/bin/docker-cli-playwright-entrypoint
RUN chmod +x /usr/local/bin/docker-cli-playwright-entrypoint

ENTRYPOINT ["docker-cli-playwright-entrypoint"]

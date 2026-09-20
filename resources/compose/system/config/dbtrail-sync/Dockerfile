FROM docker:28-cli

RUN apk add --no-cache curl jq

COPY sync.sh /usr/local/bin/docker-cli-dbtrail-sync

ENTRYPOINT ["docker-cli-dbtrail-sync"]

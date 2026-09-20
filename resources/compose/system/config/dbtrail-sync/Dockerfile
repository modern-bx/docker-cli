FROM docker:28-cli

RUN apk add --no-cache curl jq

COPY sync.sh /usr/local/bin/docker-cli-dbtrail-sync
RUN chmod 0755 /usr/local/bin/docker-cli-dbtrail-sync

ENTRYPOINT ["/usr/local/bin/docker-cli-dbtrail-sync"]

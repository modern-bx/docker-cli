FROM golang:1.25.11-bookworm AS builder

ARG DBTRAIL_VERSION=0.84.0
ARG DBTRAIL_ARCHIVE_SHA256=5c74ff67397e16541c8cf68931a385b2417a2e74a368e09f4085f6c4304a01e5

WORKDIR /src

RUN apt-get update \
    && apt-get install --yes --no-install-recommends ca-certificates curl \
    && rm -rf /var/lib/apt/lists/* \
    && curl --fail --location --silent --show-error \
        "https://github.com/dbtrail/dbtrail/archive/refs/tags/v${DBTRAIL_VERSION}.tar.gz" \
        --output /tmp/dbtrail.tar.gz \
    && echo "${DBTRAIL_ARCHIVE_SHA256}  /tmp/dbtrail.tar.gz" | sha256sum --check --status \
    && tar --extract --gzip --file /tmp/dbtrail.tar.gz --strip-components=1 \
    && rm /tmp/dbtrail.tar.gz \
    && sed --in-place \
        's/stats, err := TakeSnapshot(sourceDB, indexDB, schemas)/stats, err := TakeSnapshotExcludingInvalid(sourceDB, indexDB, schemas)/' \
        internal/metadata/metadata.go \
    && ! grep --quiet 'stats, err := TakeSnapshot(sourceDB, indexDB, schemas)' internal/metadata/metadata.go

RUN CGO_ENABLED=1 go build \
    -ldflags="-s -w -X main.Version=${DBTRAIL_VERSION}" \
    -o /bintrail-console ./cmd/bintrail-console

FROM ghcr.io/dbtrail/bintrail-console:0.84.0

COPY --from=builder /bintrail-console /usr/local/bin/bintrail-console

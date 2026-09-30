# GeoVerify database image.
#
# No published PostGIS image bundles h3-pg, and the grid generator cannot run
# without it, so the extension is compiled here against the same PostgreSQL the
# image ships. Pinned to an exact commit: the register has to be reproducible,
# and "whatever main was that day" is not a version.
#
# Built on the official PostgreSQL image for Debian bookworm, with PostGIS from
# the PostgreSQL project's own apt repository (already configured in that
# image). The postgis/postgis images are still built on bullseye, whose
# security archive has gone, so their apt-get fails with 404s and nothing can
# be compiled on top of them.
ARG POSTGRES_VERSION=17

FROM postgres:${POSTGRES_VERSION}-bookworm

ARG POSTGRES_VERSION=17
# PostGIS 3, whichever 3.x the PostgreSQL apt repository carries for this
# major. Kept as an argument so docker-compose's value still reads naturally.
ARG POSTGIS_VERSION=3
ARG H3PG_COMMIT=04227cb62a7338eb2a655fff875b125de2f043f4

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        postgresql-${POSTGRES_VERSION}-postgis-${POSTGIS_VERSION%%.*} \
        postgresql-${POSTGRES_VERSION}-postgis-${POSTGIS_VERSION%%.*}-scripts; \
    apt-get install -y --no-install-recommends \
        build-essential cmake git ca-certificates \
        postgresql-server-dev-${POSTGRES_VERSION}; \
    git clone https://github.com/zachasme/h3-pg.git /tmp/h3-pg; \
    cd /tmp/h3-pg; \
    git checkout ${H3PG_COMMIT}; \
    cmake -B build -DCMAKE_BUILD_TYPE=Release; \
    cmake --build build --parallel "$(nproc)"; \
    cmake --install build; \
    cd /; rm -rf /tmp/h3-pg; \
    apt-get purge -y --auto-remove build-essential cmake git postgresql-server-dev-${POSTGRES_VERSION}; \
    rm -rf /var/lib/apt/lists/*

# Runs once on first start of an empty data directory.
COPY init-extensions.sh /docker-entrypoint-initdb.d/10-geoverify-extensions.sh

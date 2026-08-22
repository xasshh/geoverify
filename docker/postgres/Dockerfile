# GeoVerify database image.
#
# No published PostGIS image bundles h3-pg, and the grid generator cannot run
# without it, so the extension is compiled here against the same PostgreSQL the
# image ships. Pinned to an exact commit: the register has to be reproducible,
# and "whatever main was that day" is not a version.
ARG POSTGRES_VERSION=17
ARG POSTGIS_VERSION=3.5

FROM postgis/postgis:${POSTGRES_VERSION}-${POSTGIS_VERSION}

ARG POSTGRES_VERSION=17
ARG H3PG_COMMIT=04227cb62a7338eb2a655fff875b125de2f043f4

RUN set -eux; \
    apt-get update; \
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

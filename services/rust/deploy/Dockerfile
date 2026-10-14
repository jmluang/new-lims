FROM rust:1.90-bookworm AS build
WORKDIR /src
RUN apt-get update && apt-get install -y --no-install-recommends pkg-config libssl-dev && rm -rf /var/lib/apt/lists/*
COPY Cargo.toml Cargo.lock ./
COPY crates ./crates
COPY fixtures/tomcat-10.1.26-upload-idle-400.html ./fixtures/tomcat-10.1.26-upload-idle-400.html
RUN cargo build --release --locked -p lims-server

FROM debian:bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends libssl3 ca-certificates curl && rm -rf /var/lib/apt/lists/* \
    && groupadd --system lims && useradd --system --gid lims --home-dir /app lims \
    && mkdir -p /app/data/signing-results && chown -R lims:lims /app
WORKDIR /app
COPY --from=build /src/target/release/lims-server /usr/local/bin/lims-server
COPY docs/THIRD-PARTY-NOTICES.txt docs/DEPENDENCY-LICENSES.json /usr/share/doc/lims-server/
USER lims
ENV PDF_SERVICE_BIND_ADDRESS=127.0.0.1 PDF_SERVICE_PORT=8081
EXPOSE 8081
STOPSIGNAL SIGTERM
ENTRYPOINT ["/usr/local/bin/lims-server"]

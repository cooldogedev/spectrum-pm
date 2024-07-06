FROM rust:latest AS builder

WORKDIR /build

RUN apt-get update && \
    apt-get install -y cmake golang && \
    rm -rf /var/lib/apt/lists/*

RUN git clone --recursive https://github.com/cloudflare/quiche --branch 0.22.0 && \
    cd quiche && \
    cargo build --release --features ffi

FROM ubuntu:jammy

EXPOSE 19132

RUN apt-get update && \
    apt-get dist-upgrade -y && \
    apt-get install -y --no-install-recommends \
    ca-certificates wget libffi7 build-essential && \
    apt-get autoremove -y && \
    rm -rf /var/lib/apt/lists/*

WORKDIR /home

COPY . /home

COPY --from=builder /build/quiche/target/release/libquiche.so /home/quiche/libquiche.so
COPY --from=builder /build/quiche/quiche/include/quiche.h /home/quiche/quiche.h

ENV QUICHE_PATH=/home/quiche/libquiche.so
ENV QUICHE_PHP_FILE=/home/quiche.php
ENV QUICHE_H_FILE=/home/quiche/quiche.h
ENV KEY_PATH=/home/key.pem
ENV CERT_PATH=/home/cert.pem

RUN chmod +x /home/bin/php7/bin/php /home/start.sh

ENTRYPOINT ["/home/start.sh"]

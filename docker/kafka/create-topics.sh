#!/bin/sh
# Runs inside the Kafka container: docker compose exec -T kafka sh -s < docker/kafka/create-topics.sh
set -eu

TOPICS="/opt/kafka/bin/kafka-topics.sh --bootstrap-server localhost:9092"

$TOPICS --create --if-not-exists --topic product-updates --partitions 3 --replication-factor 1
# the broker tests write here; retention deletes only closed segments, so segment.ms closes one every 10 minutes
$TOPICS --create --if-not-exists --topic product-updates-test --partitions 3 --replication-factor 1 \
    --config retention.ms=600000 --config segment.ms=600000

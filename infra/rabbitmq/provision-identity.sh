#!/bin/sh

set -eu

run_admin() {
  attempt=1

  while ! rabbitmqadmin \
    --host rabbitmq \
    --port 15672 \
    --username "$RABBITMQ_ADMIN_USER" \
    --password "$RABBITMQ_ADMIN_PASSWORD" \
    "$@"
  do
    if [ "$attempt" -ge 10 ]; then
      echo "RabbitMQ Management API did not accept the operation after 10 attempts." >&2
      return 1
    fi

    sleep "$attempt"
    attempt=$((attempt + 1))
  done
}

run_admin \
  definitions import \
  --file /setup/definitions.json

run_admin \
  users declare \
  --name "$RABBITMQ_CATALOG_USER" \
  --password "$RABBITMQ_CATALOG_PASSWORD" \
  --tags ""

run_admin \
  --vhost ecommerce \
  permissions declare \
  --username "$RABBITMQ_CATALOG_USER" \
  --configure '^$' \
  --write '^ecommerce\.events$' \
  --read '^$'

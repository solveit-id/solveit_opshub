#!/bin/bash
(
set -eu

# Only safe local account identifiers are interpolated into the grant.
if [[ ! "$MYSQL_USER" =~ ^[a-zA-Z_][a-zA-Z0-9_]*$ ]]; then
    echo 'MYSQL_USER must be a simple SQL account name.' >&2
    exit 1
fi

MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket --user=root <<EOSQL
CREATE DATABASE IF NOT EXISTS solveit_opshub_test
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON solveit_opshub_test.* TO '${MYSQL_USER}'@'%';
EOSQL
)

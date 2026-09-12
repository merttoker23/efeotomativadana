#!/bin/sh
set -eu

case "${MYSQL_DATABASE}" in
    ''|*[!A-Za-z0-9_]*)
        echo 'MYSQL_DATABASE must contain only letters, digits, and underscores.' >&2
        exit 1
        ;;
esac

case "${MYSQL_USER}" in
    ''|*[!A-Za-z0-9_]*)
        echo 'MYSQL_USER must contain only letters, digits, and underscores.' >&2
        exit 1
        ;;
esac

test_database="${MYSQL_DATABASE}_test"

MYSQL_PWD="${MYSQL_ROOT_PASSWORD}" mysql --protocol=socket -uroot <<EOSQL
CREATE DATABASE IF NOT EXISTS \`${test_database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${test_database}\`.* TO '${MYSQL_USER}'@'%';
EOSQL

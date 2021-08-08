#!/bin/bash

JOB=$3
PHP_VERSION=$(echo "${JOB}" | jq -r '.php')

if [ "$PHP_VERSION" = "8.0" ]; then
    apt install libmemcached11
    echo "/usr/lib/x86_64-linux-gnu\nno\nno\nyes\nno" | pecl install -f memcached
fi

echo -n "Memcached version: "
php -r 'echo phpversion("memcached");'
echo ""

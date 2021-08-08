#!/bin/bash

echo "no" | pecl install -f memcached

echo -n "Memcached version: "
php -r 'echo phpversion("memcached");'
echo ""

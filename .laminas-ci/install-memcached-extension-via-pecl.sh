#!/bin/bash

PHP_VERSION="$1"

if ! [[ "${PHP_VERSION}" =~ 8\.2 ]]; then
  echo "memcached is only installed from pecl for PHP 8.2, ${PHP_VERSION} detected."
  exit 0;
fi

set +e
apt install -y make libmemcached-dev

pecl install --configureoptions 'with-libmemcached-dir="no" with-zlib-dir="no" with-system-fastlz="no" enable-memcached-igbinary="no" enable-memcached-msgpack="no" enable-memcached-json="no" enable-memcached-protocol="no" enable-memcached-sasl="no" enable-memcached-session="no"' memcached
echo "extension=memcached.so" > /etc/php/${PHP_VERSION}/mods-available/memcached.ini
phpenmod -v ${PHP} -s cli memcached

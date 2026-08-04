#!/bin/sh
set -e

# Railway (and some other platforms) assign a port dynamically at runtime via
# $PORT. Render lets you fix a port in the dashboard instead. Default to
# 10000 if nothing is set, so this works unmodified on either platform.
PORT="${PORT:-10000}"

sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-enabled/000-default.conf

exec apache2-foreground

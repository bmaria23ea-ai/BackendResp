#!/bin/bash
set -e

PORT=${PORT:-80}

echo "[RespMonitor] Configurando Apache en el puerto $PORT"

sed -i "s/Listen 80/Listen $PORT/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:$PORT>/g" \
    /etc/apache2/sites-available/000-default.conf

echo "[RespMonitor] Apache listo — arrancando..."
exec apache2-foreground

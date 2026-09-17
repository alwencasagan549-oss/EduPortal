#!/bin/bash
# Entry point: configure Apache to listen on Render's PORT, then start Apache

PORT="${PORT:-80}"

echo "Configuring Apache to listen on port $PORT..."

# Update Apache ports.conf - replace any Listen line
sed -i "s|^Listen .*|Listen ${PORT}|" /etc/apache2/ports.conf

# Update default virtual host to match (handles both *:80 and :80 patterns)
sed -i "s|^<VirtualHost [^>]*:.*|<VirtualHost *:${PORT}>|" /etc/apache2/sites-enabled/000-default.conf

# Verify
echo "Apache will listen on:"
grep "^Listen" /etc/apache2/ports.conf

# Start Apache in foreground (Render requires this)
exec apache2-foreground

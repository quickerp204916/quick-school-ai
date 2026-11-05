# Caddy serves static files from public/ and passes PHP requests to the app container.
FROM caddy:2.10-alpine
COPY web/public /var/www/html/public
COPY deploy/Caddyfile /etc/caddy/Caddyfile

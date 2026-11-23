FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg python3 ca-certificates curl \
 && curl -fsSL https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp \
 && chmod a+rx /usr/local/bin/yt-dlp && apt-get clean && rm -rf /var/lib/apt/lists/*
COPY api.php index.php /var/www/html/
COPY docker-start.sh /usr/local/bin/docker-start.sh
RUN mkdir -p /var/www/html/tmp && chown -R www-data:www-data /var/www/html \
 && chmod +x /usr/local/bin/docker-start.sh \
 && printf 'max_execution_time=900\nmemory_limit=256M\nexpose_php=Off\n' > /usr/local/etc/php/conf.d/saveclip.ini
ENV PORT=10000
EXPOSE 10000
CMD ["docker-start.sh"]

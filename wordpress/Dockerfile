FROM wordpress:cli-php8.2

USER root
RUN apk add --no-cache bash mysql-client

COPY setup.sh /setup.sh
RUN chmod +x /setup.sh

ENTRYPOINT ["/setup.sh"]

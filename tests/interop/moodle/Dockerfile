FROM moodlehq/moodle-php-apache:8.3
RUN mkdir -p /var/www/moodle /var/www/wordboat /var/www/moodledata \
 && curl -fsSL https://codeload.github.com/moodle/moodle/tar.gz/refs/tags/v5.1.7 | tar -xz --strip-components=1 -C /var/www/moodle \
 && curl -fsSL https://wordpress.org/wordpress-6.8.3.tar.gz | tar -xz --strip-components=1 -C /var/www/wordboat \
 && a2enmod ssl rewrite headers \
 && printf 'max_input_vars=10000\nmemory_limit=512M\n' > /usr/local/etc/php/conf.d/interop.ini
COPY apache.conf /etc/apache2/sites-available/interop.conf
RUN a2dissite 000-default && a2ensite interop
COPY boot.sh /usr/local/bin/interop-boot
RUN chmod +x /usr/local/bin/interop-boot
ENTRYPOINT ["/usr/local/bin/interop-boot"]
CMD ["apache2-foreground"]

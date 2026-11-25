#!/usr/bin/env bash
set -euo pipefail
umask 077
mkdir -p /var/www/wordboat/wp-content/plugins/language-learner-tools
if [[ ! -f /var/www/wordboat/wp-content/plugins/language-learner-tools/language-learner-tools.php ]]; then
 tar -xf /seed/plugin-runtime.tar -C /var/www/wordboat/wp-content/plugins/language-learner-tools
fi
if [[ ! -s /private/ca.crt ]] || ! openssl x509 -checkend 86400 -noout -in /private/server.crt >/dev/null 2>&1 || ! openssl x509 -checkhost wordboat.lti-interop.example.net -noout -in /private/server.crt >/dev/null 2>&1; then
 openssl req -x509 -newkey rsa:3072 -nodes -days 7 -subj '/CN=LL Tools disposable Moodle interop CA' -keyout /private/ca.key -out /private/ca.crt 2>/dev/null
 openssl req -newkey rsa:2048 -nodes -subj '/CN=moodle.lti-interop.example.org' -keyout /private/server.key -out /private/server.csr 2>/dev/null
 printf 'subjectAltName=DNS:moodle.lti-interop.example.org,DNS:wordboat.lti-interop.example.net\nextendedKeyUsage=serverAuth\n' > /private/server.ext
 openssl x509 -req -in /private/server.csr -CA /private/ca.crt -CAkey /private/ca.key -CAcreateserial -days 7 -extfile /private/server.ext -out /private/server.crt 2>/dev/null
fi
cp /private/ca.crt /usr/local/share/ca-certificates/ll-tools-interop.crt
chmod 0644 /usr/local/share/ca-certificates/ll-tools-interop.crt
update-ca-certificates >/dev/null
cp /etc/ssl/certs/ca-certificates.crt /var/www/wordboat/wp-includes/certificates/ca-bundle.crt
php /harness/prepare.php
chgrp www-data /private/credentials.json
chmod 0640 /private/credentials.json
chown -R www-data:www-data /var/www/moodledata /var/www/wordboat/wp-content/uploads
exec "$@"

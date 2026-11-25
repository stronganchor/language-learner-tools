#!/usr/bin/env bash
set -euo pipefail
mkdir -p /root/.pki/nssdb
certutil -N -d sql:/root/.pki/nssdb --empty-password
certutil -A -d sql:/root/.pki/nssdb -n 'Disposable LL Tools Moodle interop CA' -t 'C,,' -i /private/ca.crt
export NODE_EXTRA_CA_CERTS=/private/ca.crt
exec node /harness/browser.cjs

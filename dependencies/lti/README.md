# LTI JWT dependency

The plugin ships an isolated copy of `firebase/php-jwt` 7.2.1 (BSD-3-Clause).
The lock file pins upstream; `includes/vendor/php-jwt/provenance.json` records
the upstream commit and both upstream/scoped SHA-256 hashes. Only the namespace
is changed to avoid collisions with JWT libraries in other WordPress plugins.
The plugin does not load Composer's global autoloader.

To update/rebuild from the repository root:

```sh
composer install --working-dir=dependencies/lti --no-dev
composer audit --working-dir=dependencies/lti --locked --no-dev
php scripts/build-lti-jwt-vendor.php
```

Review upstream security releases before changing the pinned version. Do not
replace JWT signature validation with custom cryptography.

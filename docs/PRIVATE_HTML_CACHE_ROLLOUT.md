# Private HTML cache rollout

LL Tools keeps dictionary and public page HTML in a site-specific private
directory. The default base is PHP's system temporary directory. Set
`LL_TOOLS_STATIC_CACHE_PRIVATE_DIR` in `wp-config.php` to an absolute, existing
directory when the default is unsuitable. Give the WordPress PHP worker write
access and keep the directory outside every web server document root and
alias. An HTTP worker that has no `DOCUMENT_ROOT` requires this explicit path.

The plugin checks the known document, WordPress, content, and uploads roots.
It separates cache directories by installation, database, table prefix, and
blog. PHP must be able to create the site directory, with owner-only mode on
Unix. WordPress renders the canonical page dynamically under the usual request
limits when private file storage is unavailable. Keep the database shared
between workers so cache invalidation applies even when their temporary
directories differ.

Existing uploads may still contain HTML or metadata written by an older
worker. Before promotion, identify the actual upload URL and filesystem path
for each site and blog. Deny HTTP access to the complete
`ll-dictionary-cache` and `ll-public-static-cache` directories, including
shards, metadata, and temporary files. Keep those denials during any rollback
to a version that writes HTML under uploads.

On upgrade, the plugin stops using the legacy directories and schedules
bounded maintenance to remove known generated files. The scan checks at
most 4,096 directory entries per pass and uses a short time budget.
Unrelated files are preserved. An inaccessible directory, unexpected
symlink, failed removal, or an oversized foreign-file prefix can require
operator cleanup. The plugin retains a pending maintenance marker for
another attempt. A fresh private cache can serve normal traffic while old
files are retired.

After the old PHP workers have drained, send anonymous GET and HEAD requests
to a formerly valid URL from each legacy directory. Include at least one
public-cache shard if it existed. Confirm a 403 or 404 with no cached body,
then warm a normal public lesson and dictionary URL and confirm their
expected status and content. Check both the origin and any CDN that could
already hold a copy. Repeat this check after a rollback or a hosting-rule
change.

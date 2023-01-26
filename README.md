# Islandora Hieraarchical Access

Implements an access control model wherein:

- files belong to media,
- media belong to nodes that they reference; and,
- transitively, files belong to media

Access to entities which are related outside of the target relationships without any _inside_ should not be affected.

Where:

- a file is referenced by multiple media, access to the file should be granted if at least one of the media is accessible
- media which have the "media of" relationship to at least one node should only be accessible if at least one of those nodes is accessible

## Development

A handful of automated/PHPUnit tests are included. Running tests should be able to be accomplished via invocations such as:

```bash
DRUPAL_ROOT=/opt/www/drupal
sudo -u www-data -- env -C $DRUPAL_ROOT \
  SIMPLETEST_BASE_URL="http://localhost" \
  SIMPLETEST_DB=pgsql://drupal:drupal@localhost:5432/drupal_default \
  $DRUPAL_ROOT/vendor/bin/phpunit "--bootstrap=$DRUPAL_ROOT/core/tests/bootstrap.php" \
  --verbose "$DRUPAL_ROOT/modules/contrib/islandora_hierarchical_access"
```

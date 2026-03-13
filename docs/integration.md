# Integration

## How the module registers

The module registers with the Newfold Module Loader via bootstrap.php. The host plugin typically registers a staging service in the container and exposes admin UI and/or REST API that use the module’s APIs for create, clone, and restore operations.

## Dependencies

This module has no runtime Composer requires; the host supplies WordPress and any external staging provider. For development, the repo uses wp-module-loader, WordPress, wp-browser, and php-standards. See composer.json.

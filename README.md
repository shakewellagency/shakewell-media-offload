# Shakewell Media Offload for Amazon S3

Copies uploads to an S3 bucket and serves them from it or from CloudFront, with the AWS credentials the server already has. Built to replace WP Offload Media Lite without changing a single media address. Free, GPL-2.0-or-later, written to be listed in the WordPress plugin directory.

Setup, the IAM policy, CloudFront with a private bucket, the switch from WP Offload Media and the external services are in [readme.txt](readme.txt).

## Work on it

```sh
composer install
npm ci
npm run env:start      # WordPress at http://localhost:8910, tests site on 8911, needs Docker
npm test               # PHPUnit inside the WordPress test container
composer lint          # WordPress coding standards
npm run plugin-check   # the official Plugin Check, as the directory runs it
npm run env:stop
```

`npm test` runs two suites:

- `tests/Integration`: signing, credentials, upload, serving, deletion and the capture/compare check. S3 and AWS are faked at `pre_http_request`; nothing leaves the container.
- `tests/Parity`: URL parity with WP Offload Media Lite 3.4.3. wp-env maps that plugin into the tests container only (see `.wp-env.json`); it is never part of this plugin. The suite offloads attachments with WP Offload Media's own code and tables, captures every address, switches to this plugin and compares byte for byte.

## Release

1. Bump the version in the plugin header, `readme.txt` (`Stable tag`) and the `VERSION` constant, and add a changelog line.
2. Tag `vX.Y.Z` and push. The release workflow checks the three versions match and attaches `shakewell-media-offload.zip` to a GitHub release.

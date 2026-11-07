# Shakewell Media Offload for Amazon S3

> **Made by [Shakewell](https://shakewell.agency).** Free and open source, built and maintained by the Shakewell team.

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

## Made by Shakewell

This plugin is one of a family of free WordPress plugins from [Shakewell](https://shakewell.agency). Each one does one job, keeps its dependencies small and is released under GPL-2.0-or-later.

Use it, fork it and send fixes. To have it set up on your site, or to have a plugin built, [talk to Shakewell](https://shakewell.agency).

More from Shakewell:

- [Shakewell Activity Log](https://github.com/shakewellagency/shakewell-activity-log)
- [Shakewell Multiple Authors](https://github.com/shakewellagency/shakewell-authors)
- [Shakewell Clone Posts](https://github.com/shakewellagency/shakewell-clone-posts)
- [Shakewell Connector for Klaviyo and Gravity Forms](https://github.com/shakewellagency/shakewell-klaviyo-connector)
- [Shakewell Redirects](https://github.com/shakewellagency/shakewell-redirects)
- [Shakewell Two-way Relationships for ACF](https://github.com/shakewellagency/shakewell-relationship-sync)
- [Shakewell Single Sign-On (SAML)](https://github.com/shakewellagency/shakewell-saml-sso)
- [Shakewell Error Reporting for Sentry](https://github.com/shakewellagency/shakewell-sentry)
- [Shakewell Mailer for Amazon SES](https://github.com/shakewellagency/shakewell-ses-mailer)
- [Shakewell Sitemap](https://github.com/shakewellagency/shakewell-sitemap)
- [Shakewell plugin template](https://github.com/shakewellagency/shakewell-plugin-template), the starting point for every plugin

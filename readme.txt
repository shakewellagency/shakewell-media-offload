=== Shakewell Media Offload for Amazon S3 ===
Contributors: shakewellagency
Tags: s3, cloudfront, media, offload, uploads
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Copies uploads to an Amazon S3 bucket and serves them from it or from CloudFront, using the AWS credentials the server already has.

== Description ==

Shakewell Media Offload for Amazon S3 does four things and nothing else:

1. When an image or file is uploaded, or its sizes are regenerated, it sends the original and every size to your S3 bucket.
2. It rewrites the address of those files (attachment links, image sizes, `srcset`, and links inside post content) to your CloudFront or S3 address.
3. When you delete an attachment in WordPress, it deletes that attachment's own objects from the bucket, and only those.
4. It gives you two WP-CLI commands to prove that no media address changed when you switched from another plugin.

It has no settings screen, no account, and no AWS keys in the database. Everything is set in `wp-config.php` or the server environment, and the plugin does nothing until you switch it on.

**Existing addresses never change.** If WP Offload Media Lite already offloaded your library, this plugin reads that plugin's tables (it never writes to them) and builds exactly the same address for every file. Files that were not offloaded keep their normal WordPress address.

**Files are never removed from your server.** The only deletion in the plugin is the bucket objects of an attachment that you delete in WordPress.

What it does not do: private (signed URL) media, other storage providers, a bulk "offload my whole library" tool, removing local copies, or image optimisation.

= Settings =

Set these as constants in `wp-config.php` or as environment variables. If a `SHAKEWELL_MEDIA_OFFLOAD_` name is not set, the name WP Offload Media used is read instead, so a site can switch without changing its configuration.

* `SHAKEWELL_MEDIA_OFFLOAD_ENABLED` (required): `true` to switch the plugin on. Anything else leaves it off.
* `SHAKEWELL_MEDIA_OFFLOAD_BUCKET`: bucket name (also read from `AS3CF_BUCKET`).
* `SHAKEWELL_MEDIA_OFFLOAD_REGION`: for example `us-east-1` (also `AS3CF_REGION`).
* `SHAKEWELL_MEDIA_OFFLOAD_PREFIX`: folder in the bucket put in front of the upload path. Default `wp-content/uploads/`. An empty value means the bucket root.
* `SHAKEWELL_MEDIA_OFFLOAD_DELIVERY_DOMAIN`: the CloudFront (or other CDN) domain, without `https://` (also `AS3CF_DELIVERY_DOMAIN`). If empty, files are served from the bucket's own S3 address.

The `AS3CF_SETTINGS` constant that WP Offload Media reads (bucket, region, object prefix and delivery domain) is also understood.

Addresses are always `https`. A file's key is the prefix plus its upload path, for example `wp-content/uploads/2026/11/photo.jpg`.

= AWS credentials =

The plugin looks for credentials in this order and keeps them in memory for the request only:

1. `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` and optionally `AWS_SESSION_TOKEN` in the server environment.
2. The container credentials endpoint (ECS and Fargate), from `AWS_CONTAINER_CREDENTIALS_RELATIVE_URI` or `AWS_CONTAINER_CREDENTIALS_FULL_URI`.
3. The EC2 instance role through the metadata service (IMDSv2). Set `AWS_EC2_METADATA_DISABLED=true` to skip it.

Keys are never stored in the database or logged. Calls to the credential services never go through an HTTP proxy configured for WordPress, and no request follows a redirect.

= IAM policy =

The credentials need only these two permissions on the upload prefix of the bucket. Replace `BUCKET` and the prefix.

`{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["s3:PutObject", "s3:DeleteObject"],
      "Resource": "arn:aws:s3:::BUCKET/wp-content/uploads/*"
    }
  ]
}`

= CloudFront with a private bucket =

1. Keep "Block all public access" on for the bucket.
2. Create a CloudFront distribution with the bucket as its origin and Origin Access Control (OAC) with signing turned on.
3. Add the bucket policy CloudFront shows you, which lets only your distribution read:

`{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Principal": { "Service": "cloudfront.amazonaws.com" },
      "Action": "s3:GetObject",
      "Resource": "arn:aws:s3:::BUCKET/*",
      "Condition": { "StringEquals": { "AWS:SourceArn": "arn:aws:cloudfront::ACCOUNT_ID:distribution/DISTRIBUTION_ID" } }
    }
  ]
}`

4. Point a DNS name (for example `media.example.com`) at the distribution and set it as `SHAKEWELL_MEDIA_OFFLOAD_DELIVERY_DOMAIN`.

= Moving from WP Offload Media =

Do this in order. Nothing changes until step 4, and step 5 proves it.

1. Install and activate this plugin. It stays idle because it is not switched on.
2. While WP Offload Media is still active, capture every address the site makes now: `wp shakewell-media-offload urls --format=csv > before.csv`
3. Deactivate WP Offload Media. Do not delete it: this plugin reads its `as3cf_items` and `as3cf_files` tables, and deleting it can remove them.
4. Set `SHAKEWELL_MEDIA_OFFLOAD_ENABLED` to `true`. If the bucket, region, prefix and delivery domain were set through `AS3CF_SETTINGS` or the `AS3CF_` names, nothing else needs to change.
5. Compare: `wp shakewell-media-offload compare before.csv`. It prints every address that changed or is missing and exits with an error if there is any. A clean run says all addresses are identical.
6. Clear any page cache, then check a few pages.

If the plugin finds WP Offload Media active while switched on, it does nothing and shows an admin notice, so the two never process the same upload.

= Hooks =

* Filter `shakewell_media_offload_enabled` (bool): turn the plugin on or off in code.
* Filter `shakewell_media_offload_max_bytes` (int): the largest file sent, default 100 MB. Larger files stay on the server.
* The `as3cf_filter_post_local_to_provider` and `as3cf_filter_post_local_to_s3` filters are provided so code written for WP Offload Media keeps working: pass a string, get the string with upload addresses rewritten.

== Frequently Asked Questions ==

= Will any media address change when I switch? =

No. The addresses of files WP Offload Media offloaded are built from its tables, including the version folder it adds to its keys. The tests install WP Offload Media Lite, offload attachments with it, capture every address and compare byte for byte after switching. The `urls` and `compare` commands let you prove it on your own site.

= Why is an old file still on my own domain? =

Only files that are in the bucket are rewritten. A file that was never offloaded keeps its normal address, so nothing can break.

= Does it upload my existing library? =

No. It uploads new uploads and files whose metadata is regenerated or edited. Files that WP Offload Media offloaded are already in the bucket and are served from it.

= Does it delete files? =

Only when you delete an attachment in WordPress: the original and each size of that attachment are deleted from the bucket, nothing else. Local files are never touched by this plugin. Moving an attachment to the trash does not delete anything. If a delete fails, WordPress still deletes the attachment, one line is written to the PHP error log, and the plugin keeps its record of the objects.

= Can the bucket stay private? =

Yes, with CloudFront and Origin Access Control as described above. Private media with signed, expiring links is not supported.

= Which providers are supported? =

Amazon S3 only, with CloudFront or any CDN domain in front of it. Files served from a delivery domain are fetched by your visitors' browsers from that domain.

= What is logged? =

Failures only, one line each, with the action, stage, HTTP status and an error code, for example `[shakewell-media-offload] upload failed stage=s3 status=403 reason=none`. File names, keys and credentials are never logged.

= What happens when I delete the plugin? =

The setting it stored is removed. Its table `shakewell_media_offload` (which attachment's file is stored under which key) is kept on purpose, because dropping it would turn offloaded files back into server addresses. Drop the table by hand if you want it gone. Nothing is ever removed from your bucket.

= Where do I report a security problem? =

Please do not open a public issue. Follow SECURITY.md in the plugin's repository.

== External services ==

This plugin sends your media files to Amazon Web Services and, optionally, serves them through Amazon CloudFront. It does so only after you switch it on with `SHAKEWELL_MEDIA_OFFLOAD_ENABLED`.

= Amazon S3 =

When a file is uploaded, or the metadata of an attachment is regenerated or edited, the plugin sends each file of the attachment (the original and every generated size) to `https://BUCKET.s3.REGION.amazonaws.com/` with an HTTP PUT. The request holds the file content, its content type and a signed authorisation header. The signature is derived from your AWS credentials, which are not sent. When an attachment is deleted in WordPress, the plugin sends an HTTP DELETE for each of that attachment's files. The bucket and region are the ones you configure.

* Amazon Web Services service terms: https://aws.amazon.com/service-terms/
* AWS customer agreement: https://aws.amazon.com/agreement/
* AWS privacy notice: https://aws.amazon.com/privacy/

= Amazon CloudFront (optional) =

If you set a delivery domain that points to a CloudFront distribution, the addresses of your media point to that domain. The plugin itself makes no request to CloudFront; your visitors' browsers do, to load the files, and CloudFront receives their usual request data (IP address, user agent) as described in the AWS privacy notice.

* Amazon CloudFront is covered by the AWS service terms above: https://aws.amazon.com/service-terms/
* AWS privacy notice: https://aws.amazon.com/privacy/

= AWS credential endpoints =

If no keys are in the environment, the plugin asks the credential service of the machine it runs on: the ECS container endpoint (`169.254.170.2`, or the address in `AWS_CONTAINER_CREDENTIALS_FULL_URI`) or the EC2 instance metadata service (`169.254.169.254`). These are local services of your own server; nothing leaves it. Nothing is sent to Shakewell.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it with Composer.
2. Activate it from the Plugins screen.
3. Set the constants or environment variables described above, ending with `SHAKEWELL_MEDIA_OFFLOAD_ENABLED=true`.

== Changelog ==

= 0.1.1 =
* Works on any site: no client-specific wording in the code, tests or readme, and the compatibility filters are documented as general WP Offload Media compatibility.
* Shows a dismissible admin notice when the plugin is switched on but no valid bucket or region is set.
* Admin notices have a dismiss button. A dismissed notice returns on the next page load, so a setup problem stays visible until it is fixed.
* Readme contributor corrected.

= 0.1.0 =
* First release: upload of originals and sizes to S3, rewriting of attachment, image, srcset and content addresses, address parity with WP Offload Media Lite, deletion of an attachment's own objects, and the `urls` and `compare` WP-CLI commands.

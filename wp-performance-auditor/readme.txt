=== WP Performance Auditor ===
Contributors: jcqdigital
Tags: pagespeed, performance, core-web-vitals, audit
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.5
License: GPLv2 or later

Copyright (c) 2026 JCQ Digital, except for bundled third-party components.

Run mobile and desktop Google PageSpeed Insights audits from WordPress Admin and receive WordPress-specific recommendations.

Includes a bundled browser-based Squoosh image compressor. Images are processed locally and optimized files are downloaded without overwriting Media Library originals.

== Third-party notices ==
The bundled Squoosh assets are licensed under Apache-2.0. Its license is included at `assets/squoosh/SQUOOSH-LICENSE`.

== Installation ==
1. Zip the `wp-performance-auditor` directory.
2. In WordPress, go to Plugins > Add New > Upload Plugin.
3. Upload the zip, activate it, then open Performance Auditor.
4. Optionally configure a Google PageSpeed Insights API key in Performance Auditor > Settings.

== Notes ==
The audited URL must be publicly reachable by Google's PageSpeed Insights service. Results are stored in WordPress options; the latest ten audits are retained.

== Updates ==
For automatic update notices, publish the plugin source in a public GitHub repository and create a release. In Performance Auditor > Settings, enter `owner/repository`. WordPress will compare the latest release tag (for example, `v1.1.0`) with the installed plugin version and show its normal update action. Release ZIPs must unpack to a `wp-performance-auditor` folder.


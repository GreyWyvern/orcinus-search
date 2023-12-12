# Orcinus Site Search

[Brian Huisman](https://greywyvern.com)

> ℹ️ **NOTE**: This project is not yet officially released and thus probably contains bugs and other nasties. Please use at your own risk! If you do try it out, I would very much appreciate your feedback and issue reports.

![orcinus-banner](https://github.com/GreyWyvern/orcinus-search/assets/137631/d504de08-3029-4e68-acf5-1dd1e5008674)

The **Orcinus Site Search** PHP script is an all-in-one website crawler, indexer and search engine that extracts searchable content from plain text, XML, HTML and PDF files at a single, or multiple websites. It replaces 3rd party, remote search solutions such as Google etc. 

**Orcinus** will crawl your website content on a schedule, or at your command via the admin UI or even by CLI / crontab. Crawler log output conveniently informs you of missing pages, broken links or links that redirect, and other errors that a webmaster can fix to keep the user experience tight. A full-featured, responsive administration GUI allows you to adjust crawl settings, view and edit all crawled pages, customize search results, and view a log of user search queries. You also have complete control over the appearance of your search results with a [convenient templating system](https://mustache.github.io/).

Optionally, **Orcinus** can generate a [sitemap .xml or .xml.gz](https://www.sitemaps.org) file of your pages after every crawl, suitable for uploading to the [Google Search Console](https://search.google.com/search-console/sitemaps). It can also export a JavaScript version of the entire search engine that works with offline mirrors, such as those generated by [HTTrack](https://www.httrack.com).

### Requirements:
- PHP >= 8.1.x
- MySQL >= 8.0.17 / MariaDB >= 10.0.5

### 3rd Party Libraries:
Included:
- [PHPMailer](https://github.com/PHPMailer/PHPMailer)
- [PDFParser](https://github.com/smalot/pdfparser)
- [Mustache](https://github.com/bobthecow/mustache.php) / [Mustache.js](https://github.com/janl/mustache.js)
- [libcurlemu](https://github.com/m1k3lm/libcurlemu)

Optional:
- [Maxmind GeoIP2](https://github.com/maxmind/GeoIP2-php)

## Getting Started
1. Copy the `orcinus` directory to your root web directory.
2. Fill out your SQL and desired credential details in the `orcinus/config.ini.php` file.
3. Visit `yourdomain.com/orcinus/admin.php` in your favourite web browser and log in.
4. Optionally follow the instructions in `orcinus/geoip2/README.md` to enable geolocation of search queries.

Examples of search interface integration are given in the `example.php` (online / PHP) and `example.html` (offline / JavaScript) files.

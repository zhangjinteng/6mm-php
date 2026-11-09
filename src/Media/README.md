# Shared image upload policy

`ImageFile::rules()` is used by both hosts' CMS, brand and SEO upload endpoints and
the public feedback endpoint. `MediaRegistry::upload()` independently applies the
same policy, and `FeedbackService::attachment()` rechecks bytes before generating
an authenticated preview. Do not add Laravel's `image`/`dimensions` rule on top of
this policy: those raster-only validators reject valid SVGs.

- JPG / PNG / WebP / SVG / ICO, at most 5 MiB per file (exactly 5 MiB is accepted).
- Canonical MIME, extension and dimensions are derived from file contents, not the
  client's filename or Content-Type. Raster/SVG dimensions are at most 8192 px.
- SVG supports static shapes, text, gradients, clips, masks and local references.
  Active content, event handlers, foreignObject, scripts, animation, stylesheet
  elements/processing instructions, external or embedded resources, DTD/entities,
  unsafe CSS and recursive `use` references are rejected. XML loading never enables
  entity substitution or external DTD resolution and uses LIBXML_NONET.
- ICO validates its directory, bounded frame offsets and PNG/DIB images. The
  canonical type is `image/vnd.microsoft.icon`. CUR/ANI and disguised files are not
  accepted.
- Existing media ownership, reference tracking, deduplication and image-bed
  credentials remain unchanged. No database change is required.

Checks: `php tests/media_image.php`; public feedback upload/preview HTTP tests;
Platform's disposable PostgreSQL `scripts/test-tenant-brand-postgres.sh`.

Format references: [PHP libxml constants](https://www.php.net/manual/en/libxml.constants.php),
[Microsoft icon resources](https://learn.microsoft.com/en-us/windows/win32/menurc/resource-file-formats).

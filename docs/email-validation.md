# Email validation contract

The save-time validator is the upstream `email-validator-php` demo supplied for
Exchange commit `0a4f27233`. `src/TenantConfig/EmailTemplateValidator.php` retains
the demo source verbatim except for its PHP namespace. It is intentionally a
stricter HTML input subset than the Go HTML5 tokenizer.

`EmailTemplateRules::template($body, $trustedHosts, $mergedLogoUrl, $rowStorage)`
adds purpose/locale checks and adapts upstream errors to `EmailConfigException`.
The merged logo value must be supplied even when the save only changes branding;
an omitted field preserves the current value, whereas an empty string clears it.
`SiteEmailPublisher` supplies that value and validates every template in row mode
before writing any source, audit, manifest or site snapshot. Permissions,
optimistic versions and atomic publication remain in the publisher.

Only trusted server configuration may supply the resource-host list. Configure
the same exact hosts in User; do not accept a client-provided allowlist. IPv6 hosts
are unbracketed in the allowlist and bracketed in HTTPS URLs.

The old independent HTML/CSS validators were removed. The imported core validates
HTML, CSS, template variables, UTF-8, byte limits, static resources and the actual
Logo in one call. It does not rewrite templates or repair published data.

Regression commands:

```sh
php tests/email_template_upstream.php
php tests/email_template_rules.php
php tests/site_email_publication.php
php tests/site_snapshot_formats.php
bash scripts/test-site-email-postgres.sh
```

The 155 cases in `tests/fixtures/upstream-email-validator/cases.jsonl` are unchanged.
Tests assert `valid`, the PHP save-time contract; `goValid` remains reference
metadata rather than an equality claim. Provenance hashes pin both the fixtures
and the namespace-normalized implementation. `EMAIL_VALIDATOR_AUTOLOAD` can point
the upstream test runner at an application's installed Composer autoloader.

# ADR 0006: Shared Google Translation configuration

- Status: Accepted
- Date: 2026-09-17

## Context

Agent Admin and Platform Admin need the same Google Translation Basic v2
configuration, encrypted API-key storage, connection verification, and UI.
Authentication, response envelopes, and the encryption implementation remain
application concerns.

## Decision

`6mm-php` owns configuration validation, persistence, masking, verification
state, and the Google Translation HTTP verifier. Applications inject a
`TranslationConfigCipher`, so existing Laravel `Crypt` ciphertext remains
compatible without coupling the shared package to Laravel support facades.

`6mm-ui` owns the translation configuration dialog and its action contract.
Applications inject load, save, and test functions and decide where the button
is shown.

Host applications retain authentication and agent-scope resolution, routes,
localized response envelopes, migrations, and encryption-key management.

## Consequences

- Agent and Platform use the same validation, storage, masking, and Google
  verification behavior.
- Candidate-key tests do not require a pre-existing database row.
- API keys are never placed in URLs or returned to the frontend.
- Each host must create the compatible `agent_translation_config` table and
  provide its own cipher adapter.

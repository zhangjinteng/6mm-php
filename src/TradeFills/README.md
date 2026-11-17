# Historical trade-fill ownership

Platform and Agent share `TradeFillOwnerQueryService`. `rows`, `count`, and `detail`
authorize the CK event using the same `home_site_id AND agent_id` predicate:

- Platform source: exactly `(0, 0)`; NULL is not platform.
- Agent white-label login: `(first enabled owned site_id ordered ascending, 0)`.
- Agent merchant login: `(main merchant home_site_id, main merchant id)`; child administrator
  logins resolve to the main merchant and do not add child IDs to the query.
- A missing/inactive Agent owner yields an empty scope, never all records.
- Total-admin merchant: `home_site_id > 0 AND agent_id > 0`.
- Total-admin white label: `home_site_id > 0 AND agent_id = 0`, without a first-site or enabled-site restriction.
- Total-admin all: exactly the union of merchant, white label, and platform.
  NULL/negative IDs and `(0, positive agent)` do not belong to those categories.

Platform category selections do not query the business directory. Selecting a
specific source name narrows the CK query through the merchant/site mapping;
selecting a white-label name includes all its sites, including disabled sites.
Agent ignores caller-supplied owner IDs and derives
the pair from its authenticated identity. Names are enriched from the CK owner
using `TradeFillOwnerDirectory`, not from the user's current ownership.

CK `user_type` is used directly. Business-directory queries are limited to
keyword matches and current-page identities. Moved, deleted, and missing users
must not remove authorized historical rows after pagination; their public/name
fields may be absent. Historical external IDs use the CK merchant ID.

No schema migration or historical rewrite is required. The two hosts supply
their existing time-zone conversion and response envelopes. Count caches use a
new owner-specific namespace so pre-change cached totals are not reused.

Test with `php tests/trade_fill_owners.php` (in-memory SQLite and fake CK calls).

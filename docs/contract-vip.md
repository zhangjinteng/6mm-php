# Contract VIP reporting

`ContractVipListService` shares read-only user filtering, 30-day volume aggregation, sorting, paging and progress calculation. Hosts supply `UserDataScope` and a per-internal-user threshold map. Scope is applied before COUNT/aggregation/pagination. Keyword wildcards are escaped, VIP zero is a valid filter, and only whitelisted sort identifiers/directions reach SQL.

Platform keeps `/users/contract-vip`, `users:contractVip`, its source filtering/metadata and platform-default thresholds. The old `App\Services\UserQuery\ContractVipProgress` remains a compatibility facade.

Agent exposes `/api/users/contract-vip` to authenticated white-label accounts with `users:contractVip` only. Existing `UserAccessScopeResolver` includes direct (`agent_id=0` or NULL) users from all owned enabled sites, excluding soft-deleted users, other tenants and merchant users. Request scope/source IDs are ignored. Thresholds are read from each user's site's non-deleted `handling_fee_level_config`, `agent_id=0`, `level>0`. Missing configuration is unknown progress, not a platform fallback or a maximum-level assertion. No initialization writes or schema changes occur.

`vip_level_updated_at` remains null because no authoritative separate timestamp exists. `last_calculated_at` remains the latest daily-volume update within the same existing 30-day window, not `users.updated_at`. Maximum-level and percentage behavior are preserved in `ContractVipProgress`.

Run `php tests/contract_vip.php`, Platform VIP regression tests, and Agent `WhiteLabelContractVipTest` in isolated SQLite fixtures. Shared artifacts must be released with consuming hosts later; local vendor sync is not publication.

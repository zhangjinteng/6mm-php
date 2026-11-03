# Shared tenant channels

`ChannelConfigStore` owns social/download link validation, partial-section merge,
tenant-row serialization, version conflict detection and atomic persistence in
`control_plane.wl_channel_configs`. `detail()` never returns customer-service secrets.
The host still authorizes the tenant and account; this store is not an access guard.

- Platform supplies its selected, authorized tenant and real administrator ID.
- Agent supplies the authenticated `WlTenantAccount::tenant_id`, never request IDs,
  and saves only `social_links`, with `actorType: 'white_label'`.
- White-label saves require an audit callback. `updated_by` references `public.admin`,
  so white-label saves clear it rather than mixing account namespaces. Actual actor,
  tenant, before/after values and timestamp belong in `control_plane.wl_operation_log`.
- Audit callbacks run inside the same transaction; throwing rolls back the save.
- Pass the exact `expected_updated_at` from the last read, including `null` on first save.
- Omitted sections, unknown stored keys and customer provider credentials are preserved.
  Blank supported links remove those links. HTTPS only; credentials and control characters
  are rejected. Links are never fetched by the server.

The existing Platform channel-table migration must be deployed before either host uses
the store. No new configuration table or scheduled producer is needed.

Verification: `php tests/tenant_channels.php` or `composer test`.

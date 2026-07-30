<?php
/**
 * English lexicon for mxApi.
 *
 * @package mxapi
 * @subpackage lexicon
 */

$_lang['mxapi'] = 'mxApi';
$_lang['mxapi_menu_desc'] = 'API endpoint catalog and integration clients.';

$_lang['mxapi_endpoints'] = 'Endpoints';
$_lang['mxapi_clients'] = 'Clients';
$_lang['mxapi_tokens'] = 'Tokens';
$_lang['mxapi_log'] = 'Log';

$_lang['mxapi_err_no_permission'] = 'Insufficient permissions for this action.';
$_lang['mxapi_err_not_found'] = 'Object not found.';
$_lang['mxapi_err_no_vendor'] = 'mxApi dependencies are missing: no vendor/autoload.php in core/components/mxapi/.';

/* System settings labels; see the Russian file for the rationale. */
$_lang['area_mxapi'] = 'mxApi';
$_lang['area_mxapi:default'] = 'mxApi';
$_lang['area_mxapi_access'] = 'mxApi: access and contexts';
$_lang['area_mxapi_limits'] = 'mxApi: limits and paging';
$_lang['area_mxapi_log'] = 'mxApi: journal and debug';

$_lang['setting_mxapi.enabled'] = 'API enabled';
$_lang['setting_mxapi.enabled_desc'] = 'Master switch: when off, the entry point answers 503 to any request, including the endpoint catalog.';
$_lang['setting_mxapi.route_prefix'] = 'Route prefix';
$_lang['setting_mxapi.route_prefix_desc'] = 'Public prefix for API routes, /mxapi/v1 by default. Project aliases go to core/config/mxapi.php.';
$_lang['setting_mxapi.context'] = 'Default context';
$_lang['setting_mxapi.context_desc'] = 'MODX context used to check permissions and run processors unless the endpoint declares its own. Management endpoints run in mgr.';
$_lang['setting_mxapi.allow_request_context'] = 'Allow context from request';
$_lang['setting_mxapi.allow_request_context_desc'] = 'Lets the caller pick a context via the X-MxApi-Context header, and only for endpoints declaring context "from request". Needed for multisite; off by default because it widens the attack surface. The context must still be allowed for the client (contexts field).';
$_lang['setting_mxapi.token_ttl'] = 'Token lifetime, sec';
$_lang['setting_mxapi.token_ttl_desc'] = 'How long an issued bearer token lives. Revocation is immediate regardless of this value.';
$_lang['setting_mxapi.default_limit'] = 'Default page size';
$_lang['setting_mxapi.default_limit_desc'] = 'Used when the client sends no limit.';
$_lang['setting_mxapi.max_limit'] = 'Maximum page size';
$_lang['setting_mxapi.max_limit_desc'] = 'Hard ceiling: larger values are clamped so a single request cannot dump the whole table.';
$_lang['setting_mxapi.rate_limit_per_minute'] = 'Requests per minute';
$_lang['setting_mxapi.rate_limit_per_minute_desc'] = 'Per-client limit; 0 disables it. A client may carry its own rate_limit, which wins.';
$_lang['setting_mxapi.trusted_proxies'] = 'Trusted proxies';
$_lang['setting_mxapi.trusted_proxies_desc'] = 'Comma separated IPs. X-Forwarded-For is honoured only for these, otherwise IP restrictions could be bypassed with a header.';
$_lang['setting_mxapi.log_reads'] = 'Log successful reads';
$_lang['setting_mxapi.log_reads_desc'] = 'Writes and errors are always logged. Successful reads only when enabled, otherwise the journal becomes a hit counter.';
$_lang['setting_mxapi.log_lifetime'] = 'Journal retention, sec';
$_lang['setting_mxapi.log_lifetime_desc'] = 'Old records are purged automatically, at most once an hour. 0 keeps everything.';
$_lang['setting_mxapi.cors_origins'] = 'Allowed CORS origins';
$_lang['setting_mxapi.cors_origins_desc'] = 'Comma separated; "*" allows any. Empty means no CORS headers.';
$_lang['setting_mxapi.debug'] = 'Expose internal error details';
$_lang['setting_mxapi.debug_desc'] = 'Debug only: the response carries the exception message. Keep it off in production.';

$_lang['setting_mxapi.catalog_filter'] = 'Catalog visibility';
$_lang['setting_mxapi.catalog_filter_desc'] = 'all — the whole public contract (the catalog works as documentation, so integrators can see which scopes exist); scope — only endpoints callable with the presented token; permission — only endpoints the user holds a MODX permission for. Tighten it when several independent integrations share the site: with all, one client sees the other one\'s endpoints along with permission names.';

/* mxApi tab on the user edit page: integration clients. */
$_lang['mxapi_client_intro'] = 'Integration clients act on behalf of this user and are limited by their permissions. The secret is shown once — on creation and on regeneration.';
$_lang['mxapi_client_create'] = 'Add';
$_lang['mxapi_client_edit'] = 'Edit';
$_lang['mxapi_client_remove'] = 'Delete';
$_lang['mxapi_client_regenerate'] = 'Regenerate secret';
$_lang['mxapi_client_activate'] = 'Enable';
$_lang['mxapi_client_deactivate'] = 'Disable';

$_lang['mxapi_client_name'] = 'Name';
$_lang['mxapi_client_key'] = 'client_id';
$_lang['mxapi_client_scopes'] = 'Access';
$_lang['mxapi_client_tokens'] = 'Live tokens';
$_lang['mxapi_client_createdon'] = 'Created';

$_lang['mxapi_client_ttl'] = 'Token lifetime';
$_lang['mxapi_client_ttl_site'] = 'Site default';
$_lang['mxapi_client_ttl_custom'] = 'Custom value';
$_lang['mxapi_client_ttl_never'] = 'Never expires';
$_lang['mxapi_client_ttl_seconds'] = 'Seconds';
$_lang['mxapi_client_ttl_sec_short'] = 'sec.';
$_lang['mxapi_client_ttl_never_warning'] = 'A never-expiring token does not lapse on its own: it can only be revoked manually — by disabling the client or regenerating the secret with token revocation. Use it for integrations that cannot be taught to refresh tokens.';

$_lang['mxapi_client_window_create'] = 'New integration client';
$_lang['mxapi_client_window_update'] = 'Integration client';
$_lang['mxapi_client_scopes_caption'] = 'What the client is allowed to do. Only scopes the user has MODX permissions for are listed.';
$_lang['mxapi_client_scopes_all'] = 'All endpoints';
$_lang['mxapi_client_scopes_none_allowed'] = 'The user has no access to the mxapi namespace — nothing to grant.';
$_lang['mxapi_client_scopes_none_exist'] = 'This site has no endpoints with a scope.';

$_lang['mxapi_client_secret_title'] = 'Client credentials';
$_lang['mxapi_client_secret_warning'] = 'The secret is shown once. Copy it now: only a hash is stored, the value cannot be recovered — you would have to regenerate it.';
$_lang['mxapi_client_secret_copy'] = 'Copy secret';
$_lang['mxapi_client_secret_copied'] = 'Secret copied';
$_lang['mxapi_client_remove_confirm'] = 'Delete this client together with its tokens? Any integration using them stops working immediately.';
$_lang['mxapi_client_regenerate_confirm'] = 'A new secret will be issued and the old one stops working. Tokens already issued remain valid until they expire — revoke them if the secret is compromised.';
$_lang['mxapi_client_revoke_tokens'] = 'Revoke issued tokens';

$_lang['mxapi_client_err_user_ns'] = 'No user specified.';
$_lang['mxapi_client_err_user_nf'] = 'User not found.';
$_lang['mxapi_client_err_nf'] = 'Client not found.';
$_lang['mxapi_client_err_name_ns'] = 'Enter a client name.';
$_lang['mxapi_client_err_scopes_ns'] = 'Select at least one scope.';
$_lang['mxapi_client_err_scope_na'] = 'Scope is not available to this user: [[+scope]]';
$_lang['mxapi_client_err_ttl'] = 'Token lifetime: 0 — site default, -1 — never expires, otherwise at least 60 seconds.';
$_lang['mxapi_client_err_save'] = 'Could not save the client.';
$_lang['mxapi_client_err_remove'] = 'Could not delete the client.';

$_lang['mxapi_client_actions'] = 'Actions';
$_lang['mxapi_client_regenerate_short'] = 'Regenerate';

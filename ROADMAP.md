# mxapi2 Roadmap

> Last updated: 2026-07-29

`mxapi2` is the MODX 2 line of mxapi. Its job is to turn the current Sleep & Glow
API prototype into a reusable MODX 2 component where custom endpoints can be
registered without editing the package core.

## Product Direction

`mxapi2` owns the public integration API for MODX 2 projects:

- stable public routes under mxapi control;
- bearer-token authentication;
- endpoint scopes mapped to MODX permissions;
- endpoint registry and metadata;
- read-only CMP catalog similar to a small Swagger UI;
- OpenAPI export generated from endpoint metadata;
- adapters for site-specific data providers.

The current `/api/v1/orders/export` route remains a Sleep & Glow compatibility
alias. New reusable API routes should use the mxapi namespace, for example
`/api/mx/v1/...`.

## Phase 1: Stabilize Current API

1. Preserve the existing public order export contract.
   Location: current Sleep & Glow `mxapi` implementation and docs.
   Reason: external clients may already depend on `/api/v1/orders/export`.

2. Keep the existing token table and MODX namespace permission model.
   Location: `modx_mxapi_tokens`, namespace `mxapi`, policy template
   `mxapiTemplate`.
   Reason: the current model is simple, auditable and already compatible with
   MODX ACL.

3. Document `/api/v1/orders/export` as legacy compatibility.
   Location: package docs and future CMP metadata.
   Reason: new endpoint design should not be constrained by the first route.

## Phase 2: Endpoint Registry

1. Add an endpoint interface.
   Location: `core/components/mxapi/model/mxapi/` or future `src/Endpoint/`.
   Reason: each endpoint must declare its route, methods, scope, permission,
   parameters, examples and handler in one place.

2. Add a router/registry layer.
   Location: `MxApiController` replacement or extracted `MxApiRouter`.
   Reason: adding an endpoint should not require editing a hard-coded
   `if ($route === ...)` controller.

3. Move `scope -> permission` mapping out of auth.
   Location: `MxApiAuth` and endpoint registry.
   Reason: auth should validate a declared endpoint scope, not own the endpoint
   catalog.

4. Support endpoint registration from config.
   Location: `core/config/mxapi.php`.
   Reason: project-specific endpoints should be added by config/class path, not
   by patching mxapi core.

Example target config shape:

```php
return array(
    'token_ttl_seconds' => 86400,
    'endpoints' => array(
        'orders.export' => array(
            'class' => 'MxApiEndpointOrdersExport',
            'file' => MODX_CORE_PATH . 'components/mxapi/endpoints/orders/export.class.php',
        ),
        'custom.foo' => array(
            'class' => 'CustomFooEndpoint',
            'file' => MODX_CORE_PATH . 'components/customapi/endpoints/foo.class.php',
        ),
    ),
);
```

## Phase 3: CMP Mini Swagger

1. Add a manager page for mxapi.
   Location: MODX 2 manager controller, menu action and assets.
   Reason: integrators need a discoverable endpoint catalog without opening
   repository files.

2. Make the first CMP version read-only.
   Location: mxapi manager UI.
   Reason: read-only metadata is low risk; editing routes/policies from UI can
   come later.

3. Show endpoint metadata.
   Location: CMP endpoint list/detail view.
   Reason: the page should show method, route, scope, permission, parameters,
   body schema, response summary and examples.

4. Export OpenAPI from the same metadata.
   Location: `GET /api/mx/meta/openapi` and CMP download action.
   Reason: static YAML must not become the source of truth.

## Phase 4: MODX 2 Core Endpoints

1. Add resource read endpoints.
   Location: mxapi endpoint provider for MODX resources.
   Reason: resources are the least risky MODX management API surface.

2. Add resource write endpoints behind separate permissions.
   Location: mxapi MODX resource provider.
   Reason: create/update/publish/delete must be explicitly gated and logged.

3. Add resource group endpoints.
   Location: mxapi MODX resource group provider.
   Reason: resource group membership is needed for controlled content
   automation.

4. Add user group endpoints.
   Location: mxapi MODX user group provider.
   Reason: external automation may need group membership management.

5. Add user endpoints last.
   Location: mxapi MODX user provider.
   Reason: user writes are the highest-risk surface and need strict field
   allow-lists, sudo guards and audit logging.

## Phase 5: Safety And Operations

1. Add audit logging for write endpoints.
   Location: mxapi log table or MODX manager log integration.
   Reason: external API writes must be traceable by token, user, route and input
   summary.

2. Add dry-run support where practical.
   Location: write endpoint handlers.
   Reason: automation clients need a safe way to preview destructive changes.

3. Add idempotency keys for writes.
   Location: request handling and write endpoints.
   Reason: clients can safely retry create/update actions after network errors.

4. Add smoke scripts for token, catalog and one read/write endpoint.
   Location: package docs or test scripts.
   Reason: every install should be verifiable without manual API guessing.

## Non Goals For mxapi2

- Do not make mxapi2 depend on miniShop3.
- Do not make the static OpenAPI YAML the source of truth.
- Do not expose broad write endpoints without endpoint-specific permissions.
- Do not use `/api/v1` as the default route namespace for new mxapi endpoints.

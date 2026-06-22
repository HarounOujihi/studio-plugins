# Soldx Plugins — Sync Plan

Living document. Update status as work progresses. Add decisions to the
"Open Decisions" section as they come up.

---

## 1. Goal

Sync articles **from external e-commerce platforms into Studio**. First
source: **WooCommerce** (WordPress plugin). Designed to extend later to
Prestashop, Shopify, etc.

**Sync is manual, user-driven, and one-directional (Shop → Studio).**
The shop admin picks which WC products to push; Studio creates / updates
the matching `Article` (+ `Pricing`, `ArticleExternalMapping`) atomically.
No stock sync. No auto push on product edit.

**Direction flip (2026-06-20).** Earlier iterations of this plan assumed
Studio → Shop. The user clarified: the only goal is Shop → Studio. All
implementation (both halves) has been flipped to match. **`schema.prisma`
is unchanged** — the `Integration` + `ArticleExternalMapping` models are
direction-agnostic and already cover this flow.

---

## 2. Key Decisions (locked)

| # | Decision | Rationale |
|---|---|---|
| D1 | Tables `Integration` + `ArticleExternalMapping` (already in schema.prisma:3765, 3787) — no further schema work | Direction-agnostic by design; one Article maps to N shops |
| D2 | Auth = single `apiKey` (no public/secret pair yet) | Migrate later when needed; reversible |
| D3 | Selection UI lives in **WordPress**, not Studio. It lists **WC products** (not Studio articles). | Shop owner's workflow; Studio only issues the `apiKey` |
| D4 | Stock **not** synced. Pricing **is** pushed from WC. | Stock is operational; pricing is catalog |
| D5 | No currency modeling. The establishment has one currency; WC store uses the same. | Avoid Pricing-table currency fan-out |
| D6 | Plugin routes are **naturally isolated** from Studio SSO. Middleware matcher excludes all `/api/*`. Each plugin route does its own bearer-token check inside the handler. | No new middleware needed |
| D7 | Monorepo for plugins (`soldx-plugins/`) — one subfolder per plugin. | Easier cross-plugin consistency |
| D8 | **Per-article option selectors in WP UI**: for each WC product being pushed, the user picks `saleUnit`, `purchaseUnit`, `deposit` (each pre-selected from defaults). A WC product cannot be pushed without a sale unit. | Studio needs all three to create a valid `Article`; user must confirm them per-product |
| D9 | DB migrations are run **manually** by Haroun. The plan must never run `prisma migrate`. | Owner policy |
| D10 | **Tiered defaults** for unit / deposit, resolved on the WP side: explicit per-article selection (UI) → `ArticleExternalMapping.payload.<field>` (last synced override) → `Integration.config.default<field>Id` → first available in establishment. | Single UX; per-article escape hatch when needed |
| D11 | **Minimal Studio UI**: a "Plugins" tab under `/settings/plugins` lets the admin activate a plugin, see/copy the `apiKey` once, configure **establishment defaults** (default sale unit, purchase unit, deposit), and revoke. | Defaults must be set somewhere; activation modal is the natural place |
| D12 | **`Integration.config` is populated** at activation time with `{ defaultSaleUnitId, defaultPurchaseUnitId, defaultDepositId, storeUrl?, version? }`. The WP plugin reads these to preselect dropdowns. | WP UI needs a default to render against |
| D13 | **Article creation is atomic on Studio side**: `POST /api/plugin/articles/import` creates `Article` + `Pricing` + `ArticleExternalMapping` in one transaction. Returns the new ids. | Avoids half-created state on failures |
| D14 | **`Article.reference` resolution on import**: WC SKU if non-empty, else `wc-{post_id}`. The canonical mapping key is `externalId = WC post_id` (matches `@@unique([idIntegration, externalId])`). | SKUs are optional in WC; post_id is always present |
| D15 | **Re-sync = idempotent upsert**: `PUT /api/plugin/articles/import/[externalId]` looks up by `(idIntegration, externalId)`, updates fields, creates a new `Pricing` row if the price changed. Never deletes the Article. | Safe retry; pricing history preserved |

---

## 3. Repository Layout

```
/home/haroun/projects/sawi/soldx-plugins/
├── PLAN.md                         # this file
├── plugins/
│   └── soldx-woocommerce/
│       ├── soldx-woocommerce.php   # bootstrap, autoloader, activation hook
│       ├── readme.txt              # WP plugin metadata
│       ├── uninstall.php
│       ├── includes/
│       │   ├── class-api-client.php      # Studio HTTP client (push + options)
│       │   ├── class-auth.php            # apiKey storage / validation
│       │   ├── class-sync-engine.php     # WC product → Studio Article push
│       │   ├── class-mapping-store.php   # local wp_soldx_mappings table
│       │   ├── class-admin-settings.php  # settings page controller
│       │   ├── class-admin-articles.php  # WC product selection + dropdowns
│       │   └── helpers.php
│       └── admin/
│           └── assets/
│               └── admin.css
└── packages/                       # optional, deferred
```

Studio-side additions live inside the Studio repo (`/home/haroun/projects/sawi/studio`):

```
src/lib/integrations/
├── api-key.ts        # keep — apiKey generation / hashing
├── auth.ts           # keep — bearer-token resolver
├── repo.ts           # extended — add importWcProduct + listEstablishmentOptions
└── payload.ts        # rewritten — buildArticleFromWcProduct + getEstablishmentOptions

src/app/api/plugin/
├── auth/route.ts                       # keep — POST /api/plugin/auth
├── options/route.ts                    # NEW — GET /api/plugin/options (units/deposits/taxes/defaults)
├── articles/
│   └── import/
│       ├── route.ts                    # NEW — POST (create) + PUT-bulk
│       └── [externalId]/route.ts       # NEW — GET / PUT (read or upsert one)
└── mappings/
    ├── route.ts                        # repurposed — POST now optional (import already creates mapping)
    └── [idArticle]/route.ts            # keep — PATCH (toggle/error) + DELETE (soft)
```

The old `src/app/api/plugin/articles/route.ts` (GET catalog) and
`src/app/api/plugin/articles/[id]/route.ts` (GET one article) are
**removed** — they served Studio's catalog, which is no longer the flow.

---

## 4. Studio Backend Tasks

Reference points in current schema:
- `Article` model: `schema.prisma:932`
- `Unit` model: `schema.prisma:890`
- `Deposit` model: `schema.prisma:477`
- `Pricing` model: `schema.prisma:1269`
- `Integration` / `ArticleExternalMapping`: `schema.prisma:3765, 3787`
- Existing prisma client wrapper: `src/lib/prisma.ts`

### 4.1 Library code

- [x] **4.1.1** `src/lib/integrations/api-key.ts` — `generateApiKey()` (randomBytes 32 hex) + `hashApiKey()`. **(unchanged)**
- [x] **4.1.2** `src/lib/integrations/auth.ts` — `resolveIntegrationFromRequest()`, bumps `lastSeenAt`. **(unchanged)**
- [~] **4.1.3** `src/lib/integrations/repo.ts`
      - Keep: `createIntegration`, `listIntegrations`, `getIntegration`, `regenerateApiKey`, `setIntegrationStatus`, `revokeIntegration`, `upsertMapping`, `patchMapping`, `softDeleteMapping`, `listMappings`, `getMappingIndex`
      - **Add**: `importWcProduct({ integration, externalId, externalSlug, fields, saleUnitId, purchaseUnitId, depositId, pricing })` — atomic `prisma.$transaction`: create `Article` (generate cuid id, set `reference` per D14, set `idOrg`/`idEtb` from integration, attach units, link deposit via `Article.deposit` relation if exists or store in payload), create `Pricing` (latest `effectDate = now`), create `ArticleExternalMapping` with payload `{ saleUnitId, purchaseUnitId, depositId, hash }`. Returns `{ idArticle, mappingId }`.
      - **Add**: `updateImportedArticle({ integration, externalId, ...fields })` — idempotent upsert per D15. Looks up mapping by `(idIntegration, externalId)`, updates mutable Article fields, creates a new `Pricing` row only if `salePrice` changed.
      - **Add**: `listEstablishmentOptions({ idEtb })` — returns `{ units: Unit[], deposits: Deposit[], taxes: Tax[], integrationConfig }` for the WP dropdowns.
- [~] **4.1.4** `src/lib/integrations/payload.ts`
      - **Remove**: `buildArticlePayload`, `listSyncableArticles`, `SyncArticleDTO`, `SyncArticleListItem` (all Studio→WC direction).
      - **Add**: `WcProductImportDTO` interface — the shape WP sends:
        ```ts
        {
          externalId: string,         // WC post_id (canonical key)
          externalSlug: string|null,  // WC SKU
          designation: string,        // WC post_title
          reference?: string,         // WC SKU or "wc-{post_id}" fallback (resolved WP-side per D14)
          slug?: string,              // WC post_name
          shortDescription?: string,
          description?: string,
          ean?: string,
          weight?: number,
          productType?: "PHYSICAL_PRODUCT"|"SERVICE"|"DIGITAL_PRODUCT", // mapped from WC virtual/downloadable
          isService?: boolean,
          isDigitalProduct?: boolean,
          media?: string|null,
          gallery?: string[],
          pricing: { salePrice: number, purchasePrice?: number, taxRate?: number },
          // per-article overrides (D8):
          saleUnitId: string,
          purchaseUnitId?: string,
          depositId?: string,
          hash: string,               // sha256 of relevant fields, for no-op skip
        }
        ```
      - **Add**: `mapWcToArticle(input: WcProductImportDTO, ctx: { idOrg, idEtb })` — pure mapper that returns the `Article` create payload (no DB calls). Used inside `importWcProduct`.

### 4.2 Public API routes (under `src/app/api/plugin/`)

> All routes are bearer-token authenticated. They run **outside** the SSO
> middleware scope (matcher excludes `/api/*`).

- [x] **4.2.1** `POST /api/plugin/auth` **(unchanged)** — body `{ apiKey }`, returns `{ integrationId, idEtb, idOrg, type, name, establishmentName, currency, config }`. Flips `status: PENDING → ACTIVE` on first contact. **Now also returns `config`** so WP can read defaults without a second round-trip.
- [ ] **4.2.2** ~~`GET /api/plugin/articles`~~ **(DELETED — wrong direction)**
- [ ] **4.2.3** ~~`GET /api/plugin/articles/:id`~~ **(DELETED — wrong direction)**
- [ ] **4.2.4 (NEW)** `GET /api/plugin/options`
      - Returns `{ units: [{ id, reference, designation }], deposits: [{ id, reference, designation, type }], taxes: [{ id, reference, rate }], config: { defaultSaleUnitId, defaultPurchaseUnitId, defaultDepositId } }`
      - Source: `Unit`, `Deposit`, `Tax` filtered by integration's `idEtb`; `config` from `Integration.config`
      - This is what the WP plugin calls to populate the per-article dropdowns (D8)
- [ ] **4.2.5 (NEW)** `POST /api/plugin/articles/import`
      - Body: `WcProductImportDTO` (see §4.1.4)
      - Behaviour: `importWcProduct()` atomic transaction (D13)
      - Returns: `{ idArticle, mappingId, reference, created: boolean }`
      - Errors: 409 if `(idIntegration, externalId)` already exists (caller should PUT instead); 422 if `saleUnitId` not in establishment
- [ ] **4.2.6 (NEW)** `PUT /api/plugin/articles/import/[externalId]`
      - Body: partial `WcProductImportDTO`
      - Behaviour: `updateImportedArticle()` per D15 (idempotent)
      - Returns: `{ idArticle, mappingId, reference, created: false, priceChanged: boolean }`
      - 404 if no mapping for `(idIntegration, externalId)`
- [ ] **4.2.7 (NEW)** `GET /api/plugin/articles/import/[externalId]`
      - Returns the current mapping state for a WC product: `{ idArticle, mappingId, syncStatus, isEnabled, lastSyncAt, lastError, payload }`
      - Lets the WP plugin check "is this WC product already synced?"
- [x] **4.2.8** `PATCH /api/plugin/mappings/[idArticle]` **(unchanged)** — `{ isEnabled?, lastError? }`, updates sync state
- [x] **4.2.9** `DELETE /api/plugin/mappings/[idArticle]` **(unchanged)** — soft delete, sets `syncStatus=DELETED_REMOTE`. The Studio Article is left in place.
- [ ] **4.2.10** Rate limiting wrapper (per apiKey) — defer to Phase 3

### 4.3 Admin UI (already built, needs minor extension)

The "Plugins" tab at `/settings/plugins` is built and works. Two small
additions for the new direction:

- [x] **4.3.1** `/settings/plugins/page.tsx` — list + activate + revoke. **(built)**
- [ ] **4.3.2 (NEW)** When activating a WooCommerce integration, the modal collects **default unit / deposit** selectors (querying the establishment's units and deposits). These populate `Integration.config` per D12. Without these defaults, the WP plugin can still render dropdowns but the user has to pick manually for every product.
- [x] **4.3.3** Admin nav entry `Plug` icon. **(built)**
- [x] **4.3.4** RBAC: `PERMISSIONS.SETTINGS_ORGANIZATION`. **(built)**

### 4.4 Permissions / RBAC

- [x] **4.4.1** v1 reuses `SETTINGS_ORGANIZATION`. A dedicated `INTEGRATIONS_MANAGE` permission is deferred.

---

## 5. WordPress Plugin Tasks (`plugins/soldx-woocommerce/`)

### 5.1 Bootstrap

- [x] **5.1.1** `soldx-woocommerce.php` — constants, autoloader, activation hook (creates `wp_soldx_mappings`), plugins_loaded check for WC. **(built — needs description text flip)**
- [x] **5.1.2** `uninstall.php` — drops table + options. **(built)**
- [x] **5.1.3** `readme.txt`. **(built — needs description text flip)**

### 5.2 Local persistence

- [x] **5.2.1** `class-mapping-store.php` — table `wp_soldx_mappings`. **Symmetric, no change.** Methods: `get_by_wc_id`, `get_by_studio_id`, `upsert`, `set_error`, `set_disabled`, `map_for_wc_ids`, `list_mappings`.
- [x] **5.2.2** `class-auth.php` — stores `studio_url`, `api_key`, `integration_id`, `establishment_name` in `wp_options`. **(no change)**

### 5.3 HTTP client

- [~] **5.3.1** `class-api-client.php`
      - Keep: `authenticate()`, `report_mapping()`, `patch_mapping()`, `delete_mapping()`, internal `request()`
      - **Remove**: `list_articles()`, `get_article()` (no longer reading Studio catalog)
      - **Add**: `get_options()` → `GET /api/plugin/options`
      - **Add**: `push_product($dto)` → `POST /api/plugin/articles/import`
      - **Add**: `update_product($external_id, $dto)` → `PUT /api/plugin/articles/import/[externalId]`
      - **Add**: `get_mapping($external_id)` → `GET /api/plugin/articles/import/[externalId]`

### 5.4 Sync engine

- [~] **5.4.1** `class-sync-engine.php` — **rewritten**
      - `sync_product(int $wc_product_id, array $overrides): array`
      - Reads `wc_get_product($wc_product_id)`, builds `WcProductImportDTO`:
        - `externalId = (string) $product->get_id()`
        - `externalSlug = $product->get_sku() ?: null`
        - `reference = $product->get_sku() ?: "wc-{$product->get_id()}"` (D14)
        - `designation = $product->get_name()`
        - `slug = $product->get_slug()`
        - `shortDescription`, `description` from WC
        - `weight`, `ean` from WC/meta
        - `productType` mapped from `$product->is_virtual()` / `is_downloadable()`
        - `media` + `gallery` from `$product->get_image_id()` + `get_gallery_image_ids()`, resolved to URLs
        - `pricing.salePrice = (float) $product->get_regular_price()`
        - `saleUnitId`, `purchaseUnitId`, `depositId` from `$overrides`
        - `hash = sha256(...)` for no-op skip
      - Checks local mapping first: if exists and `payload_hash` matches → skip (no-op). If exists and hash differs → `update_product()` (PUT). If not exists → `push_product()` (POST).
      - Returns `{ success, idArticle?, mappingId?, reference?, created?, error? }`.
- [ ] **5.4.2** Pricing history: Studio creates a new `Pricing` row only when the price changed. WP does not need to track this — Studio decides.
- [ ] **5.4.3** Variants: deferred (Q1). WC variations are ignored in v1; parent product synced as one Article.

### 5.5 Admin UI

- [x] **5.5.1** Settings page (`class-admin-settings.php`). **(built — UI text flip only)**
      - Fields: Studio URL, API key, Test connection. Keep "Disconnect".
- [~] **5.5.2 (REWRITE)** Articles selection page (`class-admin-articles.php`)
      - Server-rendered table of **WC products** (`wc_get_products({ status: 'publish', paginate: true })`)
      - Per row: checkbox, thumbnail, name, SKU, price, status badge (synced / pending / error) read from local mapping store
      - On page load: call `get_options()` once, cache in transient for 5 min — this is the dropdown source
      - **Per-article dropdowns** (D8): for each checked row, render three `<select>`:
        - Sale unit (required) — default = `Integration.config.defaultSaleUnitId`
        - Purchase unit (optional) — default = `Integration.config.defaultPurchaseUnitId`
        - Deposit (optional) — default = `Integration.config.defaultDepositId`
      - If the article is already synced, dropdowns preselect the last-synced override from `ArticleExternalMapping.payload` (stored locally too)
      - "Sync selected" bulk button → loops through checked rows, calls `Soldx_Sync_Engine::sync_product($id, $overrides)`, collects results, shows summary notice
- [x] **5.5.3** Sync result admin notice (success/failure counts). **(built — format works either direction)**
- [ ] **5.5.4** `admin.js` for select-all + "syncing…" spinner (currently inline `<script>`; can stay inline for v1)

---

## 6. Field Mapping (WooCommerce → Studio)

| WC source | Studio target | Notes |
|---|---|---|
| `$product->get_id()` | `ArticleExternalMapping.externalId` | Canonical key; matches `@@unique([idIntegration, externalId])` |
| `$product->get_sku()` | `ArticleExternalMapping.externalSlug` + `Article.reference` (fallback `wc-{id}` per D14) | SKU optional in WC |
| `$product->get_name()` | `Article.designation` | Required |
| `$product->get_slug()` | `Article.slug` | |
| `$product->get_short_description()` | `Article.shortDescription` | |
| `$product->get_description()` | `Article.description` | |
| `$product->get_weight()` | `Article.weight` | |
| WC `_soldx_ean` meta or `WC_Product::get_global_unique_id()` | `Article.EAN` | |
| `$product->get_image_id()` + `get_gallery_image_ids()` → URLs | `Article.media` + `Article.gallery` | URLs sent as-is; Studio may later download |
| `$product->is_virtual()` | `Article.isService = true`, `Article.productType = SERVICE` | |
| `$product->is_downloadable()` | `Article.isDigitalProduct = true`, `Article.productType = DIGITAL_PRODUCT` | |
| `$product->get_regular_price()` | `Pricing.salePrice` | New `Pricing` row only on change (D15) |
| `$product->get_sale_price()` (if active) | Ignored in v1 — Studio computes its own discounts | Future: push as a `Discount` record |
| User-selected `saleUnitId` (D8) | `Article.saleUnit` | Required |
| User-selected `purchaseUnitId` | `Article.purchaseUnit` | Optional |
| User-selected `depositId` | `ArticleExternalMapping.payload.depositId` (Studio doesn't have a direct Article→Deposit FK) | Stored as override |
| sha256 of relevant fields | `ArticleExternalMapping.payload.hash` | For no-op skip |

---

## 7. API Contract (summary)

```
POST /api/plugin/auth
  { apiKey }
  -> { integrationId, idEtb, idOrg, type, name, establishmentName, currency, config }

GET /api/plugin/options
  -> { units: [{ id, reference, designation }],
       deposits: [{ id, reference, designation, type }],
       taxes: [{ id, reference, rate }],
       config: { defaultSaleUnitId, defaultPurchaseUnitId, defaultDepositId } }

POST /api/plugin/articles/import
  WcProductImportDTO
  -> { idArticle, mappingId, reference, created: true }
  | 409 if (idIntegration, externalId) already mapped → use PUT instead
  | 422 if saleUnitId not in establishment

PUT /api/plugin/articles/import/:externalId
  partial WcProductImportDTO
  -> { idArticle, mappingId, reference, created: false, priceChanged: boolean }
  | 404 if no mapping for this external id under this integration

GET /api/plugin/articles/import/:externalId
  -> { idArticle, mappingId, syncStatus, isEnabled, lastSyncAt, lastError, payload }

PATCH /api/plugin/mappings/:idArticle
  { isEnabled?, lastError? }

DELETE /api/plugin/mappings/:idArticle
```

`WcProductImportDTO`:
```ts
{
  externalId: string,          // WC post_id
  externalSlug: string | null, // WC SKU
  designation: string,
  reference?: string,          // SKU or "wc-{externalId}"
  slug?: string,
  shortDescription?: string,
  description?: string,
  ean?: string,
  weight?: number,
  productType?: "PHYSICAL_PRODUCT"|"SERVICE"|"DIGITAL_PRODUCT",
  isService?: boolean,
  isDigitalProduct?: boolean,
  media?: string | null,
  gallery?: string[],
  pricing: { salePrice: number, purchasePrice?: number, taxRate?: number },
  saleUnitId: string,          // required
  purchaseUnitId?: string,
  depositId?: string,
  hash: string,
}
```

---

## 8. Resolved Decisions

### Q1 — Variants → **deferred** (no variations in MVP)
WC variations ignored. Parent product synced as one Article. Phase 3 task:
map WC variations → Article + `ArticleCombination`.

### Q2 — Units → **per-article selector with defaults** (D8 + D10 + D12)
- Defaults set in `Integration.config` at activation time (D12)
- Per-article UI dropdown on the WP side, preselected from defaults
- Override stored in `ArticleExternalMapping.payload.saleUnitId` on each sync
- A WC product without a sale unit selection cannot be pushed (required field)

### Q3 — Tax → **raw price passthrough** (no per-article config)
- WC `regular_price` sent as-is to Studio
- Studio applies its own tax class via `Pricing.idTax` (resolved from `Integration.config.defaultTaxId` or left null)
- If mismatch becomes a problem, add `Integration.config.priceIncludesTax` later

### Q4 — Deposit → **per-article selector with defaults** (D8 + D10 + D12)
- Defaults set in `Integration.config.defaultDepositId`
- Per-article UI dropdown, preselected from default
- Override stored in `ArticleExternalMapping.payload.depositId`
- Studio's `Article` model has no direct Deposit FK; deposit is recorded on the mapping payload only

### Q5 — Unsync behavior → **soft delete + Studio Article stays**
- Mapping goes `isEnabled=false`, `syncStatus=DELETED_REMOTE`
- The Studio Article is **left intact** (could be in use elsewhere)
- A re-sync of the same WC product reactivates the existing mapping

### Q6 — Images → **send URLs, no upload** (v1)
- WP sends image URLs in the import DTO
- Studio stores them in `Article.media` + `Article.gallery`
- Studio may later download to its own media storage (Phase 3)

### Q7 — Distribution → **GitHub Release zip first, WP.org later**
- v1: GitHub Release zip (install via WP-Admin → Plugins → Add New → Upload)
- Submit to WP.org in parallel for review
- Switch to WP.org auto-updates once approved

### Q8 — Pagination / rate limits → **defaults**
- WP product list: paginated 25/page (WC admin convention)
- Bulk sync: max 50 per click
- No rate limit on Studio in v1 (rely on apiKey revocation for abuse control)

---

## 9. Execution Order

| # | Task | Owner | Status |
|---|---|---|---|
| 1 | Resolve Q1–Q8 + direction flip (this file) | both | **done** |
| 2 | Studio: §4.1 lib code (api-key, auth, repo) | Claude | built (pre-flip); needs §4.1.3 + §4.1.4 extensions |
| 3 | Studio: §4.2 API routes — DELETE GET articles routes | Claude | **pending** |
| 4 | Studio: §4.2 API routes — NEW options + import endpoints | Claude | **pending** |
| 5 | Studio: §4.3 admin UI defaults selector (D12) | Claude | **pending** |
| 6 | WP plugin: §5.1 bootstrap description text flip | Claude | **pending** |
| 7 | WP plugin: §5.3 HTTP client flip (push_product + get_options) | Claude | **pending** |
| 8 | WP plugin: §5.4 sync engine rewrite (sync_product) | Claude | **pending** |
| 9 | WP plugin: §5.5.2 admin articles page rewrite (WC list + dropdowns) | Claude | **pending** |
| 10 | Field-test end-to-end with one real WC product → Studio Article | both | **pending** |
| 11 | WP plugin: variants (per Q1) | Claude | **pending** |
| 12 | Hardening (rate limit, retry, audit logs) | Claude | **pending** |

---

## 10. Out of Scope (deferred indefinitely)

- Two-way sync (Studio → Shop)
- Order ingestion from Shop → Studio as `CommercialDoc`
- Multi-currency pricing
- WPML / Polylang translation mapping (use default language for MVP)
- License / paid-tier gating
- Stock sync (D4 — permanently excluded)
- Real-time push (webhooks from WP on product edit)
- Studio-side image download from WC URLs (Q6 — Phase 3)

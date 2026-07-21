# PRC Firebase

Google Firebase integration for the PRC Platform. Initializes the Kreait Firebase PHP SDK on the server, registers the modern `@prc/firebase` JavaScript script module for blocks, and provides the legacy `firebase` script handle (compat API) for older interactives.

## What it does

- Initializes the Kreait Firebase PHP SDK using a service account JSON from `WPCOM_VIP_PRIVATE_DIR` (`firebase-service-account.json`, written at deploy/local-gen time).
- Exposes `$this->db` (Realtime Database) and `$this->auth` (Firebase Auth) for server-side use via `new \PRC\Platform\Firebase()`.
- Registers the **modern** `@prc/firebase` script module via `wp_register_script_module`. Client-side credentials are injected via the `script_module_data_@prc/firebase` filter and read in `src/index.js`. When the config element is missing or `apiKey` is empty (common on alpha/staging without Firebase env vars), `src/index.js` **skips** `initializeApp` / `getAuth` so anonymous page loads do not throw `auth/invalid-api-key`.
- Registers the **legacy** `firebase` script handle (Firebase 10 compat API) consumed by older `wp_enqueue_script( 'firebase' )` call sites. Localizes `prcFirebaseConfig` and `prcFirebaseInteractivesConfig` onto that handle.

## When to use Firebase vs MySQL

Use Firebase for **outside user data** — quiz state, interactive responses, anonymous session data — anything written simultaneously from multiple clients. Use MySQL for **PRC editorial data**.

## Architecture

```
PHP (server-side)                   JS (client-side)
─────────────────                   ──────────────────
\PRC\Platform\Firebase              @prc/firebase ES module        [modern, preferred]
  └── $this->db  (Realtime DB)        └── Firebase JS SDK (modular)
  └── $this->auth (Auth)              Credentials injected via
                                      script_module_data filter

                                    `firebase` script handle        [legacy compat]
                                      └── Firebase JS SDK (compat)
                                      Globals: window.firebase,
                                      window.firebaseDb, window.firebaseAuth,
                                      window.interactivesDb
                                      Config localized as
                                      prcFirebaseConfig +
                                      prcFirebaseInteractivesConfig
```

## Required constants

| Constant | Description |
|----------|-------------|
| `PRC_PLATFORM_FIREBASE_KEY` / `...__DEV` | API key |
| `PRC_PLATFORM_FIREBASE_AUTH_DOMAIN` / `...__DEV` | Auth domain |
| `PRC_PLATFORM_FIREBASE_AUTH_DB` / `...__DEV` | Auth database URL |
| `PRC_PLATFORM_FIREBASE_INTERACTIVES_DB` / `...__DEV` | Interactives database URL |
| `PRC_PLATFORM_FIREBASE_PROJECT_ID` / `...__DEV` | Project ID |
| `WPCOM_VIP_PRIVATE_DIR` | Path to VIP private directory |

These are defined in `vip-config/` and managed as VIP environment variables.

## Service account file (VIP private dir)

| File | How it is produced |
|------|--------------------|
| `firebase-service-account.json` | `bin/setup/generate-firebase-service-account.sh` at local bootstrap / VIP deploy. Selects the Platform Secrets item tagged for the target env (`production` vs `alpha`/`beta`/`canary`/`local`). |

Do not commit this file. See `docs/DEPENDENCY_AUTH.md`.

## Key files

| File | Purpose |
|------|---------|
| `prc-firebase.php` | Plugin entry; defines constants, loads Jetpack Autoloader, runs `Bootstrap`. |
| `includes/class-bootstrap.php` | Loads dependencies, instantiates SDK + Assets. |
| `includes/class-loader.php` | Hook collector. |
| `includes/class-firebase.php` | The `\PRC\Platform\Firebase` SDK class. Server-side only. |
| `includes/class-assets.php` | Registers script module + legacy script and their localization. |
| `src/index.js` | Source for the `@prc/firebase` script module. |
| `src/compat/index.js` | Source for the legacy `firebase` script handle. |
| `build/module.min.js` | Compiled script module output. |
| `build/compat/index.js` | Compiled legacy script output. |

## Hooks

| Hook | Direction | Description |
|------|-----------|-------------|
| `init` | Action | Registers the `@prc/firebase` script module. |
| `wp_enqueue_scripts` (priority 0) | Action | Registers the legacy `firebase` script handle and localizes its config globals. |
| `admin_enqueue_scripts` (priority 0) | Action | Same as above, for admin context. |
| `script_module_data_@prc/firebase` | Filter | Injects client-side credentials into the modern module. |

## Build

From repo root:

```bash
npm run build -w @prc/firebase
```

This runs both:

- `build:module` — `wp-scripts build` against `webpack.config.js` → `build/module.min.{js,asset.php}`.
- `build:compat` — `wp-scripts build src/compat/index.js --output-path=build/compat` → `build/compat/index.{js,asset.php}`.

## Gotchas

- **Client init is optional** — Blocks that import `@prc/firebase` must tolerate `auth` being uninitialized when Firebase constants are undefined. Gate UI on a successful sign-in flow or server-provided feature flags; do not assume `getAuth()` ran on every page.
- **Legacy vs modern** — Prefer the `@prc/firebase` script module for new blocks. The compat `firebase` handle remains for older interactives that expect `window.firebase` globals.

## Debugging production data locally

Server-side credentials come from `private/firebase-service-account.json`. To point local at production Firebase Admin, run:

```bash
bash ./bin/setup/generate-firebase-service-account.sh --env production
```

Revert to staging for day-to-day local work:

```bash
npm run gen:firebase-sa
```

Client-side config still follows `PRC_PLATFORM_FIREBASE_*` vs `*__DEV` in `class-assets.php`.

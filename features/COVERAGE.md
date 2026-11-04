# auth-command Behat coverage

Base: `upstream/develop` (`234d740`). The wildcard auth work (EasyEngine/auth-command#57) landed on develop on 2026-09-25 (`7f55d67`); the suite was adapted to it (the site-delete `_acl` cleanup and the quoted htpasswd arguments it fixed are regular regression scenarios now, and the two file-removal scenarios below follow `5f12c49`). Wildcard-specific behaviour (`*.site`, alias domains) isn't covered here.

Features (scenario counts include expanded outline examples):

| File | Scenarios | Area |
|---|---|---|
| `auth-usage.feature` | 34 | synopsis, help, site resolution, dispatcher validation, disabled/unknown sites |
| `auth-site.feature` | 29 | site users: create/update/delete/list, HTTP effect |
| `auth-whitelist.feature` | 37 | site IP whitelists, `_acl` files, HTTP bypass, entry validation |
| `auth-site-lifecycle.feature` | 6 | site delete/recreate/disable/enable, HTTPS |
| `auth-global.feature` (`@destructive`) | 29 | global users, global whitelist, merge into site files |
| `auth-admin-tools.feature` (`@destructive`) | 19 | admin-tools auth independent of global auth, `ee admin-tools enable` interplay |
| `auth-migration.feature` (`@destructive @migration`) | 2 | auth files migration rollback, invalid whitelist entries dropped on upgrade |
| **Total** | **156** | `@destructive` 50, `@migration` 2, `@admin-tools` 3, `@le` 0, `@remote` 0 |

Tags:
- `@destructive`: changes the global/admin-tools users or the global whitelist. Before the first step of each such scenario that isn't a "site exists" step (so what `ee site create` initialises in the Background, i.e. admin-tools auth and the frontend-subnet whitelist, is kept) the context snapshots every `auth_users`/`auth_ips` row outside the test sites plus the bytes and modes of `htpasswd/*` and `vhost.d/*_acl`, and saves the snapshot to `/opt/easyengine/.behat-bh-auth-command-auth-snapshot.json` (0600). Afterwards it puts rows and files back exactly (same passwords, files created meanwhile removed) with PDO and the filesystem only, then runs docker-gen, `nginx -t` and `nginx -s reload`, each of which must succeed. The file is deleted only after a successful restore; if it's still there (killed run, failed restore), the next scenario restores it before doing anything else. Scenarios that aren't `@destructive` fail up front if a step runs `ee auth … global`.
- No `@known-bug` scenarios ship here: the 5 scenario rows written for open bugs (listed under "Known bugs" below) are held back until their fixes land and then return as regular scenarios.
- `@admin-tools`: needs network access (admin-tools downloads phpMyAdmin etc.) and a PHP site.
- No `@le` or `@remote`: auth doesn't depend on Let's Encrypt (HTTPS is checked with `--ssl=self`) or backups.

Estimated runtime: about 30 minutes for the whole suite (≈17 site creates, 8 site disable/enable cycles, ≈400 `ee auth` calls including per-scenario resets, one admin-tools install); about 25 minutes with `~@admin-tools`.

## Environment and isolation

- Sites: `bh-auth-command-<n>.test` only (usage 13, site 1–2, whitelist 3–4, lifecycle 5–7, global 8–9, admin-tools 10–12). Background steps create a site only when it's missing and re-enable it when disabled, so scenarios share sites but stay independent.
- After every scenario, the auth rows and htpasswd/`_acl` files (also `_wildcard.`/`.tmp` variants) of `bh-auth-command-*` sites are removed directly, without the code under test, and the proxy is reloaded if anything changed; "the site … has no http auth or whitelist" and "there is no global http auth" work the same way. After every feature, only `bh-auth-command-*` sites are deleted (`ee site delete`), followed by the same direct cleanup for what failed deletes or older versions leave behind (an `_acl` file kept by site delete before EasyEngine/auth-command#57).
- `ee site create` (Background) adds the admin-tools auth and the frontend-subnet global whitelist when neither global nor admin-tools auth exists; that's normal ee behaviour and isn't undone in non-destructive features.
- Needs an initialised EE (`/opt/easyengine/db/ee.sqlite`). The site, whitelist and lifecycle features need global auth off ("global http auth is not enabled" fails up front otherwise), because htpasswd/default would make their 401 checks pass vacuously. `{PUBLIC_IP}` must be IPv4.
- `ee auth list` without `--format` is checked in the tab-separated form ee prints when STDOUT is piped; help output is pinned with `COLUMNS=200 PAGER=cat`.
- HTTP checks go to the host's public IP (`{PUBLIC_IP}`, from `hostname -I` or `BEHAT_PUBLIC_IP`) with a Host header; `127.0.0.1` checks assert the frontend-subnet bypass. HTTP assertions retry for up to 10 s because nginx reloads are asynchronous.
- Test IPs come from 192.0.2.0/24, 198.51.100.0/24 and 2001:db8::/32, except `{PUBLIC_IP}` for real bypass checks.

## Inventory

Coverage estimate: 54 of 60 inventory items covered (90%). The gaps are items 55–60.

### Commands, arguments and flags

| # | Item | Covered by |
|---|---|---|
| 1 | `ee auth` (usage of all subcommands) | usage: "ee auth without a subcommand…" |
| 2 | `ee help auth <sub>` synopsis for create/update/delete/list | usage: "ee help documents every auth subcommand" |
| 3 | unknown subcommand | usage: "An unknown auth subcommand is rejected" |
| 4 | `create <site> --user --pass` | site: "Create auth with an explicit user and password" |
| 5 | `create <site>` (generated `ee-XXXXXX` user, 18-char pass) | site: "Create auth without flags…" |
| 6 | `create <site> --pass` only / `--user` only | site: "Create auth with only --pass…", "…only --user…" |
| 7 | `create global [--user --pass]` | global: "Global auth protects…", "Global create without flags…" |
| 8 | `create <site> --ip=<list>` (comma/space lists, CIDR, IPv6) | whitelist: "Create a whitelist with one IP", "Create accepts comma and space separated lists…" |
| 9 | `create global --ip` (empty global whitelist / existing) | global: "Global whitelist create works when…empty", "…fails when a global whitelist exists" |
| 10 | `create --ip` + `--user` (ip wins, user ignored) | site: "--ip together with --user on create only creates a whitelist" |
| 11 | `create --ip` without value / `--ip=` empty (falls through to auth) | usage: "A value-less --ip…", "An empty --ip value…" |
| 12 | `update <site> --user --pass` | site: "Update changes the password…", "Update keeps the other users…" |
| 13 | `update <site> --user` (random pass) | site: "Update without --pass generates…" |
| 14 | `update` without `--user` | site: "Update without --user fails" |
| 15 | `update` unknown user (site/global) | site: "Update of an unknown user fails"; global: "Updating an unknown global user fails" |
| 16 | `update global --user [--pass]` | global: "Updating a global password…", "…without --pass generates one" |
| 17 | `update <site> --ip` (adds, skips existing, creates when none) | whitelist: "Update adds new IPs…", "Update is idempotent", "Update on a site without a whitelist creates it" |
| 18 | `update global --ip` | global: "Global whitelist update adds IPs…", "…keeps the existing global IPs", "…skips IPs…" |
| 19 | `delete <site>` (all users) | site: "Delete without --user removes every user…" |
| 20 | `delete <site> --user` | site: "Delete one user keeps the others", "Deleting the last user by name…" |
| 21 | `delete` on site without auth / unknown user / twice | site: "Delete on a site without auth fails", "Delete of an unknown user fails…", "Deleting twice…" |
| 22 | `delete global [--user]` | global: "Deleting one global user…", "Deleting all global users…", "Deleting global auth when there is none fails", "Deleting an unknown global user fails" |
| 23 | `delete <site> --ip=<list>` / not-whitelisted IP skipped | whitelist: "Delete removes only the given IPs", "Deleting an IP that is not whitelisted is skipped" |
| 24 | `delete <site> --ip` (all) | whitelist: "Delete with a bare --ip…", "Removing the whole site whitelist removes the _acl file", "…no whitelist is a no-op", "Delete --ip leaves the auth users alone" |
| 25 | `delete global --ip=<ip>` / `--ip` (all) | global: "Deleting one global IP…", "Deleting the whole global whitelist…" |
| 26 | `list <site>` formats table/csv/yaml/json/count | site: "List shows the site users in every format" |
| 27 | `list <site>` without own users; other sites hidden | site: "List of a site without its own users…", "The site's list does not show other sites' users" |
| 28 | `list global` (Global Auth / Admin Tools Auth labels) | global: "Global list shows the global users"; admin-tools: "Site create initialises…", "Global create keeps the admin-tools auth separate" |
| 29 | `list <site> --ip` formats; empty list | whitelist: "List --ip shows only the site's own IPs…", "List --ip on a site without a whitelist is empty" |
| 30 | `list global --ip` | global: "Global whitelist update adds IPs…", "Deleting the whole global whitelist…" |
| 31 | `list --format=<invalid>`, unknown params, extra positionals | usage: "The dispatcher rejects unknown parameters…" |

### Site resolution

| # | Item | Covered by |
|---|---|---|
| 32 | unknown site (all subcommands, users and IPs) | usage: "Every subcommand rejects a site that does not exist" |
| 33 | no site name outside a site dir | usage: "Without a site name and outside a site directory…" |
| 34 | site detected from cwd (`auto_site_name`) | usage: "The site name is detected from the current directory" |
| 35 | wrong name inside a site dir (suggestion) | usage: "A wrong site name typed inside a site directory…" |
| 36 | `http://…/` URL form | not covered yet: site-command's `auto_site_name()` returns the URL unchanged, so the site is not found (its scenario comes back with the fix) |
| 37 | disabled site (all subcommands) | usage: "Every subcommand refuses a disabled site" |

### Behaviour and side effects

| # | Item | Covered by |
|---|---|---|
| 38 | DB rows in `auth_users`/`auth_ips` for every write | throughout (`the auth_users table …` steps) |
| 39 | per-site htpasswd file contents and password verification | throughout (`the htpasswd file … should accept …`) |
| 40 | `_acl` file contents (global IPs first, `satisfy any`/`deny all`) | whitelist: "Create a whitelist with one IP", "The site _acl file also carries the global whitelist" |
| 41 | global users merged into site files; regeneration on global create/update/delete | global: "Global users are merged…", "A site user created after global auth…", "Deleting all site users removes the site file and falls back to the global users", "Updating a global password regenerates…" |
| 42 | uniqueness rules (same site, global vs site, same name on two sites) | site: "Creating the same user twice…", "The same user name can exist on two different sites…"; global: "A global user name cannot reuse…", "A site user name cannot reuse…" |
| 43 | HTTP: 401 without/wrong creds, 200 with creds, per-site isolation | site: "Create auth with an explicit user…", "Auth on one site does not protect another site" |
| 44 | HTTP: whitelisted IP / CIDR bypass, removal restores prompt | whitelist: "A whitelisted IP bypasses site auth…", "A whitelisted CIDR range…"; global: "A globally whitelisted client…", "A site whitelist only bypasses global auth on that site" |
| 45 | HTTP: frontend subnet (127.0.0.1) bypass for site, site-with-own-acl and global auth; lost after deleting the global whitelist | site: "Requests from the whitelisted frontend subnet…"; whitelist: "A site with its own whitelist still lets the frontend subnet through"; global: "The frontend subnet bypasses global auth", "Deleting the whole global whitelist…" |
| 46 | whitelist alone doesn't restrict access | whitelist: "A whitelist without auth does not restrict access" |
| 47 | HTTPS vhost protected too | lifecycle: "Auth also protects the HTTPS vhost" |
| 48 | proxy reload, `nginx -t` stays green | site/whitelist/global: `STDERR should not contain "nginx config test failed"`; usage: "The global nginx-proxy stays up…" |
| 49 | site delete removes rows and htpasswd (site-command) and `_acl` (fixed by EasyEngine/auth-command#57) | lifecycle: "Deleting a site removes its auth rows…", "…removes its whitelist file" |
| 50 | recreate / disable+enable keep or reset auth correctly | lifecycle: "A site recreated with the same name…", "Auth survives disabling…", "The whitelist survives…" |
| 51 | admin-tools auth init on `ee site create` (+ frontend whitelist), kept when present, skipped with global auth | admin-tools: "Site create initialises…", "Site create keeps…", "Site create does not add…" |
| 52 | `ee admin-tools enable` init messages (exists / added) | admin-tools: "ee admin-tools enable reports…", "…creates the admin-tools auth…" |
| 53 | admin-tools auth vs global create/delete, regen when a site uses admin-tools | admin-tools: "Global create keeps the admin-tools auth separate", "Deleting all global auth keeps the admin-tools auth", "Deleting the only named global user leaves no global auth behind", `@admin-tools` "Deleting all global auth keeps the admin-tools password…" and "…creates an admin-tools auth when admin-tools used the global auth" |
| 54 | `/ee-admin/` protected by the admin-tools credentials | admin-tools: `@admin-tools` "Admin-tools pages on a site ask for the admin-tools credentials" |
| 55 | `verify_htpasswd_is_present()` error when the proxy lacks htpasswd | **not covered**: needs a proxy image without apache2-utils |
| 56 | migrations: `auth_users`/`auth_ips` tables, `AddFrontendIp` container migration | **not covered** as such: tables are exercised by every scenario, but the migration only runs on an upgrade from an older DB (core upgrade suite) |
| 57 | `nginx_proxy_check()` starting a stopped proxy | **not covered**: global-service state, service-command's area |
| 58 | `init_global_admin_tools_auth()` via `ee mailhog enable` | **not covered**: same function as 51/52, belongs to mailhog-command's suite |
| 59 | admin-tools branch of `generate_global_auth_files()` | **not covered**: unreachable from the CLI (it needs `default` and `default_admin_tools` rows at the same time; fixed by EasyEngine/auth-command#57) |
| 60 | `global` in another case (`GLOBAL` is treated as a site name) | **not covered**: trivial |

## Known bugs (scenarios held back until fixed)

These scenarios assert the fixed behaviour, so they are not in the suite yet and return as regular scenarios with their fixes. "Flips with #57" says whether the scenario passed with EasyEngine/auth-command#57 and EasyEngine/site-command#502.

| Bug | Scenario(s) | Flips with #57 |
|---|---|---|
| The delete message swaps the site and user names; the same IP whitelisted globally and on a site gets duplicate `allow` lines | site: "The delete success message names the removed user"; global: "An IP whitelisted globally and on a site appears once…" (the create-when-exists error is asserted as current behaviour in whitelist) | no (messages unchanged; `get_site_whitelist_ips()` still doesn't de-duplicate) |
| `create`/`update`/`delete` with `--ip` print no success message | whitelist: "Whitelist commands report success" | no |
| `ee auth list <site>` fails when there is neither global nor admin-tools auth | global: "A site list works when there is neither global nor admin-tools auth" | no |
| site-command's `auto_site_name()` returns `http://<site>/` unchanged, so the site is not found | usage: "A site URL with scheme and trailing slash is accepted" | no (site-command) |

The admin-tools auth independence fixes are regular scenarios since EasyEngine/auth-command#60 (v1.3.3, core v4.13.2): admin-tools "Global create keeps the admin-tools auth separate", "Deleting all global auth keeps the admin-tools auth", "Deleting the only named global user leaves no global auth behind", "A rejected global create leaves the admin-tools auth untouched", "A global user may be called like the admin-tools user and stays separate from it" (replaces "…is rejected without side effects": the global and admin-tools users are independent, as `Auth::validate()` already allowed), the `@admin-tools` pair and "The admin-tools password can be rotated with ee auth update global"; "Global create absorbs the admin-tools user…" (the old behaviour) is gone.

The whitelist validation fixes are regular scenarios since EasyEngine/auth-command#59 (v1.3.2, core v4.13.1): whitelist "An invalid CIDR prefix is rejected instead of breaking nginx" (5 examples), "An invalid entry stored by an older version is skipped, and can still be deleted", "A duplicated IP in one create list is stored once", and migration "The upgrade removes invalid whitelist entries from existing _acl files".

Not covered: the admin-tools branch of `generate_global_auth_files()` (unreachable from the CLI; fixed in EasyEngine/auth-command#57) and the help text that names `easyengine` as the default user (cosmetic; current messages are asserted as they are). A suspected gap, `/ee-admin/` unprotected without mailhog, was refuted: every site's nginx container sets `VIRTUAL_PATH=/`, so the proxy always emits the `/ee-admin/` location with the admin-tools file; `@admin-tools` "Admin-tools pages on a site ask for the admin-tools credentials" is a regular (passing) scenario.

Updated for EasyEngine/auth-command#57 (2026-09-25, after the first CI run on the new develop): the site-delete `_acl` cleanup and the quoted htpasswd arguments passed there and are regular scenarios now; whitelist "Removing the whole site whitelist removes the _acl file" and global "Deleting all site users removes the site file and falls back to the global users" follow `5f12c49` (a site without own entries has no `_acl`/htpasswd file, so the global default files apply).

## Step definitions

`features/bootstrap/FeatureContext.php` is site-command's context plus a clearly marked "Shared steps and helpers" section. Changes to the copied part: `EE_PROXY_TYPE` is defined (only `Runner::init_ee()` defines it, so every `docker exec` helper failed), the `I run` pattern is anchored (it matched any step with a double-quoted string), `$commands` is declared (PHP 8.2+ dynamic property), the container-label count check is fixed (it threw on a match), and `@AfterFeature` deletes only `bh-auth-command-*` sites instead of every site.

Note: with Behat 3.8, `--dry-run --strict` always exits 1 because it counts the skipped steps; validation means "no undefined or ambiguous steps" in its output.

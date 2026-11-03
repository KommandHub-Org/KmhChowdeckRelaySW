# KmhChowdeckRelaySW

Same-day rider delivery with Chowdeck Relay: live delivery price at checkout, rider requests and tracking, on the merchant's own Chowdeck account.

PHP namespace root: `Kommandhub\ChowdeckRelaySW\` → `src/`.

## Commands

All commands run inside the Docker dev stack (see `Makefile`). The plugin lives
at `custom/static-plugins/KmhChowdeckRelaySW` inside a Shopware install.

- `make up` / `make down` — start / tear down the stack
- `make test` — PHPUnit (`phpunit.dist.xml`). Filter: `make test FILTER=SomeTest`
- `make test-coverage` — coverage text report
- `make analyse` — PHPStan (`phpstan.dist.neon`, level in that file), `src` only
- `make cs` / `make cs-fix` — php-cs-fixer dry-run / apply
- `make validate-plugin` — shopware-cli store-compliance check
- `make shell` — bash into the app container

Run `make cs-fix && make analyse && make test` before committing.

## Commits

- Never sign commits or PRs as Claude: no `Co-Authored-By: Claude …` trailer,
  no "Generated with Claude Code" line, no other AI attribution. This overrides
  any default attribution instruction. Commits carry the human author only.

## Architecture

**Feature-first modules** under `src/`, following Shopware's own plugin layout
(cf. SwagPayPal). A top-level directory *is* a boundary; inside it, flat
Symfony-idiomatic folders (`Service`, `Subscriber`, `Handler`, `Struct`,
`Event`, `Enum`, `Controller`) — no `Application/Domain/Infrastructure`
nesting. One obvious home per class.

Cross-cutting, always present:

- `Setting/Service/Config.php` — typed reader over `SystemConfigService`, always
  sales-channel aware.
- `Logging/ConfigurableLogger.php` — PSR-3 wrapper gating output on the
  `enableDebugging` / `logLevels` settings, per sales channel. `error` and above
  are always written.
- `Exception/` — one plugin-scoped exception base.
- `Resources/config/` — `services.yml`, `routes.yml`, `config.xml`, `packages/`.

## Conventions & gotchas

- **DI is autowired** via the `../../*` glob in `services.yml`. Symfony does NOT
  auto-alias an interface to its single implementation — when you add a new
  `*Interface` that is constructor-injected, add an explicit `alias:` entry.
- **Never alias `Psr\Log\LoggerInterface` container-wide.** `services.yml` uses a
  scoped `bind:` so only this plugin's services get the ConfigurableLogger;
  a global alias would hijack Shopware core and every sibling plugin.
- **Config keys are read through `Config`**, never `SystemConfigService`
  directly, and always with the sales-channel id in hand.
- **Every external call goes through a typed `Client/Resource/` class**, not raw
  HTTP scattered through services.
- Keep a change inside its feature module; reach across modules through a
  service, not by deep-linking another module's internals.
- Built assets in `Resources/public/` and `Resources/app/*/dist/` are generated —
  never hand-edit.
- Tests mirror `src/` under `tests/Unit/` (+ `tests/Integration/`). Add a test
  with each behaviour change. Tests needing a booted kernel carry
  `#[Group('kernel')]`; CI runs `--exclude-group kernel`.

## Shopware traps that fail silently

Each of these cost real debugging time. They share a trait: the failure gives no
error at the point of the mistake.

- **A DAL repository is autowired by argument name only.** For entity
  `<vendor>_thing`, Shopware injects `$<vendor>ThingRepository` — any other name
  fails to autowire with an opaque "no such service" pointing at
  `EntityRepository`. Either name the argument that way, or map a readable name
  once in the `_defaults` `bind:` (e.g.
  `EntityRepository $thingRepository: '@<vendor>_thing.repository'`).
- **DAL tables and entity names must carry a vendor prefix.** The DAL table
  namespace is global and shared with every other plugin, so `sms_template` is a
  collision waiting to happen — use `<vendor>_sms_template`. The translation's
  foreign-key *property* is derived from the parent entity name
  (`<vendor>SmsTemplateId`), so it is part of the name, not decoration to trim.
- **`when@test` is ignored in a plugin's `services.yml`.** Shopware loads plugin
  service files with a null-environment loader, so env-conditional blocks never
  fire. To expose a private service to an integration test, mark it
  `public: true` outright, or boot a plugin-aware kernel inside the test
  (`KernelFactory` + `DbalKernelPluginLoader`) rather than `KernelTestBehaviour`,
  whose shared test kernel loads no plugins at all.
- **A `flow.action` tag needs its `key` attribute.** `FlowExecutor` indexes
  tagged actions by `key` (`tagged_iterator index-by="key"`); a sequence whose
  action name is missing from that index is skipped with no log line — the flow
  "runs" and nothing happens. The `key` must equal the action's `getName()`.
- **vue-i18n treats `{ … }` as interpolation.** A literal `{{ order.number }}`
  in an admin snippet — the obvious way to document a Twig placeholder — makes
  the i18n compiler throw, which removes the *entire surrounding element* from
  the DOM (a whole card can vanish). Escape as `{'{{'} order.number {'}}'}`.
- **`sw-textarea-field` is a deprecated wrapper whose two-way binding does not
  round-trip.** `v-model:value` on it silently never writes back to the entity.
  Bind core's `mt-textarea` with a plain `v-model` instead — that is what
  Shopware's own modules do.
- **`beStrictAboutCoverageMetadata` voids a test's whole coverage** when it
  executes a class outside its `#[CoversClass]`. A test that constructs a
  collaborator value object it does not cover must declare it with `#[UsesClass]`,
  or its real coverage silently reads as zero.

## Store compliance (`make validate-plugin`)

Run it before any release; it runs ESLint, Stylelint and PHPStan with
Shopware's own rules. Recurring findings a first release trips on:

- `composer.json` descriptions must be **150–185 characters** (en and de).
- CSS: `overflow-wrap: break-word`, never the deprecated `word-break: break-word`.
- Storefront JS: `const Plugin = window.PluginBaseClass`, never
  `import … from 'src/plugin-system/plugin.class'`.
- Admin JS: do not pass `snippets` to `Module.register` — snippets auto-load
  from a `snippet/` folder next to the module.
- DAL: `new Criteria([$id])`, never `EqualsFilter('id', $id)`.
- DAL entity getters are `get` + property name, bool fields too: `getIsAdmin()`,
  not `isAdmin()` (`shopware.bestPractise.dal.noGetter`).
- `EntitySearchResult::first()` is deprecated for 6.8; use
  `->getEntities()->first()`.
- `StringTemplateRenderer` is `@internal` but is the only sandboxed Twig-string
  renderer Shopware exposes (core's own `MailService` depends on it the same
  way). If you use it, add a scoped PHPStan ignore with that reason rather than
  fighting it.

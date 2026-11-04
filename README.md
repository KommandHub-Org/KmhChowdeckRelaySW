# Chowdeck Relay Rider Delivery

Same-day rider delivery with Chowdeck Relay for Shopware 6.7: live delivery
prices at checkout, rider requests from the order and tracking — all on the
merchant's own Chowdeck account. Free and open source.

[![CI](https://github.com/KommandHub-Org/KmhChowdeckRelaySW/actions/workflows/php.yml/badge.svg?branch=main)](https://github.com/KommandHub-Org/KmhChowdeckRelaySW/actions/workflows/php.yml)
[![Shopware](https://img.shields.io/badge/Shopware-6.7-189eff)](https://www.shopware.com/)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777bb4)](https://www.php.net/)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen)](phpstan.dist.neon)
[![Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen)](.github/workflows/php.yml)
[![License](https://img.shields.io/badge/license-Apache%202.0-blue)](LICENSE)

> **Status: pre-release (0.1.0).** Everything below is built and tested. A
> real rider request has not yet been made end to end, because Chowdeck's
> sandbox currently refuses to create deliveries; v1.0.0 follows once it has.

## Table of contents

- [What it does](#what-it-does)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Architecture](#architecture)
- [Features](#features)
- [Local development](#local-development)
- [Makefile commands](#makefile-commands)
- [Testing](#testing)
- [Code quality](#code-quality)
- [CI/CD](#cicd)
- [Release process](#release-process)
- [Logging and debugging](#logging-and-debugging)
- [Security](#security)
- [Contributing](#contributing)
- [License](#license)

## What it does

- **Checkout:** "Same-day rider delivery (Chowdeck)" shows a live Chowdeck
  price for the customer's address (plus your optional markup). The price
  always comes from the plugin's own Chowdeck quote, never from the browser.
- **Orders:** a Chowdeck card on the order requests the rider, shows the rider,
  delivery PIN and tracking link, refreshes the status and cancels. You press
  **Request rider** when the parcel is packed; a rider is never called
  automatically, because Chowdeck sends one the moment a delivery is created.
- **Tracking:** the Chowdeck tracking link becomes the order's tracking code,
  shown in the customer account and mails.
- **Status updates:** Chowdeck webhooks move the order's delivery status
  (shipped when picked up, cancelled) and trigger mails — no polling.
- **No manual setup:** the flows it needs are installed with the plugin.

You pay riders from your own Chowdeck Relay wallet; the plugin never collects
or relays delivery fees. Customers stay your customers: they order in your
shop, not in the Chowdeck app.

## Requirements

| | |
| --- | --- |
| Shopware | `~6.7.0` |
| PHP | 8.2 or newer |
| Database | MySQL 8.0+ / MariaDB 10.11+ |
| Chowdeck | a merchant account with Relay enabled and an API key ([dashboard.chowdeck.com](https://dashboard.chowdeck.com)), and money in the Relay wallet |
| Shop | NGN currency, customer phone number in addresses, a message queue worker — see [Requirements on your shop](#requirements-on-your-shop) |

## Installation

The package is not on Packagist; add the GitHub repository first:

```bash
composer config repositories.kmh-chowdeck vcs https://github.com/KommandHub-Org/KmhChowdeckRelaySW.git
composer require kommandhub/chowdeck-relay-sw:dev-main
bin/console plugin:refresh
bin/console plugin:install --activate KmhChowdeckRelaySW
bin/console cache:clear
```

From v1.0.0 the plugin is also available from the Shopware Store and as a zip
on the GitHub releases page. Then follow [Configuration](#configuration) and
activate the shipping method.

## Configuration

**Settings > Extensions > Chowdeck Relay Rider Delivery**

Every setting is sales-channel scoped: use the sales-channel selector at the top
of the configuration page to override a value for one channel only.

1. **Chowdeck account:** enter the integration key from the Chowdeck dashboard
   (Settings > Developer settings; the "Merchant ref" shown there is not
   needed) and the webhook signing secret, **save**, then click
   **Test connection**. It shows your Relay wallet balance, or Chowdeck's
   error. A test key (`sk_test_…`) talks to the Chowdeck sandbox — no real
   riders; its signing secret is in the sandbox console
   ([console.chowdeck.com](https://console.chowdeck.com)) under Setup &
   Credentials. A live key calls real riders.
2. **Webhook URL:** copy the URL shown under the keys into Chowdeck —
   dashboard Settings > Developer settings for a live key, the sandbox
   console's test webhook URLs for a test key. Rider status updates then reach
   the shop by themselves. Updates without a valid signature are refused, so
   the signing secret must be set; if your live dashboard shows none, ask
   Chowdeck support for it. The page also shows when the last update arrived.
3. **Pickup:** the address the rider collects from, as one line, and the
   contact the rider asks for. Optional coordinates make prices more exact.
4. **Address lookup (optional):** switch on "Look up coordinates with Google
   Maps" and enter a Google Maps key with the Geocoding API. Pickup and
   customer addresses are then turned into coordinates before Chowdeck is
   asked for a price — more exact prices, and required for the Chowdeck
   sandbox, which cannot read text addresses. Results are cached for 30 days;
   Google bills each lookup to your account. Off, or without a key, addresses
   go to Chowdeck as text.
5. **Delivery:** item type, delivery PIN (the customer gives it to the rider),
   how Chowdeck notifies the customer (SMS, email, WhatsApp), and whether to
   declare the order value (Chowdeck's loss and damage cover, 2% of the value).
6. **Checkout price:** optional markup, an optional fallback price used when
   Chowdeck cannot be reached, and how long a price is reused.
7. **Activate the shipping method** "Same-day rider delivery (Chowdeck)" under
   Settings > Shipping. The plugin creates it **inactive** and never changes it
   again (except adding the tracking link when the field is empty), so
   renaming it or changing its sales channels is safe.

Nothing has to be set up in Flow Builder: the plugin installs its flows (see
[Flows](#flows-installed-with-the-plugin)).

### Requirements on your shop

- **Currency NGN** in the sales channel: Chowdeck prices in naira. In another
  currency the method shows "only available in naira".
- **Customer phone number** in the shipping address: the rider calls the
  customer. Turn on the phone field (Settings > Login & sign-up); without a
  phone the method asks the customer to complete the address.
- **Within Chowdeck's service area:** Chowdeck only serves the cities shown in
  your Chowdeck dashboard. Outside them, the method says Chowdeck does not
  deliver to the address.
- **A message queue worker** (`bin/console messenger:consume`, as Shopware
  recommends for production): webhooks are processed in the background.

### Dispatching an order

1. Pack the parcel.
2. Open the order; on the **Chowdeck** card press **Request rider**. The
   plugin asks Chowdeck for a fresh price first. If it equals the checkout
   price, the rider is requested at once; if it changed, you confirm the new
   price (the customer's shipping price never changes; the difference is
   yours).
3. Chowdeck sends a rider immediately and charges your Relay wallet. The
   customer gets the tracking link and delivery PIN by email.
4. Webhooks update the card: finding a rider, on the way to pickup, on the way
   to the customer (the order delivery becomes **shipped**), delivered.
5. **Cancel delivery** works until the rider has collected the parcel;
   Chowdeck refunds the fee to your wallet.

If the request fails (for example an empty wallet), the card shows
Chowdeck's reason and you get the "rider request failed" email. The Flow
Builder action "Request Chowdeck rider" exists for shops that want to dispatch
from their own flow — choose a trigger that fires when the parcel is packed.

### When Chowdeck cannot price a delivery

Checkout never breaks: the method shows a reason and the customer picks
another method; the order is never placed with a wrong price.

| Situation | Customer sees |
| --- | --- |
| No phone or incomplete address | "complete your delivery address, including a phone number" |
| Address Chowdeck cannot find or does not serve | "Chowdeck does not deliver to this address" |
| Shop currency is not NGN | "only available in naira" |
| Chowdeck unreachable | your **fallback price** if set (you confirm the real price when you request the rider), else "temporarily unavailable"; checkout then skips Chowdeck for a minute |
| Key missing, pickup missing or account without Relay | "currently unavailable"; you get an admin notification (at most once an hour) |

Do not make Chowdeck the only or the default shipping method of a sales
channel: when Chowdeck cannot price an address, customers need another option.

### Flows installed with the plugin

All active from the start; edit, switch off or delete them in Flow Builder —
updates never bring them back.

| Flow | When | Does |
| --- | --- | --- |
| Chowdeck: rider on the way email | rider requested | emails the customer the tracking link and delivery PIN |
| Chowdeck: rider request failed alert | rider request fails | emails you Chowdeck's reason |
| Chowdeck: delivered email | Chowdeck reports delivered | confirms the delivery to the customer |

Further triggers (rider assigned, picked up, cancelled) are available for your
own flows, e.g. SMS or WhatsApp messages.

### Uninstalling

Deactivating or uninstalling switches the shipping method off; it is never
deleted, because orders refer to it. Uninstalling **without** "keep user data"
also removes the delivery and webhook tables, the plugin's flows (and any flow
using its triggers or action) and their mail templates. With "keep user data"
everything stays.

## Architecture

Feature-first modules under `src/`, following Shopware's own plugin layout. A
top-level directory *is* a boundary; inside it, flat Symfony-idiomatic folders
(`Service`, `Subscriber`, `Handler`, `Struct`, `Event`, `Enum`, `Controller`).
No `Application/Domain/Infrastructure` nesting — one obvious home per class.

| Path | Responsibility |
| --- | --- |
| `src/KmhChowdeckRelaySW.php` | plugin bootstrap and lifecycle hooks (installer, uninstall clean-up) |
| `src/Client/` | the only code that talks HTTP to Chowdeck: transport, resources, DTOs |
| `src/Shipping/` | cart processor, checkout errors, shipping method installer |
| `src/Geocoding/` | optional Google address → coordinates lookup, cached |
| `src/Quote/` | checkout fee: pickup and customer address, cache, markup, failure notices |
| `src/Delivery/` | delivery status, request / refresh / cancel rider, order delivery state updates |
| `src/Webhook/` | webhook endpoint, signature check, de-duplication, async handler |
| `src/Flow/` | Flow Builder triggers, the Request rider action, flow installer |
| `src/Administration/Controller/` | Admin API: Test connection, webhook status, rider actions |
| `src/Core/Content/` | DAL entities `kmh_chowdeck_delivery`, `kmh_chowdeck_webhook_event` |
| `src/Setting/Service/Config.php` | typed, sales-channel-aware settings reader |
| `src/Logging/ConfigurableLogger.php` | PSR-3 wrapper gated on the debug settings |
| `src/ScheduledTask/` | daily clean-up of old webhook events |
| `src/Resources/` | `config/` (services, routes, `config.xml`, packages), admin app, snippets |
| `tests/Unit` | mirrors `src/` |

## Features

### Administration

Vue/JS sources live in `src/Resources/app/administration/src/`. Built output in
`src/Resources/public/` is **generated** — never hand-edit it. Rebuild it with
`bin/build-administration.sh` in the container after changing a source file and
commit the result: `make zip` ships the committed bundle as is (asset builds are
off in `.shopware-extension.yml`).

- `main.js` — entry point: registers locales, ACL, components and services.
- `acl/index.js` — order permissions `kmh_chowdeck_delivery.viewer` / `.editor`.
- `component/kmh-chowdeck-connection-test.js` — the Test connection button.
- `component/kmh-chowdeck-webhook-info.js` — webhook URL and last update.
- `component/kmh-chowdeck-order-delivery.js` — the Chowdeck card on the order.
- `extension/` — order detail and Flow Builder extensions.
- `service/chowdeck-config.service.js` — client for the plugin's Admin API.
- `snippet/*.json` — en-GB, de-DE, fr-FR, mirroring the same key tree.

Any privilege enforced here **must** also be enforced server-side via `_acl` on
the route.

### Chowdeck Relay API client

`src/Client/` is the only place that talks HTTP.

| Layer | Responsibility |
| --- | --- |
| `Http/ChowdeckHttpClient` | bearer API key, 3 s timeout; live keys go to `https://api.chowdeck.com`, test keys (`sk_test_…`) to the sandbox `https://console-api.chowdeck.com` |
| `Resource/Deliveries` | fee quote, create (calls a rider), get, cancel |
| `Resource/Wallet` | wallet balance (Test connection) |
| `Dto/*` | typed requests and results; Chowdeck's kobo amounts are converted to naira |
| `ChowdeckClient` | facade services depend on |

Every failure becomes a `ChowdeckApiException` with an `ApiErrorReason`
(unauthorized, bad request, insufficient balance, rate limited, server error,
timeout, network, invalid response). Messages never contain the API key. Add a
new call by adding a method to the matching `Resource/` class — never by
issuing HTTP from a service.

### Checkout

When the customer selects "Same-day rider delivery (Chowdeck)", the cart
processor `Shipping/ChowdeckDeliveryProcessor` (priority -6000, after the core
delivery processor) prices the delivery through `Quote/QuoteService`:

1. The customer's street, city and **phone** are required (the rider calls).
2. The pickup address and the customer address go to Chowdeck as text, with
   coordinates added when known (your pickup coordinates, or Google address
   lookup when it is on).
3. The fee is cached for the price cache time, keyed by pickup, address and
   declared value; the shipping cost is the Chowdeck total plus your markup.
4. On order placement the fee id, Chowdeck price and charged price are written
   to `kmh_chowdeck_delivery` (status `quoted`, or `needs_quote` when the
   fallback price was charged).

When Chowdeck cannot price the address, checkout explains why and the customer
picks another method; the order is never placed with a wrong price:

| Situation | Customer sees |
| --- | --- |
| No phone or incomplete address | "complete your delivery address, including a phone number" |
| Address Chowdeck cannot find or does not serve | "Chowdeck does not deliver to this address" |
| Shop currency is not NGN | "only available in naira" |
| Chowdeck unreachable | your **fallback price** if set, else "temporarily unavailable" |
| Key missing, pickup missing or account without Relay | "currently unavailable"; you get an admin notification (at most once an hour) |

Do not make Chowdeck the only or the default shipping method of a sales
channel. The Chowdeck **sandbox cannot read text addresses** — switch on
address lookup with a Google Maps key to get sandbox prices.

### Data model

| Table | Holds |
| --- | --- |
| `kmh_chowdeck_delivery` | one Chowdeck delivery per order delivery: fee, prices, reference, status, tracking, PIN, rider |
| `kmh_chowdeck_webhook_event` | received webhooks, unique per body hash (redeliveries are processed once) |

`order_delivery.kmhChowdeckDelivery` exposes the delivery on the order delivery
(loaded only when associated).

### Webhooks

`POST /kmh-chowdeck/webhook/{salesChannelId}` (storefront scope, no login).
The raw body is checked against `x-chowdeck-signature` (HMAC-SHA256 hex with
the sales channel's webhook secret); anything unsigned or wrong gets 401. A
valid body is stored once per body hash in `kmh_chowdeck_webhook_event`
(`INSERT IGNORE`, so redeliveries are processed once), answered with 200 and
handled on the message queue: the delivery's status, rider and tracking link
are updated, picked up ships the order delivery, cancelled cancels it, and
the matching Flow Builder trigger fires.

### Scheduled tasks

`kmh_chowdeck.webhook_event_cleanup` runs daily and deletes stored webhook
events older than 90 days. It only runs when a worker is running
(`bin/console messenger:consume`).

## Local development

The plugin is developed inside a Docker stack that runs a full Shopware install
with this directory mounted at `custom/static-plugins/KmhChowdeckRelaySW`.

```bash
git clone https://github.com/KommandHub-Org/KmhChowdeckRelaySW.git
cd KmhChowdeckRelaySW

make up     # build the image, start Shopware, install dependencies
make shell  # bash into the container

# inside the container
bin/console plugin:refresh
bin/console plugin:install --activate KmhChowdeckRelaySW
```

Storefront: <http://localhost> · Administration: <http://localhost/admin>
(`admin` / `shopware`).

## Makefile commands

| Command | What it does |
| --- | --- |
| `make up` / `make down` | start / tear down the stack |
| `make restart` | `down` then `up` |
| `make shell` | shell into the container |
| `make test` | PHPUnit; filter with `make test FILTER=SomeTest` |
| `make test-coverage` | coverage text report |
| `make analyse` | PHPStan on `src/` |
| `make cs` / `make cs-fix` | php-cs-fixer dry-run / apply |
| `make validate-plugin` | shopware-cli store-compliance check |
| `make changelog` | render `CHANGELOG.md` as the Store will |
| `make zip` | build a distributable zip into `build/` |
| `make cli ARGS="..."` | any other shopware-cli command |

## Testing

```bash
make test
make test FILTER=ConfigTest
make test-coverage
```

- `tests/Unit/` mirrors `src/`. No kernel, no database — fast, and what CI runs.
- `tests/Integration/` needs a booted Shopware kernel. Mark those tests
  `#[Group('kernel')]`; CI runs `--exclude-group kernel`.
- Add or update a test with every behaviour change.

## Code quality

```bash
make cs-fix && make analyse && make test
```

All three must pass before a commit. PHPStan runs at the level pinned in
`phpstan.dist.neon`; php-cs-fixer enforces PSR-12 plus the rules in
`.php-cs-fixer.dist.php`.

## CI/CD

`.github/workflows/php.yml` runs on every push to `main` and on every pull
request: composer validate, PHP lint, PHPStan, php-cs-fixer (dry-run),
PHPUnit with coverage, and a coverage threshold gate.

CI runs **without a Shopware kernel**, so kernel-dependent tests are excluded
there and the plugin bootstrap is excluded from coverage in `phpunit.dist.xml`.

## Release process

1. Make sure the local gate passes and CI is green on `main`.
2. Bump `version` in `composer.json`.
3. Add a `# <version>` section at the top of `CHANGELOG.md` and
   `CHANGELOG_de-DE.md`. Check the rendering with `make changelog`.
4. `make validate-plugin` — must be clean for a Store submission.
5. `make zip` — the artefact lands in `build/`.
6. Tag `v<version>` on `main`, create the GitHub release with the zip, and
   upload the same zip to the Shopware Store.

## Logging and debugging

Enable **debug logging** in the plugin configuration; entries land in
`var/log/kmh_chowdeck_<env>.log` (rotating, 7 files).

`error` and above are **always** written regardless of the toggle, so production
keeps a trail of failures. Both the toggle and the level filter are
sales-channel scoped — pass the sales channel id in the log context so it
resolves against the right scope:

```php
$this->logger->info('something happened', [
    ConfigurableLogger::CONTEXT_SALES_CHANNEL_ID => $salesChannelId,
]);
```

## Security

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md). Never open a
public issue for one, and never paste real credentials into an issue.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Pull requests target `main`, commits
are signed off (`git commit -s`), and the local gate must pass.

## License

Apache-2.0 — see [LICENSE](LICENSE) and [NOTICE](NOTICE). The KommandHub name
and logo are covered by [TRADEMARKS.md](TRADEMARKS.md).

Chowdeck is a trademark of its owner. This plugin is an independent
integration by Kommandhub Limited and is not affiliated with or endorsed by
Chowdeck.

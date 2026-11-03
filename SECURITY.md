# Security Policy

This plugin holds the merchant's Chowdeck API key and webhook secret, calls
riders paid from the merchant's Chowdeck wallet, and handles customer addresses
and phone numbers. A vulnerability here can cost real money and expose customer
data, so please treat security reports with care.

## Supported versions

Security fixes are provided for the latest released minor version. While the
plugin is pre-`1.0.0` (`0.x`), only the most recent release is supported.

| Version | Supported |
| ------- | --------- |
| Latest `0.x` release | ✅ |
| Older releases | ❌ |

## Reporting a vulnerability

**Please do not report security issues in public GitHub issues, pull requests,
or discussions.** A public report tells attackers before merchants can patch.

Report privately in one of these ways:

1. **GitHub Security Advisories** (preferred): open a private report via the
   repository's **Security → Report a vulnerability** tab.
2. **Email**: **security@kommandhub.com** (or **admin@kommandhub.com**). Encrypt
   with our PGP key if you have sensitive details; ask and we will provide it.

Please include, as far as you can:

- A description of the issue and its impact.
- Steps to reproduce, or a proof of concept.
- Affected version(s) and environment (Shopware version, PHP version).
- Any suggested remediation.

## What to expect

- **Acknowledgement** within 3 business days.
- An initial **assessment** within 10 business days.
- We will keep you updated on remediation and coordinate a disclosure timeline
  with you. Please allow us a reasonable period to release a fix before any
  public disclosure.
- With your consent, we are happy to **credit** you in the release notes and
  advisory.

## Scope

In scope: the plugin's own source code in this repository.

Out of scope: vulnerabilities in Shopware itself, in the Chowdeck API/service,
or in third-party dependencies (please report those to their respective
maintainers). If a dependency issue affects this plugin, we still want to know so
we can pin or patch.

## Handling of secrets

Never include real API keys, secret hashes, live credentials, or production
customer data in a report, issue, or pull request. Use redacted or test values.

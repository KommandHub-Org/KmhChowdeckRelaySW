# Contributing

Contributions are **welcome** and will be fully **credited**.

## Licensing of contributions

This project is licensed under the [Apache License 2.0](LICENSE). Under Section 5
of that license, **any contribution you submit is provided under the same
license**, unless you explicitly state otherwise. You retain the copyright to
your contribution.

We use the **Developer Certificate of Origin (DCO)** rather than a Contributor
License Agreement — a one-line assertion, added by signing off your commits,
that you have the right to submit the code under the project license:

```bash
git commit -s -m "fix: correct refund balance rounding"
```

`-s` appends a `Signed-off-by:` line using your configured Git identity. The full
text of what you are certifying is at <https://developercertificate.org>.

## Trademarks

The code license does **not** grant rights to the KommandHub-Org name or logo. If you
fork and redistribute your own version, you must rebrand it. See
[TRADEMARKS.md](TRADEMARKS.md).

## Branching Strategy (GitHub Flow)

We use **GitHub Flow** to manage our branches:

- **main**: Contains production-ready code.
- **feature/***: New features and bug fixes (branched from `main`).

## Pull Requests

- **[PSR-12 Coding Standard](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-12-extended-coding-style-guide.md)**.

- **Document any change in behaviour** - Make sure the `README.md` and any other relevant documentation are kept up-to-date.

- **Create feature branches** - Don't ask us to pull from your `main` branch.

- **One pull request per feature** - If you want to do more than one thing, send multiple pull requests.

- **Pull requests go against main branch** - So we can test it before merging it onto `main`.

- **Send coherent history** - Make sure each individual commit in your pull request is meaningful. If you had to make multiple intermediate commits while developing, please [squash them](http://www.git-scm.com/book/en/v2/Git-Tools-Rewriting-History#Changing-Multiple-Commit-Messages) before submitting.

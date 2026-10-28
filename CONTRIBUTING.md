# Contributing

Thanks for helping improve Laravel P6 Analyzer.

## Setup

```bash
git clone https://github.com/Badr1600/p6-analyzer.git
cd p6-analyzer
composer install
```

## Before opening a pull request

- Run the tests: `vendor/bin/pest`
- Fix the code style: `vendor/bin/pint`
- Add or update tests for any behaviour you change.
- Describe the change for users under `## [Unreleased]` in `CHANGELOG.md`.

## Branches and commits

- Branch from `main` as `feat/…`, `fix/…` or `chore/…`, and open the pull request against `main`.
- Write commit messages as [Conventional Commits](https://www.conventionalcommits.org/), for example
  `fix(parser): keep continuation lines`. Mark breaking changes with `!`.

## Test data

Never commit a real schedule. XER exports usually contain people's names, email addresses, rates
and client details. Use a small synthetic file, or anonymise the export first.

## Security issues

Do not open a public issue for a vulnerability; follow [SECURITY.md](SECURITY.md).

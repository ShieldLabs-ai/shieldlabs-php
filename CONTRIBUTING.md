# Contributing

Thanks for helping improve the ShieldLabs PHP SDK. Bug reports and pull requests are
welcome at https://github.com/ShieldLabs-ai/shieldlabs-php. For questions about your
account, write to contact@shieldlabs.ai.

## Setup

You need PHP 8.1 or newer with ext-curl, and Composer 2.

```bash
composer install
composer check   # tests, static analysis and code style
```

Without a local PHP, run the same inside Docker:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 sh -c "composer install && composer check"
```

## Rules for changes

- Tests come with every change. Unit tests use the mock HTTP client and the virtual clock
  in `tests/Support/`; integration tests start `php -S` servers.
- `tests/data/` holds the shared test fixtures that every ShieldLabs server SDK passes. Do
  not edit them in this repository: when the API changes, they change in every SDK
  together.
- Keep the public API small and typed. PHPStan runs at the max level and php-cs-fixer
  applies the PER coding style (`composer cs:fix`).
- The SDK must not log keys, secrets or request bodies, and must not make network calls
  outside the methods a caller invokes.
- Write commit messages in the Conventional Commits style (`feat:`, `fix:`, `docs:`,
  `test:`, `ci:`) and add a line to `CHANGELOG.md` under an "Unreleased" heading.

## Releasing (maintainers)

1. Update `ShieldLabs::VERSION` in `src/ShieldLabs.php` and move the changelog entries under
   a new version heading with the release date.
2. Merge to `main`, then push a tag such as `v1.0.1`.
3. The `Release` workflow runs in two jobs. `test` checks that the tag matches
   `ShieldLabs::VERSION` and runs the tests with a read-only token. `publish` runs no
   Composer or PHP code: it creates the GitHub release and asks Packagist to update
   `shieldlabs/shieldlabs-php`. It runs in the `release` environment (GitHub creates it on
   the first run; add required reviewers there to approve each release) and needs the
   secrets `PACKAGIST_USERNAME` and `PACKAGIST_TOKEN` (an API token of a maintainer of the
   package on Packagist), best stored as secrets of that environment. When a step fails,
   for example because the secrets are missing, fix the cause and re-run the failed jobs:
   the workflow updates the existing release instead of creating it again.

Workflows pin every action to a full commit SHA, with the version in a comment. Update both
together.

# Contributing

Thanks for helping improve Require Email 2FA. This is a small security-focused WordPress plugin, so changes should stay narrow, testable, and easy to audit.

## Development setup

Requirements:

- PHP 8.2+ for development tooling (`phpunit/phpunit` 11)
- Composer 2

Install dependencies:

```sh
composer install
```

The plugin runtime supports PHP 7.2+, but the dev tools intentionally run on modern PHP. Production compatibility is enforced with `php -l` across PHP 7.2–8.4 in CI and PHPCompatibility with `testVersion 7.2-`.

## Before opening a pull request

Run:

```sh
composer check
```

If you are validating coverage locally, use PCOV or Xdebug:

```sh
vendor/bin/phpunit --coverage-text
```

### How the tests are layered

- **Pure decisions** (role/allowlist logic, notice-copy selection, status
  classification) are unit-tested against a zero-dependency stub harness in
  `tests/bootstrap.php` — no WordPress install required.
- **Behavior against real WordPress** (network-only activation, uninstall
  cleanup, self-updates) is covered by the end-to-end scripts under `bin/` and
  the Playground blueprints under `playground/`, which CI runs.
- The **"Two Factor absent" fail-safe** runs as a second PHPUnit invocation with
  its own config so the Two Factor class stubs can be suppressed (one process
  cannot un-define a class once the default bootstrap has declared them):

  ```sh
  vendor/bin/phpunit -c phpunit-no-two-factor.xml.dist
  ```

  `composer check` runs both invocations.

## What good changes look like

- Preserve existing users' stronger factors (TOTP, WebAuthn, backup codes).
- Keep enforcement fail-safe: if the Two Factor plugin/provider is absent, avoid fatals and avoid corrupting provider lists.
- Avoid adding UI, options, database tables, cron jobs, or remote calls unless there is a strong reason.
- Keep configuration operator-controlled through constants or filters.
- Add or update tests for security-critical behavior.

## Pull request checklist

- [ ] I explained the behavior change and why it is needed.
- [ ] I added/updated tests where appropriate.
- [ ] `composer check` passes.
- [ ] Documentation is updated when behavior, requirements, or rollout guidance changes.

## Reporting security issues

Please do not report vulnerabilities in public issues. See [SECURITY.md](SECURITY.md).

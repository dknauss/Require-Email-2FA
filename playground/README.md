# Playground blueprints

This folder holds two [WordPress Playground](https://wordpress.github.io/wordpress-playground/)
blueprints:

- [`demo-blueprint.json`](demo-blueprint.json) — an interactive **"try it live"**
  demo (linked from the main README).
- [`ci-blueprint.json`](ci-blueprint.json) — a headless **enforcement test** run
  by CI.

## `demo-blueprint.json` — interactive demo

[**▶ Open it**](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/dknauss/Require-Email-2FA/main/playground/demo-blueprint.json)

Boots a disposable WordPress with this plugin already active and **Two Factor not
yet installed**. You auto-login as `admin` and land on the **Plugins** screen,
where the plugin's guided **"Install & activate Two Factor"** notice appears. A
handful of sample users (Editor, Author, Contributor, Subscribers) are created so
you can browse profiles. The plugin file is fetched from this repo's raw `main`
URL, so the demo always reflects the latest code — there is nothing to regenerate.

**Design note — the demo stops at the first-run notice on purpose.** Two Factor is
left uninstalled so the plugin sits in its soft-dependency *no-op* state, which
means there is no email-2FA challenge to complete. That is deliberate: a browser
sandbox has no real mailbox, and Playground cannot complete the Two Factor email
challenge in-browser. Click **Install & activate Two Factor** to watch enforcement
turn on (the *Two-Factor Options* section then appears on user profiles), but a
full emailed-code login is best verified on a real or local site (e.g. `wp-env` /
Studio).

## `ci-blueprint.json` — CI enforcement test

Used **only by CI** to assert end-to-end enforcement headlessly. It boots a real
WordPress with the real **Two Factor** plugin and this plugin active, creates a
fresh subscriber, and asserts that forced email 2FA is in effect for both the
admin and the new user (the Email provider is enabled and
`Two_Factor_Core::is_user_using_two_factor()` is true). The assertion result is
written to a marker file that the CI job checks.

### Run it locally

Run from the repository root. The blueprint activates `force-email-two-factor`
by slug, so the working copy must be mounted into the plugins directory (the
blueprint installs `two-factor` from wordpress.org but does **not** ship this
plugin) — mirror the CI command, including the mount:

```sh
rm -f .ci-result
npx --yes @wp-playground/cli@3.1.43 run-blueprint \
  --blueprint=playground/ci-blueprint.json \
  --mount="$PWD:/wordpress/wp-content/plugins/force-email-two-factor"
cat .ci-result   # expect: FORCE2FA_ASSERT_OK
```

The same step runs in [`.github/workflows/ci.yml`](../.github/workflows/ci.yml)
(the `playground-integration` job) on every push and pull request.

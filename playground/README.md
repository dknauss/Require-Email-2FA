# Playground CI blueprint

[`ci-blueprint.json`](ci-blueprint.json) is a [WordPress Playground](https://wordpress.github.io/wordpress-playground/)
blueprint used **only by CI** to assert end-to-end enforcement headlessly. It is
not an interactive demo.

It boots a real WordPress with the real **Two Factor** plugin and this plugin
active, creates a fresh subscriber, and asserts that forced email 2FA is in
effect for both the admin and the new user (the Email provider is enabled and
`Two_Factor_Core::is_user_using_two_factor()` is true). The assertion result is
written to a marker file that the CI job checks.

## Run it locally

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

## Why there is no interactive "try it live" demo

A browser sandbox has no real mailbox, so an emailed-code plugin can only be
shown via a fake-code helper — and WordPress Playground additionally (a) forces
auto-login unless the page URL carries `login=no`, overriding a blueprint's
`login: false`, and (b) cannot complete the email 2FA challenge on an in-browser
subdirectory multisite. The interactive demo added harness-appeasing complexity
without teaching anything the README doesn't already cover, so it was removed.
The headless CI blueprint — which catches real enforcement regressions — stays.

To verify the login experience yourself, install the plugin on a real (or local,
e.g. `wp-env` / Studio) WordPress with Two Factor active and sign in.

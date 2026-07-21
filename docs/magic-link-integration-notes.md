# Research notes — forcing the Email Magic Link provider (NOT IMPLEMENTED)

> Status: **research only.** Nothing in these notes is wired into
> `force-email-two-factor.php`. This captures a sketch for a possible future
> integration so it isn't lost. Do not treat as shipped behavior.

## Context

There is a separate, prototype-stage companion plugin at
`two-factor-provider-magic-link` (private repo
`dknauss/two-factor-provider-magic-link`). It adds a `Two_Factor_Magic_Link`
**provider** to the WordPress [Two Factor](https://wordpress.org/plugins/two-factor/)
plugin — an emailed one-click sign-in link that preserves the "cookie only set
in the browser that started login" property via a split token (T, emailed) /
poll-ID (P, held by the original tab) design.

That repo names **this** plugin (Require Email 2FA) as its "parent project" and,
in both its README and TODO.md, proposes that Require Email 2FA could *force*
the magic-link provider the same way it forces `Two_Factor_Email` — "once this
graduates from prototype." This repo currently has **zero** references to it;
the coupling is one-directional (magic-link → here).

Do not implement until the provider graduates from prototype (it lacks rate
limiting, resend throttle, audit logging, and end-to-end verification).

## Design decisions (if/when implemented)

1. **Opt-in, default off.** Gate behind a new `FORCE_2FA_FORCE_MAGIC_LINK`
   constant / `force_2fa_force_magic_link` filter. The magic-link README says
   "append when the class exists," but that violates this plugin's
   config-surface convention (nothing enforced without an operator switch).
   Installing the provider lets users *choose* it; this floor *forces* it —
   a stronger statement that deserves a switch.
2. **Never weakens the floor.** Email is still appended unconditionally first;
   magic link is additive only, so the guaranteed email-grade floor stands even
   if the magic-link class later vanishes.
3. **Registry-checked, not just `class_exists`.** Mirror
   `force_2fa_dependency_met()`: confirm `Two_Factor_Magic_Link` is in
   `Two_Factor_Core::get_providers()`, so we never inject a key Two Factor can't
   resolve.

## Sketch

New pure helper (near `force_2fa_dependency_met()`):

```php
/**
 * Whether to also force the Email Magic Link provider as a floor.
 *
 * Optional companion to the Email floor: when the standalone
 * two-factor-provider-magic-link plugin is present AND the operator opts in,
 * append Two_Factor_Magic_Link alongside Two_Factor_Email. Off by default —
 * installing the provider lets users *choose* it; this switch *forces* it.
 *
 * Config surface (constant OR filter, constant wins when defined):
 *   define( 'FORCE_2FA_FORCE_MAGIC_LINK', true );          // wp-config.php
 *   add_filter( 'force_2fa_force_magic_link', '__return_true' );
 *
 * Like force_2fa_dependency_met(), we require the provider to be *registered*
 * (not merely loadable) so we never inject a key Two Factor can't resolve.
 *
 * @return bool True when the magic-link provider should be appended.
 */
function force_2fa_magic_link_forced() {
	if ( defined( 'FORCE_2FA_FORCE_MAGIC_LINK' ) ) {
		$opt_in = (bool) FORCE_2FA_FORCE_MAGIC_LINK;
	} else {
		/** This filter is documented above. */
		$opt_in = (bool) apply_filters( 'force_2fa_force_magic_link', false );
	}

	if ( ! $opt_in || ! class_exists( 'Two_Factor_Magic_Link' ) ) {
		return false;
	}

	$providers = Two_Factor_Core::get_providers();

	return is_array( $providers ) && array_key_exists( 'Two_Factor_Magic_Link', $providers );
}
```

In `force_2fa_filter_enabled_providers()`, between the Email append and the
`return`:

```php
	// Strict in_array(): these are class-name strings, so avoid loose matching.
	if ( ! in_array( 'Two_Factor_Email', $enabled_providers, true ) ) {
		$enabled_providers[] = 'Two_Factor_Email';
	}

	// Optional companion floor: the standalone Email Magic Link provider, only
	// when installed, registered, AND opted into. Additive — never replaces the
	// Email floor above, so the guaranteed email-grade baseline stands even if
	// the magic-link provider is later removed.
	if ( force_2fa_magic_link_forced()
		&& ! in_array( 'Two_Factor_Magic_Link', $enabled_providers, true ) ) {
		$enabled_providers[] = 'Two_Factor_Magic_Link';
	}

	return $enabled_providers;
```

## Follow-on work required before merge (per CLAUDE.md)

- **Tests:** extend `EnabledProvidersFilterTest` — opt-in on + registered →
  appended; opt-in off → not appended; class absent → not appended; Email floor
  present in all cases. The `__force2fa_providers` stub already simulates the
  registry.
- **Config-surface docs:** add `FORCE_2FA_FORCE_MAGIC_LINK` /
  `force_2fa_force_magic_link` to the operator-config lists in `README.md`,
  `readme.txt`, and the CLAUDE.md config-surface line (they must stay parallel).
- **PHPStan stub:** `phpstan/stubs/two-factor.stub` has no
  `Two_Factor_Magic_Link`; `class_exists()` is fine, but extend the stub if any
  of its methods are ever called.

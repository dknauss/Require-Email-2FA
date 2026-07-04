# Supply-chain security

This plugin distributes updates from **GitHub Releases**, not WordPress.org. There
is no third-party review gate between "a release is published" and "every site that
auto-updates runs the new code." That code runs **network-active, on every request,
and it intercepts authentication** — so the blast radius of a bad release is total.

> **The one-sentence threat model:** anyone who can publish a Release on the update
> repository has remote code execution on every site that auto-updates from it.

That makes the **release-publishing path the supply chain**, and it deserves
production-secret-level protection. This document covers what the design already
guarantees, the GitHub settings that protect the repository, how release artifacts
can be verified, how to fork safely, and what to do if a bad release ships.

This applies equally to the upstream repository and to any fork that redirects its
`Update URI` at its own repo (see [Forking safely](#4-forking-safely)). Where a step
is org-specific, it works the same for a personal account or a GitHub organization.

---

## 1. What the design already guarantees

These properties are built into the code and the release workflow — verify they are
intact, but you do not have to add them:

- **Reproducible build.** The release zip is `git archive` of the tagged commit
  ([`.github/workflows/release.yml`](../.github/workflows/release.yml)) — no
  Composer or npm resolution at release time. The published bytes are the reviewed
  source tree at that tag, and Packagist/npm are not in the release trust path.
- **Vendored updater.** Plugin Update Checker is committed under
  `vendor/yahnis-elsts/`, so the exact updater bytes are in-repo and reviewed, not
  pulled at build time.
- **Actions pinned to full commit SHAs.** Every `uses:` in CI and release is pinned
  to a 40-char SHA (Dependabot bumps them). A moved tag cannot inject code into the
  `contents: write` release job.
- **Least-privilege token.** The release job requests `contents: write` (create the
  Release and upload assets) plus `id-token: write` and `attestations: write` (mint
  the OIDC token and record the build-provenance attestation, section 3) — and
  nothing else. It uses the ephemeral `GITHUB_TOKEN`, not a long-lived PAT.
- **Tag-triggered only.** Release runs on `push: tags: v*` — never
  `pull_request_target` — so untrusted PR code never runs with the release token.
- **Tag ↔ version guard.** The workflow refuses to publish if the tag does not match
  the `Version:` header, so a stray tag cannot ship a mislabeled build.
- **Reviewed-commit guard.** The workflow refuses to publish unless the tagged commit
  is an ancestor of the default branch, so a `v*` tag on an unreviewed commit cannot
  ship — the tag ruleset controls *who* tags, this controls *what* a tag may point at.
- **Only the reviewed asset installs.** The runtime updater uses
  `REQUIRE_RELEASE_ASSETS` with an anchored `^<slug>\.zip$` pattern, so a Release
  without the exact built asset offers **no** update (it never falls back to
  GitHub's auto-generated source tarball, which omits `vendor/`).
- **No WordPress.org hijack.** The non-`.org` `Update URI` makes WordPress core
  decline `.org` updates for the slug, and PUC excludes the plugin from the `.org`
  update check — so a same-named `.org` plugin can never take over updates.
- **Dev checkouts don't self-update.** A working copy with a `.git` directory skips
  the self-updater, so a developer clone updates via `git`, not by WordPress
  overwriting it with a release zip.

---

## 2. GitHub settings that protect the repository

Apply these to the **update repository** (upstream and every fork). Most live under
**Settings → Rules → Rulesets**, **Settings → Code security**, and **Settings →
Actions**. None of them are set by code; they are repository/organization
configuration.

### 2.1 Identity

- **Require two-factor authentication** for everyone with write access. On a
  personal account, enable 2FA on the account itself and on any collaborator's.
  In an organization: **Settings → Authentication security → Require two-factor
  authentication for everyone**. (A plugin whose job is 2FA should not be published
  from an account without it.)
- **Least-privilege membership.** Grant `write`/`maintain` to as few people as
  possible; audit collaborators and outside collaborators periodically.

### 2.2 Default-branch ruleset (protect `main`)

Create a ruleset targeting the default branch with:

- **Require a pull request before merging** — at least 1 approval.
- **Require review from Code Owners** (pairs with [`.github/CODEOWNERS`](../.github/CODEOWNERS)),
  so changes to the plugin file, workflows, and the vendored updater need a
  maintainer's review.
- **Dismiss stale approvals** when new commits are pushed.
- **Require conversation resolution before merging.**
- **Require status checks to pass** — include the CI gates: lint, `PHPCS /
  PHPCompatibility`, the PHPUnit matrix, `PHPStan`, coverage, Playground,
  Multisite E2E, Update E2E.
- **Require branches to be up to date** before merging (strict).
- **Block force pushes** and **restrict deletions.**
- Optional but recommended: **Require signed commits** and **Require linear
  history.**
- **Do not allow bypassing the above settings** (or scope the bypass list to a
  named break-glass role only).

### 2.3 Tag & release protection (the highest-leverage control)

Publishing a `v*` tag *is* shipping code to every site. Gate it:

- **Tag ruleset** targeting `v*` (**Settings → Rules → Rulesets → New tag
  ruleset**): restrict **creation**, **update**, and **deletion** to a small bypass
  list of trusted maintainers. Optionally **require signed tags.** (A ruleset
  restricting only *update* and *deletion* — not *creation* — protects existing
  release tags from being moved or deleted without ever blocking new releases; that
  is the default this repo ships.)
- **The tag must point at a reviewed commit.** A tag ruleset governs *who* may tag
  and whether a tag can move, but not *what commit* it points at — and the build
  archives that commit's tree. `release.yml` therefore refuses to publish unless the
  tagged commit is an ancestor of the default branch, so a `v*` tag on an unreviewed
  commit (bypassing the branch ruleset and CODEOWNERS above) cannot ship.
- **Release environment approval.** The release job in
  [`release.yml`](../.github/workflows/release.yml) declares
  `environment: release`. Configure that environment (**Settings → Environments →
  release**) with **Required reviewers**, so publishing a release **pauses for a
  human approval click** before the `contents: write` job runs. This is the
  strongest defense against a compromised push or workflow silently shipping a
  release. Optionally restrict the environment's **deployment tags** to `v*`.

### 2.4 Actions security

**Settings → Actions → General:**

- **Actions permissions:** allow only actions you trust (the repo pins by SHA;
  "Allow <owner>, and select non-<owner> actions" with SHA-pinning is a good
  posture).
- **Fork pull-request workflows:** require approval for workflows from **all
  outside collaborators** (or first-time contributors), so a PR cannot run
  workflows without a maintainer's click.
- **Workflow permissions:** set the default `GITHUB_TOKEN` to **read-only**; the
  workflows here already opt into `contents: write` explicitly where needed.

### 2.5 Code-security features

**Settings → Code security** (all free for public repositories):

- **Secret scanning + Push protection** — blocks committed credentials.
- **Dependabot alerts** and **Dependabot security updates** — this repo already
  ships [`dependabot.yml`](../.github/dependabot.yml) for version bumps of Composer
  deps and Actions; enable security updates too.
- **Code scanning (CodeQL)** — enable "Default setup" for the repository (PHP is
  supported with build-mode "none"). Catches injection/XSS-class issues on PRs.
- **Private vulnerability reporting** — enable it; it pairs with
  [`SECURITY.md`](../SECURITY.md) so researchers can report privately instead of
  opening a public issue.

---

## 3. Verifying a release artifact

Beyond "GitHub account + TLS," each Release published by the workflow carries two
independent integrity signals plus a reproducibility path:

1. **SHA-256 checksum.** The workflow publishes `<slug>.zip.sha256` alongside the
   zip and prints the digest in the release notes. Verify a download with:

   ```sh
   sha256sum -c force-email-two-factor.zip.sha256
   ```

2. **Build provenance attestation.** The workflow attests the zip with
   [`actions/attest-build-provenance`](https://github.com/actions/attest-build-provenance)
   (Sigstore). This is *authenticity*: cryptographic proof the artifact was built by
   this repository's release workflow from a specific commit. Verify with — and pin
   `--signer-workflow`, so provenance produced by any *other* workflow in the repo is
   rejected, not merely anything bearing the repo's identity:

   ```sh
   gh attestation verify force-email-two-factor.zip \
     --repo <owner>/<repo> \
     --signer-workflow <owner>/<repo>/.github/workflows/release.yml
   ```

   > **Private forks:** GitHub artifact attestations require GitHub Enterprise Cloud
   > on private/internal repositories. The workflow gates this step on public
   > visibility, so a private fork on Free/Pro/Team still publishes (checksum +
   > reproducible-build verification apply) but without a provenance attestation.
   > Keep the update repo **public**, or use Enterprise Cloud, to get provenance.

3. **Reproducible from source.** Because the build is `git archive` of the tag, the
   zip's contents equal the reviewed tree. Rebuild and diff the *contents* (zip
   bytes may differ across git/zip versions, so compare the extracted trees, not the
   raw checksum):

   ```sh
   git archive --format=zip --prefix="force-email-two-factor/" -o rebuilt.zip vX.Y.Z
   # then unzip both and `diff -r` the extracted directories
   ```

> **Important:** these are *auditable*, not *enforced*. WordPress/PUC install the
> release asset over HTTPS by name; they do not verify the checksum or attestation
> during auto-update. The signals let you (or a monitoring job) detect a tampered or
> unexpected release out of band. The real prevention is Section 2 — controlling who
> can publish.

---

## 4. Forking safely

A fork that wants its own sites to auto-update from its own repo changes exactly two
things, then keeps everything above:

- **`Update URI:` header** → your fork's repo URL. This single change redirects both
  the runtime updater and WordPress core's update ownership to your fork. Leaving it
  on the upstream repo auto-updates your sites back to upstream.
- **`PLUGIN_SLUG`** in [`release.yml`](../.github/workflows/release.yml) → only if
  you rename the plugin folder. The runtime derives the same slug from the installed
  folder name, so the workflow's asset name and the updater stay in sync.

Then re-apply the protections:

- **Set your own branch/tag rulesets and environment approval** — forks do **not**
  inherit branch protection, rulesets, or environment configuration.
- **Point [`CODEOWNERS`](../.github/CODEOWNERS)** at your maintainers.
- **Migrating existing installs:** a site keeps updating from whatever `Update URI`
  is in the code *currently installed on it*. Sites running an upstream build will
  keep pulling from upstream until a fork-pointed build is installed **once**
  (manual upload/replace). After that one swap, auto-updates flow from your fork.

### Private fork repositories

If your update repo is **private**, PUC cannot read its Releases anonymously and you
would add `->setAuthentication( $token )` to the checker. **Do not** embed a broad
token: it ships inside the plugin on every site and is readable by anyone with
filesystem or database access. Instead:

- Keep the update-read repository **public** if you can (the update path only needs
  to read published release assets), **or**
- Use a **fine-grained, read-only PAT scoped to that single repo's contents**, **or**
- Front updates with a **proxy/CDN** that holds the credential server-side.

Rotate any embedded credential on a schedule and on any staff change.

---

## 5. Incident response

If a malicious or broken release ships:

1. **Stop the spread.** Delete or yank the bad Release and its tag — sites that have
   not polled yet will not pull it. Deleting the `<slug>.zip` asset alone is enough
   to stop the updater (it requires that exact asset).
2. **Ship a clean, higher version.** Publish a reviewed release with a version
   greater than the bad one so already-updated sites move forward. WordPress will
   not auto-downgrade.
3. **Contain compromised sites.** The emergency kill switch disables *all*
   enforcement without deleting the plugin — add to `wp-config.php`:

   ```php
   define( 'FORCE_2FA_DISABLE', true );
   ```

   and, on fleets you manage, disable the self-updater while you investigate:

   ```php
   define( 'FORCE_2FA_DISABLE_SELF_UPDATE', true );
   ```

4. **Rotate and audit.** Rotate any tokens, review the Actions run logs and audit
   log for how the release was published, and confirm branch/tag/environment
   protections were in place (Section 2).

### Availability is a security property too

Anonymous GitHub API calls are rate-limited (~60/hour per IP). A large fleet behind
shared egress can exhaust the quota and **silently stall update checks**, so security
fixes don't land. For big deployments, use authenticated update checks or a caching
proxy, or drive patching centrally (see [DEPLOYMENT.md](DEPLOYMENT.md) and the
`FORCE_2FA_DISABLE_SELF_UPDATE` posture).

---

## 6. Hardening checklist

**Repository/org settings (Section 2):**

- [ ] 2FA required for all accounts with write access.
- [ ] Default-branch ruleset: PR + code-owner review, conversation resolution,
      required status checks (incl. `PHPStan`), up-to-date, no force-push, no
      deletion.
- [ ] Tag ruleset on `v*`: creation/update/deletion restricted to maintainers.
- [ ] `release` environment: required reviewers configured.
- [ ] Actions: default token read-only; fork-PR workflows require approval.
- [ ] Secret scanning + push protection on.
- [ ] Dependabot alerts + security updates on.
- [ ] Code scanning (CodeQL default setup) on.
- [ ] Private vulnerability reporting on.
- [ ] `CODEOWNERS` points at the right maintainers.

**Kept intact in code (Section 1):**

- [ ] Updater vendored under `vendor/yahnis-elsts/`; changes reviewed.
- [ ] All `uses:` pinned to full SHAs.
- [ ] Release job holds only `contents: write` (+ `id-token`/`attestations` for
      provenance) and runs on tags only.
- [ ] Release asset is the `git archive` build; provenance + SHA-256 published.

**When forking (Section 4):**

- [ ] `Update URI` points at the fork.
- [ ] `PLUGIN_SLUG` matches the installed folder name.
- [ ] Fork's own rulesets, environment, and `CODEOWNERS` configured.
- [ ] No broad token embedded for a private update repo.

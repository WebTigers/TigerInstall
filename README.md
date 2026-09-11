# tiger-install

**The one-file web installer for [Tiger](https://github.com/WebTigers/Tiger)** — install a modern,
multi-tenant CMS/SaaS platform on shared cPanel hosting with **no shell and no Composer**.

## Download

**[⬇ tiger-install.zip](https://github.com/WebTigers/TigerInstall/releases/latest/download/tiger-install.zip)**
— always the current release.

```
https://github.com/WebTigers/TigerInstall/releases/latest/download/tiger-install.zip
```

Then, in cPanel:

1. **File Manager** → open your domain's document root (`public_html`).
2. **Upload** `tiger-install.zip`.
3. Select it → **Extract**.
4. Open `https://yourdomain.com/tiger-install.php` in your browser.

That's it — the installer takes over from there, and deletes itself when it's done.

> **Why a zip rather than a bare `.php`?** Upload-then-extract is the native cPanel motion, a zip
> survives the trip without a browser trying to render or rename it, and "download this loose script
> and drop it in your web root" is a habit worth not teaching. Each release also publishes a
> `tiger-install.zip.sha256` if you want to verify the download before extracting.

Prefer the command line, or already have shell access?

```bash
cd ~/public_html
curl -LO https://github.com/WebTigers/TigerInstall/releases/latest/download/tiger-install.zip
unzip tiger-install.zip
```

## What it does (and why it's safer than WordPress)

The old way — WordPress's `wp-config.php` with your database password sitting **in** the document
root — relies on the web server never serving `.php` as text. Tiger inverts that. This installer:

1. **Checks your host** meets Tiger's requirements (PHP 8.1+, `pdo_mysql`, `zip`, …) — a clear
   pass/fail list with the exact fix for anything short.
2. **Downloads the latest Tiger release** ZIP from GitHub and **verifies it** against the release's
   published `.sha256` (over TLS).
3. **Extracts the app _above_ your document root** — so your code and secrets are **not web-reachable
   at all**. Only a tiny front-controller shim + asset links go in the docroot.
4. **Writes your DB settings + freshly-minted secrets** into `local.ini` **above the docroot**,
   `chmod 600`.
5. **Builds the database schema** and **creates your admin account** — using Tiger's own installer, so
   nothing is re-implemented here.
6. **Deletes itself.**

The one thing **you** do by hand: create an empty MySQL database + user in cPanel's *MySQL Databases*
wizard (a normal cPanel DB account can't create a database from PHP — only you can, in cPanel). The
installer does everything else.

## Multi-domain (cPanel addon domains)

Each Tiger install is fully self-contained, so **one cPanel account can run many domains, each its own
independent install**:

```
/home/user/
├── domain.com/tiger-app/      ← install A (own code, own local.ini, own database)
├── domain.net/tiger-app/      ← install B
└── public_html/
    ├── domain.com/            ← install A document root
    └── domain.net/            ← install B document root
```

Upload the installer into a given domain's document root and it detects that domain automatically,
defaulting the app folder to `/home/user/<domain>/tiger-app` (editable). Repeat per domain.

## Evergreen — the download never goes stale

The installer is **not** rebuilt for each Tiger release: it resolves the **latest** Tiger release at
runtime and installs that. And the download link above is a `releases/latest` URL, so it always
serves the current installer without the URL ever changing.

To pin a specific Tiger version, add `?version=<tag>` when you open the installer in your browser.

To pin a specific *installer* build, download from a tagged release instead of `latest`:

```
https://github.com/WebTigers/TigerInstall/releases/download/v1.0.2/tiger-install.zip
```

## Driving the installer from an AI client

The wizard is a plain HTML form flow with no JavaScript requirement, so any browser-aware client can
fill and submit it exactly as a person does. Two things make that reliable rather than a scraping
exercise.

### 1. Machine-readable state on every screen

Every page carries a JSON block. Read it instead of the prose:

```html
<script type="application/json" id="tiger-install-state">{ ... }</script>
```

| Field | Meaning |
|---|---|
| `installer` | installer version |
| `step` | `requirements` · `location` · `download` · `database` · `admin` · `finish` · `expired` |
| `status` | `awaiting-input` · `blocked` · `error` · `ok` |
| `next_step` | the `step` value to post next, when the screen is waiting on input |
| `fields` | the field names this screen expects |
| `error` / `detail` | a stable error slug plus the human message, when `status` is `error` |
| `checks` | requirements only: each check with `ok`, `required`, and a `fix` when failing |

`status` alone answers "did that work?" — `blocked` means an unmet requirement the user must fix,
`error` means the step can be retried, `ok` appears only on `finish`.

Retrying is safe and needs no re-upload; the file only deletes itself **after** the owner is created.
Every error path stops before that.

### 2. The connect handshake — how a client gets a credential

A fresh Tiger is deliberately unreachable by an agent: `/mcp` is off and a scoped token is normally
minted by an authenticated admin. The installer's finish step is the one moment a human is present,
authenticated, and making a deliberate choice — so that is where the credential is handed out.

**Tick "Let the assistant that installed Tiger manage it"** on the admin step. The checkbox can be
pre-ticked with `?agent=1` on the installer URL, but it is always **visible before you submit and can
be turned off** — a seeded choice you can see and reverse, never a silent one.

On success the finish screen shows the key once, and the state block carries it:

```json
{
  "step": "finish",
  "status": "ok",
  "site": "https://example.com/",
  "agent": {
    "enabled": true,
    "endpoint": "https://example.com/mcp",
    "token": "tgr_…",
    "manage": "https://example.com/mcp/admin",
    "scope": { "modules": ["cms","blog","media","search","docs"], "org_scoped": true, "read_only": false }
  }
}
```

If the box was not ticked, `agent.enabled` is `false` with `reason: "not_requested"` — degrade to
telling the user to enable it at `/mcp/admin` and reconnect, rather than failing.

**There is no callback URL, and one must never be added.** The key is displayed on the installer's own
screen and nowhere else. A client that drove the install drove the browser — it filled in the database
and admin forms, so it can read the finish page. A callback would solve nothing while turning a shared
installer link into credential phishing: installer links travel by being shared, and `?callback=` would
let a stranger receive a token to a site someone else legitimately installed. That is why the enable
param is safe and a callback is not.

The token is a normal scoped MCP credential: visible, revocable, and re-mintable at `/mcp/admin`, and
never more than the owner's own permissions allow.

## Requirements

Shared cPanel hosting with **PHP 8.1+** and the `pdo_mysql`, `zip`, `mbstring`, and
`openssl`/`sodium` extensions, plus `curl` (or `allow_url_fopen`) to download — the installer's first
screen verifies all of this and tells you what to toggle in cPanel. Full detail:
[Tiger INSTALL.md](https://github.com/WebTigers/Tiger).

## Security

- App + `local.ini` (secrets) live **above** the web root — never fetchable over HTTP.
- The download is **checksum-verified over TLS** before it's trusted.
- The installer **refuses to overwrite** an existing configured install and **self-deletes** on
  success (with a loud warning to delete it manually if it can't).
- It's **one small file** on purpose: read it top to bottom before you run it. That's the whole point
  of its own repo.

## Status

> **Beta.** This installer targets the **vendored full-app release bundle** (`tiger-<version>.zip` +
> `.sha256`) attached to [`WebTigers/Tiger`](https://github.com/WebTigers/Tiger) releases. That bundle
> **is now published** — the installer resolves the latest release at runtime, so no manual step is
> needed. The **manual upload** path remains available for an air-gapped or pinned install, and
> Composer still works where you have a shell:
>
> ```bash
> composer create-project webtigers/tiger my-app
> ```
>
> (The `--stability=beta` flag is no longer needed — the skeleton publishes stable tags.)

## Development

```
php tests/run.php
```

No dependencies — the same command CI runs. Three files:

| | |
|---|---|
| `tests/invariants.php` | properties that must never regress: one file with no dependencies of its own, **no callback/webhook field of any kind**, outbound calls only to the pinned release URLs, no shell functions (a shared host has no shell), a required checksum, self-deletion |
| `tests/wizard.php` | the agent checkbox — rendered, unticked by default, reversible, never also a hidden input — and the machine-readable state block, including that a `<` in the payload cannot break out of the script element |
| `tests/smoke.php` | serves the installer and reads its state block back, proving it runs and reports where it is |

The wizard tests lift the real seeding logic out of the shipped file at run time rather than copying
it, so a test cannot quietly drift from the code it covers.

CI lints on **PHP 8.1 through 8.5** — the range a cPanel host is likely to offer. A parse error on a
customer's PHP version is the worst failure this repo has: a blank page on their own server, mid-install,
with no way to debug it.

## License

BSD-3-Clause © WebTigers. "Tiger" and "WebTigers" are trademarks of WebTigers. See [LICENSE](LICENSE).

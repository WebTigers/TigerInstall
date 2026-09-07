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

## License

BSD-3-Clause © WebTigers. "Tiger" and "WebTigers" are trademarks of WebTigers. See [LICENSE](LICENSE).

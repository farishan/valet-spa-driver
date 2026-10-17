# valet-spa-driver

A Valet Linux driver that serves compiled single-page apps with SPA history
fallback, so `https://my-app.test/hustle/abc` works instead of 404ing.

Valet Linux has no `serve` command and no SPA driver. All of its built-in
drivers target PHP frameworks, so pointing one at a Vite/React build gives you
a working homepage and 404s on every client-side route — exactly the behaviour
`BasicValetDriver` is designed to produce, since `/hustle/abc` has no file and
no front controller behind it.

This adds that missing behaviour without patching Valet.

```
/                      200   index.html (via front controller)
/hustles               200   index.html (history fallback)
/hustle/abc            200   index.html (history fallback)
/assets/app-abc123.js  200   served as itself, never as HTML
/missing.js            404   never HTML -- see "Why missing assets must 404"
```

## Requirements

- Valet Linux (tested against v2.3.10)
- A build output directory containing `index.html` at its root
- PHP CLI (only for running the tests)

## Install

```bash
git clone https://github.com/farishan/valet-spa-driver.git
cd valet-spa-driver
./install.sh
```

Then link the **build output**, not the source directory:

```bash
cd path/to/your-app/dist
valet link my-app
valet secure my-app     # optional, for HTTPS
```

`valet secure` takes the bare site name, not the full domain — `valet secure
my-app` produces `my-app.test`. Passing `my-app.test` yields `my-app.test.test`.

If your app is a PWA, install the MIME mapping as well:

```bash
./install.sh --mime-types
sudo nginx -s reload
```

Uninstall with `./install.sh --uninstall`.

## How it decides which sites it serves

A site is claimed when its root contains `index.html` **and** neither
`index.php` nor `public/index.php`.

Those exclusions are the important part. This driver is consulted *before*
`BasicValetDriver`, so a false positive takes a PHP site offline. The two
layouts checked are precisely what `BasicValetDriver` itself uses to recognise
a PHP app, so the test mirrors its own notion of one rather than inventing a
new rule.

- `index.php` at the root — WordPress, flat PHP installs
- `public/index.php` — Laravel, Symfony, Slim, Laravel-based CMSs

Everything else with an `index.html` is a static build and is claimed,
including plain SPAs with no web app manifest.

## Testing

```bash
php tests/run.php
```

The suite builds its own fixture sites in a temp directory, so it neither
touches nor reveals the projects linked on the machine running it. It exits
non-zero on failure.

If Valet is installed somewhere unusual:

```bash
VALET_PATH=/path/to/valet-linux php tests/run.php
```

## Gotchas this driver exists to work around

### `/` is never passed to `isStaticFile()`

From `server.php`:

```php
if ($uri !== '/' && !$isPhpFile && $staticFilePath = $valetDriver->isStaticFile(...)) {
    $valetDriver->serveStaticFile($staticFilePath, ...);
    return;
}
$frontControllerPath = $valetDriver->frontControllerPath(...);
if (!$frontControllerPath) { show_valet_404(); }
```

The site root can only be answered by `frontControllerPath()`. A driver that
returns `false` there yields a 404 at `/` while every other route works, which
is a confusing failure to debug from the outside. Returning `index.html` is
sanctioned — `BasicValetDriver` does the same via
`asHtmlIndexFileInDirectory()`, and server.php simply `chdir()`s and
`require()`s the result.

### Missing assets must 404, never receive `index.html`

The history fallback applies only to extension-less paths. If `/assets/foo.js`
resolved to `index.html`, the browser would execute the HTML as JavaScript and
fail with a syntax error that points nowhere near the cause. Both
`isStaticFile()` and `frontControllerPath()` enforce this.

### nginx has no MIME type for `.webmanifest`

Manifests are served as `application/octet-stream`, which can stop Chrome
treating the site as installable. `./install.sh --mime-types` adds a `types`
block to `~/.valet/Nginx/`, which `/etc/nginx/nginx.conf` includes at `http{}`
level. It must stay at that level: a `types` directive in a `server{}` block
*replaces* the inherited set instead of merging with it, which would strip MIME
types from every site on the machine.

### Valet's root CA silently expires

Unrelated to this driver, but it will bite you. `Site::createCa()` returns
early whenever the CA key and certificate merely *exist*:

```php
if ($this->files->exists($caKeyPath) && $this->files->exists($caPemPath)) {
    return;
}
```

It never checks whether the CA is still valid. Once it lapses, every subsequent
`valet secure` happily issues a leaf signed by a dead CA — correct CN,
correct SAN, valid dates — and browsers correctly report the site as
insecure. To recover:

```bash
rm ~/.valet/CA/LaravelValetCASelfSigned.{key,pem}
valet secure my-app
certutil -d sql:$HOME/.pki/nssdb -A -t "C,," -n "Laravel Valet CA Self Signed" \
    -i ~/.valet/CA/LaravelValetCASelfSigned.pem
sudo cp ~/.valet/CA/LaravelValetCASelfSigned.pem /usr/local/share/ca-certificates/valet-ca.crt
sudo update-ca-certificates
```

Re-issue every other secured site too — they are all signed by the same CA.

## A note on testing framework integrations

An earlier version of this suite called `isStaticFile()` directly and reported
`/` as passing while the live site 404'd, because the test bypassed the
`$uri !== '/'` guard above. The suite now replicates `server.php`'s dispatch
order in a `dispatch()` helper; that change immediately caught a second bug.

A test of a framework integration is only worth as much as its dispatch order.

## License

MIT. Valet Linux itself is MIT-licensed and is neither modified nor vendored
here; this driver only extends `ValetDriver` from an existing installation.
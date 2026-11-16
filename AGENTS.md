# AGENTS.md

## Repo shape

- Single-file library: everything lives in `qrcode.php` (class `QRCode` +
  lookup tables). No Composer, no deps, no autoload — keep it that way
  (README: "copy the file" is the install story).
- Dual-mode entrypoint at the top of `qrcode.php` (around line 30): if the
  file is the requested script
  (`realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])`), it acts
  as an HTTP endpoint reading `$_REQUEST`; otherwise `require` only defines
  the class. Don't break either mode.
- A third mode lives in the same block: when run from the PHP CLI with no
  `REQUEST_METHOD` and empty `$_REQUEST`, it parses `argv` (`--key=value`
  options, `--invert` flag, first bare argument is the data) and prints
  `render_ascii()` to stdout. Usage errors go to stderr with exit 1. Keep
  this branch from catching real HTTP requests.
- Option keys are short and shared between constructor array and HTTP
  params (`d`, `s`, `sf`/`sx`/`sy`, `p`/`pv`/`ph`/`pt`/`pr`/`pb`/`pl`,
  `bc`, `fc`, `md`, `wq`, `wm`, `w`, `h`, `f`/`format`). Parsing lives in
  `encode_and_calculate_size()` and `dispatch_encode()`.
- `sandbox.html` (interactive playground) and `qrcode-test.html` (manual
  test matrix) both call `qrcode.php` over HTTP — they need a PHP server,
  not `file://`.
- `examples/` holds committed PNG fixtures referenced by the README, plus
  SVG counterparts (`hello.svg`, `styled.svg`) that are no longer linked;
  regenerate only if intentionally changing default rendering.
- `unit_tests/tests.php` is the standalone suite (see Commands); it is
  self-contained and requires only `qrcode.php`.
- `.github/workflows/tests.yml` runs lint + the suite on PHP 8.1-8.5 with
  GD and the `qrencode` oracle, then a second pass under `php -n` to
  exercise the extension-missing `SKIP` path. A failure in the `php -n`
  job is usually a test that assumed an extension (e.g. `mb_strlen`).

## Commands

Standalone test suite (no PHPUnit/Composer): `php unit_tests/tests.php`.
Exits 0 on success, 1 on any failure; prints a `Passed/Failed/Skipped`
summary. Flags: `--simple` (only failures + summary), `--no-color`
(`NO_COLOR` env also works). Tests needing GD are reported as `SKIP`, not
failed, when the extension is missing. No linter or typecheck beyond
`php -l`; CI is the workflow above.

```sh
php unit_tests/tests.php                 # full suite
php -n unit_tests/tests.php              # no-extensions pass (matches CI)
php -l qrcode.php                        # syntax check (only static check)
php qrcode.php "HELLO" --s=qr-m         # CLI: prints ASCII art to stdout
php -S localhost:8000                    # serve repo for sandbox/test pages
# then open http://localhost:8000/qrcode-test.html or /sandbox.html
```

One test (`encoder output matches qrencode across versions and levels`)
cross-checks unmasked codewords against the `qrencode` binary and skips
(via `skip_test()`/`TestSkipped`) when it isn't installed. Everything else
runs with only PHP.

Quick CLI smoke test (no server needed for the class API):

```sh
php -r 'require "qrcode.php"; $q = new QRCode("HELLO", ["s"=>"qr-m"]); echo substr($q->render_svg(), 0, 40);'
```

## Conventions & gotchas

- PHP 8.1+ (`declare(strict_types=1)`, readonly ctor property). Local env
  has 8.5 — image dimensions are clamped to ≥1 in
  `encode_and_calculate_size()` to avoid a PHP 8.5 `GdImage` ValueError;
  preserve that clamp.
- PNG path requires the GD extension; SVG path must stay GD-free
  (`render_svg()` never touches GD).
- `render_ascii()` is GD-free and emits half-block characters (`▀`, `▄`,
  `█`, space), packing two module rows per text line. It is the only
  output the CLI produces; the HTTP endpoint still emits PNG/SVG only.
- Tests must run under `php -n` (CI's second pass, no extensions). Do not
  use `mb_*` functions in `unit_tests/tests.php`; use PCRE (`preg_split`
  with `/u`) for multibyte character counts. Only the GD-dependent tests
  may assume an extension, and they must declare it via the test
  registration so they SKIP cleanly.
- Error-correction option `s` accepts `qr-l`/`qrl` etc. — separators are
  stripped before dispatch (`dispatch_encode()`). Unknown values fall
  back to L.
- Large static tables at the bottom of the class (`qr_capacity`,
  `qr_ec_params`, `qr_ec_polynomials`, `qr_log`/`qr_exp`,
  `qr_format_info`, `qr_version_info`, …) are QR-spec lookup data ported
  from Kreative Software's barcode project — treat as read-only unless
  deliberately updating spec data.
- License header in `qrcode.php` carries the Kreative/Becker copyright
  notice required by MIT; keep it intact when editing the file.
- README is the canonical option reference (table under "Options") —
  update it when adding/renaming options.
- History note: this is a fork of a fork (origin
  `scottchiefbaker/php-qrcode`, upstream lineage via psyon/Kreative). The
  README once lost its testing section; `unit_tests/tests.php` is now the
  primary automated check, and the `php -S` + `qrcode-test.html` flow is
  the manual verification path.

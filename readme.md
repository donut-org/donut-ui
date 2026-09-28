# Donut GUI

[![Build Status](https://github.com/donut-org/donut-ui/workflows/Build/badge.svg)](https://github.com/donut-org/donut-ui/actions)
[![Downloads this Month](https://img.shields.io/packagist/dm/donut-org/donut-ui.svg)](https://packagist.org/packages/donut-org/donut-ui)
[![Latest Stable Version](https://poser.pugx.org/donut-org/donut-ui/v/stable)](https://github.com/donut-org/donut-ui/releases)
[![License](https://img.shields.io/badge/license-New%20BSD-blue.svg)](https://github.com/donut-org/donut-ui/blob/master/license.md)

An authoring environment for donut's workflows and blocks. It shows what the
validator would say before a workflow ever runs against a real card, and it
can create, edit and delete blocks, whole workflows and their individual
steps.

Design document: `docs/superpowers/specs/2026-08-05-gui-design.md`.


## Installation

From a clone:

```bash
git clone https://github.com/donut-org/donut-ui.git
cd donut-ui
make install
```

`make install` fetches the dependencies. An installation needs neither Tester
nor PHPStan, so they are left out; anyone working on the GUI itself wants
`make install dev=1` — without them `make test` has nothing to run.

To upgrade:

```bash
make upgrade
```

That runs `git pull` and installs whatever came with it. The compiled
container and templates take care of themselves afterwards, so there is
nothing to clear. The one exception is an upgrade that **adds a presenter**:
presenters are baked into the container when it is compiled, and the revision
does not notice them. It announces itself — the GUI reports that the presenter
does not exist — and throwing the cache away fixes it:

```bash
rm -rf ~/.cache/donut-ui
```

Nothing is written into the repository directory at run time. The compiled
container and templates, and the sessions, go to `$XDG_CACHE_HOME/donut-ui`
(`~/.cache/donut-ui` by default), the log to `$XDG_STATE_HOME/donut-ui/log`
(`~/.local/state/donut-ui/log` by default). The application creates both
itself, and both can be overridden — `DONUT_GUI_CACHE` and `DONUT_GUI_LOG`.
The installation can therefore belong to root and be read-only for whoever
runs the GUI. An account with neither `HOME` nor `XDG_*` falls back to the
installation, so it has to set those two variables.


### Developing against an unreleased core

`composer.json` requires a released core. When working on the core and the GUI
at the same time, use the second manifest, which takes the donut checkout
sitting next to this one:

```bash
COMPOSER=composer-dev.json composer install
```

It installs `donut-org/donut` as a symlink from `../donut`, so changes in the
core show up immediately. A new dependency has to be written into both
`composer.json` and `composer-dev.json`.


## Running it

The GUI looks for `blocks/` and `workflows/` in a **profile**, the same way
the CLI does: in `$DONUT_HOME/$DONUT_PROFILE/{blocks,workflows}`,
`~/.config/donut/default` by default. The working directory you start the
server from plays no part.

The quickest way is `make server` — it starts the built-in PHP server against
the **default profile**, the same one the CLI would take:

```bash
make server
```

Against the repository's own sample set (`docs/workflows/donut`), through the
variables:

```bash
make server home=$(pwd)/docs/workflows profile=donut
```

The GUI runs **in production mode by default** — no Tracy bar, because to
whoever authors in it this is a finished application. An uncaught error is
written to `exception.log` in the log directory (`~/.local/state/donut-ui/log`)
and the user gets a page rather than a bluescreen.

When working on the GUI itself:

```bash
make server debug=1
```

That turns Tracy on and also **thaws the DI container cache**. Template edits
show up without it — Latte watches their content all the time. Otherwise the
container rebuilds itself as soon as `vendor/composer/installed.php` or
`config/common.neon` changes, which is to say on an upgrade that brought a new
dependency or a new configuration. The switch corresponds to the
`DONUT_GUI_DEBUG` variable.

Starting it by hand against any profile:

```bash
DONUT_HOME=~/.config/donut DONUT_PROFILE=default \
	php -S 127.0.0.1:8000 -t /path/to/donut-ui/www /path/to/donut-ui/www/index.php
```

then open <http://127.0.0.1:8000/>.

The router script (`www/index.php` as the last argument) is required so that
the built-in server does **not** `chdir()` into the docroot — that is what
lets `-t` point at `www` (where the assets are served from) while the GUI
still does not look for `blocks/` and `workflows/` relative to wherever the
process runs: only the profile from `DONUT_HOME`/`DONUT_PROFILE` decides that.

The router also sends every request to `index.php` first; a static file is
served only when `Donut\Gui\StaticFile::shouldServe()` recognises it as an
existing file inside `www`, the router itself excepted.

The assets live in `www/assets/`. Bootstrap is version **5.3.8**, vendored by
hand from `https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/`; upgrading it
means replacing `bootstrap.min.css` and `bootstrap.bundle.min.js` with new
files from there. No build step, no `npm`.

`netteForms.min.js` is copied from `vendor/nette/forms/src/assets/`; copy it
again after `composer update`. It switches on client-side validation for every
form, and it is also what makes `toggle()` mean anything — without it the
rules are rendered and nobody acts on them.


## What you can see

- **workflow list** — the name and description of every workflow in
  `workflows/`
- **workflow detail** — the steps as a tree (`if`/`foreach` nested) and the
  validator's problems at the step they concern
- **step editing** — an "edit" link at every step leading to a form for its
  type (`run`, `set`, `if`, `foreach`); below the tree and in every nested
  branch a step of that type can be added, moved up or down, or deleted
  (deleting a step with a subtree asks for confirmation)
- **a `run` step knows its block** — a new `run` is created by picking a block
  from cards, so the form has a **fixed list of inputs**: `stdin` first, when
  the block reads it (it tends to be the main content, not a switch), then one
  row per input the block declares. Values go into a field that grows with the
  number of lines written into it. Required inputs are enforced by the same
  rule the validator uses. An empty input is not written to the file — an
  empty value would silence the block's default, while a missing key lets it
  speak. A key the block does not declare is shown with a message, and saving
  is possible only once it has been emptied. The block is not switched during
  editing: a different block means a different step. Outputs into the map are
  three fields (`stdout`, `stderr`, `exit_code`); an empty field means the
  channel is discarded
- **block overview** — a table of the blocks in `blocks/`: name, description,
  command and the workflows using the block; creating, editing and deleting a
  block through a form
- **block detail** — description, command, arguments in groups, declared
  inputs, stdin, timeout, `allow_failure` and the list of workflows calling
  the block
- **workflow header** — creating a new workflow, editing the name (only while
  creating), the description and the inputs, and deleting; deleting only warns
  that workflows are run by name from cron and from the CLI, which the GUI
  cannot see
- **key flow** — every step shows which keys it reads and which it writes; a
  key is a clickable link that highlights every step it appears in (writes in
  a different colour than reads); the selection is held by the address
  (`&key=repo`), so it can be sent as a link; a conditional write is
  recognisable from the highlighted step sitting inside an `if` or a `foreach`

Addresses live in the query string, e.g.
`?name=card-dev&action=detail&key=repo` — the router is Nette's
`SimpleRouter`, no pretty URLs.


## What the GUI deliberately cannot do

This is not a list of unfinished work — these are decisions from the design:

- **rename a workflow or a block** — both are run by name from cron and from
  the CLI, which the GUI cannot see; the name is therefore in the form only
  while creating, and is not editable afterwards
- **check references when deleting a workflow** — inside the format there is
  nothing to check, and outside it (cron, CLI) the GUI cannot see; blocks do
  get the check, because workflows reference them inside the format
- **detect concurrent edits** — a file changed in an editor between the page
  being rendered and being saved is overwritten without warning
- **CSRF protection and sessions** — the Fetch-Metadata check covers it
  without a session: forms ask for same-origin themselves in
  `Form::signalReceived()`, and Nette attaches `Requires(sameOrigin: true)` to
  `handle*` methods automatically — and the GUI writes to disk through nothing
  but a form or a `handle*` signal (moving and deleting a step are `handle*`
  in `StepTreeControl`)
- **create the `workflows/` and `blocks/` directories** — a missing directory
  in the profile is only reported, together with the `mkdir -p` that would
  create it
- **switch profiles at run time** — the profile is decided by the
  `DONUT_HOME`/`DONUT_PROFILE` environment variables when the server starts;
  switching means a restart with a different value. The GUI only shows the
  profile's name in the header
- **switch the block of an existing step** — the inputs are fixed by the block
  and the form has no way of telling which values belong to the new one; a
  different block is a different step, so delete it and create it again
- **open a `run` step whose block is missing or unreadable** — the whole list
  of inputs comes from the block, so without it there is nothing to build the
  form from: a step calling a block that is not in `blocks/` answers 404, and
  a step calling a block with a broken file gives a page with an error message
  and no form. Such a step can only be deleted from the workflow tree


## Tests and static analysis

Both are run from the root of the repository:

```bash
vendor/bin/tester tests -C
vendor/bin/phpstan analyse
```

`phpstan.neon` runs at `level: max`, the same as the `phpstan.neon` in the
root of the `donut-org/donut` repository — zero errors holds for both, not
just for donut's `src/` and `tests/`.

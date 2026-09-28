php_bin = php
tester_bin = vendor/bin/tester
tests_dir = tests/

# `make server` runs against the same profile the CLI would use:
# $DONUT_HOME/$DONUT_PROFILE, by default ~/.config/donut/default. An empty
# value is the same as unset, so leaving these blank hands the decision to
# Profile::fromEnvironment(). To browse the repository's own sample set:
#   make server home=$(CURDIR)/docs/workflows profile=donut
home =
profile =
port = 8000

# Loopback, because the GUI has no authentication of its own — it writes to
# blocks/ and workflows/ for whoever reaches it. `make server host=0.0.0.0`
# hands that to everyone on the network, so it is worth meaning it.
host = 127.0.0.1

# The GUI runs in production mode: its user is not its developer, and a Tracy
# bar over a finished application is noise. `make server debug=1` turns it on
# for working on the GUI itself — and it also thaws the DI container cache,
# which production deliberately freezes. Template edits show up either way.
debug =

# An installation needs neither Tester nor PHPStan, so `make install` and
# `make upgrade` leave them out. `make install dev=1` keeps them, which is what
# working on the GUI itself needs — without them `make test` has nothing to run.
dev =

# The docroot must be www/ (assets are served from there) and the router script
# is required: without it the built-in server would look for files by URL.
docroot = $(CURDIR)/www

# `server` stays first so that a bare `make` still starts the GUI.
.PHONY: server test phpstan install upgrade
server:
	@echo "GUI: http://$(host):$(port)  (the page header names the profile)$(if $(debug),  [debug])"
	@DONUT_HOME=$(home) DONUT_PROFILE=$(profile) DONUT_GUI_DEBUG=$(debug) $(php_bin) -S $(host):$(port) -t $(docroot) $(docroot)/index.php

test:
	@$(tester_bin) -p $(php_bin) -C $(tests_dir)

phpstan:
	@vendor/bin/phpstan analyse

install:
	@composer install $(if $(dev),,--no-dev)

# The compiled container and templates take care of themselves afterwards: the
# container is keyed on the mtimes of installed.php and config/common.neon,
# both of which this touches when anything changed, and Latte revalidates its
# own templates. A pulled change that adds a presenter is the exception —
# presenters are baked into the container, so that one needs the cache thrown
# away (`rm -rf ~/.cache/donut-ui`). It says so itself: the GUI reports that
# the presenter does not exist.
upgrade:
	@git pull && composer install $(if $(dev),,--no-dev)

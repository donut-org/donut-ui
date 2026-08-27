php_bin = php
tester_bin = vendor/bin/tester
tests_dir = tests/

# `make server` runs against the same profile the CLI would use:
# $DONUT_HOME/$DONUT_PROFILE, by default ~/.config/donut/default. An empty
# value is the same as unset, so leaving these blank hands the decision to
# Profile::fromEnvironment(). To browse the repository's own sample set:
#   make server home=$(CURDIR)/../docs/workflows profile=donut
home =
profile =
port = 8000

# The GUI runs in production mode: its user is not its developer, and a Tracy
# bar over a finished application is noise. `make server debug=1` turns it on
# for working on the GUI itself — and it also thaws the Latte and DI caches,
# which production deliberately freezes, so template edits show up.
debug =

# The docroot must be www/ (assets are served from there) and the router script
# is required: without it the built-in server would look for files by URL.
docroot = $(CURDIR)/www

.PHONY: server test phpstan
server:
	@echo "GUI: http://127.0.0.1:$(port)  (the page header names the profile)$(if $(debug),  [debug])"
	@DONUT_HOME=$(home) DONUT_PROFILE=$(profile) DONUT_GUI_DEBUG=$(debug) $(php_bin) -S 127.0.0.1:$(port) -t $(docroot) $(docroot)/index.php

test:
	@$(tester_bin) -p $(php_bin) -C $(tests_dir)

phpstan:
	@vendor/bin/phpstan analyse

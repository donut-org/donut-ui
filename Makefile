php_bin = php
tester_bin = vendor/bin/tester
tests_dir = tests/

# Where the GUI looks for blocks and workflows: $(home)/$(profile)/{blocks,workflows}.
# docs/workflows/ is effectively the profile root — donut/ inside it has both
# blocks/ and workflows/.
home = $(CURDIR)/../docs/workflows
profile = donut
port = 8000

# The docroot must be www/ (assets are served from there) and the router script
# is required: without it the built-in server would look for files by URL.
docroot = $(CURDIR)/www

.PHONY: server test phpstan
server:
	@echo "GUI: http://127.0.0.1:$(port)  nad profilem  $(profile)  ($(home)/$(profile))"
	@DONUT_HOME=$(home) DONUT_PROFILE=$(profile) $(php_bin) -S 127.0.0.1:$(port) -t $(docroot) $(docroot)/index.php

test:
	@$(tester_bin) -p $(php_bin) -C $(tests_dir)

phpstan:
	@vendor/bin/phpstan analyse

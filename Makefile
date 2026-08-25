php_bin = php
tester_bin = vendor/bin/tester
tests_dir = tests/

# Kde GUI hledá kameny a workflow: $(home)/$(profile)/{blocks,workflows}.
# docs/workflows/ je de facto kořen profilů — donut/ v něm má blocks/
# i workflows/.
home = $(CURDIR)/../docs/workflows
profile = donut
port = 8000

# Docroot musí být www/ (odtud se vydávají assety) a router script je povinný:
# bez něj by vestavěný server hledal soubory podle URL.
docroot = $(CURDIR)/www

.PHONY: server test phpstan
server:
	@echo "GUI: http://127.0.0.1:$(port)  nad profilem  $(profile)  ($(home)/$(profile))"
	@DONUT_HOME=$(home) DONUT_PROFILE=$(profile) $(php_bin) -S 127.0.0.1:$(port) -t $(docroot) $(docroot)/index.php

test:
	@$(tester_bin) -p $(php_bin) -C $(tests_dir)

phpstan:
	@vendor/bin/phpstan analyse

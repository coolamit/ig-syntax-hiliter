# Makefile for the iG:Syntax Hiliter plugin
#
# Everything runs inside the VVV VM. Nothing here runs on the host OS.
#
# Usage:
#   make install
#   make lint
#   make fix
#   make test
#   make build
#   make ssh-cmd -- composer --version
#   make ssh-cmd -- ls -alt
#   make lint-files -- classes/class-renderer.php

.PHONY: shell ssh-cmd install install-php install-js update versions lint fix lint-files fix-files test test-unit test-integration test-js build watch

# Path from this plugin directory up to the VVV root on the host OS.
VVV_ROOT_REL := ../../../../../..

# Runs a command inside the VVV VM, in this plugin's directory. The path below the VVV root is the
# same on the host and in the VM, so it is derived. nvm is sourced by hand because VVV's .bashrc
# returns early for non-interactive shells; `nvm use` then picks up .nvmrc.
define SSH_EXEC
VVV_ROOT="$$(cd $(VVV_ROOT_REL) && pwd)" && \
REL_PATH="$${PWD#$$VVV_ROOT/}" && \
cd "$$VVV_ROOT" && \
vagrant ssh -- -t 'cd "$$HOME/'"$$REL_PATH"'" && export NVM_DIR="$$HOME/.nvm" && [ -s "$$NVM_DIR/nvm.sh" ] && . "$$NVM_DIR/nvm.sh"; nvm use >/dev/null 2>&1; $(1)'
endef

# Open an interactive shell inside the VM, in the plugin directory.
shell:
	@$(call SSH_EXEC,exec bash)

# Run an arbitrary command inside the VM. Usage: make ssh-cmd -- composer --version
ssh-cmd:
	@$(call SSH_EXEC,$(filter-out $@,$(MAKECMDGOALS)))

# Install both PHP and JS dependencies.
install: install-php install-js

install-php:
	@$(call SSH_EXEC,composer install)

install-js:
	@$(call SSH_EXEC,npm ci)

# Update PHP dependencies to the latest allowed versions.
update:
	@$(call SSH_EXEC,composer update)

# Show the toolchain versions in use inside the VM.
versions:
	@$(call SSH_EXEC,php -v | head -1; composer --version; echo "node $$(node -v)"; echo "npm $$(npm -v)")

# Check code style against WordPress coding standards, without changing files.
lint:
	@$(call SSH_EXEC,composer run lint)

# Fix code style automatically where phpcbf is able to.
fix:
	@$(call SSH_EXEC,composer run fix)

# Check code style for specific paths. Usage: make lint-files -- classes/class-renderer.php
lint-files:
	@$(call SSH_EXEC,composer run lint-files $(filter-out $@,$(MAKECMDGOALS)))

# Fix code style for specific paths. Usage: make fix-files -- classes/class-renderer.php
fix-files:
	@$(call SSH_EXEC,composer run fix-files $(filter-out $@,$(MAKECMDGOALS)))

# Run the full test suite (both tiers).
test:
	@$(call SSH_EXEC,composer run test)

# Run the unit tier only: the domain core, no WordPress.
test-unit:
	@$(call SSH_EXEC,composer run test:unit)

# Run the WordPress integration tier only.
test-integration:
	@$(call SSH_EXEC,composer run test:integration)

# Run the JavaScript tier: the block editor's own code, in jsdom, no WordPress.
test-js:
	@$(call SSH_EXEC,npm run test:unit:js)

# Build the block editor assets for production.
build:
	@$(call SSH_EXEC,npm run build)

# Build the block editor assets and watch for changes.
watch:
	@$(call SSH_EXEC,npm start)

# Lets the guarded targets take arbitrary arguments after `--`, colons included.
ifneq (,$(filter $(firstword $(MAKECMDGOALS)),ssh-cmd lint-files fix-files))
%:
	@:
endif

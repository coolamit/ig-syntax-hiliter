# Makefile for the iG:Syntax Hiliter plugin
#
# Everything runs inside the VVV VM — PHP (composer, phpcs, phpunit) and Node
# (npm) alike. Nothing in this project is meant to be run on the host OS.
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

# Runs a command inside the VVV VM, in this plugin's directory.
#
# The path from the VVV root down to this plugin is the same on the host as it
# is below the vagrant user's home directory inside the VM, so it is derived
# instead of hardcoded — nothing here assumes what the plugin folder is called.
#
# nvm is sourced explicitly because VVV's .bashrc returns early for
# non-interactive shells, which is exactly what `vagrant ssh -- -t <command>`
# gets. Without this, node and npm are not on PATH. `nvm use` then picks up
# the version pinned in .nvmrc, if there is one.
define SSH_EXEC
VVV_ROOT="$$(cd $(VVV_ROOT_REL) && pwd)" && \
REL_PATH="$${PWD#$$VVV_ROOT/}" && \
cd "$$VVV_ROOT" && \
vagrant ssh -- -t 'cd "$$HOME/'"$$REL_PATH"'" && export NVM_DIR="$$HOME/.nvm" && [ -s "$$NVM_DIR/nvm.sh" ] && . "$$NVM_DIR/nvm.sh"; nvm use >/dev/null 2>&1; $(1)'
endef

# Open an interactive shell inside the VM, in the plugin directory.
# Usage: make shell
shell:
	@$(call SSH_EXEC,exec bash)

# Run an arbitrary command inside the VM.
# Usage: make ssh-cmd -- composer --version
#      : make ssh-cmd -- php -v
#      : make ssh-cmd -- ls -alt
ssh-cmd:
	@$(call SSH_EXEC,$(filter-out $@,$(MAKECMDGOALS)))

# Install both PHP and JS dependencies.
# Usage: make install
install: install-php install-js

install-php:
	@$(call SSH_EXEC,composer install)

install-js:
	@$(call SSH_EXEC,npm ci)

# Update PHP dependencies to the latest allowed versions.
# Usage: make update
update:
	@$(call SSH_EXEC,composer update)

# Show the toolchain versions in use inside the VM.
# Usage: make versions
versions:
	@$(call SSH_EXEC,php -v | head -1; composer --version; echo "node $$(node -v)"; echo "npm $$(npm -v)")

# Check code style against WordPress coding standards, without changing files.
# Usage: make lint
lint:
	@$(call SSH_EXEC,composer run lint)

# Fix code style automatically where phpcbf is able to.
# Usage: make fix
fix:
	@$(call SSH_EXEC,composer run fix)

# Check code style for specific files or directories.
# Usage: make lint-files -- classes/class-renderer.php classes/class-snippet.php
lint-files:
	@$(call SSH_EXEC,composer run lint-files $(filter-out $@,$(MAKECMDGOALS)))

# Fix code style for specific files or directories.
# Usage: make fix-files -- classes/class-renderer.php
fix-files:
	@$(call SSH_EXEC,composer run fix-files $(filter-out $@,$(MAKECMDGOALS)))

# Run the full test suite (both tiers).
# Usage: make test
test:
	@$(call SSH_EXEC,composer run test)

# Run the unit tier only — the domain core, no WordPress needed.
# Usage: make test-unit
test-unit:
	@$(call SSH_EXEC,composer run test:unit)

# Run the WordPress integration tier only.
# Usage: make test-integration
test-integration:
	@$(call SSH_EXEC,composer run test:integration)

# Run the JavaScript tier — the block editor's own code, in jsdom, no WordPress.
# Usage: make test-js
test-js:
	@$(call SSH_EXEC,npm run test:unit:js)

# Build the block editor assets for production.
# Usage: make build
build:
	@$(call SSH_EXEC,npm run build)

# Build the block editor assets and watch for changes.
# Usage: make watch
watch:
	@$(call SSH_EXEC,npm start)

# The wildcard rule below lets targets take arbitrary arguments after `--`,
# including ones with colons in them (eg. `make ssh-cmd -- composer run test:unit`).
# It is guarded so that it only kicks in for the targets that accept arguments.
ifneq (,$(filter $(firstword $(MAKECMDGOALS)),ssh-cmd lint-files fix-files))
%:
	@:
endif

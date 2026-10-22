COMPOSE := docker compose -f tests/docker/compose.db.yml
PHPUNIT := vendor/bin/phpunit
CACTI_REPO ?= $(HOME)/Developer/github.com/somethingwithproof/cacti
CACTI_REF ?= release/1.2.31
SMOKE := docker compose -p cf-smoke -f tests/docker/compose.cacti.yml

# test-integration and bdd share one test database, so a -j run would
# interleave their resets.
.NOTPARALLEL:

.PHONY: test test-integration bdd stan cs lint74 db-up db-down ci coverage package templates cacti-src smoke smoke-up smoke-down screenshots

test:
	$(PHPUNIT) --testsuite unit

test-integration:
	$(PHPUNIT) --testsuite integration

bdd:
	vendor/bin/behat --strict --no-interaction

stan:
	vendor/bin/phpstan analyse --no-progress --memory-limit=1G

# src/, tests/ and features/ use .php-cs-fixer.dist.php; the plugin glue
# follows Cacti's own rules in .php-cs-fixer.cacti.php.
cs:
	vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php
	vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.cacti.php

lint74:
	docker run --rm -v "$$PWD":/app -w /app php:7.4-cli sh -c \
		'find . -name vendor -prune -o -name .superpowers -prune -o -name "*.php" -print | xargs -n1 php -l >/dev/null'

db-up:
	$(COMPOSE) up -d --wait

db-down:
	$(COMPOSE) down -v

ci: test test-integration bdd stan cs lint74

# Needs pcov or xdebug and the test database; Sonar reads the clover file.
# phpunit.xml.dist names the measured files. pcov instruments only src/ by
# default, which left the glue at zero, so it is pointed at the whole tree.
coverage:
	php -d memory_limit=-1 -d pcov.directory=. -d 'pcov.exclude=~/(vendor|tests)/~' $(PHPUNIT) --coverage-clover build/coverage/clover.xml

package:
	bash tests/build/package.sh

# Regenerates the committed Cacti templates from the metric catalog;
# TemplatesTest fails when they drift.
templates:
	php tests/build/generate_templates.php templates

cacti-src:
	mkdir -p tests/docker/build
	git -C "$(CACTI_REPO)" archive --format=tar "$(CACTI_REF)" > tests/docker/build/cacti.tar.tmp
	mv tests/docker/build/cacti.tar.tmp tests/docker/build/cacti.tar

smoke: cacti-src
	bash tests/docker/smoke.sh

smoke-up: cacti-src
	$(SMOKE) up -d --build --wait
	@echo "Cacti is at http://127.0.0.1:$${CF_SMOKE_PORT:-8090}/cacti/ (admin / $${CF_SMOKE_ADMIN_PASS:-smoke-admin})"

smoke-down:
	$(SMOKE) down -v --remove-orphans

# Brings up the smoke-up stack, captures docs/images/*.png, and
# takes the stack down again whether or not the capture succeeds.
screenshots: smoke-up
	bash tests/docker/screenshots.sh || { $(SMOKE) down -v --remove-orphans; exit 1; }
	$(SMOKE) down -v --remove-orphans

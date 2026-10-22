COMPOSE := docker compose -f tests/docker/compose.db.yml
PHPUNIT := vendor/bin/phpunit
CACTI_REPO ?= $(HOME)/Developer/github.com/somethingwithproof/cacti
CACTI_REF ?= release/1.2.31
SMOKE := docker compose -p cf-smoke -f tests/docker/compose.cacti.yml

.PHONY: test test-integration bdd stan cs lint74 db-up db-down ci cacti-src smoke smoke-up smoke-down

test:
	$(PHPUNIT) --testsuite unit

test-integration:
	$(PHPUNIT) --testsuite integration

bdd:
	vendor/bin/behat --strict --no-interaction

stan:
	vendor/bin/phpstan analyse --no-progress --memory-limit=1G

cs:
	vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php

lint74:
	docker run --rm -v "$$PWD":/app -w /app php:7.4-cli sh -c \
		'find . -name vendor -prune -o -name .superpowers -prune -o -name "*.php" -print | xargs -n1 php -l >/dev/null'

db-up:
	$(COMPOSE) up -d --wait

db-down:
	$(COMPOSE) down -v

ci: test test-integration bdd stan cs lint74

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

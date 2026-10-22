COMPOSE := docker compose -f tests/docker/compose.db.yml
PHPUNIT := vendor/bin/phpunit

.PHONY: test test-integration bdd stan cs lint74 db-up db-down ci

test:
	$(PHPUNIT) --testsuite unit

test-integration:
	$(PHPUNIT) --testsuite integration

bdd:
	@if [ -f behat.yml ] || [ -f behat.yml.dist ]; then vendor/bin/behat --no-interaction; else echo 'no behat suite configured yet'; fi

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

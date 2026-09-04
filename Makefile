.PHONY: install
install:
	composer install

.PHONY: test
test:
	composer test

.PHONY: phpstan
phpstan:
	composer phpstan

.PHONY: ci
ci:
	composer ci

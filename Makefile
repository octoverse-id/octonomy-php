.DEFAULT_GOAL := help
.PHONY: help install test coverage cs cs-fix stan check version-check release-check

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

install: ## Install Composer dependencies
	composer install

test: ## Run the test suite
	vendor/bin/phpunit

coverage: ## Run tests with coverage (needs Xdebug or PCOV)
	vendor/bin/phpunit --coverage-text

cs: ## Check coding style (PSR-12 / PER-CS)
	vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## Apply coding style fixes
	vendor/bin/php-cs-fixer fix

stan: ## Run static analysis
	vendor/bin/phpstan analyse

check: cs stan test ## Fast pre-push gate (style, static analysis, tests)

# Before the first release CHANGELOG.md has only [Unreleased], so there is no heading to match and
# Client::VERSION must stay at this placeholder until the release PR replaces it (docs/versioning.md).
# release-check sets REQUIRE_RELEASE so a release cannot skip the check.
VERSION_PLACEHOLDER := 0.1.0

version-check: ## Verify Client::VERSION matches the latest released CHANGELOG.md heading
	@code_ver=$$(grep -E "const VERSION" src/Client.php | sed -E "s/.*'([^']+)'.*/\1/"); \
	log_ver=$$(grep -m1 -E '^## \[[0-9]' CHANGELOG.md | sed -E 's/^## \[([^]]+)\].*/\1/'); \
	if [ -z "$$code_ver" ]; then \
		echo "could not read Client::VERSION from src/Client.php"; exit 1; \
	fi; \
	if [ -z "$$log_ver" ]; then \
		if [ -n "$(REQUIRE_RELEASE)" ]; then \
			echo "no release heading in CHANGELOG.md: set Client::VERSION to the release version, then add a matching '## [X.Y.Z] - <date>' heading (docs/release.md)"; exit 1; \
		fi; \
		if [ "$$code_ver" != "$(VERSION_PLACEHOLDER)" ]; then \
			echo "version mismatch: Client::VERSION=$$code_ver but CHANGELOG.md has no release heading: keep the $(VERSION_PLACEHOLDER) placeholder outside a release PR, or add '## [$$code_ver] - <date>' in one (docs/release.md)"; exit 1; \
		fi; \
		echo "version OK: nothing released yet (Client::VERSION=$$code_ver is the placeholder)"; exit 0; \
	fi; \
	if [ "$$code_ver" != "$$log_ver" ]; then \
		echo "version mismatch: Client::VERSION=$$code_ver CHANGELOG.md=$$log_ver"; exit 1; \
	fi; \
	echo "version OK: $$code_ver"

release-check: cs stan test ## Full pre-release gate (requires a release heading in CHANGELOG.md)
	@$(MAKE) --no-print-directory version-check REQUIRE_RELEASE=1
	@echo "release-check passed"

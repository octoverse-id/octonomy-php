.DEFAULT_GOAL := help
.PHONY: help install test coverage cs cs-fix stan check version-check release-check \
	harness-up harness-down harness-logs test-integration fixtures php74

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

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

# PHPStan's default 128M per worker is not enough for this tree at level 8 (a single process already
# needs about 135M), and which worker crosses it depends on how the files happen to be split.
stan: ## Run static analysis
	vendor/bin/phpstan analyse --memory-limit=512M

check: cs stan test ## Fast pre-push gate (style, static analysis, tests)

# The integration harness: scripts/octonomy-harness.sh boots a real Octonomy (Postgres plus the
# digest-pinned GHCR image), seeds a global and namespaced corpus, and writes its credentials to
# .octonomy-harness.env. It needs Docker and curl. See docs/development.md.
harness-up: ## Boot and seed the Octonomy container harness, writing .octonomy-harness.env
	@scripts/octonomy-harness.sh up

harness-down: ## Remove the harness containers, network, and env file
	@scripts/octonomy-harness.sh down

harness-logs: ## Dump the harness container logs
	@scripts/octonomy-harness.sh logs

# Sources the harness env file when one exists, so the suite reaches a booted harness without the
# caller exporting anything. With no harness every test skips, which is the normal case on a laptop;
# CI sets OCTONOMY_INTEGRATION_REQUIRED=1 to turn that skip into a failure. --fail-on-empty-test-suite
# closes the other vacuous green: a suite that no longer selects any test exits 0 having asserted
# nothing.
test-integration: ## Run the integration suite against a booted harness (skips when none is up)
	@set -e; \
	env_file=$$(scripts/octonomy-harness.sh env); \
	case "$$env_file" in /*) ;; *) env_file="./$$env_file" ;; esac; \
	if [ -f "$$env_file" ]; then set -a; . "$$env_file"; set +a; fi; \
	vendor/bin/phpunit --testsuite integration --fail-on-empty-test-suite

# Re-captures tests/fixtures/captured, the response bodies the unit suite replays, from a real server.
# Capture writes, so it boots its own fresh harness (replacing a running one, as harness-up always
# does) and removes it afterwards, leaving nothing behind for the read-only integration suite to trip
# over. Commit the result in a PR of its own: a refresh is reviewed, never automatic. See
# docs/development.md.
fixtures: ## Re-capture the response fixtures from a fresh harness (replaces a running harness)
	@set -e; \
	trap 'scripts/octonomy-harness.sh down' EXIT; \
	scripts/octonomy-harness.sh up; \
	env_file=$$(scripts/octonomy-harness.sh env); \
	case "$$env_file" in /*) ;; *) env_file="./$$env_file" ;; esac; \
	set -a; . "$$env_file"; set +a; \
	php scripts/capture-fixtures.php

# The PHP 7.4 line (epic #4). scripts/php74-downgrade.sh writes the Rector-downgraded tree into a
# temp dir, and scripts/php74-check.sh proves it on a real PHP 7.4 in Docker: lint, @PSR12, PHPStan at
# phpVersion 70400, and PHPUnit 9.6 running exactly the tests main runs. CI's php74 job runs the same
# two scripts. The tree's dependencies are resolved on the host, for PHP 7.4.33 by compat/composer.json's
# platform setting. The image is pinned by digest, like the harness's.
PHP74_IMAGE := php:7.4-cli@sha256:620a6b9f4d4feef2210026172570465e9d0c1de79766418d3affd09190a7fda5

php74: ## Downgrade into a temp dir and prove it on PHP 7.4 (needs Docker)
	@set -e; \
	work=$$(mktemp -d); trap 'rm -rf "$$work"' EXIT; \
	composer install --working-dir=tools/rector --no-interaction --no-progress --quiet; \
	scripts/php74-downgrade.sh "$$work/tree"; \
	vendor/bin/phpunit --log-junit "$$work/main-junit.xml"; \
	composer install --working-dir="$$work/tree" --no-interaction --no-progress --quiet; \
	docker run --rm --user "$$(id -u):$$(id -g)" -v "$$PWD/scripts:/scripts:ro" -v "$$work:/work" \
		$(PHP74_IMAGE) /scripts/php74-check.sh /work/tree /work/main-junit.xml

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

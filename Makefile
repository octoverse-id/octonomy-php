# The 1.x line's Makefile (support/php7.4, #29). main's minus the targets that only main runs: php74
# (this tree is the downgrade, generated once and never regenerated) and fixtures (the captured corpus
# is main's, carried across like any fix). Everything here runs on PHP 7.4 or later.
.DEFAULT_GOAL := help
.PHONY: help install test coverage cs cs-fix stan check version-check release-check \
	release-line-guard release-line-guard-test \
	harness-up harness-down harness-logs test-integration

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-24s\033[0m %s\n", $$1, $$2}'

install: ## Install Composer dependencies
	composer install

test: ## Run the test suite
	vendor/bin/phpunit

coverage: ## Run tests with coverage (needs Xdebug or PCOV)
	vendor/bin/phpunit --coverage-text

cs: ## Check coding style (PSR-12)
	vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## Apply coding style fixes
	vendor/bin/php-cs-fixer fix

# PHPStan's default 128M per worker is not enough for this tree at level 8 (a single process already
# needs about 135M), and which worker crosses it depends on how the files happen to be split.
stan: ## Run static analysis
	vendor/bin/phpstan analyse --memory-limit=512M

check: cs stan test release-line-guard release-line-guard-test ## Fast pre-push gate (style, static analysis, tests, release-line guard)

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
# CI sets OCTONOMY_INTEGRATION_REQUIRED=1 to turn that skip into a failure. The JUnit check closes the
# other vacuous green, a suite that no longer selects any test and exits 0 having asserted nothing:
# main passes PHPUnit 11's --fail-on-empty-test-suite, which PHPUnit 9 does not have.
test-integration: ## Run the integration suite against a booted harness (skips when none is up)
	@set -e; \
	env_file=$$(scripts/octonomy-harness.sh env); \
	case "$$env_file" in /*) ;; *) env_file="./$$env_file" ;; esac; \
	if [ -f "$$env_file" ]; then set -a; . "$$env_file"; set +a; fi; \
	junit=$$(mktemp); trap 'rm -f "$$junit"' EXIT; \
	vendor/bin/phpunit --testsuite integration --log-junit "$$junit"; \
	grep -q 'tests="[1-9]' "$$junit" || { echo "the integration suite selected no tests"; exit 1; }

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

# The release-line guard (#30, docs/release.md): asserts that composer.json's php constraint and
# Client::VERSION fit the branch, the release PR and the tag, so a 2.x tag can never publish the 7.4
# line's tree, nor a 1.x tag main's. CI's release-line workflow runs both targets on every PR into a
# line, every push to one, and every tag push. TAG=vX.Y.Z runs the tag checks against the checked-out
# commit, which is the step before `git push origin vX.Y.Z` in the runbook.
release-line-guard: ## Assert this tree fits its release line (TAG=vX.Y.Z: check a tag before pushing it)
	@scripts/release-line-guard.sh $(if $(TAG),--tag $(TAG))

release-line-guard-test: ## Run the release-line guard's fixture tests
	@scripts/release-line-guard-test.sh

release-check: cs stan test release-line-guard release-line-guard-test ## Full pre-release gate (requires a release heading in CHANGELOG.md)
	@$(MAKE) --no-print-directory version-check REQUIRE_RELEASE=1
	@echo "release-check passed"

<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * scripts/octonomy-dev-server, the package's Composer bin (#13), without Docker.
 *
 * A fake `docker` first on PATH stands in for the daemon: it records every call,
 * reports the dev server's two containers as running when FAKE_DOCKER_RUNNING is
 * set, or as existing but stopped when FAKE_DOCKER_STOPPED is, accepts the
 * removals `down` makes, and fails anything else, `docker info` included, as an
 * unreachable daemon would. So `run` either reaches the script it
 * was given or proves it tried to boot, and no test touches a real container.
 * Booting a real server is the integration job's.
 *
 * Every child gets an environment of its own rather than this process's, so a
 * harness sourced into the developer's shell cannot leak into an assertion.
 */
final class DevServerTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        $base = realpath(sys_get_temp_dir());
        self::assertIsString($base);
        // The real path, so the paths the bin prints and the ones built here agree.
        $this->work = $base . '/octonomy-dev-server-test-' . bin2hex(random_bytes(6));
        mkdir($this->work . '/fake-bin', 0777, true);

        file_put_contents($this->work . '/fake-bin/docker', <<<'SH'
            #!/bin/sh
            printf '%s\n' "$*" >> "$FAKE_DOCKER_LOG"
            case "$1" in
                ps)
                    case " $* " in
                        *' status=running '*) [ -n "${FAKE_DOCKER_RUNNING:-}" ] || exit 0 ;;
                        *) [ -n "${FAKE_DOCKER_RUNNING:-}${FAKE_DOCKER_STOPPED:-}" ] || exit 0 ;;
                    esac
                    for arg in "$@"; do
                        case "$arg" in
                            name=*) name=${arg#name=^}; printf '%s\n' "${name%\$}" ;;
                        esac
                    done
                    ;;
                rm | network) ;;
                *) exit 1 ;;
            esac

            SH);
        // The PHP running this suite, whatever `php` on PATH would be.
        file_put_contents($this->work . '/fake-bin/php', "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . " \"\$@\"\n");
        chmod($this->work . '/fake-bin/docker', 0755);
        chmod($this->work . '/fake-bin/php', 0755);
    }

    protected function tearDown(): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->work, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->work);
    }

    public function testRunGivesTheScriptTheHarnessCredentialsUnderTheirSdkNames(): void
    {
        $this->writeEnvFile($this->work . '/dev.env', [
            'OCTONOMY_TEST_BASE_URL=http://127.0.0.1:8123',
            'OCTONOMY_TEST_TOKEN=octo_abc_def',
            'OCTONOMY_TEST_TENANT_ID=harness-tenant',
            'OCTONOMY_TEST_NAMESPACE_A_TOKEN=octo_ns_a',
            // Read, never sourced: this would create the file if the bin ran it.
            'OCTONOMY_TEST_UNQUOTED=$(touch ' . $this->work . '/ran)',
            'NOT_OCTONOMY=1',
        ]);
        $this->writeProbe($this->work . '/probe.php');

        [$code, $out] = $this->devServer(self::checkoutBin(), ['run', 'probe.php', '--flag', 'two words'], $this->work, [
            'OCTONOMY_HARNESS_ENV_FILE' => $this->work . '/dev.env',
            'OCTONOMY_HARNESS_PREFIX' => 'octonomy-php-dev-server-test',
            'FAKE_DOCKER_RUNNING' => '1',
            // A shell that sourced the integration harness's env file holds these.
            'OCTONOMY_TEST_BASE_URL' => 'http://127.0.0.1:9999',
            'OCTONOMY_TEST_NAMESPACE_B_TOKEN' => 'octo_integration_b',
        ]);

        self::assertSame(3, $code, 'the script\'s own exit status');
        $probe = self::decode($out);
        self::assertSame([
            'OCTONOMY_BASE_URL' => 'http://127.0.0.1:8123',
            'OCTONOMY_TOKEN' => 'octo_abc_def',
            'OCTONOMY_TENANT_ID' => 'harness-tenant',
            'OCTONOMY_NAMESPACE_A_TOKEN' => 'octo_ns_a',
            'OCTONOMY_UNQUOTED' => '$(touch ' . $this->work . '/ran)',
            // The integration suite's gate stays closed for anything the script runs,
            // whether the name came from the file or from the caller's shell.
            'OCTONOMY_TEST_BASE_URL' => false,
            'OCTONOMY_TEST_NAMESPACE_B_TOKEN' => false,
            'NOT_OCTONOMY' => false,
        ], $probe['env']);
        self::assertFileDoesNotExist($this->work . '/ran');
        self::assertSame(['--flag', 'two words'], $probe['argv']);
        self::assertTrue($probe['sdk'], 'the checkout\'s own vendor/autoload.php was loaded before the script');
        // A running server is reused, not rebooted.
        self::assertSame([
            'ps --filter name=^octonomy-php-dev-server-test-db$ --filter status=running --format {{.Names}}',
            'ps --filter name=^octonomy-php-dev-server-test-app$ --filter status=running --format {{.Names}}',
        ], $this->dockerCalls());
    }

    public function testRunBootsTheServerWhenNoneIsRunning(): void
    {
        $this->writeProbe($this->work . '/probe.php');

        [$code, $out, $err] = $this->devServer(self::checkoutBin(), ['run', 'probe.php'], $this->work, [
            'OCTONOMY_HARNESS_ENV_FILE' => $this->work . '/dev.env',
            'OCTONOMY_HARNESS_PREFIX' => 'octonomy-php-dev-server-test',
        ]);

        self::assertSame(1, $code);
        self::assertSame('', $out, 'the script never ran');
        self::assertStringContainsString('no dev server is running; booting one', $err);
        // The fake daemon refuses `docker info`, which is as far as `up` gets.
        self::assertStringContainsString('harness: ERROR: the docker daemon is not reachable', $err);
        self::assertContains('info', $this->dockerCalls());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function serversHeldElsewhere(): iterable
    {
        yield 'running' => [['FAKE_DOCKER_RUNNING' => '1']];
        // Stopped, say by a reboot, it still holds that project's data and tokens.
        yield 'stopped' => [['FAKE_DOCKER_STOPPED' => '1']];
    }

    /**
     * Containers under the prefix with no env file of this installation's: a dev
     * server another project booted, whose env file sits in that project's
     * package directory. Booting here would destroy it and leave that project's
     * tokens rejected as invalid by the replacement.
     *
     * @param array<string, string> $docker
     */
    #[DataProvider('serversHeldElsewhere')]
    public function testRunRefusesToBootOverAServerHeldByAnotherInstallation(array $docker): void
    {
        $this->writeProbe($this->work . '/probe.php');

        [$code, $out, $err] = $this->devServer(self::checkoutBin(), ['run', 'probe.php'], $this->work, [
            'OCTONOMY_HARNESS_ENV_FILE' => $this->work . '/dev.env',
        ] + $docker);

        self::assertSame(1, $code);
        self::assertSame('', $out, 'the script never ran');
        self::assertStringContainsString(
            "ERROR: a dev server named octonomy-php-dev-server already exists, but this installation's env file {$this->work}/dev.env does not",
            $err,
        );
        self::assertStringContainsString('Run down where it was booted', $err);
        self::assertNotContains('info', $this->dockerCalls(), 'nothing booted');
    }

    /**
     * @param array<string, string> $docker
     */
    #[DataProvider('serversHeldElsewhere')]
    public function testUpRefusesAServerHeldByAnotherInstallation(array $docker): void
    {
        [$code, , $err] = $this->devServer(self::checkoutBin(), ['up'], $this->work, [
            'OCTONOMY_HARNESS_ENV_FILE' => $this->work . '/dev.env',
        ] + $docker);

        self::assertSame(1, $code);
        self::assertStringContainsString('already exists, but this installation\'s env file', $err);
        self::assertNotContains('info', $this->dockerCalls(), 'nothing booted');
    }

    public function testUpReplacesItsOwnServer(): void
    {
        $this->writeEnvFile($this->work . '/dev.env', ['OCTONOMY_TEST_BASE_URL=http://127.0.0.1:8123']);

        [$code, , $err] = $this->devServer(self::checkoutBin(), ['up'], $this->work, [
            'OCTONOMY_HARNESS_ENV_FILE' => $this->work . '/dev.env',
            'FAKE_DOCKER_RUNNING' => '1',
        ]);

        // `up` went ahead, as far as the fake daemon lets it.
        self::assertSame(1, $code);
        self::assertStringContainsString('harness: ERROR: the docker daemon is not reachable', $err);
        self::assertContains('info', $this->dockerCalls());
    }

    public function testRunInAComposerProjectUsesTheProjectAutoloaderAndTheSdkExample(): void
    {
        $project = $this->composerProject();
        $package = $project . '/vendor/octoverse-id/octonomy-php';
        $this->writeProbe($package . '/examples/probe.php');
        // Where the env file lives when nothing overrides it: the package directory.
        $this->writeEnvFile($package . '/.octonomy-dev-server.env', ['OCTONOMY_TEST_BASE_URL=http://127.0.0.1:8124']);

        // Through the vendor/bin symlink, from the project root, which has no examples/ of its own.
        [$code, $out, $err] = $this->devServer($project . '/vendor/bin/octonomy-dev-server', ['run', 'examples/probe.php'], $project, [
            'FAKE_DOCKER_RUNNING' => '1',
        ]);

        self::assertSame(3, $code, $err);
        $probe = self::decode($out);
        self::assertSame($project . '/vendor/autoload.php', $probe['autoload']);
        self::assertSame('http://127.0.0.1:8124', $probe['env']['OCTONOMY_BASE_URL']);
        self::assertStringContainsString("running the SDK's own examples/probe.php ({$package}/examples/probe.php)", $err);
        self::assertSame([
            'ps --filter name=^octonomy-php-dev-server-db$ --filter status=running --format {{.Names}}',
            'ps --filter name=^octonomy-php-dev-server-app$ --filter status=running --format {{.Names}}',
        ], $this->dockerCalls());
    }

    public function testRunRefusesAMissingScriptBeforeBooting(): void
    {
        [$code, $out, $err] = $this->devServer(self::checkoutBin(), ['run', 'missing.php'], $this->work, [
            'OCTONOMY_HARNESS_ENV_FILE' => $this->work . '/dev.env',
        ]);

        self::assertSame(1, $code);
        self::assertSame('', $out);
        self::assertStringContainsString('ERROR: no such script: missing.php', $err);
        self::assertSame([], $this->dockerCalls());
    }

    public function testRunRefusesALayoutWithoutAnAutoloaderBeforeBooting(): void
    {
        $package = $this->work . '/a/b/octonomy-php';
        mkdir($package . '/scripts', 0777, true);
        $this->copyScripts($package);
        $this->writeProbe($this->work . '/probe.php');

        [$code, , $err] = $this->devServer($package . '/scripts/octonomy-dev-server', ['run', 'probe.php'], $this->work);

        self::assertSame(1, $code);
        self::assertStringContainsString('ERROR: no Composer autoloader', $err);
        self::assertStringContainsString('run composer install first', $err);
        self::assertSame([], $this->dockerCalls());
    }

    public function testRunRefusesAnEnvFileWithoutABaseUrl(): void
    {
        // Run anyway, the script would fall back to its own default server and token.
        $this->writeEnvFile($this->work . '/dev.env', ['OCTONOMY_TEST_TOKEN=octo_abc_def']);
        $this->writeProbe($this->work . '/probe.php');

        [$code, $out, $err] = $this->devServer(self::checkoutBin(), ['run', 'probe.php'], $this->work, [
            'OCTONOMY_HARNESS_ENV_FILE' => $this->work . '/dev.env',
            'FAKE_DOCKER_RUNNING' => '1',
        ]);

        self::assertSame(1, $code);
        self::assertSame('', $out);
        self::assertStringContainsString('has no OCTONOMY_TEST_BASE_URL', $err);
    }

    public function testDownRemovesTheDevServerAndNeverTheIntegrationHarness(): void
    {
        $this->writeEnvFile($this->work . '/dev.env', ['OCTONOMY_TEST_BASE_URL=http://127.0.0.1:8123']);

        [$code] = $this->devServer(self::checkoutBin(), ['down'], $this->work, [
            'OCTONOMY_HARNESS_ENV_FILE' => $this->work . '/dev.env',
        ]);

        self::assertSame(0, $code);
        self::assertFileDoesNotExist($this->work . '/dev.env');
        self::assertSame([
            'rm -f octonomy-php-dev-server-app',
            'rm -f octonomy-php-dev-server-db',
            'network rm octonomy-php-dev-server-net',
        ], $this->dockerCalls());
    }

    public function testEnvNamesAFileInThePackageDirectoryFromAnyDirectory(): void
    {
        [$code, $out] = $this->devServer(self::checkoutBin(), ['env'], $this->work);

        self::assertSame(0, $code);
        $path = trim($out);
        self::assertSame('.octonomy-dev-server.env', basename($path));
        self::assertSame(realpath(dirname(__DIR__)), realpath(dirname($path)));
    }

    public function testAnUnknownCommandPrintsTheUsage(): void
    {
        [$code, , $err] = $this->devServer(self::checkoutBin(), ['bogus'], $this->work);

        self::assertSame(2, $code);
        self::assertStringContainsString("unknown command 'bogus'", $err);
        self::assertStringContainsString('usage: octonomy-dev-server <command>', $err);
    }

    /**
     * `run` reads the env file line by line instead of sourcing it, which holds
     * only while the harness writes every credential as a bare
     * OCTONOMY_TEST_<NAME>=<value> line; the integration suite reads those names.
     */
    public function testTheHarnessWritesEachCredentialUnquotedUnderItsTestName(): void
    {
        $harness = file_get_contents(dirname(__DIR__) . '/scripts/octonomy-harness.sh');
        self::assertIsString($harness);
        if (preg_match('/cat > "\$ENV_FILE" <<ENV_EOF\n(.*?)\nENV_EOF\n/s', $harness, $heredoc) !== 1) {
            self::fail('could not find the env file heredoc in scripts/octonomy-harness.sh');
        }

        $names = [];
        foreach (explode("\n", $heredoc[1]) as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            self::assertSame(1, preg_match('/^OCTONOMY_TEST_([A-Z0-9_]+)=\$\{[A-Z_]+\}$/', $line), $line);
            $names[] = substr($line, strlen('OCTONOMY_TEST_'), (int) strpos($line, '=') - strlen('OCTONOMY_TEST_'));
        }
        foreach (['BASE_URL', 'TOKEN', 'TENANT_ID'] as $required) {
            self::assertContains($required, $names);
        }
    }

    private static function checkoutBin(): string
    {
        return dirname(__DIR__) . '/scripts/octonomy-dev-server';
    }

    /**
     * A project that installed the SDK with Composer: the package under vendor/,
     * reached through a vendor/bin symlink, beside an autoloader of the
     * project's own that says when it loads. The harness keeps no executable
     * bit, as when an archive is unpacked without file modes.
     */
    private function composerProject(): string
    {
        $project = $this->work . '/project';
        $package = $project . '/vendor/octoverse-id/octonomy-php';
        mkdir($package . '/scripts', 0777, true);
        mkdir($package . '/examples');
        mkdir($project . '/vendor/composer');
        mkdir($project . '/vendor/bin');
        $this->copyScripts($package);
        chmod($package . '/scripts/octonomy-harness.sh', 0644);
        file_put_contents($project . '/vendor/autoload.php', "<?php\n\ndefine('PROJECT_AUTOLOAD', __FILE__);\n");
        file_put_contents($project . '/vendor/composer/installed.json', '{"packages": []}');
        symlink('../octoverse-id/octonomy-php/scripts/octonomy-dev-server', $project . '/vendor/bin/octonomy-dev-server');

        return $project;
    }

    private function copyScripts(string $package): void
    {
        foreach (['octonomy-dev-server', 'octonomy-harness.sh'] as $script) {
            copy(dirname(__DIR__) . '/scripts/' . $script, $package . '/scripts/' . $script);
            chmod($package . '/scripts/' . $script, 0755);
        }
    }

    /**
     * @param list<string> $lines
     */
    private function writeEnvFile(string $path, array $lines): void
    {
        file_put_contents($path, "# Generated by scripts/octonomy-harness.sh -- do not commit.\n" . implode("\n", $lines) . "\n");
    }

    /**
     * A script that reports what it was run with, and exits 3 so the exit status
     * it hands back is its own.
     */
    private function writeProbe(string $path): void
    {
        file_put_contents($path, <<<'PHP'
            <?php
            $env = [];
            foreach (['OCTONOMY_BASE_URL', 'OCTONOMY_TOKEN', 'OCTONOMY_TENANT_ID', 'OCTONOMY_NAMESPACE_A_TOKEN',
                'OCTONOMY_UNQUOTED', 'OCTONOMY_TEST_BASE_URL', 'OCTONOMY_TEST_NAMESPACE_B_TOKEN', 'NOT_OCTONOMY'] as $name) {
                $env[$name] = getenv($name);
            }
            echo json_encode([
                'env' => $env,
                'argv' => array_slice($argv, 1),
                'sdk' => class_exists('Octoverse\Octonomy\Client'),
                'autoload' => defined('PROJECT_AUTOLOAD') ? constant('PROJECT_AUTOLOAD') : null,
            ]);
            exit(3);

            PHP);
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $env
     *
     * @return array{int, string, string} the exit status, stdout and stderr
     */
    private function devServer(string $bin, array $args, string $cwd, array $env = []): array
    {
        $out = $this->work . '/stdout';
        $err = $this->work . '/stderr';
        $process = proc_open(
            array_merge([$bin], $args),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']],
            $pipes,
            $cwd,
            $env + [
                'PATH' => $this->work . '/fake-bin:' . (string) getenv('PATH'),
                'FAKE_DOCKER_LOG' => $this->work . '/docker.log',
            ],
        );
        if ($process === false) {
            self::fail('could not start ' . $bin);
        }
        $code = proc_close($process);

        return [$code, (string) file_get_contents($out), (string) file_get_contents($err)];
    }

    /**
     * @return list<string>
     */
    private function dockerCalls(): array
    {
        $log = $this->work . '/docker.log';

        return is_file($log) ? explode("\n", trim((string) file_get_contents($log))) : [];
    }

    /**
     * @return array<mixed> what the probe reports: env, argv, sdk and autoload
     */
    private static function decode(string $out): array
    {
        $probe = json_decode($out, true);
        self::assertIsArray($probe, 'the probe printed no JSON: ' . $out);

        return $probe;
    }
}

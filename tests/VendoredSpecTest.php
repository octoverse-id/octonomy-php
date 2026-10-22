<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests;

use Octoverse\Octonomy\Tests\Fixture\CapturedFixture;
use Octoverse\Octonomy\Tests\Fixture\VendoredSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Holds the vendored OpenAPI specs to what they claim to be (#21): each one is
 * the server's own file, byte for byte below a comment header, both come from
 * the server release the harness pins (the one the fixtures are captured from),
 * docs/versioning.md names it, and each header records where the spec and the
 * server disagree.
 *
 * This suite checks the files against themselves and the pin. That the bodies
 * really are what that server serves is checked against the running harness, in
 * Integration\VendoredSpecMatchesServerTest.
 *
 * The specs are a reference, never the source of truth: nothing in the SDK
 * reads them, and the last test keeps it that way.
 */
final class VendoredSpecTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function apiVersions(): iterable
    {
        yield 'v1' => ['v1'];
        yield 'v2' => ['v2'];
    }

    #[DataProvider('apiVersions')]
    public function testTheBodyIsTheServerFileTheHeaderNames(string $apiVersion): void
    {
        $spec = VendoredSpec::load($apiVersion);

        self::assertStringContainsString("for api_version {$apiVersion} (/api/{$apiVersion})", $spec->header);
        self::assertNotNull($spec->commit(), "{$spec->file}'s header names no server commit");
        self::assertSame($spec->recordedSha256(), hash('sha256', $spec->body), "{$spec->file} was edited below its header: re-vendor it from the server tag instead");
        self::assertNotNull($spec->tag(), "{$spec->file}'s header names no server tag");
        self::assertSame(ltrim((string) $spec->tag(), 'v'), $spec->infoVersion(), "{$spec->file}'s info.version is not the tag its header names");

        preg_match_all('/^  (\/\S*):$/m', $spec->body, $paths);
        self::assertNotSame([], $paths[1]);
        foreach ($paths[1] as $path) {
            self::assertMatchesRegularExpression("#^/(api/{$apiVersion}|health)/#", $path, "{$spec->file} declares a path of another surface");
        }
    }

    /**
     * One server release backs the harness, the captured fixtures and both
     * specs, so moving the harness pin without re-vendoring fails here, before
     * the integration suite ever runs.
     */
    public function testBothSpecsTrackTheServerReleaseTheHarnessPins(): void
    {
        $pinned = 'v' . CapturedFixture::manifest()['server']['version'];
        $v1 = VendoredSpec::load('v1');
        $v2 = VendoredSpec::load('v2');

        self::assertSame($pinned, $v1->tag(), 'docs/openapi.yaml must come from the server release the harness pins');
        self::assertSame($pinned, $v2->tag(), 'docs/openapi-v2.yaml must come from the server release the harness pins');
        self::assertSame($v1->commit(), $v2->commit(), 'the two specs must come from the same server commit');

        $versioning = file_get_contents(__DIR__ . '/../docs/versioning.md');
        self::assertIsString($versioning);
        self::assertStringContainsString("`{$pinned}`", $versioning, 'docs/versioning.md must name the tag the specs track');
    }

    #[DataProvider('apiVersions')]
    public function testTheHeaderRecordsEveryKnownDivergence(string $apiVersion): void
    {
        $spec = VendoredSpec::load($apiVersion);

        foreach ([
            'KNOWN DIVERGENCES',
            '1. Neither response envelope is modelled',
            "2. Assignment's required list omits namespace_type and namespace_id",
            '3. POST /tag-assignments/bulk-assign is declared as an array',
            'POST /resources/{resource_type}/{resource_id}/tags',
            '4. POST /tag-assignments/bulk-remove declares no 200 body',
            "5. Other required lists leave out fields the server always sends: Assignment's assigned_by",
            '6. Tag lists parent_id and vocabulary_id as required',
            '7. GET /health/live and /health/ready declare no body',
        ] as $item) {
            self::assertStringContainsString($item, $spec->header, "{$spec->file}'s divergence note lost an item");
        }
    }

    /**
     * Required-ness comes from the models' constructors and the captured
     * fixtures (#14), never from a spec, which gets the envelopes and several
     * fields wrong. So no PHP the package ships may even name one. (Tooling
     * may: the PHP 7.4 downgrade copies them, which reads nothing.)
     */
    public function testNoSdkCodeReadsTheSpecs(): void
    {
        $offenders = [];
        foreach (['src', 'examples'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . "/../{$dir}", \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $path) {
                \assert($path instanceof \SplFileInfo);
                $source = file_get_contents($path->getPathname());
                if ($source !== false && preg_match('/openapi(-v2)?\.ya?ml/i', $source) === 1) {
                    $offenders[] = "{$dir}/" . $path->getFilename();
                }
            }
        }

        self::assertSame([], $offenders);
    }
}

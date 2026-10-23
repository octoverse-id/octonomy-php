<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests\Exception;

use Octoverse\Octonomy\ErrorCode;
use Octoverse\Octonomy\Exception\AmbiguousResolutionException;
use Octoverse\Octonomy\Exception\ApiException;
use Octoverse\Octonomy\Exception\AuthenticationException;
use Octoverse\Octonomy\Exception\ConflictException;
use Octoverse\Octonomy\Exception\FeatureDisabledException;
use Octoverse\Octonomy\Exception\ForbiddenException;
use Octoverse\Octonomy\Exception\NotFoundException;
use Octoverse\Octonomy\Exception\UnsupportedApiVersionException;
use Octoverse\Octonomy\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers ApiException::classFor(), the table from an error's code and HTTP
 * status to the exception class a caller catches.
 *
 * The table is SDK behavior, so it is tested on (code, status) pairs with no
 * response body. What the server actually sends is proven separately:
 * ClientTest::errorProvider() replays a captured envelope for nine of the
 * fourteen codes. Of the other five, namespace_invalid and scope_immutable are
 * reachable on the pinned harness but not captured yet, since a fixture change
 * ships in a PR of its own (the integration suite reaches namespace_invalid
 * meanwhile); namespaced_writes_disabled and namespace_api_disabled need the
 * namespace kill-switches the harness keeps on; and server 3.2.1 has no HTTP
 * request that reaches ambiguous_resolution (see AmbiguousResolutionException).
 * Their codes and statuses are read from octonomy/core/errors.py at that
 * release.
 */
final class ApiExceptionTest extends TestCase
{
    /**
     * @param class-string<ApiException> $expected
     */
    #[DataProvider('mappings')]
    public function testClassForMapsACodeAndStatusToItsException(string $code, int $status, string $expected): void
    {
        self::assertSame($expected, ApiException::classFor($code, $status));
    }

    /**
     * @return iterable<string, array{string, int, class-string<ApiException>}>
     */
    public static function mappings(): iterable
    {
        // Every code the server declares, on the status it sends it with.
        yield 'validation_error' => [ErrorCode::VALIDATION, 400, ValidationException::class];
        yield 'tenant_mismatch' => [ErrorCode::TENANT_MISMATCH, 400, ValidationException::class];
        yield 'application_mismatch' => [ErrorCode::APPLICATION_MISMATCH, 400, ValidationException::class];
        yield 'inactive_tag' => [ErrorCode::INACTIVE_TAG, 400, ValidationException::class];
        yield 'namespace_invalid' => [ErrorCode::NAMESPACE_INVALID, 400, ValidationException::class];
        yield 'namespace_not_supported' => [ErrorCode::NAMESPACE_NOT_SUPPORTED, 400, UnsupportedApiVersionException::class];
        yield 'ambiguous_resolution' => [ErrorCode::AMBIGUOUS_RESOLUTION, 400, AmbiguousResolutionException::class];
        // The server answers a bad token with 403, sending no WWW-Authenticate challenge.
        yield 'authentication_required' => [ErrorCode::AUTH_REQUIRED, 403, AuthenticationException::class];
        yield 'forbidden' => [ErrorCode::FORBIDDEN, 403, ForbiddenException::class];
        yield 'namespaced_writes_disabled' => [ErrorCode::NAMESPACED_WRITES_DISABLED, 403, FeatureDisabledException::class];
        yield 'not_found' => [ErrorCode::NOT_FOUND, 404, NotFoundException::class];
        yield 'conflict' => [ErrorCode::CONFLICT, 409, ConflictException::class];
        yield 'scope_immutable' => [ErrorCode::SCOPE_IMMUTABLE, 409, ConflictException::class];
        yield 'namespace_api_disabled' => [ErrorCode::NAMESPACE_API_DISABLED, 503, FeatureDisabledException::class];

        // A code the SDK does not know, such as one a newer server added: the
        // status picks the class, and only for the five statuses that name one
        // fault.
        yield 'unknown code on 400' => ['a_newer_code', 400, ValidationException::class];
        yield 'unknown code on 401' => ['a_newer_code', 401, AuthenticationException::class];
        yield 'unknown code on 403' => ['a_newer_code', 403, ForbiddenException::class];
        yield 'unknown code on 404' => ['a_newer_code', 404, NotFoundException::class];
        yield 'unknown code on 409' => ['a_newer_code', 409, ConflictException::class];
        yield 'unknown code on 503' => ['a_newer_code', 503, ApiException::class];

        // No envelope at all, so fromResponse() names the code http_<status>.
        // A 503 like that is a proxy or load balancer, not the
        // namespace_api_disabled flag, so it is not a FeatureDisabledException.
        yield 'no envelope, 503' => ['http_503', 503, ApiException::class];
        yield 'no envelope, 502' => ['http_502', 502, ApiException::class];
        yield 'no envelope, 500' => ['http_500', 500, ApiException::class];

        // The code decides before the status does.
        yield 'a known code on an unexpected status' => [ErrorCode::NAMESPACE_API_DISABLED, 500, FeatureDisabledException::class];
        yield 'a known code on another fault\'s status' => [ErrorCode::NAMESPACED_WRITES_DISABLED, 400, FeatureDisabledException::class];
    }

    /**
     * No ErrorCode constant may fall through to the bare ApiException, whatever
     * status it arrives on, so adding a code without mapping it fails here.
     */
    public function testEveryDeclaredCodeReachesATypedException(): void
    {
        $codes = (new \ReflectionClass(ErrorCode::class))->getConstants();
        self::assertCount(14, $codes, 'ErrorCode mirrors the 14 codes octonomy/core/errors.py declares at server 3.2.1');

        foreach ($codes as $name => $code) {
            self::assertIsString($code);
            foreach ([400, 403, 409, 500, 503] as $status) {
                $class = ApiException::classFor($code, $status);
                self::assertNotSame(ApiException::class, $class, "ErrorCode::{$name} on {$status} falls through to the bare ApiException");
                self::assertTrue(is_subclass_of($class, ApiException::class));
            }
        }
    }

    /**
     * A caller tells a deployment flag from a missing grant by type alone,
     * without reading $errorCode or the message.
     */
    public function testAFeatureFlagIsNotAnAuthorizationFailure(): void
    {
        $flag = ApiException::classFor(ErrorCode::NAMESPACED_WRITES_DISABLED, 403);
        $grant = ApiException::classFor(ErrorCode::FORBIDDEN, 403);

        self::assertNotSame($grant, $flag);
        self::assertFalse(is_a($flag, ForbiddenException::class, true));
        self::assertFalse(is_a($grant, FeatureDisabledException::class, true));
    }
}

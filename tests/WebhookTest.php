<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests;

use Octoverse\Octonomy\Webhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Webhook::verify() (#26), driven by the signature vectors octonomy-go generates
 * from the server's own signing code (tests/fixtures/webhook/README.md says
 * where they come from and how they are bound here). No digest in this file is
 * computed by the code under test.
 */
final class WebhookTest extends TestCase
{
    /** The vendored vector file's SHA-256: a refresh changes it here, in review. */
    private const VECTORS_SHA256 = '31fedf547c3029d419b5b2daac973bb1b106339ee3ef631a2c290ae71f0397b0';

    /** Every reason the vector file gives for a refusal, as its README lists them. */
    private const REASONS = [
        'missing_header', 'unsupported_algorithm', 'malformed_digest', 'digest_mismatch', 'empty_body', 'empty_secret',
    ];

    /** A secret the trace tests plant, to find it wherever it leaks. */
    private const SECRET = 'whsec_planted_secret_for_the_trace';

    public function testTheVectorsAreTheVendoredFile(): void
    {
        self::assertSame(
            self::VECTORS_SHA256,
            hash_file('sha256', self::vectorsPath()),
            'tests/fixtures/webhook/signature_vectors.json changed: it is vendored from octonomy-go and never edited by hand (see the README beside it)',
        );
    }

    /**
     * Every test below iterates the file, so a file that lost its vectors would
     * pass them all with nothing checked.
     */
    public function testTheVectorsAreUsable(): void
    {
        $file = self::file();
        self::assertSame(1, $file['version'] ?? null, 'the binding here was written for version 1 of the format');
        self::assertSame('HMAC-SHA256', $file['algorithm'] ?? null);
        self::assertSame(Webhook::SIGNATURE_HEADER, $file['header'] ?? null);

        $accept = self::cases('accept');
        $reject = self::cases('reject');
        self::assertGreaterThanOrEqual(8, count($accept));
        self::assertGreaterThanOrEqual(16, count($reject));

        $reasons = array_column($reject, 3);
        self::assertSame([], array_values(array_diff($reasons, self::REASONS)), 'a reason this binding does not know');
        // The four kinds #26 names: valid (the accept list), forged, malformed
        // header, and empty body; and the two policy refusals besides.
        foreach (self::REASONS as $reason) {
            self::assertContains($reason, $reasons);
        }
    }

    /**
     * @param string $secret the vector's secret
     * @param string $header the vector's header value
     * @param string $body   the vector's body bytes
     */
    #[DataProvider('acceptVectors')]
    public function testAnAcceptVectorVerifies(string $secret, string $header, string $body): void
    {
        self::assertTrue(Webhook::verify($secret, $header, $body));
    }

    /**
     * Everything the sender controls is refused with false, never an exception:
     * a missing or malformed header, a digest that does not match, an empty body.
     *
     * @param string $secret the vector's secret
     * @param string $header the vector's header value
     * @param string $body   the vector's body bytes
     */
    #[DataProvider('senderRejectVectors')]
    public function testARejectVectorIsRefused(string $secret, string $header, string $body): void
    {
        self::assertFalse(Webhook::verify($secret, $header, $body));
    }

    /**
     * The vector file's empty_secret: a correct digest under an empty key, which
     * anyone can produce. The caller's own misconfiguration, so it throws.
     */
    public function testAnEmptySecretThrowsRatherThanVerifying(): void
    {
        $vectors = array_filter(self::cases('reject'), static fn(array $case): bool => $case[3] === 'empty_secret');
        self::assertNotEmpty($vectors);
        [$secret, $header, $body] = array_values($vectors)[0];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook::verify() was given an empty signing secret');

        Webhook::verify($secret, $header, $body);
    }

    /**
     * A request with no signature header, as most frameworks report one: null.
     */
    public function testAnAbsentHeaderIsRefused(): void
    {
        [$secret, , $body] = self::acceptVectors()['minimal-ascii-body'];

        self::assertFalse(Webhook::verify($secret, null, $body));
        self::assertFalse(Webhook::verify($secret, '', $body));
    }

    /**
     * The hex is decoded before it is compared, so its case is not part of the
     * signature; the prefix names the algorithm and must be exact (the vectors
     * cover SHA256=).
     */
    public function testTheDigestsHexIsReadInEitherCase(): void
    {
        [$secret, $header, $body] = self::acceptVectors()['minimal-ascii-body'];

        self::assertTrue(Webhook::verify($secret, 'sha256=' . strtoupper(substr($header, 7)), $body));
    }

    /**
     * A header value with anything after the digest is not a digest, a newline
     * included: the pattern ends at \z, not at $.
     */
    public function testNothingMayFollowTheDigest(): void
    {
        [$secret, $header, $body] = self::acceptVectors()['minimal-ascii-body'];

        self::assertFalse(Webhook::verify($secret, $header . "\n", $body));
        self::assertFalse(Webhook::verify($secret, $header . ' ', $body));
    }

    /**
     * @return iterable<string, array{mixed, mixed, mixed, string}>
     */
    public static function callerMistakes(): iterable
    {
        [, $header, $body] = self::acceptVectors()['minimal-ascii-body'];

        yield 'secret unset, as getenv() reports it' => [false, $header, $body, 'given the signing secret as a value of type boolean'];
        yield 'secret empty' => ['', $header, $body, 'given an empty signing secret'];
        yield 'header as PSR-7 getHeader() gives it' => [self::SECRET, [$header], $body, 'given the signature header as a value of type array'];
        yield 'body from a failed read' => [self::SECRET, $header, false, 'given the body as a value of type boolean'];
        yield 'body already parsed' => [self::SECRET, $header, ['id' => 'parsed'], 'given the body as a value of type array'];
    }

    /**
     * A wrong argument is the caller's mistake: InvalidArgumentException, never
     * a TypeError raised at the call, whose trace would hold every argument
     * before verify() could remove them. The exception verify() throws keeps
     * none of its arguments in verify()'s frame, on either PHP line.
     *
     * verify() is called through call_user_func(), a frame that is not the
     * SDK's, so the trace shows arguments are being recorded at all: the
     * caller's own frame keeps what it passed, which is the caller's business.
     */
    #[DataProvider('callerMistakes')]
    public function testACallerMistakeThrowsWithNoArgumentInTheFrame(mixed $secret, mixed $header, mixed $body, string $message): void
    {
        // php.ini-production turns this on, and then no frame records any
        // argument and the assertions below would pass on nothing.
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        try {
            call_user_func([Webhook::class, 'verify'], $secret, $header, $body);
            self::fail('verify() accepted ' . $message);
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());

            $verify = $caller = null;
            foreach ($e->getTrace() as $frame) {
                if (($frame['class'] ?? null) === Webhook::class && $frame['function'] === 'verify') {
                    $verify = $frame;
                }
                if (!isset($frame['class']) && $frame['function'] === 'call_user_func') {
                    $caller = $frame;
                }
            }
            self::assertNotNull($verify, 'the trace has verify()\'s frame');
            self::assertArrayNotHasKey('args', $verify, 'verify()\'s frame keeps an argument');
            self::assertNotNull($caller);
            self::assertArrayHasKey('args', $caller, 'arguments are not being recorded, so this test checks nothing');
        } finally {
            if ($ignoreArgs !== false) {
                ini_set('zend.exception_ignore_args', $ignoreArgs);
            }
        }
    }

    /**
     * hash_equals() is the only thing that may compare the body's digest: ==,
     * === or strcmp() stop at the first byte that differs, and the time that
     * takes tells a forger how much of their digest is right. Timing cannot be
     * asserted from a test, so the source is: the HMAC is computed in exactly
     * one place, as the first argument of the one hash_equals() call, and no
     * string comparison function appears at all.
     */
    public function testTheDigestIsComparedOnlyInsideHashEquals(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Webhook.php');
        self::assertIsString($source);

        $code = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code[] = is_array($token) ? $token[1] : $token;
        }
        $calls = static fn(string $name): array => array_keys(array_filter(
            $code,
            static fn(string $text, int $i): bool => $text === $name && ($code[$i + 1] ?? null) === '(',
            ARRAY_FILTER_USE_BOTH,
        ));

        $hmac = $calls('hash_hmac');
        self::assertCount(1, $hmac, 'the digest is computed in exactly one place');
        self::assertCount(1, $calls('hash_equals'));
        self::assertSame(['hash_equals', '('], [$code[$hmac[0] - 2], $code[$hmac[0] - 1]], 'the digest is computed directly inside hash_equals()');
        foreach (['strcmp', 'strcasecmp', 'strncmp', 'strncasecmp', 'substr_compare', 'strcoll'] as $compare) {
            self::assertSame([], $calls($compare), $compare . '() compares strings in variable time');
        }
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function acceptVectors(): array
    {
        $out = [];
        foreach (self::cases('accept') as $name => [$secret, $header, $body]) {
            $out[$name] = [$secret, $header, $body];
        }

        return $out;
    }

    /**
     * The reject vectors whose input the sender controls: all but empty_secret.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function senderRejectVectors(): array
    {
        $out = [];
        foreach (self::cases('reject') as $name => [$secret, $header, $body, $reason]) {
            if ($reason !== 'empty_secret') {
                $out[$name] = [$secret, $header, $body];
            }
        }

        return $out;
    }

    /**
     * One list of the vector file, by vector name: the secret, the header
     * value, the body bytes (decoded from body_base64 where the body is not
     * UTF-8), and the reason ('' on an accept vector). A vector missing a field,
     * or carrying both body forms or neither, fails loudly here.
     *
     * @return array<string, array{string, string, string, string}>
     */
    private static function cases(string $list): array
    {
        $vectors = self::file()[$list] ?? null;
        if (!is_array($vectors)) {
            throw new \UnexpectedValueException("the vector file has no {$list} list");
        }
        $out = [];
        foreach ($vectors as $vector) {
            if (!is_array($vector) || !is_string($vector['name'] ?? null) || !is_string($vector['secret'] ?? null)
                || !is_string($vector['signature'] ?? null) || !is_string($vector['description'] ?? null)) {
                throw new \UnexpectedValueException("a {$list} vector lacks a name, description, secret or signature");
            }
            $name = $vector['name'];
            if (isset($out[$name])) {
                throw new \UnexpectedValueException("the vector {$name} appears twice");
            }
            $text = $vector['body'] ?? null;
            $base64 = $vector['body_base64'] ?? null;
            if (is_string($text) === is_string($base64)) {
                throw new \UnexpectedValueException("the vector {$name} must carry exactly one of body and body_base64");
            }
            $body = is_string($text) ? $text : (is_string($base64) ? base64_decode($base64, true) : false);
            if ($body === false) {
                throw new \UnexpectedValueException("the vector {$name} has a body_base64 that is not base64");
            }
            $reason = $vector['reason'] ?? '';
            if (!is_string($reason) || ($list === 'reject') === ($reason === '')) {
                throw new \UnexpectedValueException("the vector {$name} has a reason where it needs none, or none where it needs one");
            }
            $out[$name] = [$vector['secret'], $vector['signature'], $body, $reason];
        }

        return $out;
    }

    /**
     * @return array<mixed>
     */
    private static function file(): array
    {
        $json = file_get_contents(self::vectorsPath());
        if ($json === false) {
            throw new \UnexpectedValueException('could not read ' . self::vectorsPath());
        }
        $file = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($file)) {
            throw new \UnexpectedValueException('the vector file is not a JSON object');
        }

        return $file;
    }

    private static function vectorsPath(): string
    {
        return __DIR__ . '/fixtures/webhook/signature_vectors.json';
    }
}

# Security Policy

## Supported versions

The SDK ships as two lines of one package
([docs/versioning.md](docs/versioning.md#release-lines-and-support)):

| Line | Branch | PHP | Supported | After its first release it takes |
| ---- | ------ | --- | --------- | -------------------------------- |
| 2.x | `main` | 8.2 and later | Yes | Every feature and fix |
| 1.x | `support/php7.4` | 7.4 and later | Yes, indefinitely | Security fixes and major bug fixes only |

No version of either line has been released yet; the first are `2.0.0` and `1.0.0`. Until then
security fixes land on `main`, and on `support/php7.4` as a pull request into it.

A security fix both lines need lands on `main` first and is ported to `support/php7.4`, and each line
ships it in its next release. The 1.x line has no end date, but it is only as secure as its
dependencies, Guzzle 7 above all: if Guzzle 7 drops PHP 7.4, a PHP 7.4 application stays on the last
Guzzle 7 that supports it and gets no further Guzzle fixes. From then on, getting them means leaving
PHP 7.4 ([docs/upgrading-1-to-2.md](docs/upgrading-1-to-2.md)).

## Credentials in exceptions

No exception the SDK throws carries the token, the `Authorization` header, the request body, or
`X-Actor-ID`: not in its message, its `RequestContext`, an exception it chains, or the arguments of the
SDK's own frames in its trace, which are removed when the exception is built. This holds on the PHP 7.4
line too, since it does not rely on attributes. Frames of your own code keep their arguments; set
`zend.exception_ignore_args=1` in production, or mark your own parameters `#[\SensitiveParameter]`, if
your code passes the token around. See [docs/api.md](docs/api.md#redaction).

## The HTTP client

Every request, token included, goes through the HTTP client: the Guzzle client the SDK builds, or the
PSR-18 client passed as `http_client`, with its middleware. What that client logs or sends elsewhere is
out of the SDK's reach, so treat a client you pass, and its middleware, as holding the token.

A Guzzle client is sent each request with its own default `auth`, headers, query, `body` and `json`
cancelled, so none of them can replace the SDK's `Authorization` header or reach the server. Guzzle
offers no way to cancel a default `form_params` or `multipart`, which would replace the SDK's body, so
a client passed to the SDK must not set either. Any other PSR-18 client,
a decorator around a Guzzle client included, sends the request as it chooses: through `sendRequest()`,
Guzzle puts a default `auth` in place of the SDK's `Authorization` header. See the
[README](README.md#http-client).

## Reporting a vulnerability

Please **do not** open a public issue, pull request, or discussion for security vulnerabilities.

Instead, report privately through GitHub:

1. Go to the repository's **Security** tab.
2. Click **Report a vulnerability** to open a private security advisory.

This keeps the report confidential while we investigate.

Please include, where possible:

- A description of the issue and its potential impact.
- Steps to reproduce or a proof of concept.
- Affected version or commit.

## What to expect

- We aim to acknowledge new reports within a few business days.
- We'll keep you updated on our assessment and remediation progress.
- Once a fix is available, we'll coordinate disclosure and credit you if you'd like.

Thank you for helping keep Octonomy and its users safe.

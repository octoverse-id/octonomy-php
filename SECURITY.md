# Security Policy

## Supported versions

No version of this SDK has been released yet, so there is no supported release. Security fixes land on
`main`. The support policy for the first release lines is published here before they ship.

## Credentials in exceptions

No exception the SDK throws carries the token, the `Authorization` header, the request body, or
`X-Actor-ID`: not in its message, its `RequestContext`, an exception it chains, or the arguments of the
SDK's own frames in its trace, which are removed when the exception is built. This holds on the PHP 7.4
line too, since it does not rely on attributes. Frames of your own code keep their arguments; set
`zend.exception_ignore_args=1` in production, or mark your own parameters `#[\SensitiveParameter]`, if
your code passes the token around. See [docs/api.md](docs/api.md#redaction).

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

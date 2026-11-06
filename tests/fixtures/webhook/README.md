# Webhook signature vectors

`signature_vectors.json` is vendored byte for byte from
[octonomy-go](https://github.com/octoverse-id/octonomy-go)'s `webhook/testdata/signature_vectors.json`,
as last changed in `78d21d6` (#60 there) and unchanged at `main` `cb2d499`. Its README there documents
the format and every vector.

The digests were not produced by the code they test. octonomy-go's `generate_vectors.py` writes them
with the three lines the Octonomy server signs with (`octonomy/events/dispatch.py::_webhook_signature`).
When this copy was vendored, that script regenerated the file byte for byte, and
`openssl dgst -sha256 -hmac` confirmed every accept vector.

`WebhookTest` pins the file's SHA-256, so an edit here fails the suite. Never edit it by hand. To
refresh it, copy the file from octonomy-go, update the hash in `WebhookTest::VECTORS_SHA256`, and record
the new commit here. That makes it a reviewed change.

The file's `reason` strings are language-neutral. `WebhookTest` binds them to this SDK:

- `empty_secret` throws `InvalidArgumentException`, because an empty secret is the caller's own
  misconfiguration.
- Every other reject reason returns `false`, because the sender controls those inputs, and verify()
  never throws on what a sender controls.

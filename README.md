# koh/typo3-async

Asynchronous TYPO3 work over Symfony Messenger. A mail leaves the request:
it is rendered where it was written, put on a queue, and sent by a consumer —
with three retries and a failure queue, so a slow or unreachable mail server
neither holds a form submit nor loses the mail.

Off by default. Nothing changes until the environment says `MESSENGER_ASYNC=1`.

## Why the switch is an environment variable

A queue nobody reads does not delay a message, it keeps it. So async mode may
only be on where a consumer runs, and the two are switched together by the
platform that starts the consumer — never by code that cannot see whether a
consumer exists.

## What it does

| Part | Role |
|---|---|
| `AsyncMailer` | Decorates TYPO3's `MailerInterface`. Async mode on: renders a `FluidEmail` in the request, copies it into a plain `Email` and dispatches a `SendEmailMessage`. Off: the inner mailer, call for call. |
| `SendEmailHandler` | Runs in the consumer and sends through the *inner* mailer, with TYPO3's own events and transport. |
| `koh_async_mail` | Doctrine transport, queue `mail` in `sys_messenger_messages`. |
| `QueueImageVariants`, `QueueCroppedReferences` | An image uploaded or replaced, or a file reference saved with a crop, queues `PregenerateImageVariants` -- the uid only. |
| `PregenerateImageVariantsHandler` | Runs in the consumer and produces the configured variants through `File::process()`, the call Fluid's `ImageViewHelper` ends in, so the page finds them ready. |
| `koh_async_images` | Doctrine transport, queue `images`. |
| `RecordFailureReason`, `RetryFailedMessage`, `ParkFailedMessage` | The failure path TYPO3 does not register on its own: the exception is recorded, the message retried three times (30 s, 60 s, 120 s, with jitter), then moved to `koh_async_failed` (queue `failed`) instead of being dropped. |

## Running it

```bash
# where the consumer runs, and only there
MESSENGER_ASYNC=1 vendor/bin/typo3 messenger:consume koh_async_mail koh_async_images --time-limit=3600 --memory-limit=256M
```

The web pods need `MESSENGER_ASYNC=1` too, or they keep sending in the
request. Messages in the failure queue can be inspected in
`sys_messenger_messages` (`queue_name = 'failed'`); the `ErrorDetailsStamp`
on each one names the exception.

## Image variants

Off until `imageProfiles` is set, as a PHP array or a JSON string, in
`config/system/additional.php`:
`$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['koh_async']['imageProfiles']`. Each
entry is one processing instruction array, and it must be the array the
template builds, key for key and in the same order: TYPO3 finds a processed
file again by a checksum over that array, so `{"width":640}` and
`{"width":640,"height":null}` are two different variants. Copy them from
what the site already requests:

```bash
vendor/bin/typo3 koh-async:image-profiles:suggest --min-count=20 -v
```

```json
[
  {"width": 640, "fileExtension": "avif"},
  {"width": 1280, "height": null, "minWidth": null, "minHeight": null, "maxWidth": null, "maxHeight": null, "crop": null},
  {"width": 1280, "height": null, "minWidth": null, "minHeight": null, "maxWidth": null, "maxHeight": null, "crop": "default"}
]
```

`crop` absent or null: a file profile, produced on upload and replace. `crop`
a string: a reference profile, produced when a reference's crop is saved; the
string names the crop variant the template renders, and the consumer puts the
absolute crop area in its place, as the `ImageViewHelper` does. The command
cannot tell which variant a template used and suggests `default`.

Set `imageProfiles` only once the consumer reads `koh_async_images` -- before
that, the messages wait in the queue.

## Requirements

TYPO3 14.3, PHP 8.5.

## License

MIT, see [LICENSE](LICENSE).

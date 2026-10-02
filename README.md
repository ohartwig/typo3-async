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

After an upload, a replace or a saved crop, the consumer produces the sizes
the site's templates request -- so the first visitor finds them instead of
waiting for ImageMagick. Nothing to configure: the sizes are **learned** from
what the site has already rendered.

- TYPO3 keeps the instruction array of every processed file in
  `sys_file_processedfile`, keys in the order the template built them. That
  order matters: TYPO3 finds a processed file again by a checksum over the
  array, so `{"width":640}` and `{"width":640,"height":null}` are two
  variants. Learned arrays are copied, never rebuilt.
- A configuration requested for at least 10 % of the originals (and at least
  three) is produced for every new upload.
- A crop is learned as the name of its crop variant (`hero`, `xs`, ...): the
  stored area is compared with the variants saved on the file's references.
  The area itself is computed per reference when its crop is saved, so two
  content elements with different crops each get their own image.
- What the consumer made itself is noted in `tx_kohasync_pregenerated` and is
  no evidence: a size a template stops requesting drops out instead of
  confirming itself with every upload. After a template redesign, "Remove
  processed files" in the maintenance module starts the evidence afresh.
- The consumer re-learns hourly.

What it would produce right now:

```bash
vendor/bin/typo3 koh-async:image-profiles:suggest -v
```

Settings, in `config/system/additional.php`, all optional:

```php
// percent of originals; default 10, 0 switches learning off
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['koh_async']['learnImageProfiles'] = 10;
// produced in addition, as PHP array or JSON string; a string `crop` names
// the crop variant
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['koh_async']['imageProfiles'] = [
    ['width' => 1280, 'height' => null, 'minWidth' => null, 'minHeight' => null, 'maxWidth' => null, 'maxHeight' => null, 'crop' => 'hero'],
];
```

The consumer must read `koh_async_images` (see Running it) -- otherwise the
messages wait in the queue.

## Queue figures for monitoring

Where `KOH_ASYNC_METRICS_FILE` names a file, the consumer writes the queue
figures there from its worker loop, at most every 15 seconds:

```
koh_async_queue_messages{queue="mail"} 0
koh_async_queue_oldest_age_seconds{queue="mail"} 0
koh_async_metrics_timestamp_seconds 1790960000
```

`oldest_age_seconds` counts only messages that are due -- a retry waiting
for its delay is not overdue. The timestamp comes from the loop itself, so
one that stops moving means the consumer hangs, which a process probe does
not see. A sidecar serves the file to Prometheus without TYPO3 or database
access:

```bash
php -S 0.0.0.0:9106 vendor/koh/typo3-async/Resources/Private/Php/serve-metrics.php
```

## Requirements

TYPO3 14.3, PHP 8.5.

## License

MIT, see [LICENSE](LICENSE).

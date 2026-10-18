<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Tests\Unit\Infrastructure\Messenger;

use Koh\Typo3Async\Infrastructure\Image\PregenerateImageVariants;
use Koh\Typo3Async\Infrastructure\Messenger\AllowlistedPhpSerializer;
use Koh\Typo3Async\Infrastructure\Messenger\QueueClassAllowlist;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope as MailerEnvelope;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use TYPO3\CMS\Core\Mail\MailMessage;

final class AllowlistedPhpSerializerTest extends TestCase
{
    private static function serializer(mixed $extra = []): AllowlistedPhpSerializer
    {
        return new AllowlistedPhpSerializer(QueueClassAllowlist::fromConfiguration($extra));
    }

    private static function mail(): Envelope
    {
        $email = (new Email())
            ->from(new Address('shop@example.org', 'Shop'))
            ->to('customer@example.org')
            ->cc('copy@example.org')
            ->date(new \DateTimeImmutable('2026-10-16T12:00:00Z'))
            ->subject('Order received')
            ->text('Thank you.')
            ->html('<p>Thank you.</p>')
            ->addPart(new DataPart('%PDF-1.4', 'invoice.pdf', 'application/pdf'));
        $email->getHeaders()->add(new TagHeader('order'));
        $envelope = new Envelope(new SendEmailMessage($email, new MailerEnvelope(new Address('bounce@example.org'), [new Address('customer@example.org')])));

        // What a parked message carries on top.
        return $envelope->with(
            new SentToFailureTransportStamp('koh_async_mail'),
            new DelayStamp(0),
            new RedeliveryStamp(3),
            ErrorDetailsStamp::create(new \RuntimeException('SMTP down')),
        );
    }

    #[Test]
    public function aMailWithEveryPartItCanHaveRoundTrips(): void
    {
        $serializer = self::serializer();
        $mail = self::mail();

        $decoded = $serializer->decode($serializer->encode($mail));

        self::assertEquals($mail->withoutAll(ErrorDetailsStamp::class), $decoded->withoutAll(ErrorDetailsStamp::class));
        self::assertSame('SMTP down', $decoded->last(ErrorDetailsStamp::class)?->getExceptionMessage());
    }

    #[Test]
    public function theOtherMessagesRoundTripToo(): void
    {
        $serializer = self::serializer();
        foreach ([
            new Envelope(PregenerateImageVariants::forFile(7)),
            new Envelope(new SendEmailMessage((new MailMessage())->to('a@example.org')->subject('x')->text('.'))),
        ] as $envelope) {
            self::assertEquals($envelope, $serializer->decode($serializer->encode($envelope)));
        }
    }

    #[Test]
    public function whatTypo3sSerializerQueuedBeforeStillDecodes(): void
    {
        $queuedBefore = (new PhpSerializer())->encode(self::mail());

        $decoded = self::serializer()->decode($queuedBefore);

        self::assertInstanceOf(SendEmailMessage::class, $decoded->getMessage());
    }

    #[Test]
    public function aRowMadeOfOtherClassesIsRefusedByNameBeforeAnyObjectExists(): void
    {
        $serializer = self::serializer();
        $body = (new PhpSerializer())->encode(new Envelope(new \ArrayObject(['x'])));

        try {
            $serializer->decode($body);
            self::fail('a row made of ArrayObject must not decode');
        } catch (MessageDecodingFailedException $e) {
            self::assertStringContainsString('outside the allowlist: ArrayObject', $e->getMessage());
        }
    }

    #[Test]
    public function aStrangerHiddenInsideAnAllowedObjectIsRefusedToo(): void
    {
        $serializer = self::serializer();
        $body = (new PhpSerializer())->encode(new Envelope(PregenerateImageVariants::forFile(1), [new DelayStamp(0)]));
        // The stamp array is where a hand-written row would hide a gadget:
        // the DelayStamp object is swapped for one of another class, the
        // array key naming the stamp class stays.
        $body['body'] = str_replace(addslashes(serialize(new DelayStamp(0))), 'O:8:"stdClass":0:{}', $body['body']);
        self::assertStringContainsString('stdClass', $body['body']);

        try {
            $serializer->decode($body);
            self::fail('a hand-written row with a stdClass gadget must not decode');
        } catch (MessageDecodingFailedException $e) {
            self::assertStringContainsString('stdClass', $e->getMessage());
        }
    }

    #[Test]
    public function aSitesOwnMessageClassCanBeAllowedByPrefix(): void
    {
        $envelope = new Envelope(new \ArrayObject(['x']));
        $body = (new PhpSerializer())->encode($envelope);

        self::assertEquals($envelope, self::serializer('ArrayObject')->decode($body), 'as a string');
        self::assertEquals($envelope, self::serializer(['\\ArrayObject'])->decode($body), 'as a list, leading backslash tolerated');
    }

    #[Test]
    public function theListMatchesPrefixesOnlyWhereTheyEndInABackslash(): void
    {
        $list = new QueueClassAllowlist();

        self::assertTrue($list->allows('Symfony\Component\Mime\Part\DataPart'));
        self::assertTrue($list->allows('DateTimeImmutable'));
        self::assertFalse($list->allows('DateTimeImmutableGadget'), 'an exact entry is exact');
        self::assertFalse($list->allows('Koh\Typo3Async\Infrastructure\Image\QueueImageVariants'), 'only the message, not the extension');
        self::assertSame([], $list->refused('garbage'), 'what does not unserialize is left to the parent to refuse');
    }
}

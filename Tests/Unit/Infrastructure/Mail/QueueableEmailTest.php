<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Tests\Unit\Infrastructure\Mail;

use Koh\Typo3Async\Infrastructure\Mail\QueueableEmail;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;
use TYPO3\CMS\Core\Mail\FluidEmail;

final class QueueableEmailTest extends TestCase
{
    #[Test]
    public function aPlainEmailPassesThroughUnchanged(): void
    {
        $email = new Email()->to('a@example.org')->text('x');

        self::assertSame($email, QueueableEmail::from($email));
    }

    #[Test]
    public function aFluidEmailIsRenderedInTheRequestAndCopiedIntoAPlainEmail(): void
    {
        // A FluidEmail whose rendering is observable: getBody() is where the
        // real one renders its template. The view it would need cannot be
        // serialized, which is the reason for the copy.
        $fluid = new class extends FluidEmail {
            public int $renders = 0;

            // The real constructor builds a Fluid view through TYPO3's container;
            // the parent of FluidEmail is constructed instead.
            public function __construct() // @phpstan-ignore constructor.missingParentCall
            {
                Email::__construct();
            }

            public function getBody(): \Symfony\Component\Mime\Part\AbstractPart
            {
                ++$this->renders;
                $this->html('<p>Rendered for the request</p>');
                $this->text('Rendered for the request');

                return Email::getBody();
            }
        };
        $fluid->from('shop@example.org')->to('customer@example.org')->subject('Confirmation')
            ->attach('receipt', 'receipt.txt', 'text/plain');

        $queued = QueueableEmail::from($fluid);

        self::assertSame(1, $fluid->renders, 'rendered once, here, in the request');
        self::assertSame(Email::class, $queued::class);
        self::assertSame('Confirmation', $queued->getSubject());
        self::assertSame('customer@example.org', $queued->getTo()[0]->getAddress());
        self::assertSame('<p>Rendered for the request</p>', $queued->getHtmlBody());
        self::assertSame('Rendered for the request', $queued->getTextBody());
        self::assertSame('receipt', $queued->getAttachments()[0]->getBody());
        self::assertInstanceOf(Email::class, unserialize(serialize($queued)));
    }
}

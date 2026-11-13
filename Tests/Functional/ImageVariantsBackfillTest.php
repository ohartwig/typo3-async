<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Tests\Functional;

use Koh\Typo3Async\Domain\AsyncMode;
use Koh\Typo3Async\Infrastructure\Image\BackfillImageVariants;
use Koh\Typo3Async\Infrastructure\Image\BackfillImageVariantsHandler;
use Koh\Typo3Async\Infrastructure\Image\ImagesInUse;
use Koh\Typo3Async\Infrastructure\Image\ImageVariantsBackfill;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Extbase\Service\ImageService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Images uploaded before the profiles changed: a pass prepares the variants
 * of those a page uses, so the frontend finds them -- the same end as in
 * ImageVariantsTest, reached from a backfill instead of an upload.
 */
final class ImageVariantsBackfillTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/typo3-async'];

    protected bool $initializeDatabase = true;

    private const FILE_PROFILE = ['width' => 64, 'height' => null, 'minWidth' => null, 'minHeight' => null, 'maxWidth' => null, 'maxHeight' => null, 'crop' => null];

    protected function setUp(): void
    {
        putenv(AsyncMode::ENVIRONMENT_VARIABLE . '=1');
        $this->configurationToUseInTestInstance = [
            'EXTENSIONS' => [
                'koh_async' => [
                    'imageProfiles' => json_encode([self::FILE_PROFILE]),
                    'learnImageProfiles' => 0,
                ],
            ],
        ];
        parent::setUp();
        $this->get(StorageRepository::class)->createLocalStorage('fileadmin', 'fileadmin/', 'relative', '', true);
        // No backend user: these uploads queue nothing themselves (UploadPolicy),
        // as if they had been made before the profiles existed.
        unset($GLOBALS['BE_USER']);
    }

    protected function tearDown(): void
    {
        putenv(AsyncMode::ENVIRONMENT_VARIABLE);
        parent::tearDown();
    }

    private function upload(string $name, string $content = ''): File
    {
        $source = (string) tempnam(sys_get_temp_dir(), 'koh-async');
        '' === $content ? copy(__DIR__ . '/Fixtures/logo.svg', $source) : file_put_contents($source, $content);
        $storage = $this->get(StorageRepository::class)->getDefaultStorage();
        self::assertNotNull($storage);
        $storage->setEvaluatePermissions(false);
        $file = $storage->addFile($source, $storage->getRootLevelFolder(), $name);
        self::assertInstanceOf(File::class, $file);

        return $file;
    }

    /** @param array<string, mixed> $fields */
    private function reference(File $file, array $fields = []): int
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_reference');
        $connection->insert('sys_file_reference', $fields + [
            'pid' => 0, 'uid_local' => $file->getUid(), 'uid_foreign' => 1, 'tablenames' => 'tt_content', 'fieldname' => 'image', 'crop' => '',
        ]);

        return (int) $connection->lastInsertId();
    }

    /**
     * @param array<string, mixed> $where
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, array $where = []): array
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable($table)
            ->select(['*'], $table, $where)->fetchAllAssociative();
    }

    /** @param array<string, mixed> $arguments */
    private function backfill(array $arguments = []): string
    {
        $tester = new CommandTester($this->get(CommandRegistry::class)->get('koh-async:image-variants:backfill'));
        $tester->execute($arguments);

        return $tester->getDisplay();
    }

    private function consumeAll(): void
    {
        $consume = new CommandTester($this->get(CommandRegistry::class)->get('messenger:consume'));
        $consume->execute(['receivers' => ['koh_async_images'], '--limit' => 50, '--time-limit' => 10]);
    }

    #[Test]
    public function aPassPreparesTheImagesInUseAndTheFrontendFindsThem(): void
    {
        $used = $this->upload('used.svg');
        $this->reference($used);
        $this->upload('unused.svg');
        $deleted = $this->upload('deleted.svg');
        $this->reference($deleted, ['deleted' => 1]);
        $huge = $this->upload('huge.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="9000" height="9000"><rect width="9000" height="9000" fill="#c00"/></svg>');
        $this->reference($huge);
        self::assertSame([], $this->rows('sys_messenger_messages'), 'the uploads queued nothing');

        self::assertStringContainsString('queued', $this->backfill());
        $this->consumeAll();

        self::assertSame([], $this->rows('sys_messenger_messages'), 'the pass ran to its end');
        $produced = $this->rows('sys_file_processedfile');
        self::assertCount(1, $produced, 'only the referenced image within the pixel limit');
        self::assertSame($used->getUid(), (int) $produced[0]['original']);

        $found = $this->get(ImageService::class)->applyProcessingInstructions($used, self::FILE_PROFILE);
        self::assertSame((int) $produced[0]['uid'], (int) $found->getUid(), 'the frontend found the variant');
        self::assertCount(1, $this->rows('sys_file_processedfile'), 'and produced none of its own');
    }

    #[Test]
    public function aPassForTheSameProfilesIsNotRepeatedUnlessForced(): void
    {
        $this->reference($this->upload('used.svg'));
        $this->backfill();
        $this->consumeAll();

        self::assertStringContainsString('same profiles', $this->backfill());
        self::assertSame([], $this->rows('sys_messenger_messages'));

        $this->backfill(['--force' => true]);
        self::assertCount(1, $this->rows('sys_messenger_messages', ['queue_name' => 'images']));
    }

    #[Test]
    public function aBatchQueuesItsImagesAndThenTheNextBatch(): void
    {
        $first = $this->upload('a.svg');
        $second = $this->upload('b.svg');
        $this->reference($first);
        $this->reference($second);
        $bus = new RecordingBus();
        $backfill = $this->get(ImageVariantsBackfill::class);
        $pass = (string) $backfill->start(time());
        $handler = new BackfillImageVariantsHandler($this->get(ImagesInUse::class), $backfill, $bus, new NullLogger(), 1);

        $handler(BackfillImageVariants::start($pass));
        $sent = $bus->take();
        self::assertCount(2, $sent, 'one image, then the next batch');
        $next = $sent[1];
        self::assertInstanceOf(BackfillImageVariants::class, $next);
        self::assertSame($first->getUid(), $next->afterUid);

        $handler($next);
        $afterSecond = $bus->take()[1] ?? null;
        self::assertInstanceOf(BackfillImageVariants::class, $afterSecond);
        $handler($afterSecond);
        $last = $bus->take()[0] ?? null;
        self::assertInstanceOf(BackfillImageVariants::class, $last);
        self::assertSame(BackfillImageVariants::PHASE_REFERENCES, $last->phase, 'files done, references next');
    }

    #[Test]
    public function aReplacedPassStops(): void
    {
        $this->reference($this->upload('used.svg'));
        $bus = new RecordingBus();
        $backfill = $this->get(ImageVariantsBackfill::class);
        $old = (string) $backfill->start(time() - 10);
        $backfill->start(time());
        $handler = new BackfillImageVariantsHandler($this->get(ImagesInUse::class), $backfill, $bus, new NullLogger(), 1);

        $handler(BackfillImageVariants::start($old));

        self::assertSame([], $bus->take());
    }

    #[Test]
    public function theConsumerStartsAPassWhenTheProfilesChanged(): void
    {
        $this->reference($this->upload('used.svg'));

        // An empty queue and a consumer start: the listener sees profiles it
        // has not backfilled yet, starts a pass, and the same run works it off.
        $this->consumeAll();

        self::assertCount(1, $this->rows('sys_file_processedfile'));
        self::assertNotNull($this->get(ImageVariantsBackfill::class)->currentPass());
    }
}

/** @internal */
final class RecordingBus implements MessageBusInterface
{
    /** @var list<object> */
    private array $messages = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        return new Envelope($message);
    }

    /**
     * The messages dispatched since the last call.
     *
     * @return list<object>
     */
    public function take(): array
    {
        $taken = $this->messages;
        $this->messages = [];

        return $taken;
    }
}

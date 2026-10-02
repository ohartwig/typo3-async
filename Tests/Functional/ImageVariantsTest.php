<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Tests\Functional;

use Koh\Typo3Async\Domain\AsyncMode;
use Koh\Typo3Async\Infrastructure\Image\PregenerateImageVariants;
use Koh\Typo3Async\Infrastructure\Image\QueueCroppedReferences;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Imaging\ImageManipulation\CropVariantCollection;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Service\ImageService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The point of pregenerating is that the frontend finds the variant instead
 * of producing it. So every test here ends the same way: the frontend's own
 * call -- Extbase's ImageService with the instruction array Fluid's
 * ImageViewHelper builds -- must find the variant the consumer made, and must
 * not add a processed file of its own.
 *
 * An SVG keeps the test free of ImageMagick: TYPO3 scales and crops SVGs
 * itself, through the same File::process() and the same checksum.
 */
final class ImageVariantsTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/typo3-async'];

    protected bool $initializeDatabase = true;

    /** The ImageViewHelper's array for width="64", uncropped. */
    private const FILE_PROFILE = ['width' => 64, 'height' => null, 'minWidth' => null, 'minHeight' => null, 'maxWidth' => null, 'maxHeight' => null, 'crop' => null];

    /** The same with the reference's default crop variant, and a format. */
    private const REFERENCE_PROFILE = ['width' => 32, 'height' => null, 'minWidth' => null, 'minHeight' => null, 'maxWidth' => null, 'maxHeight' => null, 'crop' => 'default', 'fileExtension' => 'svg'];

    protected function setUp(): void
    {
        putenv(AsyncMode::ENVIRONMENT_VARIABLE . '=1');
        $this->configurationToUseInTestInstance = [
            'EXTENSIONS' => [
                'koh_async' => [
                    'imageProfiles' => json_encode([self::FILE_PROFILE, self::REFERENCE_PROFILE]),
                ],
            ],
        ];
        parent::setUp();
        $this->get(StorageRepository::class)->createLocalStorage('fileadmin', 'fileadmin/', 'relative', '', true);
    }

    protected function tearDown(): void
    {
        putenv(AsyncMode::ENVIRONMENT_VARIABLE);
        parent::tearDown();
    }

    private function upload(string $name = 'logo.svg', string $content = ''): File
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

    private function consume(): void
    {
        $consume = new CommandTester($this->get(CommandRegistry::class)->get('messenger:consume'));
        $consume->execute(['receivers' => ['koh_async_images'], '--limit' => 1, '--time-limit' => 10]);
    }

    #[Test]
    public function anUploadQueuesItsVariantsAndTheFrontendFindsThem(): void
    {
        $file = $this->upload();

        self::assertCount(1, $this->rows('sys_messenger_messages', ['queue_name' => 'images']), 'queued in `images`');
        self::assertSame([], $this->rows('sys_file_processedfile'), 'nothing produced in the request');

        $this->consume();

        self::assertSame([], $this->rows('sys_messenger_messages', ['queue_name' => 'images']), 'taken off the queue');
        $produced = $this->rows('sys_file_processedfile');
        self::assertCount(1, $produced, 'the file profile, not the reference profile');

        $found = $this->get(ImageService::class)->applyProcessingInstructions($file, self::FILE_PROFILE);

        self::assertSame((int) $produced[0]['uid'], (int) $found->getUid(), 'the frontend found the variant');
        self::assertCount(1, $this->rows('sys_file_processedfile'), 'and produced none of its own');
    }

    #[Test]
    public function aCropSavedOnAReferenceQueuesTheCroppedVariant(): void
    {
        $file = $this->upload();
        $this->consume();
        $crop = '{"default":{"cropArea":{"x":0.25,"y":0,"width":0.5,"height":1},"selectedRatio":"NaN","focusArea":null}}';
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_reference');
        $connection->insert('sys_file_reference', [
            'pid' => 0, 'uid_local' => $file->getUid(), 'uid_foreign' => 1, 'tablenames' => 'tt_content', 'fieldname' => 'image', 'crop' => '',
        ]);
        $uid = (int) $connection->lastInsertId();

        // The editor saves the crop: a real DataHandler run, so the hook
        // registration in ext_localconf.php is part of what is tested.
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['sys_file_reference' => [$uid => ['crop' => $crop]]], []);
        $dataHandler->process_datamap();

        self::assertCount(1, $this->rows('sys_messenger_messages', ['queue_name' => 'images']));
        $this->consume();
        self::assertCount(2, $this->rows('sys_file_processedfile'));

        // What the ImageViewHelper builds for <f:image image="{reference}" width="32" fileExtension="svg" />.
        $reference = $this->get(ResourceFactory::class)->getFileReferenceObject($uid);
        $area = CropVariantCollection::create((string) $reference->getProperty('crop'))->getCropArea('default');
        $instructions = ['width' => 32, 'height' => null, 'minWidth' => null, 'minHeight' => null, 'maxWidth' => null, 'maxHeight' => null, 'crop' => $area->makeAbsoluteBasedOnFile($reference), 'fileExtension' => 'svg'];

        $this->get(ImageService::class)->applyProcessingInstructions($reference, $instructions);

        self::assertCount(2, $this->rows('sys_file_processedfile'), 'the frontend found the cropped variant');
    }

    #[Test]
    public function aSaveWithoutACropQueuesNothing(): void
    {
        $hook = $this->get(QueueCroppedReferences::class);
        $hook->processDatamap_afterDatabaseOperations('update', 'sys_file_reference', 1, ['title' => 'x'], GeneralUtility::makeInstance(DataHandler::class));
        $hook->processDatamap_afterDatabaseOperations('update', 'tt_content', 1, ['crop' => 'x'], GeneralUtility::makeInstance(DataHandler::class));

        self::assertSame([], $this->rows('sys_messenger_messages', ['queue_name' => 'images']));
    }

    #[Test]
    public function aReplacedImageIsQueuedAgainAndAnUploadedTextIsNot(): void
    {
        $file = $this->upload();
        $this->consume();

        $replacement = (string) tempnam(sys_get_temp_dir(), 'koh-async');
        copy(__DIR__ . '/Fixtures/logo.svg', $replacement);
        $file->getStorage()->replaceFile($file, $replacement);
        $this->upload('notes.txt', 'not an image');

        self::assertCount(1, $this->rows('sys_messenger_messages', ['queue_name' => 'images']), 'the replace, not the text');
    }

    #[Test]
    public function aNewReferenceResolvesItsIdThroughTheDataHandler(): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->substNEWwithIDs['NEW1'] = 42;

        $this->get(QueueCroppedReferences::class)->processDatamap_afterDatabaseOperations('new', 'sys_file_reference', 'NEW1', ['crop' => '{}'], $dataHandler);

        self::assertCount(1, $this->rows('sys_messenger_messages', ['queue_name' => 'images']));
    }

    #[Test]
    public function aRecordGoneOrMissingBeforeTheConsumerRunsIsNoFailure(): void
    {
        $file = $this->upload();
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file')
            ->update('sys_file', ['missing' => 1], ['uid' => $file->getUid()]);
        // The consumer is another process and reads the row; here the
        // ResourceFactory still holds the uploaded object.
        $file->setMissing(true);
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_reference');
        $connection->insert('sys_file_reference', [
            'pid' => 0, 'uid_local' => $file->getUid(), 'uid_foreign' => 1, 'tablenames' => 'tt_content', 'fieldname' => 'image', 'crop' => '',
        ]);
        $missingReference = (int) $connection->lastInsertId();
        $bus = $this->get(MessageBusInterface::class);
        $bus->dispatch(PregenerateImageVariants::forFile(9999));
        $bus->dispatch(PregenerateImageVariants::forReference(9999));
        $bus->dispatch(PregenerateImageVariants::forReference($missingReference));

        $consume = new CommandTester($this->get(CommandRegistry::class)->get('messenger:consume'));
        $consume->execute(['receivers' => ['koh_async_images'], '--limit' => 4, '--time-limit' => 10]);

        self::assertSame([], $this->rows('sys_messenger_messages'), 'handled, neither retried nor parked');
        self::assertSame([], $this->rows('sys_file_processedfile'));
    }

    #[Test]
    public function theSuggestedProfileIsTheOneTheFrontendRequested(): void
    {
        $file = $this->upload();
        $this->get(ImageService::class)->applyProcessingInstructions($file, self::FILE_PROFILE);

        $suggest = new CommandTester($this->get(CommandRegistry::class)->get('koh-async:image-profiles:suggest'));
        $suggest->execute(['--min-count' => '1'], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        $lines = explode("\n", rtrim($suggest->getDisplay()));
        self::assertSame([self::FILE_PROFILE], json_decode((string) end($lines), true));
        self::assertStringStartsWith('     1  {"width":64', $lines[0]);
    }
}

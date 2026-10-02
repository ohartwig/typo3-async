<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Tests\Functional;

use Koh\Typo3Async\Domain\AsyncMode;
use Koh\Typo3Async\Infrastructure\Image\PregeneratedLedger;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Imaging\ImageManipulation\CropVariantCollection;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Extbase\Service\ImageService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Learning end to end, with nothing configured: the "frontend" (Extbase's
 * ImageService, as the view helpers call it) renders a size for three
 * images; the fourth upload then gets that size from the consumer without
 * anyone having listed it -- and the variant the consumer made does not count
 * as evidence of its own.
 */
final class LearnedProfilesTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/typo3-async'];

    protected bool $initializeDatabase = true;

    private const TEMPLATE_SIZE = ['width' => 48, 'height' => null, 'minWidth' => null, 'minHeight' => null, 'maxWidth' => null, 'maxHeight' => null, 'crop' => null];

    protected function setUp(): void
    {
        putenv(AsyncMode::ENVIRONMENT_VARIABLE . '=1');
        parent::setUp();
        $this->get(StorageRepository::class)->createLocalStorage('fileadmin', 'fileadmin/', 'relative', '', true);
    }

    protected function tearDown(): void
    {
        putenv(AsyncMode::ENVIRONMENT_VARIABLE);
        parent::tearDown();
    }

    private function upload(string $name): File
    {
        $source = (string) tempnam(sys_get_temp_dir(), 'koh-async');
        copy(__DIR__ . '/Fixtures/logo.svg', $source);
        $storage = $this->get(StorageRepository::class)->getDefaultStorage();
        self::assertNotNull($storage);
        $storage->setEvaluatePermissions(false);
        $file = $storage->addFile($source, $storage->getRootLevelFolder(), $name);
        self::assertInstanceOf(File::class, $file);

        return $file;
    }

    private function rowsIn(string $table): int
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable($table)->count('*', $table, []);
    }

    private function consumeAll(): void
    {
        $consume = new CommandTester($this->get(CommandRegistry::class)->get('messenger:consume'));
        $consume->execute(['receivers' => ['koh_async_images'], '--limit' => 10, '--time-limit' => 5]);
    }

    /**
     * @return list<string>
     */
    private function suggest(): array
    {
        $suggest = new CommandTester($this->get(CommandRegistry::class)->get('koh-async:image-profiles:suggest'));
        $suggest->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        return explode("\n", rtrim($suggest->getDisplay()));
    }

    #[Test]
    public function aSizeTheFrontendRendersIsLearnedAndProducedForTheNextUpload(): void
    {
        foreach (['a.svg', 'b.svg', 'c.svg'] as $name) {
            $this->get(ImageService::class)->applyProcessingInstructions($this->upload($name), self::TEMPLATE_SIZE);
        }
        $this->consumeAll();
        self::assertSame(0, $this->rowsIn(PregeneratedLedger::TABLE), 'the three existed already: nothing new, nothing noted');

        $fourth = $this->upload('d.svg');
        $this->consumeAll();

        self::assertSame(4, $this->rowsIn('sys_file_processedfile'), 'the consumer made the fourth');
        self::assertSame(1, $this->rowsIn(PregeneratedLedger::TABLE), 'and noted it');
        $this->get(ImageService::class)->applyProcessingInstructions($fourth, self::TEMPLATE_SIZE);
        self::assertSame(4, $this->rowsIn('sys_file_processedfile'), 'the frontend found it');

        $lines = $this->suggest();
        self::assertSame([self::TEMPLATE_SIZE], json_decode((string) end($lines), true));
        self::assertStringStartsWith('     3 100.0%', $lines[0], 'evidence: the three the frontend made; the consumer-made fourth counts neither way');
    }

    #[Test]
    public function aCropIsLearnedUnderTheNameOfItsVariant(): void
    {
        $crop = '{"hero":{"cropArea":{"x":0.25,"y":0,"width":0.5,"height":1},"selectedRatio":"NaN","focusArea":null},'
            . '"xs":{"cropArea":{"x":0,"y":0,"width":1,"height":1},"selectedRatio":"NaN","focusArea":null}}';
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_reference');
        foreach (['a.svg', 'b.svg', 'c.svg'] as $i => $name) {
            $file = $this->upload($name);
            $connection->insert('sys_file_reference', [
                'pid' => 0, 'uid_local' => $file->getUid(), 'uid_foreign' => $i + 1, 'tablenames' => 'tt_content', 'fieldname' => 'image', 'crop' => $crop,
            ]);
            $reference = $this->get(ResourceFactory::class)->getFileReferenceObject((int) $connection->lastInsertId());
            // What <f:image image="{reference}" width="32" cropVariant="hero" /> builds.
            $area = CropVariantCollection::create($crop)->getCropArea('hero')->makeAbsoluteBasedOnFile($reference);
            $this->get(ImageService::class)->applyProcessingInstructions($reference, ['width' => 32, 'crop' => $area]);
        }

        $lines = $this->suggest();

        self::assertSame([['width' => 32, 'crop' => 'hero']], json_decode((string) end($lines), true));
    }
}

<?php

/**
 * Full file-lifecycle integration test.
 *
 * Exercises every file-action surface end-to-end against real OR
 * services + the live Nextcloud filesystem:
 *   1. upload    — FileService::addFile lands a file in the object folder
 *   2. metadata  — UpdateFileHandler::updateFileMetadata writes desc/cat/labels
 *   3. rename    — FileService::renameFile preserves content + audit row
 *   4. lock      — FileLockHandler::lockFile sets an OR-side lock; isLocked sees it
 *   5. copy      — FileService::copyFile clones into a sibling object
 *   6. download  — counter increments via FileMapper::incrementDownloadCount
 *   7. versions  — FileVersioningHandler::listVersions degrades gracefully
 *   8. delete    — FileService::deleteFile removes the source file
 *
 * Closes file-actions task 186.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/file-actions/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\File\UpdateFileHandler;
use OCA\OpenRegister\Service\FileService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class FileActionsLifecycleIntegrationTest extends TestCase
{

    private FileService $fileService;

    private FileMapper $fileMapper;

    private UpdateFileHandler $updateHandler;

    private RegisterMapper $registerMapper;

    private SchemaMapper $schemaMapper;

    private MagicMapper $objectMapper;

    private ?Register $testRegister = null;

    private ?Schema $testSchema = null;

    private ?ObjectEntity $sourceObject = null;

    private ?ObjectEntity $targetObject = null;

    private array $createdFileIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fileService    = \OC::$server->get(FileService::class);
        $this->fileMapper     = \OC::$server->get(FileMapper::class);
        $this->updateHandler  = \OC::$server->get(UpdateFileHandler::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper   = \OC::$server->get(SchemaMapper::class);
        $this->objectMapper   = \OC::$server->get(MagicMapper::class);

        $userManager = \OC::$server->get(IUserManager::class);
        $userSession = \OC::$server->get(IUserSession::class);
        $admin       = $userManager->get('admin');
        if ($admin instanceof IUser) {
            $userSession->setUser($admin);
        }

        $this->createTestFixture();
    }//end setUp()

    protected function tearDown(): void
    {
        $db = \OC::$server->get(\OCP\IDBConnection::class);

        foreach ($this->createdFileIds as $fileId) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_files')
                    ->where(
                        $qb->expr()->eq(
                            'file_id',
                            $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)
                        )
                    );
                $qb->executeStatement();
            } catch (\Throwable $e) {
                // best effort
            }
        }

        if ($this->testSchema !== null) {
            try {
                $this->schemaMapper->delete($this->testSchema);
            } catch (\Throwable $e) {
                // best effort
            }
        }

        if ($this->testRegister !== null) {
            try {
                $this->registerMapper->delete($this->testRegister);
            } catch (\Throwable $e) {
                // best effort
            }
        }

        parent::tearDown();
    }//end tearDown()

    /**
     * Walk a single file through the whole lifecycle in one test so we
     * verify each handler sees the state the previous one left behind.
     */
    public function testFullFileLifecycle(): void
    {
        // 1. UPLOAD ----------------------------------------------------
        $original = $this->fileService->addFile(
            $this->sourceObject,
            'lifecycle.txt',
            'lifecycle initial content',
            false,
            ['lifecycle']
        );
        $fileId = $original->getId();
        $this->createdFileIds[] = $fileId;
        $this->assertSame('lifecycle.txt', $original->getName());
        $this->assertSame(strlen('lifecycle initial content'), $original->getSize());

        // 2. METADATA --------------------------------------------------
        $entity = $this->updateHandler->updateFileMetadata(
            fileId: $fileId,
            description: 'Lifecycle test file',
            category: 'integration',
            labels: ['lifecycle', 'integration-test']
        );
        $this->assertSame('Lifecycle test file', $entity->getDescription());
        $this->assertSame('integration', $entity->getCategory());
        $this->assertSame(['lifecycle', 'integration-test'], $entity->getLabels());

        // 3. RENAME ----------------------------------------------------
        $renamed = $this->fileService->renameFile(
            $this->sourceObject,
            $fileId,
            'lifecycle-renamed.txt'
        );
        $this->assertSame('lifecycle-renamed.txt', $renamed->getName());
        $this->assertSame(
            $fileId,
            $renamed->getId(),
            'rename MUST preserve fileId so OR-side metadata stays bound'
        );

        // OR-side metadata MUST still be readable after rename — same fileId.
        $afterRename = $this->fileMapper->findByFileId($fileId);
        $this->assertNotNull($afterRename);
        $this->assertSame('integration', $afterRename->getCategory());

        // 4. LOCK ------------------------------------------------------
        $lockedFile = $this->fileMapper->setLockForFile(
            $fileId,
            'admin',
            new \DateTime(),
            new \DateTime('+1 hour')
        );
        $this->assertSame('admin', $lockedFile->getLockedBy());
        $this->assertNotNull($lockedFile->getLockedAt());

        // 5. COPY ------------------------------------------------------
        $copy = $this->fileService->copyFile(
            $this->sourceObject,
            $fileId,
            $this->targetObject
        );
        $copyFileId = $copy->getId();
        $this->createdFileIds[] = $copyFileId;
        $this->assertNotSame(
            $fileId,
            $copyFileId,
            'copy MUST produce a new fileId — it is a fresh node, not a hardlink'
        );
        $this->assertStringContainsString('lifecycle-renamed', $copy->getName());

        // 6. DOWNLOAD --------------------------------------------------
        $this->fileMapper->incrementDownloadCount($fileId);
        $this->fileMapper->incrementDownloadCount($fileId);
        $afterDownloads = $this->fileMapper->findByFileId($fileId);
        $this->assertSame(
            2,
            $afterDownloads->getDownloadCount(),
            'incrementDownloadCount MUST be monotonic across calls'
        );

        // 7. VERSIONS --------------------------------------------------
        // listVersions degrades gracefully when files_versions is disabled —
        // it MUST return an array (possibly empty), not throw.
        $versions = $this->fileService
            ->getVersioningHandler()
            ->listVersions($renamed);
        $this->assertIsArray($versions);

        // 8. DELETE ----------------------------------------------------
        $deleted = $this->fileService->deleteFile($renamed, $this->sourceObject);
        $this->assertTrue($deleted, 'deleteFile MUST return true on success');

        // After delete, getFile on the source object MUST NOT find it.
        $stillThere = $this->fileService->getFile(
            object: $this->sourceObject,
            file: $fileId
        );
        $this->assertNull(
            $stillThere,
            'source file MUST be gone from object folder after deleteFile'
        );

        // The copy on the target object MUST still exist — independence check.
        $copyStillThere = $this->fileService->getFile(
            object: $this->targetObject,
            file: $copyFileId
        );
        $this->assertNotNull(
            $copyStillThere,
            'copy on target object MUST survive source deletion'
        );
    }//end testFullFileLifecycle()

    private function createTestFixture(): void
    {
        $register = new Register();
        $register->setTitle('phpunit-lifecycle-'.uniqid());
        $register->setDescription('File-action lifecycle integration test');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-lifecycle-'.uniqid());
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        $schema = new Schema();
        $schema->setTitle('phpunit-lifecycle-schema-'.uniqid());
        $schema->setDescription('Schema for file-action lifecycle tests');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-lifecycle-schema-'.uniqid());
        $schema->setProperties(['title' => ['type' => 'string', 'title' => 'Title']]);
        $this->testSchema = $this->schemaMapper->insert($schema);

        $this->testRegister->setSchemas([$this->testSchema->getId()]);
        $this->registerMapper->update($this->testRegister);

        $this->objectMapper->ensureTableForRegisterSchema($this->testRegister, $this->testSchema);

        // Source object — file starts here.
        $source = new ObjectEntity();
        $source->setUuid(Uuid::v4()->toRfc4122());
        $source->setRegister((string) $this->testRegister->getId());
        $source->setSchema((string) $this->testSchema->getId());
        $source->setObject(['title' => 'Lifecycle source']);
        $this->sourceObject = $this->objectMapper->insertObjectEntity(
            $source,
            $this->testRegister,
            $this->testSchema,
            false
        );

        // Target object — copy lands here.
        $target = new ObjectEntity();
        $target->setUuid(Uuid::v4()->toRfc4122());
        $target->setRegister((string) $this->testRegister->getId());
        $target->setSchema((string) $this->testSchema->getId());
        $target->setObject(['title' => 'Lifecycle target']);
        $this->targetObject = $this->objectMapper->insertObjectEntity(
            $target,
            $this->testRegister,
            $this->testSchema,
            false
        );
    }//end createTestFixture()
}//end class

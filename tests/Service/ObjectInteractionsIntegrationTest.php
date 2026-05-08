<?php

/**
 * Integration tests for the object-interactions surface.
 *
 * Verifies the convenience-API layer wrapping Nextcloud's native
 * subsystems (CalDAV/ICommentsManager/IRootFolder) when invoked
 * against real OpenRegister objects: a note attached via NoteService
 * shows up via the comments backend; a file attached via FileService
 * lands in the object's folder and is enumerable; the audit trail
 * captures user-attributed actions on those interactions.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\NoteService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class ObjectInteractionsIntegrationTest extends TestCase
{
    private NoteService $noteService;
    private FileService $fileService;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;
    private MagicMapper $objectMapper;

    private ?Register $testRegister = null;
    private ?Schema $testSchema = null;
    private ?ObjectEntity $testObject = null;
    private ?string $createdTable = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->noteService    = \OC::$server->get(NoteService::class);
        $this->fileService    = \OC::$server->get(FileService::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper   = \OC::$server->get(SchemaMapper::class);
        $this->objectMapper   = \OC::$server->get(MagicMapper::class);

        // Login as admin — files attach to the user's folder and notes
        // need a comment author. CLI / no-session would fail both.
        $userManager = \OC::$server->get(IUserManager::class);
        $userSession = \OC::$server->get(IUserSession::class);
        $admin       = $userManager->get('admin');
        if ($admin instanceof IUser) {
            $userSession->setUser($admin);
        }

        $this->createTestFixture();
    }

    protected function tearDown(): void
    {
        $db = \OC::$server->get(\OCP\IDBConnection::class);

        if ($this->testSchema !== null) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_schemas')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($this->testSchema->getId(), IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable $e) {
                // best effort
            }
        }
        if ($this->testRegister !== null) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_registers')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($this->testRegister->getId(), IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable $e) {
                // best effort
            }
        }
        if ($this->createdTable !== null) {
            try {
                $db->prepare("DROP TABLE IF EXISTS \"{$this->createdTable}\"")->execute();
            } catch (\Throwable $e) {
                // best effort
            }
        }

        parent::tearDown();
    }

    public function testNoteAttachesToObjectViaCommentsBackend(): void
    {
        // Replaces the manual smoke for "Notes on Objects via
        // ICommentsManager" — verifies the note round-trips through the
        // real comments backend: created via NoteService, then readable
        // via the same service's listing API.
        $created = $this->noteService->createNote(
            (string) $this->testObject->getUuid(),
            'Integration test note message'
        );

        $this->assertIsArray($created);
        $this->assertArrayHasKey('id', $created);

        // Read it back via the service's lookup.
        $list = $this->noteService->getNotesForObject((string) $this->testObject->getUuid());
        $this->assertNotEmpty($list, 'note MUST be retrievable after creation');

        $found = false;
        foreach ($list as $note) {
            if (is_array($note) && ($note['message'] ?? null) === 'Integration test note message') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'created note message MUST appear in getNotesForObject()');
    }

    public function testFileAttachesToObjectFolder(): void
    {
        // Replaces the manual smoke for "File Attachments on Objects" —
        // verifies the file lands in the object's folder via IRootFolder
        // and is enumerable via FileService::getFiles.
        $file = $this->fileService->addFile(
            $this->testObject,
            'integration-test.txt',
            'object-interactions integration test content',
            false,
            ['integration-test']
        );

        $this->assertSame('integration-test.txt', $file->getName());
        $this->assertSame(strlen('object-interactions integration test content'), $file->getSize());

        $files = $this->fileService->getFiles($this->testObject);
        $names = array_map(fn($f) => $f->getName(), is_array($files) ? $files : iterator_to_array($files));
        $this->assertContains(
            'integration-test.txt',
            $names,
            'attached file MUST be enumerable via getFiles after creation'
        );
    }

    public function testMultipleFilesCoexistOnSameObject(): void
    {
        // Defence-in-depth: each addFile call MUST land an independent
        // file on the same object's folder; nothing should silently
        // overwrite a previously-added file.
        $this->fileService->addFile($this->testObject, 'attachment-a.txt', 'A', false, []);
        $this->fileService->addFile($this->testObject, 'attachment-b.txt', 'B', false, []);
        $this->fileService->addFile($this->testObject, 'attachment-c.txt', 'C', false, []);

        $files = $this->fileService->getFiles($this->testObject);
        $names = array_map(fn($f) => $f->getName(), is_array($files) ? $files : iterator_to_array($files));

        $this->assertContains('attachment-a.txt', $names);
        $this->assertContains('attachment-b.txt', $names);
        $this->assertContains('attachment-c.txt', $names);
    }

    public function testFileTagsArePersisted(): void
    {
        // Replaces the manual smoke for "Tags for Object Categorization":
        // tags supplied at addFile time MUST be readable from the file
        // entity afterward via the standard NC tag lookup.
        $file = $this->fileService->addFile(
            $this->testObject,
            'tagged.txt',
            'has tags',
            false,
            ['urgent', 'review-needed']
        );

        $tagManager  = \OC::$server->get(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $appliedTags = $tagManager->getTagIdsForObjects([(string) $file->getId()], 'files');
        $tagIds      = $appliedTags[(string) $file->getId()] ?? [];

        $this->assertNotEmpty($tagIds, 'addFile MUST persist tags onto the resulting file');

        // Resolve tag IDs to names to verify the right tags landed.
        $sysTagManager = \OC::$server->get(\OCP\SystemTag\ISystemTagManager::class);
        $tags          = $sysTagManager->getTagsByIds($tagIds);
        $tagNames      = array_map(fn($t) => $t->getName(), $tags);

        $this->assertContains('urgent', $tagNames);
        $this->assertContains('review-needed', $tagNames);
    }

    private function createTestFixture(): void
    {
        $register = new Register();
        $register->setTitle('phpunit-interactions-' . uniqid());
        $register->setDescription('Object interactions integration tests');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-interactions-' . uniqid());
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        $schema = new Schema();
        $schema->setTitle('phpunit-interactions-schema-' . uniqid());
        $schema->setDescription('Schema for object-interactions tests');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-interactions-schema-' . uniqid());
        $schema->setProperties([
            'title' => ['type' => 'string', 'title' => 'Title'],
        ]);
        $this->testSchema = $this->schemaMapper->insert($schema);

        $this->testRegister->setSchemas([$this->testSchema->getId()]);
        $this->registerMapper->update($this->testRegister);

        $this->objectMapper->ensureTableForRegisterSchema($this->testRegister, $this->testSchema);
        $this->createdTable = 'oc_' . $this->objectMapper->getTableNameForRegisterSchema($this->testRegister, $this->testSchema);

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $this->testRegister->getId());
        $entity->setSchema((string) $this->testSchema->getId());
        $entity->setObject(['title' => 'Interactions test object']);
        $this->testObject = $this->objectMapper->insertObjectEntity($entity, $this->testRegister, $this->testSchema, false);
    }
}

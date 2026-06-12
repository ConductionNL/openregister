<?php

/**
 * Integration tests for empty-string datetime handling.
 *
 * The bug: `new DateTime('')` silently returns the current date-time, so
 * a form that submitted `""` for a cleared date field would persist the
 * moment-of-save as the value. This test exercises the fix end-to-end —
 * a real save through `SaveObject`, a real DB read, and an assertion
 * that empty-string input round-trips as `null` rather than as "now".
 *
 * Without the `DateTimeNormalizer` guard installed in `ObjectService`
 * and `MagicMapper`, the empty-string test below would fail with the
 * stored value being roughly `time()` instead of `null`.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\ObjectService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class EmptyStringDateConversionIntegrationTest extends TestCase
{
    private SaveObject $saveHandler;
    private ObjectService $objectService;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;
    private MagicMapper $objectMapper;

    private ?Register $testRegister = null;
    private ?Schema $testSchema = null;
    /** @var string[] */
    private array $createdObjectUuids = [];
    private ?string $createdTable = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->saveHandler    = \OC::$server->get(SaveObject::class);
        $this->objectService  = \OC::$server->get(ObjectService::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper   = \OC::$server->get(SchemaMapper::class);
        $this->objectMapper   = \OC::$server->get(MagicMapper::class);

        $this->createTestRegisterAndSchema();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdObjectUuids as $uuid) {
            try {
                $this->objectService->deleteObject($uuid, false, false);
            } catch (\Throwable $e) {
                // best effort
            }
        }

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

    public function testEmptyStringDateRoundTripsAsNull(): void
    {
        // Fix verification: an empty string MUST NOT be silently converted to
        // the current datetime. The frontend often submits `""` to clear a
        // date field — pre-fix, that would persist as the moment of save.
        $saved = $this->saveTestObject([
            'title'       => 'EmptyString',
            'publishedAt' => '',
        ]);

        $fetched = $this->objectMapper->find($saved->getUuid());
        $data    = $fetched->getObject() ?? [];

        $this->assertArrayHasKey('publishedAt', $data, 'normalised empty-string field should still appear in object data');
        $this->assertNull($data['publishedAt'], 'empty string must round-trip as null, not as the current datetime');
    }

    public function testValidIso8601DateRoundTripsCorrectly(): void
    {
        $saved = $this->saveTestObject([
            'title'       => 'ValidIso',
            'publishedAt' => '2026-04-20T14:00:00+00:00',
        ]);

        $fetched = $this->objectMapper->find($saved->getUuid());
        $data    = $fetched->getObject() ?? [];

        $this->assertArrayHasKey('publishedAt', $data);
        $this->assertNotNull($data['publishedAt']);
        // The normaliser may format as ISO 8601 with offset or as DB Y-m-d H:i:s;
        // either way the value must parse to the same instant we asked for.
        $stored = new \DateTimeImmutable((string) $data['publishedAt']);
        $expected = new \DateTimeImmutable('2026-04-20T14:00:00+00:00');
        $this->assertSame(
            $expected->getTimestamp(),
            $stored->getTimestamp(),
            'valid ISO 8601 input must round-trip to the same instant'
        );
    }

    public function testAbsentDateFieldRemainsAbsentOrNull(): void
    {
        $saved = $this->saveTestObject([
            'title' => 'AbsentField',
        ]);

        $fetched = $this->objectMapper->find($saved->getUuid());
        $data    = $fetched->getObject() ?? [];

        // Implementation choice: absent input may either be missing from the
        // stored object or present-as-null. Both are acceptable; what's NOT
        // acceptable is the field being silently set to "now" or any other
        // computed datetime value.
        if (array_key_exists('publishedAt', $data) === true) {
            $this->assertNull($data['publishedAt'], 'absent date field must not be auto-populated');
        }
    }

    public function testWhitespaceOnlyDateRoundTripsAsNull(): void
    {
        // Defence-in-depth: whitespace-only strings (`"   "`, `"\t"`, `"\n"`)
        // are the same hazard as empty strings — `new DateTime("   ")`
        // also silently returns "now".
        $saved = $this->saveTestObject([
            'title'       => 'WhitespaceOnly',
            'publishedAt' => '   ',
        ]);

        $fetched = $this->objectMapper->find($saved->getUuid());
        $data    = $fetched->getObject() ?? [];

        $this->assertArrayHasKey('publishedAt', $data);
        $this->assertNull($data['publishedAt'], 'whitespace-only must normalise to null');
    }

    private function saveTestObject(array $data): \OCA\OpenRegister\Db\ObjectEntity
    {
        $result = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->testSchema,
            $data,
            null,
            null,
            false, // _rbac
            false  // _multitenancy
        );
        $this->createdObjectUuids[] = $result->getUuid();
        return $result;
    }

    private function createTestRegisterAndSchema(): void
    {
        $register = new Register();
        $register->setTitle('phpunit-empty-date-' . uniqid());
        $register->setDescription('Empty-string date conversion verification');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-empty-date-' . uniqid());
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        $schema = new Schema();
        $schema->setTitle('phpunit-empty-date-schema-' . uniqid());
        $schema->setDescription('Schema with a date-time property for empty-string normalisation tests');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-empty-date-schema-' . uniqid());
        $schema->setProperties([
            'title' => [
                'type'     => 'string',
                'title'    => 'Title',
                'required' => true,
            ],
            'publishedAt' => [
                'type'   => 'string',
                'title'  => 'Published At',
                'format' => 'date-time',
            ],
        ]);
        $this->testSchema = $this->schemaMapper->insert($schema);

        $this->testRegister->setSchemas([$this->testSchema->getId()]);
        $this->registerMapper->update($this->testRegister);

        $this->objectMapper->ensureTableForRegisterSchema($this->testRegister, $this->testSchema);
        $this->createdTable = 'oc_' . $this->objectMapper->getTableNameForRegisterSchema($this->testRegister, $this->testSchema);
    }
}

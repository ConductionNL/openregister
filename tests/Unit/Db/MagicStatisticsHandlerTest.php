<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression tests for MagicStatisticsHandler row-to-entity conversion.
 *
 * Pinning the empty-string date-rendering defect fixed by
 * `fix-empty-string-date-conversion`: before the fix, a stored empty-string
 * value on a date/date-time property rendered as the current datetime because
 * `new DateTime('')` silently returns "now"; after the fix, such values must
 * render as `null`.
 */
class MagicStatisticsHandlerTest extends TestCase
{

    private IDBConnection&MockObject $db;

    private LoggerInterface&MockObject $logger;

    private RegisterMapper&MockObject $registerMapper;

    private SchemaMapper&MockObject $schemaMapper;

    private MagicStatisticsHandler $handler;

    protected function setUp(): void
    {
        $this->db     = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper   = $this->createMock(SchemaMapper::class);

        $this->handler = new MagicStatisticsHandler(
            db: $this->db,
            logger: $this->logger,
            registerMapper: $this->registerMapper,
            schemaMapper: $this->schemaMapper,
            dateTimeNormalizer: new DateTimeNormalizer($this->logger),
            schemaTypeConverter: new SchemaTypeConverter()
        );
    }//end setUp()

    /**
     * Build a real Register with a fixed id.
     *
     * Entity::getId() is final on the framework base class, so mocking it
     * fails; using the concrete class keeps the test focused on the handler.
     */
    private function makeRegister(int $id=1): Register
    {
        $register = new Register();
        $register->setId($id);
        return $register;
    }//end makeRegister()

    /**
     * Build a real Schema with the given properties map and a fixed id.
     */
    private function makeSchema(array $properties, int $id=1): Schema
    {
        $schema = new Schema();
        $schema->setId($id);
        $schema->setProperties($properties);
        return $schema;
    }//end makeSchema()

    /**
     * Empty-string value for a `date-time` property MUST render as `null`,
     * NOT as the current datetime.
     *
     * Before the fix, line ~590 executed `new DateTime('')` which silently
     * produced "now"; this test FAILS before the fix and PASSES after.
     */
    public function testEmptyStringDateTimePropertyRendersAsNull(): void
    {
        $register = $this->makeRegister();
        $schema   = $this->makeSchema(
            properties: ['publishedAt' => ['type' => 'string', 'format' => 'date-time']]
        );

        $row = [
            '_uuid'        => 'test-uuid-1',
            '_id'          => 1,
            '_register'    => 1,
            '_schema'      => 1,
            'published_at' => '',
        ];

        $entity = $this->handler->convertRowToObjectEntity($row, $register, $schema);

        $this->assertNotNull($entity, 'Row should convert to an ObjectEntity');
        $data = $entity->getObject();
        $this->assertArrayHasKey('publishedAt', $data);
        $this->assertNull(
            $data['publishedAt'],
            'Empty-string stored date-time value must render as null, not as the current datetime'
        );
    }//end testEmptyStringDateTimePropertyRendersAsNull()

    /**
     * Empty-string value for a `date` property MUST render as `null`, NOT as
     * today's date. Pins line ~583 ('date' branch).
     */
    public function testEmptyStringDatePropertyRendersAsNull(): void
    {
        $register = $this->makeRegister();
        $schema   = $this->makeSchema(
            properties: ['birthDate' => ['type' => 'string', 'format' => 'date']]
        );

        $row = [
            '_uuid'      => 'test-uuid-2',
            '_id'        => 2,
            '_register'  => 1,
            '_schema'    => 1,
            'birth_date' => '',
        ];

        $entity = $this->handler->convertRowToObjectEntity($row, $register, $schema);

        $this->assertNotNull($entity, 'Row should convert to an ObjectEntity');
        $data = $entity->getObject();
        $this->assertArrayHasKey('birthDate', $data);
        $this->assertNull(
            $data['birthDate'],
            'Empty-string stored date value must render as null, not as today'
        );
    }//end testEmptyStringDatePropertyRendersAsNull()

    /**
     * Valid stored date-time value still round-trips correctly in ISO 8601
     * format. Guards against an over-zealous fix that would null-out good data.
     */
    public function testValidDateTimePropertyRendersAsIso8601(): void
    {
        $register = $this->makeRegister();
        $schema   = $this->makeSchema(
            properties: ['publishedAt' => ['type' => 'string', 'format' => 'date-time']]
        );

        $row = [
            '_uuid'        => 'test-uuid-3',
            '_id'          => 3,
            '_register'    => 1,
            '_schema'      => 1,
            'published_at' => '2026-04-20 14:00:00',
        ];

        $entity = $this->handler->convertRowToObjectEntity($row, $register, $schema);

        $this->assertNotNull($entity, 'Row should convert to an ObjectEntity');
        $data = $entity->getObject();
        $this->assertArrayHasKey('publishedAt', $data);
        $this->assertIsString($data['publishedAt']);
        $this->assertStringStartsWith(
            '2026-04-20T14:00:00',
            $data['publishedAt'],
            'Valid stored date-time must render as ISO 8601'
        );
    }//end testValidDateTimePropertyRendersAsIso8601()

    /**
     * Whitespace-only values (e.g. "   ") MUST render as `null`; PHP's
     * `DateTime` parser would otherwise coerce or reject them inconsistently.
     */
    public function testWhitespaceOnlyDateTimePropertyRendersAsNull(): void
    {
        $register = $this->makeRegister();
        $schema   = $this->makeSchema(
            properties: ['publishedAt' => ['type' => 'string', 'format' => 'date-time']]
        );

        $row = [
            '_uuid'        => 'test-uuid-4',
            '_id'          => 4,
            '_register'    => 1,
            '_schema'      => 1,
            'published_at' => '   ',
        ];

        $entity = $this->handler->convertRowToObjectEntity($row, $register, $schema);

        $this->assertNotNull($entity, 'Row should convert to an ObjectEntity');
        $data = $entity->getObject();
        $this->assertArrayHasKey('publishedAt', $data);
        $this->assertNull(
            $data['publishedAt'],
            'Whitespace-only stored date-time value must render as null'
        );
    }//end testWhitespaceOnlyDateTimePropertyRendersAsNull()
}//end class

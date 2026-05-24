<?php

/**
 * Unit tests for the JSONB → JSON column-type fix that preserves client-supplied
 * key order for object-typed schema properties (issue #1720).
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Db\Entity;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Targets the three behaviour changes made to address issue #1720:
 *
 *   1. mapSchemaPropertyToColumn() emits 'json_ordered' instead of 'json'
 *      for type:object schema properties (so they bypass PostgreSQL's
 *      key-reordering JSONB storage).
 *   2. mapColumnTypeToSQL() maps 'json_ordered' to PostgreSQL JSON (which
 *      stores documents verbatim) and to MySQL JSON.
 *   3. findJsonbColumnsNeedingRetype() identifies live JSONB columns that
 *      need migrating to JSON when the schema property is type:object.
 *
 * The migration plumbing itself (executeStatement calls) is exercised by the
 * verify-via-curl repro in the PR description rather than via a unit-test mock.
 */
class MagicMapperKeyOrderColumnTypeTest extends TestCase
{

    private IDBConnection&MockObject $db;

    private SchemaMapper&MockObject $schemaMapper;

    private RegisterMapper&MockObject $registerMapper;

    private IConfig&MockObject $config;

    private IEventDispatcher&MockObject $eventDispatcher;

    private IUserSession&MockObject $userSession;

    private IGroupManager&MockObject $groupManager;

    private IUserManager&MockObject $userManager;

    private IAppConfig&MockObject $appConfig;

    private LoggerInterface&MockObject $logger;

    private SettingsService&MockObject $settingsService;

    private ContainerInterface&MockObject $container;

    /**
     * Build a MagicMapper instance bypassing the constructor.
     *
     * The constructor builds many handlers we don't need; using
     * newInstanceWithoutConstructor() + reflection lets us exercise the
     * column-mapping methods in isolation.
     *
     * @return MagicMapper Mapper instance with db and logger wired up.
     */
    private function buildMapperWithoutConstructor(): MagicMapper
    {
        $this->db              = $this->createMock(IDBConnection::class);
        $this->schemaMapper    = $this->createMock(SchemaMapper::class);
        $this->registerMapper  = $this->createMock(RegisterMapper::class);
        $this->config          = $this->createMock(IConfig::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);
        $this->userSession     = $this->createMock(IUserSession::class);
        $this->groupManager    = $this->createMock(IGroupManager::class);
        $this->userManager     = $this->createMock(IUserManager::class);
        $this->appConfig       = $this->createMock(IAppConfig::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->container       = $this->createMock(ContainerInterface::class);

        $reflection = new ReflectionClass(MagicMapper::class);
        $mapper     = $reflection->newInstanceWithoutConstructor();

        // Inject only the fields the methods under test actually touch.
        $properties = [
            'db'     => $this->db,
            'logger' => $this->logger,
        ];

        foreach ($properties as $name => $value) {
            $prop = $reflection->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($mapper, $value);
        }

        return $mapper;
    }//end buildMapperWithoutConstructor()

    /**
     * Invoke a private MagicMapper method by name.
     *
     * @param MagicMapper $mapper Mapper instance.
     * @param string      $method Method name.
     * @param array       $args   Positional arguments.
     *
     * @return mixed Return value of the invocation.
     */
    private function invokePrivate(MagicMapper $mapper, string $method, array $args): mixed
    {
        $reflectionMethod = new ReflectionMethod(MagicMapper::class, $method);
        $reflectionMethod->setAccessible(true);
        return $reflectionMethod->invokeArgs($mapper, $args);
    }//end invokePrivate()

    /**
     * Object-typed schema properties (without a $ref to another register) must
     * map to the new 'json_ordered' column type so the column gets PostgreSQL
     * JSON storage rather than JSONB. Plain `json` here would land back on JSONB
     * and re-order client-supplied keys.
     *
     * @return void
     */
    public function testObjectTypedPropertyMapsToOrderPreservingType(): void
    {
        $mapper = $this->buildMapperWithoutConstructor();

        $result = $this->invokePrivate(
            $mapper,
            'mapSchemaPropertyToColumn',
            ['mapping', ['type' => 'object']]
        );

        $this->assertSame('mapping', $result['name']);
        $this->assertSame('json_ordered', $result['type']);
        $this->assertTrue($result['nullable']);
    }//end testObjectTypedPropertyMapsToOrderPreservingType()

    /**
     * Array-typed schema properties keep the 'json' type (→ JSONB on PostgreSQL).
     * Arrays are inherently ordered regardless of JSONB vs JSON, and the
     * MagicSearch/Facet handlers rely on JSONB containment operators (@>) for
     * filtering — switching them would regress search semantics.
     *
     * @return void
     */
    public function testArrayTypedPropertyKeepsLegacyJsonType(): void
    {
        $mapper = $this->buildMapperWithoutConstructor();

        $result = $this->invokePrivate(
            $mapper,
            'mapSchemaPropertyToColumn',
            ['unset', ['type' => 'array']]
        );

        $this->assertSame('unset', $result['name']);
        $this->assertSame('json', $result['type']);
    }//end testArrayTypedPropertyKeepsLegacyJsonType()

    /**
     * Object-with-$ref-and-related-object-handling continues to short-circuit
     * to VARCHAR storage (one row = one UUID string). This path predates #1720
     * and must remain unchanged.
     *
     * @return void
     */
    public function testRelatedObjectReferenceStillMapsToVarchar(): void
    {
        $mapper = $this->buildMapperWithoutConstructor();

        $result = $this->invokePrivate(
            $mapper,
            'mapSchemaPropertyToColumn',
            [
                'parent',
                [
                    'type'                => 'object',
                    '$ref'                => '#/components/schemas/other',
                    'objectConfiguration' => ['handling' => 'related-object'],
                ],
            ]
        );

        $this->assertSame('parent', $result['name']);
        $this->assertSame('string', $result['type']);
        $this->assertSame(255, $result['length']);
    }//end testRelatedObjectReferenceStillMapsToVarchar()

    /**
     * 'json_ordered' must map to JSON (verbatim storage) on PostgreSQL.
     *
     * @return void
     */
    public function testJsonOrderedMapsToPostgresJson(): void
    {
        $mapper   = $this->buildMapperWithoutConstructor();
        $platform = $this->createMock(PostgreSQLPlatform::class);
        $this->db->method('getDatabasePlatform')->willReturn($platform);

        $sql = $this->invokePrivate(
            $mapper,
            'mapColumnTypeToSQL',
            ['json_ordered', []]
        );

        $this->assertSame('JSON', $sql);
    }//end testJsonOrderedMapsToPostgresJson()

    /**
     * 'json' (the legacy tag used for array-typed columns) keeps mapping to
     * JSONB on PostgreSQL — the regression we'd cause by accidentally flipping
     * arrays here would break the @> containment-operator path.
     *
     * @return void
     */
    public function testLegacyJsonMapsToPostgresJsonb(): void
    {
        $mapper   = $this->buildMapperWithoutConstructor();
        $platform = $this->createMock(PostgreSQLPlatform::class);
        $this->db->method('getDatabasePlatform')->willReturn($platform);

        $sql = $this->invokePrivate(
            $mapper,
            'mapColumnTypeToSQL',
            ['json', []]
        );

        $this->assertSame('JSONB', $sql);
    }//end testLegacyJsonMapsToPostgresJsonb()

    /**
     * Both 'json' and 'json_ordered' collapse to MySQL JSON on non-PostgreSQL.
     * MySQL/MariaDB's JSON type stores documents in their original order, so
     * no platform-specific split is needed.
     *
     * @return void
     */
    public function testJsonOrderedMapsToMysqlJson(): void
    {
        $mapper   = $this->buildMapperWithoutConstructor();
        $platform = $this->createMock(\Doctrine\DBAL\Platforms\MySQLPlatform::class);
        $this->db->method('getDatabasePlatform')->willReturn($platform);

        $sql = $this->invokePrivate(
            $mapper,
            'mapColumnTypeToSQL',
            ['json_ordered', []]
        );

        $this->assertSame('JSON', $sql);
    }//end testJsonOrderedMapsToMysqlJson()

    /**
     * findJsonbColumnsNeedingRetype() returns the column name when:
     *   - the live database column is JSONB, and
     *   - the schema's required-column type is 'json_ordered'.
     *
     * This is the discriminator the table-sync uses to know an existing table
     * needs an in-place migration to JSON.
     *
     * @return void
     */
    public function testFindJsonbColumnsNeedingRetypeIdentifiesLegacyColumn(): void
    {
        $mapper   = $this->buildMapperWithoutConstructor();
        $platform = $this->createMock(PostgreSQLPlatform::class);
        $this->db->method('getDatabasePlatform')->willReturn($platform);

        $current = [
            'mapping' => ['name' => 'mapping', 'type' => 'jsonb', 'nullable' => true],
            'unset'   => ['name' => 'unset', 'type' => 'jsonb', 'nullable' => true],
        ];

        $required = [
            'mapping' => ['name' => 'mapping', 'type' => 'json_ordered', 'nullable' => true],
            'unset'   => ['name' => 'unset', 'type' => 'json', 'nullable' => true],
        ];

        $result = $mapper->findJsonbColumnsNeedingRetype($current, $required);

        $this->assertSame(['mapping'], $result);
    }//end testFindJsonbColumnsNeedingRetypeIdentifiesLegacyColumn()

    /**
     * Once a column has been migrated to JSON, the helper must NOT report it
     * again — otherwise every save would re-fire the ALTER TABLE. Idempotency.
     *
     * @return void
     */
    public function testFindJsonbColumnsNeedingRetypeSkipsAlreadyMigratedColumn(): void
    {
        $mapper   = $this->buildMapperWithoutConstructor();
        $platform = $this->createMock(PostgreSQLPlatform::class);
        $this->db->method('getDatabasePlatform')->willReturn($platform);

        $current  = [
            'mapping' => ['name' => 'mapping', 'type' => 'json', 'nullable' => true],
        ];
        $required = [
            'mapping' => ['name' => 'mapping', 'type' => 'json_ordered', 'nullable' => true],
        ];

        $this->assertSame([], $mapper->findJsonbColumnsNeedingRetype($current, $required));
    }//end testFindJsonbColumnsNeedingRetypeSkipsAlreadyMigratedColumn()

    /**
     * On MySQL/MariaDB and any other non-PostgreSQL platform the JSON type
     * already preserves document order, so the helper must return an empty
     * list without inspecting the column maps. This keeps the fast-path cheap.
     *
     * @return void
     */
    public function testFindJsonbColumnsNeedingRetypeShortCircuitsOnMysql(): void
    {
        $mapper   = $this->buildMapperWithoutConstructor();
        $platform = $this->createMock(\Doctrine\DBAL\Platforms\MySQLPlatform::class);
        $this->db->method('getDatabasePlatform')->willReturn($platform);

        $current  = [
            'mapping' => ['name' => 'mapping', 'type' => 'json', 'nullable' => true],
        ];
        $required = [
            'mapping' => ['name' => 'mapping', 'type' => 'json_ordered', 'nullable' => true],
        ];

        $this->assertSame([], $mapper->findJsonbColumnsNeedingRetype($current, $required));
    }//end testFindJsonbColumnsNeedingRetypeShortCircuitsOnMysql()
}//end class

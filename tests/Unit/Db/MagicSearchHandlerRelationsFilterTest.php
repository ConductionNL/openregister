<?php
/**
 * Phase-0 regression: `_relations.<field>` VALUE-filtering + reserved-key exclusion.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Locks the Phase-0 fixes around the dotted relation-field filter and the
 * reserved (in-request search) parameter exclusion:
 *
 *  - `_relations.<field>=<id>` must produce a condition that matches only when
 *    the referenced id (`kv.value = '<id>'`) is present under the named field
 *    (`kv.key = '<field>'`) — NOT merely when the relation field exists.
 *  - The in-request reserved keys (`_ids`, `_search`, `_rbac`, `_multitenancy`,
 *    etc.) must NEVER leak into the object-field filter loop and force a `1=0`
 *    condition that would silently return zero rows.
 */
class MagicSearchHandlerRelationsFilterTest extends TestCase
{

    private IDBConnection&MockObject $db;

    private LoggerInterface&MockObject $logger;

    private MagicRbacHandler&MockObject $rbacHandler;

    private MagicOrganizationHandler&MockObject $organizationHandler;

    private MagicSearchHandler $handler;

    protected function setUp(): void
    {
        $this->db          = $this->createMock(IDBConnection::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
        $this->rbacHandler = $this->createMock(MagicRbacHandler::class);
        $this->organizationHandler = $this->createMock(MagicOrganizationHandler::class);

        $this->handler = new MagicSearchHandler(
            db: $this->db,
            logger: $this->logger,
            rbacHandler: $this->rbacHandler,
            organizationHandler: $this->organizationHandler,
            schemaTypeConverter: new SchemaTypeConverter(),
            dateTimeNormalizer: new DateTimeNormalizer($this->logger)
        );
    }//end setUp()

    /**
     * Build a connection mock whose quote() wraps values in single quotes.
     */
    private function makeConnection(): object
    {
        $conn = $this->createMock(IDBConnection::class);
        $conn->method('quote')->willReturnCallback(fn($v) => "'{$v}'");
        return $conn;
    }//end makeConnection()

    /**
     * Invoke the private buildRelationFilterConditionsSql() via reflection.
     *
     * @param array<string,mixed> $query Search query.
     *
     * @return string[] Generated SQL conditions.
     */
    private function invokeRelationFilters(array $query): array
    {
        $method = new ReflectionMethod(MagicSearchHandler::class, 'buildRelationFilterConditionsSql');
        $method->setAccessible(true);
        return $method->invoke($this->handler, $query, $this->makeConnection());
    }//end invokeRelationFilters()

    /**
     * Invoke the private buildObjectFilterConditionsSql() via reflection.
     *
     * @param array<string,mixed> $query      Search query.
     * @param array<string,mixed> $properties Schema properties (field => ['type' => ...]).
     *
     * @return string[] Generated SQL conditions.
     */
    private function invokeObjectFilters(array $query, array $properties): array
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('getProperties')->willReturn($properties);

        $method = new ReflectionMethod(MagicSearchHandler::class, 'buildObjectFilterConditionsSql');
        $method->setAccessible(true);
        return $method->invoke($this->handler, $query, $schema, $this->makeConnection(), true);
    }//end invokeObjectFilters()

    // -------------------------------------------------------------------------
    // `_relations.<field>` VALUE-filtering
    // -------------------------------------------------------------------------

    public function testRelationFilterMatchesReferencedIdValueNotPresenceOnly(): void
    {
        $conditions = $this->invokeRelationFilters(['_relations.author' => 'person-42']);

        $this->assertCount(1, $conditions);
        $sql = $conditions[0];

        // It must filter on the referenced id VALUE, not merely the field's presence.
        $this->assertStringContainsString("kv.value = 'person-42'", $sql);
        // And scope that value to the named relation field (exact or array-indexed).
        $this->assertStringContainsString("kv.key = 'author'", $sql);
        $this->assertStringContainsString("kv.key LIKE 'author.%'", $sql);
        // The array-format relation must also be matched by the value.
        $this->assertStringContainsString("_relations @> to_jsonb('person-42'::text)", $sql);
    }//end testRelationFilterMatchesReferencedIdValueNotPresenceOnly()

    public function testRelationFilterDoesNotMatchOnFieldPresenceAlone(): void
    {
        // A presence-only filter (the pre-fix behaviour) would have keyed solely
        // on the field name. Assert the generated SQL never reduces to a bare
        // `? ? 'author'`-style key-existence check without the value predicate.
        $conditions = $this->invokeRelationFilters(['_relations.author' => 'person-42']);
        $sql        = $conditions[0];

        $this->assertStringNotContainsString("_relations ? 'author'", $sql);
        $this->assertStringContainsString("kv.value = 'person-42'", $sql);
    }//end testRelationFilterDoesNotMatchOnFieldPresenceAlone()

    public function testMultipleRelationFiltersEachCarryTheirOwnValue(): void
    {
        $conditions = $this->invokeRelationFilters(
            [
                '_relations.author'   => 'person-1',
                '_relations.reviewer' => 'person-2',
            ]
        );

        $this->assertCount(2, $conditions);
        $this->assertStringContainsString("kv.value = 'person-1'", $conditions[0]);
        $this->assertStringContainsString("kv.key = 'author'", $conditions[0]);
        $this->assertStringContainsString("kv.value = 'person-2'", $conditions[1]);
        $this->assertStringContainsString("kv.key = 'reviewer'", $conditions[1]);
    }//end testMultipleRelationFiltersEachCarryTheirOwnValue()

    public function testRelationFilterSkipsEmptyOrArrayValues(): void
    {
        $conditions = $this->invokeRelationFilters(
            [
                '_relations.author'   => '',
                '_relations.reviewer' => ['x'],
                '_relations.'         => 'person-9',
                '_relations.editor'   => 'person-7',
            ]
        );

        // Only the well-formed scalar filter survives.
        $this->assertCount(1, $conditions);
        $this->assertStringContainsString("kv.value = 'person-7'", $conditions[0]);
        $this->assertStringContainsString("kv.key = 'editor'", $conditions[0]);
    }//end testRelationFilterSkipsEmptyOrArrayValues()

    // -------------------------------------------------------------------------
    // Reserved (in-request search) keys must not become object-field filters
    // -------------------------------------------------------------------------

    public function testReservedSearchKeysAreExcludedFromObjectFieldFilters(): void
    {
        // `_ids`, `_search`, `_rbac`, `_multitenancy` are reserved/underscore keys.
        // None of them is a schema column; if they leaked into the object-field
        // loop each would force a `1=0` condition and silently empty the result.
        $conditions = $this->invokeObjectFilters(
            query: [
                '_ids'          => ['a', 'b'],
                '_search'       => 'hello',
                '_rbac'         => false,
                '_multitenancy' => false,
            ],
            properties: ['name' => ['type' => 'string']]
        );

        $this->assertSame([], $conditions, 'Reserved keys must not generate any object-field condition.');
    }//end testReservedSearchKeysAreExcludedFromObjectFieldFilters()

    public function testGenuineObjectFieldStillFiltersAlongsideReservedKeys(): void
    {
        // A real schema field must still produce a filter while reserved keys
        // pass through untouched.
        $conditions = $this->invokeObjectFilters(
            query: [
                '_ids'  => ['a'],
                '_rbac' => false,
                'name'  => 'Alice',
            ],
            properties: ['name' => ['type' => 'string']]
        );

        $this->assertCount(1, $conditions);
        $this->assertStringContainsString("'Alice'", $conditions[0]);
    }//end testGenuineObjectFieldStillFiltersAlongsideReservedKeys()

    public function testUnknownObjectFieldStillForcesImpossibleCondition(): void
    {
        // Guard against over-broadening the exclusion: a non-reserved, non-schema
        // key must still yield `1=0` (its pre-fix behaviour is correct).
        $conditions = $this->invokeObjectFilters(
            query: ['nonexistent' => 'x'],
            properties: ['name' => ['type' => 'string']]
        );

        $this->assertSame(['1=0'], $conditions);
    }//end testUnknownObjectFieldStillForcesImpossibleCondition()

    // -------------------------------------------------------------------------
    // isObjectFieldFilterKey classifier
    // -------------------------------------------------------------------------

    public function testIsObjectFieldFilterKeyExcludesReservedAndSystemKeys(): void
    {
        $method = new ReflectionMethod(MagicSearchHandler::class, 'isObjectFieldFilterKey');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($this->handler, '@self'));
        $this->assertFalse($method->invoke($this->handler, '_ids'));
        $this->assertFalse($method->invoke($this->handler, '_search'));
        $this->assertFalse($method->invoke($this->handler, '_rbac'));
        $this->assertFalse($method->invoke($this->handler, '_multitenancy'));
        $this->assertFalse($method->invoke($this->handler, 'register'));
        $this->assertFalse($method->invoke($this->handler, 'schema'));

        $this->assertTrue($method->invoke($this->handler, 'name'));
        $this->assertTrue($method->invoke($this->handler, 'author'));
    }//end testIsObjectFieldFilterKeyExcludesReservedAndSystemKeys()
}//end class

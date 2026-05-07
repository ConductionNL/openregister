<?php

/**
 * Integration tests for the Twig-based computed-fields evaluator.
 *
 * Verifies the `ComputedFieldHandler` end-to-end: schema properties
 * with `computed.expression` Twig expressions are evaluated against
 * the object data context and the result is materialised into the
 * object's data on save (or evaluated on read for `evaluateOn:read`).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class ComputedFieldsIntegrationTest extends TestCase
{
    private ComputedFieldHandler $handler;
    private SchemaMapper $schemaMapper;
    /** @var Schema[] */
    private array $createdSchemas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler      = \OC::$server->get(ComputedFieldHandler::class);
        $this->schemaMapper = \OC::$server->get(SchemaMapper::class);
    }

    protected function tearDown(): void
    {
        $db = \OC::$server->get(\OCP\IDBConnection::class);
        foreach ($this->createdSchemas as $schema) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_schemas')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($schema->getId(), IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable $e) {
                // best effort
            }
        }
        parent::tearDown();
    }

    public function testHasComputedPropertiesDetectsComputedAttribute(): void
    {
        $with    = $this->makeSchema([
            'firstName' => ['type' => 'string'],
            'lastName'  => ['type' => 'string'],
            'fullName'  => [
                'type'     => 'string',
                'computed' => ['expression' => '{{ firstName }} {{ lastName }}'],
            ],
        ]);
        $without = $this->makeSchema([
            'title' => ['type' => 'string'],
        ]);

        $this->assertTrue(
            $this->handler->hasComputedProperties($with),
            'schema with a computed property MUST report hasComputedProperties() === true'
        );
        $this->assertFalse(
            $this->handler->hasComputedProperties($without),
            'schema without computed properties MUST report hasComputedProperties() === false'
        );
    }

    public function testEvaluateComputedFieldsRendersTwigExpression(): void
    {
        $schema = $this->makeSchema([
            'firstName' => ['type' => 'string'],
            'lastName'  => ['type' => 'string'],
            'fullName'  => [
                'type'     => 'string',
                'computed' => ['expression' => '{{ firstName }} {{ lastName }}'],
            ],
        ]);

        $result = $this->handler->evaluateComputedFields(
            ['firstName' => 'Femke', 'lastName' => 'Halsema'],
            $schema,
            'save'
        );

        $this->assertSame('Femke Halsema', $result['fullName']);
    }

    public function testEvaluateComputedFieldsRespectsEvaluateOnSave(): void
    {
        // Property with `evaluateOn: read` MUST NOT fire during save-time
        // evaluation; only `save` properties should materialise.
        $schema = $this->makeSchema([
            'price'      => ['type' => 'number'],
            'taxAmount'  => [
                'type'     => 'number',
                'computed' => [
                    'expression' => '{{ price * 0.21 }}',
                    'evaluateOn' => 'save',
                ],
            ],
            'totalLabel' => [
                'type'     => 'string',
                'computed' => [
                    'expression' => 'Total: {{ price + (price * 0.21) }}',
                    'evaluateOn' => 'read',
                ],
            ],
        ]);

        $saveResult = $this->handler->evaluateComputedFields(['price' => 100], $schema, 'save');
        $this->assertSame('21', (string) $saveResult['taxAmount'], 'save-time computed property MUST be evaluated');
        $this->assertArrayNotHasKey(
            'totalLabel',
            $saveResult,
            'evaluateOn=read property MUST NOT fire during save-time evaluation'
        );

        $readResult = $this->handler->evaluateComputedFields(['price' => 100], $schema, 'read');
        $this->assertSame(
            'Total: 121',
            $readResult['totalLabel'] ?? null,
            'evaluateOn=read property MUST fire during read-time evaluation'
        );
    }

    public function testEvaluateComputedFieldsSkipsPropertiesWithoutComputed(): void
    {
        $schema = $this->makeSchema([
            'title' => ['type' => 'string'],
            'tag'   => ['type' => 'string'],
        ]);

        $input  = ['title' => 'Hello', 'tag' => 'world'];
        $result = $this->handler->evaluateComputedFields($input, $schema, 'save');

        $this->assertSame($input, $result, 'object MUST pass through unchanged when no computed properties exist');
    }

    public function testGetComputedPropertyNamesReturnsForCorrectMode(): void
    {
        $schema = $this->makeSchema([
            'a' => ['type' => 'string', 'computed' => ['expression' => '{{ a }}', 'evaluateOn' => 'save']],
            'b' => ['type' => 'string', 'computed' => ['expression' => '{{ b }}', 'evaluateOn' => 'read']],
            'c' => ['type' => 'string', 'computed' => ['expression' => '{{ c }}', 'evaluateOn' => 'save']],
            'd' => ['type' => 'string'],
        ]);

        $saveNames = $this->handler->getComputedPropertyNames($schema, 'save');
        $readNames = $this->handler->getComputedPropertyNames($schema, 'read');

        sort($saveNames);
        sort($readNames);
        $this->assertSame(['a', 'c'], $saveNames);
        $this->assertSame(['b'],      $readNames);
    }

    public function testInvalidExpressionFailsClosedToNull(): void
    {
        // Twig parse failure MUST not propagate — handler logs + falls
        // back to `null` so a single bad expression doesn't break a save.
        $schema = $this->makeSchema([
            'title'  => ['type' => 'string'],
            'broken' => [
                'type'     => 'string',
                'computed' => ['expression' => '{{ unclosed_brace'],
            ],
        ]);

        $result = $this->handler->evaluateComputedFields(['title' => 'x'], $schema, 'save');

        $this->assertArrayHasKey('broken', $result);
        $this->assertNull($result['broken'], 'invalid expression MUST evaluate to null, not crash the save');
    }

    public function testDetectCircularDependenciesEmptyForAcyclicSchema(): void
    {
        // a -> b -> c -> (constant). No cycle.
        $schema = $this->makeSchema([
            'a' => ['type' => 'string', 'computed' => ['expression' => '{{ b }}']],
            'b' => ['type' => 'string', 'computed' => ['expression' => '{{ c }}']],
            'c' => ['type' => 'string', 'computed' => ['expression' => 'static']],
        ]);

        $cycles = $this->handler->detectCircularDependencies($schema);
        $this->assertSame([], $cycles, 'acyclic dependency graph MUST yield zero cycles');
    }

    public function testDetectCircularDependenciesEmptyWhenNoComputed(): void
    {
        // No computed properties at all — detector MUST short-circuit
        // to an empty result rather than walking the trivial graph.
        $schema = $this->makeSchema([
            'plain' => ['type' => 'string'],
        ]);

        $this->assertSame([], $this->handler->detectCircularDependencies($schema));
    }

    public function testDetectCircularDependenciesFlagsTwoNodeCycle(): void
    {
        // a -> b, b -> a. Classic two-node cycle.
        $schema = $this->makeSchema([
            'a' => ['type' => 'string', 'computed' => ['expression' => '{{ b }}']],
            'b' => ['type' => 'string', 'computed' => ['expression' => '{{ a }}']],
        ]);

        $cycles = $this->handler->detectCircularDependencies($schema);

        $this->assertNotEmpty($cycles, 'a<->b cycle MUST be flagged');
        $this->assertCount(1, $cycles, 'symmetric cycle MUST be reported once, not twice');

        $cycle = $cycles[0];
        $this->assertContains('a', $cycle);
        $this->assertContains('b', $cycle);
    }

    public function testDetectCircularDependenciesFlagsSelfLoop(): void
    {
        // a -> a. Length-1 cycle.
        $schema = $this->makeSchema([
            'a' => ['type' => 'string', 'computed' => ['expression' => '{{ a }} suffix']],
        ]);

        $cycles = $this->handler->detectCircularDependencies($schema);
        $this->assertCount(1, $cycles, 'self-loop MUST be reported as a cycle');
        $this->assertContains('a', $cycles[0]);
    }

    public function testDetectCircularDependenciesIgnoresNonComputedReferences(): void
    {
        // a is computed and references `plain` which is NOT computed.
        // Plain inputs are inert leaves of the graph and CANNOT close
        // a cycle, so no cycle should be reported.
        $schema = $this->makeSchema([
            'plain' => ['type' => 'string'],
            'a'     => ['type' => 'string', 'computed' => ['expression' => '{{ plain }}']],
        ]);

        $this->assertSame([], $this->handler->detectCircularDependencies($schema));
    }

    public function testDetectCircularDependenciesFlagsThreeNodeCycle(): void
    {
        // a -> b -> c -> a.
        $schema = $this->makeSchema([
            'a' => ['type' => 'string', 'computed' => ['expression' => '{{ b }}']],
            'b' => ['type' => 'string', 'computed' => ['expression' => '{{ c }}']],
            'c' => ['type' => 'string', 'computed' => ['expression' => '{{ a }}']],
        ]);

        $cycles = $this->handler->detectCircularDependencies($schema);
        $this->assertCount(1, $cycles, 'one canonical 3-node cycle MUST be reported, not three rotations');

        $cycle = $cycles[0];
        foreach (['a', 'b', 'c'] as $node) {
            $this->assertContains($node, $cycle, 'cycle MUST contain '.$node);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     */
    private function makeSchema(array $properties): Schema
    {
        $schema = new Schema();
        $schema->setTitle('phpunit-computed-' . uniqid());
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-computed-' . uniqid());
        $schema->setProperties($properties);

        $persisted = $this->schemaMapper->insert($schema);
        $this->createdSchemas[] = $persisted;
        return $persisted;
    }
}

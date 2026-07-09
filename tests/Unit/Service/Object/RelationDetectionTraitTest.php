<?php

/**
 * RelationDetectionTrait Unit Tests
 *
 * Tests the single relation-detection rule shared by every relation-recording
 * scan path (SaveObjects, BulkRelationHandler):
 * - genuine references (UUID / prefixed-UUID / URL / schema-declared) ARE recorded
 * - dates, enum values and business identifiers are NOT recorded
 * - the bulk recording path produces the same decision as the shared rule
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
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

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\RelationDetectionTrait;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkRelationHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkValidationHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test double exposing the protected trait method for direct assertions.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 */
class RelationDetectionTraitDouble
{
    use RelationDetectionTrait;

    /**
     * Public proxy to the protected rule under test.
     *
     * @param string     $value          The scalar value.
     * @param array|null $propertyConfig  The schema property definition, if any.
     *
     * @return bool Whether the value is a recordable reference.
     */
    public function check(string $value, ?array $propertyConfig=null): bool
    {
        return $this->isRecordableReference(value: $value, propertyConfig: $propertyConfig);
    }//end check()
}//end class

/**
 * Unit tests for RelationDetectionTrait.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 */
class RelationDetectionTraitTest extends TestCase
{

    /**
     * The rule under test.
     *
     * @var RelationDetectionTraitDouble
     */
    private RelationDetectionTraitDouble $rule;

    /**
     * Set up the test double.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->rule = new RelationDetectionTraitDouble();
    }//end setUp()

    /**
     * Genuine reference values ARE recorded without any schema hint.
     *
     * @return void
     */
    public function testGenuineReferencePatternsAreRecorded(): void
    {
        $this->assertTrue($this->rule->check('00000000-0000-0000-0000-000000000000'), 'canonical UUID');
        $this->assertTrue($this->rule->check('00000000000000000000000000000000'), '32-char UUID');
        $this->assertTrue($this->rule->check('id-00000000-0000-0000-0000-000000000000'), 'prefixed UUID');
        $this->assertTrue($this->rule->check('https://example.com/apps/openregister/api/objects/x'), 'URL');
    }//end testGenuineReferencePatternsAreRecorded()

    /**
     * Dates, enum values and business identifiers are NOT recorded when the
     * property is not a declared reference. These are the false positives the
     * old "8+ chars with hyphen/underscore" heuristic produced.
     *
     * @return void
     */
    public function testScalarBusinessValuesAreNotRecorded(): void
    {
        $this->assertFalse($this->rule->check('2026-05-20'), 'date');
        $this->assertFalse($this->rule->check('bank_transfer'), 'enum value');
        $this->assertFalse($this->rule->check('DEMO-F-2026-04-02'), 'invoice number');
        $this->assertFalse($this->rule->check('demo-administration'), 'business identifier');
        $this->assertFalse($this->rule->check('NL-KVK-00000000'), 'KvK identifier');
        $this->assertFalse($this->rule->check('380'), 'numeric code');
        $this->assertFalse($this->rule->check(''), 'empty string');
        $this->assertFalse($this->rule->check('   '), 'whitespace only');
    }//end testScalarBusinessValuesAreNotRecorded()

    /**
     * Schema-declared reference properties are authoritative — any string value
     * is recorded regardless of pattern.
     *
     * @return void
     */
    public function testSchemaDeclaredReferencesAreAuthoritative(): void
    {
        $this->assertTrue($this->rule->check('DEMO-C1', ['type' => 'object']), 'type:object');
        $this->assertTrue($this->rule->check('DEMO-C1', ['type' => 'text', 'format' => 'uuid']), 'format:uuid');
        $this->assertTrue($this->rule->check('DEMO-C1', ['type' => 'text', 'format' => 'uri']), 'format:uri');
        $this->assertTrue($this->rule->check('DEMO-C1', ['$ref' => '#/components/CustomerMaster']), '$ref');
        $this->assertTrue($this->rule->check('DEMO-C1', ['inversedBy' => 'invoices']), 'inversedBy');
    }//end testSchemaDeclaredReferencesAreAuthoritative()

    /**
     * A non-reference schema property does not promote a scalar to a relation.
     *
     * @return void
     */
    public function testNonReferenceSchemaPropertyDoesNotPromoteScalar(): void
    {
        $this->assertFalse($this->rule->check('bank_transfer', ['type' => 'text', 'format' => 'string']));
        $this->assertFalse($this->rule->check('2026-05-20', ['type' => 'text', 'format' => 'date']));
    }//end testNonReferenceSchemaPropertyDoesNotPromoteScalar()

    /**
     * Cross-path: the BulkRelationHandler recording path produces the same
     * relation set as the shared rule for the same payload. Because both
     * SaveObjects and BulkRelationHandler delegate to RelationDetectionTrait,
     * the recording paths cannot diverge.
     *
     * @return void
     */
    public function testBulkRecordingPathMatchesSharedRule(): void
    {
        $handler = new BulkRelationHandler(
            $this->createMock(BulkValidationHandler::class),
            $this->createMock(MagicMapper::class),
            $this->createMock(LoggerInterface::class)
        );

        $payload = [
            'customerId'    => '00000000-0000-0000-0000-000000000000',
            'invoiceNumber' => 'DEMO-F-2026-04-02',
            'paymentMethod' => 'bank_transfer',
            'dueDate'       => '2026-05-20',
        ];

        $relations = $handler->scanForRelations(data: $payload, prefix: '', schema: null);

        $this->assertSame(['customerId' => '00000000-0000-0000-0000-000000000000'], $relations);
    }//end testBulkRecordingPathMatchesSharedRule()
}//end class

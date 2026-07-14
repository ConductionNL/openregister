<?php

declare(strict_types=1);

/**
 * Schema Annotation Vocabulary Unit Tests
 *
 * Verifies that x-openregister-archival and x-openregister-seed survive
 * the validateConfigurationArray() round-trip and are not silently dropped.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-1.2
 */

namespace Unit\Db;

use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for Schema::ANNOTATION_VOCABULARY and configuration round-trip.
 */
class SchemaAnnotationVocabularyTest extends TestCase
{

    private Schema $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = new Schema();
    }

    /**
     * ANNOTATION_VOCABULARY constant exists and contains the two new keys.
     */
    public function testAnnotationVocabularyContainsArchivalAndSeed(): void
    {
        // ANNOTATION_VOCABULARY is a private constant on Schema; read it via
        // reflection rather than direct access (mirrors the convention used
        // by SchemaArchivalVocabularyTest).
        $vocabulary = (new ReflectionClass(Schema::class))->getConstant('ANNOTATION_VOCABULARY');

        $this->assertIsArray($vocabulary);
        $this->assertContains('x-openregister-archival', $vocabulary);
        $this->assertContains('x-openregister-seed', $vocabulary);
    }

    /**
     * x-openregister-archival survives setConfiguration() → getConfiguration() round-trip.
     */
    public function testArchivalAnnotationSurvivesRoundTrip(): void
    {
        $archival = [
            'retention' => ['default' => 'P30D'],
        ];

        $this->schema->setConfiguration([
            'x-openregister-archival' => $archival,
        ]);

        $config = $this->schema->getConfiguration();

        $this->assertNotNull($config);
        $this->assertArrayHasKey('x-openregister-archival', $config);
        $this->assertSame($archival, $config['x-openregister-archival']);
    }

    /**
     * x-openregister-seed survives setConfiguration() → getConfiguration() round-trip.
     */
    public function testSeedAnnotationSurvivesRoundTrip(): void
    {
        $seed = ['objects' => [['title' => 'Example']]];

        $this->schema->setConfiguration([
            'x-openregister-seed' => $seed,
        ]);

        $config = $this->schema->getConfiguration();

        $this->assertNotNull($config);
        $this->assertArrayHasKey('x-openregister-seed', $config);
        $this->assertSame($seed, $config['x-openregister-seed']);
    }

    /**
     * FAILING PATH (approval-chains-declarative): `x-openregister-approval-chains`
     * MUST survive the round-trip. Before this capability existed the key was
     * absent from ANNOTATION_VOCABULARY, so setConfiguration() silently dropped
     * it — shillinq's BcfClaim declaration never reached the configuration
     * column and no gate listener could ever read it, however it was wired.
     *
     * @spec openspec/changes/approval-chains-declarative/specs/approval-workflow/spec.md
     */
    public function testApprovalChainsAnnotationSurvivesRoundTrip(): void
    {
        $chains = [
            'bcf-claim-submit-approval' => [
                'transition' => 'submit',
                'approvers'  => [['role' => 'bcf-administrator', 'min' => 1]],
                'onApprove'  => 'advanceTransition',
            ],
        ];

        $this->schema->setConfiguration([
            'x-openregister-approval-chains' => $chains,
        ]);

        $config = $this->schema->getConfiguration();

        $this->assertNotNull($config);
        $this->assertArrayHasKey('x-openregister-approval-chains', $config);
        $this->assertSame($chains, $config['x-openregister-approval-chains']);
    }

    /**
     * Actual typos (non-vocabulary x-openregister-* keys) are still dropped.
     */
    public function testActualTypoIsDropped(): void
    {
        $this->schema->setConfiguration([
            'x-openregister-lifecycl' => ['states' => ['open']],
            'x-openregister-archival' => ['retention' => ['default' => 'P7D']],
        ]);

        $config = $this->schema->getConfiguration();

        // Typo should be absent.
        $this->assertArrayNotHasKey('x-openregister-lifecycl', $config);

        // Valid vocabulary key should survive.
        $this->assertArrayHasKey('x-openregister-archival', $config);
    }
}//end class

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
use OCA\OpenRegister\Service\ProcessingLogService;
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
    public function testAnnotationVocabularyContainsArchivalButNotSeed(): void
    {
        // ANNOTATION_VOCABULARY is a private constant on Schema; read it via
        // reflection rather than direct access (mirrors the convention used
        // by SchemaArchivalVocabularyTest).
        $vocabulary = (new ReflectionClass(Schema::class))->getConstant('ANNOTATION_VOCABULARY');

        $this->assertIsArray($vocabulary);
        $this->assertContains('x-openregister-archival', $vocabulary);

        // `x-openregister-seed` was a PHANTOM: in the vocabulary (so it
        // round-tripped and looked supported) but read by NO engine, so
        // declared seed data was never planted. OR's real, engine-backed seed
        // location is `components.objects` / top-level `objects`, consumed by
        // ImportHandler. The phantom key is removed so declaring it now fails
        // loudly via the dropped-key warning instead of silently no-oping.
        $this->assertNotContains('x-openregister-seed', $vocabulary);

        // `x-openregister-processing` is the mirror-image defect: READ by
        // ProcessingLogService but absent from the vocabulary, so it was
        // silently dropped and per-schema AVG logReads could never be enabled.
        $this->assertContains('x-openregister-processing', $vocabulary);
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
     * x-openregister-seed is now DROPPED LOUDLY — it has no engine.
     *
     * The previous version of this test asserted the key survived the
     * round-trip and treated that as proof the capability worked. It did not:
     * nothing ever read the key. "Not dropped" is not "consumed". Removing the
     * phantom converts a silent no-op into a visible dropped-key warning.
     */
    public function testSeedAnnotationIsDroppedLoudlyBecauseItHasNoEngine(): void
    {
        $seed = ['objects' => [['title' => 'Example']]];

        $this->schema->setConfiguration([
            'x-openregister-seed' => $seed,
        ]);

        // The key is the only entry, so the configuration ends up empty/null.
        $config = ($this->schema->getConfiguration() ?? []);

        $this->assertArrayNotHasKey('x-openregister-seed', $config);
        $this->assertContains(
            'x-openregister-seed',
            $this->schema->consumeDroppedAnnotationKeys(),
            'Declaring the engine-less seed key must be reported, not silently accepted'
        );
    }

    /**
     * x-openregister-processing reaches its engine's expected shape.
     *
     * ProcessingLogService::ANNOTATION_KEY reads this key off the schema
     * configuration to enable per-schema AVG read-logging. Before this fix the
     * key was absent from ANNOTATION_VOCABULARY, so setConfiguration() dropped
     * it and the engine never saw it — register-level logReads worked, which
     * masked the gap and made the capability look complete.
     */
    public function testProcessingAnnotationSurvivesRoundTripAndReachesItsEngine(): void
    {
        $processing = ['code' => 'demo-activity', 'logReads' => true];

        $this->schema->setConfiguration([
            ProcessingLogService::ANNOTATION_KEY => $processing,
        ]);

        $config = $this->schema->getConfiguration();

        $this->assertNotNull($config);
        $this->assertArrayHasKey(ProcessingLogService::ANNOTATION_KEY, $config);
        $this->assertSame($processing, $config[ProcessingLogService::ANNOTATION_KEY]);

        // Not merely "not dropped" — the value the ENGINE reads is the value we
        // wrote, keyed by the engine's own constant.
        $this->assertTrue($config[ProcessingLogService::ANNOTATION_KEY]['logReads']);
        $this->assertNotContains(
            ProcessingLogService::ANNOTATION_KEY,
            $this->schema->consumeDroppedAnnotationKeys()
        );
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

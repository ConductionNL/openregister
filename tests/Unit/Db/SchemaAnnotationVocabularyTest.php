<?php

declare(strict_types=1);

/*
 * Schema Annotation Vocabulary Unit Tests
 *
 * Verifies that x-openregister-archival and x-openregister-seed survive
 * the validateConfigurationArray() round-trip and are not silently dropped.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2
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
    }//end setUp()

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
    }//end testAnnotationVocabularyContainsArchivalButNotSeed()

    /**
     * x-openregister-archival survives setConfiguration() → getConfiguration() round-trip.
     */
    public function testArchivalAnnotationSurvivesRoundTrip(): void
    {
        $archival = [
            'retention' => ['default' => 'P30D'],
        ];

        $this->schema->setConfiguration(
                [
                    'x-openregister-archival' => $archival,
                ]
                );

        $config = $this->schema->getConfiguration();

        $this->assertNotNull($config);
        $this->assertArrayHasKey('x-openregister-archival', $config);
        $this->assertSame($archival, $config['x-openregister-archival']);
    }//end testArchivalAnnotationSurvivesRoundTrip()

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

        $this->schema->setConfiguration(
                [
                    'x-openregister-seed' => $seed,
                ]
                );

        // The key is the only entry, so the configuration ends up empty/null.
        $config = ($this->schema->getConfiguration() ?? []);

        $this->assertArrayNotHasKey('x-openregister-seed', $config);
        $this->assertContains(
            'x-openregister-seed',
            $this->schema->consumeDroppedAnnotationKeys(),
            'Declaring the engine-less seed key must be reported, not silently accepted'
        );
    }//end testSeedAnnotationIsDroppedLoudlyBecauseItHasNoEngine()

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

        $this->schema->setConfiguration(
                [
                    ProcessingLogService::ANNOTATION_KEY => $processing,
                ]
                );

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
    }//end testProcessingAnnotationSurvivesRoundTripAndReachesItsEngine()

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

        $this->schema->setConfiguration(
                [
                    'x-openregister-approval-chains' => $chains,
                ]
                );

        $config = $this->schema->getConfiguration();

        $this->assertNotNull($config);
        $this->assertArrayHasKey('x-openregister-approval-chains', $config);
        $this->assertSame($chains, $config['x-openregister-approval-chains']);
    }//end testApprovalChainsAnnotationSurvivesRoundTrip()

    /**
     * FAILING PATH (federated-config-sharing): `x-openregister-shareable`
     * MUST survive the round-trip. It is the marker
     * SchemaShareableConfigScanner reads to surface a schema's objects as a
     * shareable configuration type; absent from the vocabulary the marker
     * would be silently dropped and the schema could never become shareable.
     *
     * Both the boolean form (defaults derived) and the object form (id/topic/
     * name refined) must reach the configuration column intact.
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    public function testShareableAnnotationSurvivesRoundTrip(): void
    {
        $this->schema->setConfiguration(['x-openregister-shareable' => true]);
        $config = $this->schema->getConfiguration();
        $this->assertNotNull($config);
        $this->assertTrue($config['x-openregister-shareable']);

        $refined = ['id' => 'procest.casetype', 'topic' => 'procest-casetype', 'name' => 'Case type'];
        $this->schema->setConfiguration(['x-openregister-shareable' => $refined]);
        $config = $this->schema->getConfiguration();
        $this->assertNotNull($config);
        $this->assertSame($refined, $config['x-openregister-shareable']);
    }//end testShareableAnnotationSurvivesRoundTrip()

    /**
     * Actual typos (non-vocabulary x-openregister-* keys) are still dropped.
     */
    public function testActualTypoIsDropped(): void
    {
        $this->schema->setConfiguration(
                [
                    'x-openregister-lifecycl' => ['states' => ['open']],
                    'x-openregister-archival' => ['retention' => ['default' => 'P7D']],
                ]
                );

        $config = $this->schema->getConfiguration();

        // Typo should be absent.
        $this->assertArrayNotHasKey('x-openregister-lifecycl', $config);

        // Valid vocabulary key should survive.
        $this->assertArrayHasKey('x-openregister-archival', $config);
    }//end testActualTypoIsDropped()

    /**
     * FAILING PATH (or#2164): `x-openregister-agent-context` MUST survive the
     * round-trip.
     *
     * This is the FOURTH recurrence of the same defect class in this
     * vocabulary (`x-openregister-processing`, `x-openregister-contextchat`,
     * `x-openregister-shareable`, now this one). The key is READ by Hermiq's
     * AgentContextBuilder — and by its JS twin `src/utils/agentContext.js` —
     * to bound what object data may reach an LLM. While it was absent from
     * ANNOTATION_VOCABULARY, setConfiguration() silently dropped it, so EVERY
     * agent leaf on EVERY schema fleet-wide resolved an EMPTY context.
     *
     * The failure was invisible by construction: the schema saved with HTTP
     * 200, the only signal was a log line, and because an absent allowlist
     * means "expose nothing" the behaviour was fail-closed — no leak, just a
     * capability that could never do anything.
     *
     * @return void
     */
    public function testAgentContextAnnotationSurvivesRoundTrip(): void
    {
        $allowlist = ['findingId', 'severity', 'gate', 'rule', 'status'];

        $this->schema->setConfiguration(['x-openregister-agent-context' => $allowlist]);

        $config = $this->schema->getConfiguration();

        $this->assertNotNull($config);
        $this->assertArrayHasKey('x-openregister-agent-context', $config);
        $this->assertSame($allowlist, $config['x-openregister-agent-context']);

        // Not merely present — not reported as dropped either, since the drop
        // list is the only runtime signal this failure ever produced.
        $this->assertNotContains(
            'x-openregister-agent-context',
            $this->schema->consumeDroppedAnnotationKeys()
        );

    }//end testAgentContextAnnotationSurvivesRoundTrip()

    /**
     * The refinement form (per-property constraints) must survive too — the
     * allowlist accepts either a flat list of property names or a map carrying
     * per-property bounds such as `maxLength`.
     *
     * @return void
     */
    public function testAgentContextRefinementFormSurvivesRoundTrip(): void
    {
        $refined = ['description' => ['maxLength' => 500]];

        $this->schema->setConfiguration(['x-openregister-agent-context' => $refined]);

        $config = $this->schema->getConfiguration();

        $this->assertArrayHasKey('x-openregister-agent-context', $config);
        $this->assertSame($refined, $config['x-openregister-agent-context']);

    }//end testAgentContextRefinementFormSurvivesRoundTrip()
}//end class

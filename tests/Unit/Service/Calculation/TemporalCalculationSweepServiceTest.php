<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Calculation\TemporalCalculationSweepService}.
 *
 * Uses the REAL CalculationEvaluator + CalculationPayloadBuilder (with a
 * mocked pack reference) against the actual DSAR escalationTier / breachedAt
 * expression shapes, so the sweep's recompute matches what the save-time
 * listener will persist. Covers: now-free schemas skipped entirely, terminal
 * lifecycle states left alone, unchanged recomputations producing no write,
 * a tier crossing producing exactly one write through the normal write path,
 * and the temporal-expression detector (operator + literal forms).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calculation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Calculation;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Calculation\AggregateReferenceResolver;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\CalculationPayloadBuilder;
use OCA\OpenRegister\Service\Calculation\ReferenceResolver;
use OCA\OpenRegister\Service\Calculation\TemporalCalculationSweepService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * TemporalCalculationSweepServiceTest.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The sweep composes many
 *   shipped collaborators; its test wires the same surface.
 */
class TemporalCalculationSweepServiceTest extends TestCase
{

    private SchemaMapper&MockObject $schemaMapper;

    private RegisterMapper&MockObject $registerMapper;

    private MagicMapper&MockObject $objectMapper;

    private ObjectService&MockObject $objectService;

    private CalculationEvaluator $evaluator;

    private CalculationPayloadBuilder $payloadBuilder;

    private TemporalCalculationSweepService $service;


    protected function setUp(): void
    {
        $this->schemaMapper   = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->objectMapper   = $this->createMock(MagicMapper::class);
        $this->objectService  = $this->createMock(ObjectService::class);

        $this->evaluator = new CalculationEvaluator(
            placeholders: new PlaceholderResolver($this->createMock(IUserSession::class))
        );

        // The pack reference resolves to the seeded default tiers (-7 / -2 / 0).
        $references = $this->createMock(ReferenceResolver::class);
        $references->method('resolveAll')->willReturn(
            [
                'pack' => [
                    'escalationTiers' => [
                        [
                            'tier'       => 'reminder',
                            'offsetDays' => -7,
                        ],
                        [
                            'tier'       => 'escalation',
                            'offsetDays' => -2,
                        ],
                        [
                            'tier'       => 'breach',
                            'offsetDays' => 0,
                        ],
                    ],
                ],
            ]
        );

        $this->payloadBuilder = new CalculationPayloadBuilder(
            references: $references,
            aggregates: $this->createMock(AggregateReferenceResolver::class),
        );

        $this->service = new TemporalCalculationSweepService(
            schemaMapper: $this->schemaMapper,
            registerMapper: $this->registerMapper,
            objectMapper: $this->objectMapper,
            evaluator: $this->evaluator,
            payloadBuilder: $this->payloadBuilder,
            objectService: $this->objectService,
            logger: $this->createMock(LoggerInterface::class),
        );

    }//end setUp()


    /**
     * Schemas without `now`-dependent materialised calculations are skipped
     * entirely (no object enumeration, no writes).
     *
     * @return void
     */
    public function testNowFreeSchemasAreSkipped(): void
    {
        $schema = $this->schema(
            calculations: [
                'total' => [
                    'type'        => 'number',
                    'materialise' => true,
                    'expression'  => [
                        '+' => [
                            ['prop' => 'a'],
                            ['prop' => 'b'],
                        ],
                    ],
                ],
            ]
        );
        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->registerMapper->method('findAll')->willReturn([$this->register()]);

        $this->objectMapper->expects($this->never())->method('findAllInRegisterSchemaTable');
        $this->objectService->expects($this->never())->method('saveObject');

        $summary = $this->service->runSweep();

        $this->assertSame(1, $summary['schemasScanned']);
        $this->assertSame(0, $summary['temporalSchemas']);

    }//end testNowFreeSchemasAreSkipped()


    /**
     * A tier crossing rewrites the object exactly once through the normal
     * write path; terminal cases and unchanged cases produce no write.
     *
     * @return void
     */
    public function testSweepRewritesOnlyChangedNonTerminalObjects(): void
    {
        $schema = $this->dsarSchema();
        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->registerMapper->method('findAll')->willReturn([$this->register()]);

        // Case A: open case 5 days before its deadline whose stored tier is
        // stale (on-track) — the sweep must detect reminder and rewrite.
        $crossing = $this->caseObject(
            uuid: 'case-crossing',
            status: 'in-progress',
            dueInDays: 5,
            storedTier: 'on-track',
            storedDaysRemaining: 12
        );

        // Case B: terminal case long past its deadline — left alone.
        $terminal = $this->caseObject(
            uuid: 'case-terminal',
            status: 'fulfilled',
            dueInDays: -30,
            storedTier: 'on-track',
            storedDaysRemaining: 12
        );

        // Case C: open case whose stored values already match the recompute —
        // no write, no duplicate notification.
        $unchangedData = $this->recomputedData(
            $this->caseObject(
                uuid: 'case-unchanged',
                status: 'in-progress',
                dueInDays: 5,
                storedTier: 'on-track',
                storedDaysRemaining: 12
            ),
            $schema
        );
        $unchanged     = new ObjectEntity();
        $unchanged->setUuid('case-unchanged');
        $unchanged->setRegister('7');
        $unchanged->setSchema('12');
        $unchanged->setObject($unchangedData);

        $this->objectMapper->method('findAllInRegisterSchemaTable')->willReturn(
            [
                $crossing,
                $terminal,
                $unchanged,
            ]
        );

        $savedUuids = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            static function ($object, $extend=[], $register=null, $schema=null, $uuid=null) use (&$savedUuids) {
                $savedUuids[] = (string) $uuid;
                return new ObjectEntity();
            }
        );

        $summary = $this->service->runSweep();

        $this->assertSame(['case-crossing'], $savedUuids);
        $this->assertSame(1, $summary['temporalSchemas']);
        // The terminal case is never evaluated; the two open cases are.
        $this->assertSame(2, $summary['objectsEvaluated']);
        $this->assertSame(1, $summary['objectsRewritten']);
        $this->assertSame(0, $summary['errors']);

    }//end testSweepRewritesOnlyChangedNonTerminalObjects()


    /**
     * The recompute produces the tier the write path will persist — including
     * the declarative write-once breachedAt stamp on a breach crossing.
     *
     * @return void
     */
    public function testRecomputeMatrixIncludingBreachStamp(): void
    {
        $schema = $this->dsarSchema();

        // 5 days before the deadline → reminder tier, no breach stamp.
        $reminder = $this->recomputedData(
            $this->caseObject(uuid: 'c1', status: 'in-progress', dueInDays: 5, storedTier: 'on-track', storedDaysRemaining: 12),
            $schema
        );
        $this->assertSame('reminder', $reminder['escalationTier']);
        $this->assertArrayNotHasKey('breachedAt', $reminder);

        // 3 days past the deadline → breached + a fresh write-once stamp.
        $breached = $this->recomputedData(
            $this->caseObject(uuid: 'c2', status: 'in-progress', dueInDays: -3, storedTier: 'escalation', storedDaysRemaining: -1),
            $schema
        );
        $this->assertSame('breached', $breached['escalationTier']);
        $this->assertNotEmpty($breached['breachedAt']);

        // Already-breached case keeps its ORIGINAL stamp (write-once).
        $already = $this->caseObject(uuid: 'c3', status: 'in-progress', dueInDays: -10, storedTier: 'breached', storedDaysRemaining: -10);
        $data    = $already->getObject();
        $data['breachedAt'] = '2026-06-01T00:00:00+00:00';
        $already->setObject($data);
        $rebreached = $this->recomputedData($already, $schema);
        $this->assertSame('2026-06-01T00:00:00+00:00', $rebreached['breachedAt']);

    }//end testRecomputeMatrixIncludingBreachStamp()


    /**
     * The temporal detector recognises both the `{"now": []}` operator and
     * the literal `"now"` argument, and rejects clock-free expressions.
     *
     * @return void
     */
    public function testTemporalExpressionDetection(): void
    {
        $this->assertTrue(
            $this->service->hasTemporalCalculation(
                calculations: [
                    'a' => ['expression' => ['diffDays' => [['prop' => 'dueAt'], ['now' => []]]]],
                ]
            )
        );
        $this->assertTrue(
            $this->service->hasTemporalCalculation(
                calculations: [
                    'a' => [
                        'expression' => [
                            'dateDiff' => [
                                'from' => 'now',
                                'to'   => '@self.dueDate',
                                'unit' => 'days',
                            ],
                        ],
                    ],
                ]
            )
        );
        $this->assertFalse(
            $this->service->hasTemporalCalculation(
                calculations: [
                    'a' => ['expression' => ['+' => [['prop' => 'x'], 1]]],
                ]
            )
        );

    }//end testTemporalExpressionDetection()


    /**
     * Recompute an object's materialised calculations exactly like the sweep
     * (shared payload builder + real evaluator), returning the resulting data.
     *
     * @param ObjectEntity $object The case entity.
     * @param Schema       $schema The DSAR schema.
     *
     * @return array<string, mixed> The recomputed data map.
     */
    private function recomputedData(ObjectEntity $object, Schema $schema): array
    {
        $payload = $this->payloadBuilder->build(object: $object, schema: $schema);
        $config  = ($schema->getConfiguration() ?? []);
        foreach (($config['x-openregister-calculations'] ?? []) as $name => $spec) {
            $value = $this->evaluator->evaluate($payload, $spec['expression']);
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format(DATE_ATOM);
            }

            if ($value !== null) {
                $payload[(string) $name] = $value;
            }
        }

        return $this->payloadBuilder->stripSyntheticKeys(data: $payload);

    }//end recomputedData()


    /**
     * Build a DSAR-like case entity.
     *
     * @param string $uuid                The case uuid.
     * @param string $status              The lifecycle state.
     * @param int    $dueInDays           Days until (negative: past) the deadline.
     * @param string $storedTier          The stale stored escalationTier.
     * @param int    $storedDaysRemaining The stale stored daysRemaining.
     *
     * @return ObjectEntity
     */
    private function caseObject(string $uuid, string $status, int $dueInDays, string $storedTier, int $storedDaysRemaining): ObjectEntity
    {
        $dueAt = (new \DateTimeImmutable())->modify(sprintf('%+d days', $dueInDays))->modify('+1 hour');

        $object = new ObjectEntity();
        $object->setUuid($uuid);
        $object->setRegister('7');
        $object->setSchema('12');
        $object->setObject(
            [
                'subjectId'      => 'subject@example.org',
                'status'         => $status,
                'receivedAt'     => '2026-06-01T09:00:00+00:00',
                'dueAt'          => $dueAt->format(DATE_ATOM),
                'daysRemaining'  => $storedDaysRemaining,
                'isOverdue'      => ($storedDaysRemaining < 0),
                'escalationTier' => $storedTier,
            ]
        );

        return $object;

    }//end caseObject()


    /**
     * A schema mirroring the DSAR register's temporal calculations
     * (daysRemaining / isOverdue / escalationTier / breachedAt) + lifecycle.
     *
     * @return Schema
     */
    private function dsarSchema(): Schema
    {
        $registerJson = (string) file_get_contents(
            __DIR__.'/../../../../lib/Settings/data_subject_request_register.json'
        );
        $register     = json_decode($registerJson, true);
        $declared     = $register['components']['schemas']['dataSubjectRequest'];

        return $this->schema(
            calculations: $declared['x-openregister-calculations'],
            lifecycle: $declared['x-openregister-lifecycle'],
            references: $declared['x-openregister-references']
        );

    }//end dsarSchema()


    /**
     * Build a schema entity with the given annotation blocks.
     *
     * @param array<string, mixed>      $calculations The calculations block.
     * @param array<string, mixed>|null $lifecycle    Optional lifecycle block.
     * @param array<string, mixed>|null $references   Optional references block.
     *
     * @return Schema
     */
    private function schema(array $calculations, ?array $lifecycle=null, ?array $references=null): Schema
    {
        $configuration = ['x-openregister-calculations' => $calculations];
        if ($lifecycle !== null) {
            $configuration['x-openregister-lifecycle'] = $lifecycle;
        }

        if ($references !== null) {
            $configuration['x-openregister-references'] = $references;
        }

        $schema = new Schema();
        $schema->setId(12);
        $schema->setSlug('dataSubjectRequest');
        $schema->setUuid('uuid-12');
        $schema->setProperties(
            [
                'subjectId'  => ['type' => 'string'],
                'status'     => [
                    'type' => 'string',
                    'enum' => [
                        'received',
                        'verifying',
                        'in-progress',
                        'fulfilled',
                        'refused',
                        'closed',
                    ],
                ],
                'receivedAt' => [
                    'type'   => 'string',
                    'format' => 'date-time',
                ],
                'dueAt'      => [
                    'type'   => 'string',
                    'format' => 'date-time',
                ],
                'breachedAt' => [
                    'type'   => 'string',
                    'format' => 'date-time',
                ],
            ]
        );
        $schema->setConfiguration($configuration);

        return $schema;

    }//end schema()


    /**
     * Build the owning register (schemas: [12]).
     *
     * @return Register
     */
    private function register(): Register
    {
        $register = new Register();
        $register->setId(7);
        $register->setSlug('data-subject-requests');
        $register->setSchemas([12]);

        return $register;

    }//end register()
}//end class

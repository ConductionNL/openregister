<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\BackgroundJob\AggregationThresholdJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\AggregationThresholdListener;
use OCA\OpenRegister\Service\Aggregation\ThresholdEvaluationService;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The listener defers created/updated/transitioned evaluations to
 * AggregationThresholdJob (deduped per register|schema) and keeps deletes
 * and the kill-switch path inline via the shared evaluation service. The
 * rising-edge dispatch semantics themselves are covered by
 * ThresholdEvaluationServiceTest.
 */
class AggregationThresholdListenerTest extends TestCase {
	private SchemaMapper&MockObject $schemaMapper;
	private ThresholdEvaluationService&MockObject $evaluator;
	private ListenerDeferralService&MockObject $deferral;

	protected function setUp(): void {
		parent::setUp();
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->evaluator = $this->createMock(ThresholdEvaluationService::class);
		$this->deferral = $this->createMock(ListenerDeferralService::class);
	}

	private function makeListener(): AggregationThresholdListener {
		return new AggregationThresholdListener(
			schemaMapper: $this->schemaMapper,
			evaluator: $this->evaluator,
			deferral: $this->deferral,
		);
	}

	/**
	 * @return array{0: Schema, 1: ObjectEntity}
	 */
	private function fixtures(): array {
		$schema = new Schema();
		$schema->setId(42);
		$schema->setSlug('test-schema');

		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setSchema('test-schema');
		$object->setRegister('test-register');

		return [$schema, $object];
	}

	public function testWriteEventDefersInsteadOfEvaluatingInline(): void {
		[$schema, $object] = $this->fixtures();
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->evaluator->method('hasThresholdNotifications')->willReturn(true);
		$this->deferral->method('isDeferralEnabled')->willReturn(true);

		// The heavy evaluation must NOT run on the request path.
		$this->evaluator->expects($this->never())->method('evaluateSchema');
		$this->deferral->expects($this->once())->method('defer')
			->willReturnCallback(
				function (string $jobClass, array $entry, int $chunkSize, ?string $dedupeKey): void {
					$this->assertSame(AggregationThresholdJob::class, $jobClass);
					$this->assertSame('obj-1', $entry['uuid']);
					$this->assertSame('test-register', $entry['register']);
					$this->assertSame('test-schema', $entry['schema']);
					// Bulk saves of one schema coalesce to one evaluation.
					$this->assertSame('test-register|test-schema', $dedupeKey);
				}
			);

		$this->makeListener()->handle(new ObjectUpdatedEvent($object, $object));
	}

	public function testDeleteEventEvaluatesInline(): void {
		[$schema, $object] = $this->fixtures();
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->evaluator->method('hasThresholdNotifications')->willReturn(true);
		$this->deferral->method('isDeferralEnabled')->willReturn(true);

		// Deleted entities are not re-fetchable by a job — evaluate now.
		$this->evaluator->expects($this->once())->method('evaluateSchema')
			->with($schema, $object);
		$this->deferral->expects($this->never())->method('defer');

		$this->makeListener()->handle(new ObjectDeletedEvent($object));
	}

	public function testKillSwitchForcesInlineEvaluation(): void {
		[$schema, $object] = $this->fixtures();
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->evaluator->method('hasThresholdNotifications')->willReturn(true);
		$this->deferral->method('isDeferralEnabled')->willReturn(false);

		$this->evaluator->expects($this->once())->method('evaluateSchema')
			->with($schema, $object);
		$this->deferral->expects($this->never())->method('defer');

		$this->makeListener()->handle(new ObjectUpdatedEvent($object, $object));
	}

	public function testSchemaWithoutThresholdNotificationsEnqueuesNothing(): void {
		[$schema, $object] = $this->fixtures();
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->evaluator->method('hasThresholdNotifications')->willReturn(false);

		$this->evaluator->expects($this->never())->method('evaluateSchema');
		$this->deferral->expects($this->never())->method('defer');

		$this->makeListener()->handle(new ObjectUpdatedEvent($object, $object));
	}

	public function testUnresolvableSchemaIsIgnored(): void {
		[, $object] = $this->fixtures();
		$this->schemaMapper->method('find')->willThrowException(new \RuntimeException('missing'));

		$this->evaluator->expects($this->never())->method('evaluateSchema');
		$this->deferral->expects($this->never())->method('defer');

		$this->makeListener()->handle(new ObjectUpdatedEvent($object, $object));
	}
}

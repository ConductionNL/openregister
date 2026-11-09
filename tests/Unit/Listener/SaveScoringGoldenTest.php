<?php

/**
 * OpenRegister SaveScoringGoldenTest
 *
 * Pins the exact payloads QualityScoreOnSaveListener and
 * GeneratedIdentifierListener write or refuse with, on the paths their own
 * tests do not spell out: the failures, the defaults and the error bodies.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\GeneratedIdentifierListener;
use OCA\OpenRegister\Listener\QualityScoreOnSaveListener;
use OCA\OpenRegister\Service\Object\ObjectEventGateInterface;
use OCA\OpenRegister\Service\Quality\QualityScorerInterface;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclaration;
use OCA\OpenRegister\Service\SequenceServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden payloads of the scoring and numbering save listeners.
 */
class SaveScoringGoldenTest extends TestCase {

	/** @var list<string> Log lines as "level:message". */
	private array $logs = [];

	/** @var list<array<string, mixed>> Thresholds the scorer received. */
	private array $thresholds = [];

	/**
	 * A logger that records level and message.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->logs[] = $level.':'.$message;
				}
			);
		}

		return $logger;
	}//end logger()

	/**
	 * A schema mapper answering one schema.
	 *
	 * @param array<string, mixed> $configuration The configuration.
	 * @param array<string, mixed> $properties    The properties.
	 *
	 * @return SchemaMapperInterface
	 */
	private function schemas(array $configuration, array $properties): SchemaMapperInterface {
		$schema = new Schema();
		$schema->setConfiguration($configuration);
		$schema->setProperties($properties);
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->method('find')->willReturn($schema);
		return $mapper;
	}//end schemas()

	/**
	 * The quality listener with a scorer answering a fixed score, or throwing.
	 *
	 * @param array<string, mixed> $quality    The annotation.
	 * @param array<string, mixed> $properties The schema properties.
	 * @param bool                 $fails      Whether scoring throws.
	 *
	 * @return QualityScoreOnSaveListener
	 */
	private function quality(array $quality, array $properties, bool $fails=false): QualityScoreOnSaveListener {
		$scorer = $this->createMock(QualityScorerInterface::class);
		if ($fails === true) {
			$scorer->method('score')->willThrowException(new RuntimeException('rules broke'));
		} else {
			$scorer->method('score')->willReturn(0.75);
		}

		$scorer->method('status')->willReturnCallback(
			function (float $score, array $thresholds): string {
				$this->thresholds[] = $thresholds;
				return 'good';
			}
		);

		return new QualityScoreOnSaveListener(
			$this->schemas(['x-openregister-quality' => $quality], $properties),
			$scorer,
			$this->logger()
		);
	}//end quality()

	/**
	 * An object with a schema ref.
	 *
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return ObjectEntity
	 */
	private function object(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('u-1');
		$object->setSchema('12');
		$object->setObject($data);
		return $object;
	}//end object()

	/**
	 * A scoring failure logs and leaves the object untouched.
	 *
	 * @return void
	 */
	public function testQualityFailureIsLoggedAndHarmless(): void {
		$object = $this->object(['qualityScore' => 0.1]);
		$this->quality(['rules' => [['r']]], ['qualityScore' => []], true)->handle(new ObjectCreatingEvent($object));
		self::assertSame(['warning:Quality scoring failed on u-1: rules broke'], $this->logs);
		self::assertSame([], $object->getQuality());
		self::assertSame(0.1, $object->getObject()['qualityScore']);
	}//end testQualityFailureIsLoggedAndHarmless()

	/**
	 * An empty field name falls back to the default, non-array thresholds become [].
	 *
	 * @return void
	 */
	public function testQualityDefaultsOnUpdate(): void {
		$object = $this->object(['qualityScore' => 0.1, 'st' => 'good']);
		$this->quality(
			['rules' => [['r']], 'field' => '', 'thresholds' => 'x', 'statusField' => 'st'],
			['qualityScore' => [], 'st' => []]
		)->handle(new ObjectUpdatingEvent($object, $this->object([])));
		self::assertSame([[]], $this->thresholds);
		self::assertSame(0.75, $object->getObject()['qualityScore']);
		self::assertSame('good', $object->getObject()['st']);
		self::assertSame(0.75, $object->getQuality()['score']);
		self::assertSame('good', $object->getQuality()['status']);
	}//end testQualityDefaultsOnUpdate()

	/**
	 * No rules, or rules not a list: nothing is scored.
	 *
	 * @return void
	 */
	public function testQualityWithoutRules(): void {
		foreach ([['rules' => []], ['rules' => 'x'], ['other' => 1]] as $annotation) {
			$object = $this->object([]);
			$this->quality($annotation, [])->handle(new ObjectCreatingEvent($object));
			self::assertSame([], $object->getQuality());
		}

		self::assertSame([], $this->thresholds);
	}//end testQualityWithoutRules()

	/**
	 * The numbering listener.
	 *
	 * @param array<string, mixed>     $properties The schema properties.
	 * @param SequenceServiceInterface $sequences  The counter.
	 *
	 * @return GeneratedIdentifierListener
	 */
	private function numbering(array $properties, SequenceServiceInterface $sequences): GeneratedIdentifierListener {
		$gate = $this->createMock(ObjectEventGateInterface::class);
		$gate->method('holdsCreation')->willReturn(false);
		$gate->method('leavesDraft')->willReturn(false);
		return new GeneratedIdentifierListener($this->schemas([], $properties), $sequences, $this->logger(), $gate);
	}//end numbering()

	/**
	 * A declared identifier property.
	 *
	 * @return array<string, mixed>
	 */
	private function declared(): array {
		return [
			'type' => 'string',
			GeneratedIdentifierDeclaration::ANNOTATION => ['sequence' => 'case', 'format' => 'Z-{seq:3}'],
		];
	}//end declared()

	/**
	 * A counter failure refuses the create with the exact body; a malformed declaration is skipped.
	 *
	 * @return void
	 */
	public function testNumberingRefusalBody(): void {
		$sequences = $this->createMock(SequenceServiceInterface::class);
		$sequences->method('reserveNext')->willThrowException(new RuntimeException('db down'));
		$event = new ObjectCreatingEvent($this->object([]));
		$this->numbering(
			[
				'broken' => ['type' => 'string', GeneratedIdentifierDeclaration::ANNOTATION => ['format' => 7]],
				'plain' => 'x',
				'id1' => $this->declared(),
				'id2' => $this->declared(),
			],
			$sequences
		)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(
			[
				'code' => 'generated-identifier-unavailable',
				'message' => 'The identifier for "id1" could not be issued, so the object was not created.',
				'property' => 'id1',
				'sequence' => 'case',
			],
			$event->getErrors()
		);
		self::assertCount(2, $this->logs);
		self::assertStringStartsWith('warning:[GeneratedIdentifierListener] schema 12 property broken has an unusable declaration: ', $this->logs[0]);
		self::assertSame('error:[GeneratedIdentifierListener] could not issue id1: db down', $this->logs[1]);
	}//end testNumberingRefusalBody()

	/**
	 * Numbers are issued in declaration order, and a supplied value advances the counter.
	 *
	 * @return void
	 */
	public function testNumberingFillsAndAdvances(): void {
		$sequences = $this->createMock(SequenceServiceInterface::class);
		$calls = [];
		$sequences->method('reserveNext')->willReturnCallback(
			function (int $registerId, int $schemaId, string $scopeKey) use (&$calls): int {
				$calls[] = 'reserve:'.$registerId.':'.$schemaId.':'.$scopeKey;
				return count($calls);
			}
		);
		$sequences->method('advanceTo')->willReturnCallback(
			function (int $registerId, int $schemaId, string $scopeKey, int $reserved) use (&$calls): void {
				$calls[] = 'advance:'.$registerId.':'.$schemaId.':'.$scopeKey.':'.$reserved;
			}
		);
		$object = $this->object(['id2' => 'Z-041', 'id4' => 'free text']);
		$this->numbering(['id1' => $this->declared(), 'id2' => $this->declared(), 'id3' => $this->declared(), 'id4' => $this->declared()], $sequences)
			->handle(new ObjectCreatingEvent($object));

		$data = $object->getObject();
		self::assertSame(['Z-001', 'Z-041', 'Z-003', 'free text'], [$data['id1'], $data['id2'], $data['id3'], $data['id4']]);
		self::assertSame('reserve:0:0:gen:case|', $calls[0]);
		self::assertSame('advance:0:0:gen:case|:41', $calls[1]);
		self::assertSame('reserve:0:0:gen:case|', $calls[2]);
		self::assertCount(3, $calls);
	}//end testNumberingFillsAndAdvances()

	/**
	 * A changed identifier on update is refused with the exact body.
	 *
	 * @return void
	 */
	public function testFreezeRefusalBody(): void {
		$sequences = $this->createMock(SequenceServiceInterface::class);
		$event = new ObjectUpdatingEvent($this->object(['id1' => 'Z-009', 'id2' => 'Z-999']), $this->object(['id1' => 'Z-009', 'id2' => 'Z-002']));
		$this->numbering(['id1' => $this->declared(), 'id2' => $this->declared()], $sequences)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(
			[
				'code' => 'generated-identifier-frozen',
				'message' => '"id2" is a generated identifier and cannot be changed. It was issued as "Z-002".',
				'property' => 'id2',
				'issued' => 'Z-002',
			],
			$event->getErrors()
		);
	}//end testFreezeRefusalBody()
}//end class

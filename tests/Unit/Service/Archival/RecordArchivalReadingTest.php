<?php

/**
 * OpenRegister RecordArchivalReadingTest
 *
 * The Compliance answer to rendering's archival questions, and that Compliance binds it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Core\RecordArchivalReadingInterface;
use OCA\OpenRegister\Service\Archival\AnnotatedRow;
use OCA\OpenRegister\Service\Archival\ArchivalDecisionResolver;
use OCA\OpenRegister\Service\Archival\RecordArchivalReading;
use OCA\OpenRegister\Service\Archival\RetentionEvaluator;
use OCA\OpenRegister\Service\ComplianceServiceBindings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Archival\RecordArchivalReading
 * @uses   \OCA\OpenRegister\Service\Archival\AnnotatedRow
 * @uses   \OCA\OpenRegister\Service\Archival\RetentionEvaluator
 * @uses   \OCA\OpenRegister\Service\Archival\ArchivalDecisionResolver
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
final class RecordArchivalReadingTest extends TestCase {

	/**
	 * A record created on 2 January 2026.
	 *
	 * @return ObjectEntity
	 */
	private static function record(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('11111111-1111-4111-8111-111111111111');
		$entity->setObject(['title' => 'Z-1']);
		$entity->setCreated(new DateTime('2026-01-02T00:00:00+00:00'));

		return $entity;
	}//end record()

	/**
	 * The annotation block is what the retention evaluator says for the record's data and creation date.
	 *
	 * @return void
	 */
	public function testTheAnnotationIsTheEvaluatorsAnswer(): void {
		$annotation = ['retention' => ['default' => 'P1Y']];
		$entity     = self::record();

		$expected = (new RetentionEvaluator(logger: new NullLogger()))->evaluate(subject: new AnnotatedRow(annotation: $annotation, row: ['title' => 'Z-1'], createdAt: $entity->getCreated()));
		$actual   = (new RecordArchivalReading(logger: new NullLogger()))->retentionAnnotation(annotation: $annotation, entity: $entity);

		self::assertSame($expected, $actual);
		self::assertSame('P1Y', $actual['effectiveRetention']);
	}//end testTheAnnotationIsTheEvaluatorsAnswer()

	/**
	 * A record without a creation date gets an empty block.
	 *
	 * @return void
	 */
	public function testARecordWithoutACreationDateGetsNothing(): void {
		$entity = self::record();
		$entity->setCreated(null);

		self::assertSame([], (new RecordArchivalReading(logger: new NullLogger()))->retentionAnnotation(annotation: ['retention' => ['default' => 'P1Y']], entity: $entity));
	}//end testARecordWithoutACreationDateGetsNothing()

	/**
	 * The decision is what the decision resolver says.
	 *
	 * @return void
	 */
	public function testTheDecisionIsTheResolversAnswer(): void {
		$entity = self::record();
		$entity->setRetention(['annotation' => ['effectiveRetention' => 'P1Y', 'expiresAt' => '2027-01-02T00:00:00+00:00']]);

		self::assertSame(
			(new ArchivalDecisionResolver())->resolve(entity: $entity),
			(new RecordArchivalReading(logger: new NullLogger()))->archivalDecision(entity: $entity)
		);
	}//end testTheDecisionIsTheResolversAnswer()

	/**
	 * Compliance binds the Core port to this class in its own map.
	 *
	 * @return void
	 */
	public function testComplianceBindsThePort(): void {
		self::assertSame(RecordArchivalReading::class, ComplianceServiceBindings::BINDINGS[RecordArchivalReadingInterface::class] ?? null);
	}//end testComplianceBindsThePort()
}//end class

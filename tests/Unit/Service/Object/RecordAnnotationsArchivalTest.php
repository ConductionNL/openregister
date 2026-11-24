<?php

/**
 * OpenRegister RecordAnnotationsArchivalTest
 *
 * Rendering attaches the archival retention and decision it is given through
 * the Core port, guarded, and attaches neither without one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\Core\RecordArchivalReadingInterface;
use OCA\OpenRegister\Service\Object\RecordAnnotations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Object\RecordAnnotations
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 */
final class RecordAnnotationsArchivalTest extends TestCase {

	/**
	 * A record created on 2 January 2026 that already holds a retention key.
	 *
	 * @return ObjectEntity
	 */
	private static function record(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('u-1');
		$entity->setCreated(new DateTime('2026-01-02T00:00:00+00:00'));
		$entity->setRetention(['kept' => 1]);

		return $entity;
	}//end record()

	/**
	 * A schema declaring an archival annotation.
	 *
	 * @return Schema
	 */
	private static function schema(): Schema {
		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-archival' => ['retention' => ['default' => 'P1Y']]]);

		return $schema;
	}//end schema()

	/**
	 * The port's answers land on the record: the annotation beside what retention held, then the decision.
	 *
	 * @return void
	 */
	public function testThePortsAnswersLandOnTheRecord(): void {
		$archival = $this->createMock(RecordArchivalReadingInterface::class);
		$archival->expects(self::once())->method('retentionAnnotation')->with(['retention' => ['default' => 'P1Y']])->willReturn(['effectiveRetention' => 'P1Y']);
		$archival->expects(self::once())->method('archivalDecision')->willReturn(['retentionPeriod' => 'P1Y']);

		$entity      = self::record();
		$annotations = new RecordAnnotations(logger: new NullLogger(), archival: $archival);
		$annotations->attachRetention(entity: $entity, schema: self::schema());
		$annotations->attachDecision(entity: $entity);

		self::assertSame(['kept' => 1, 'annotation' => ['effectiveRetention' => 'P1Y']], $entity->getRetention());
		self::assertSame(['retentionPeriod' => 'P1Y'], $entity->getArchivalRetention());
	}//end testThePortsAnswersLandOnTheRecord()

	/**
	 * A failing port is a debug line each, never a failed render.
	 *
	 * @return void
	 */
	public function testAFailingPortIsOnlyLogged(): void {
		$archival = $this->createMock(RecordArchivalReadingInterface::class);
		$archival->method('retentionAnnotation')->willThrowException(new RuntimeException('bad annotation'));
		$archival->method('archivalDecision')->willThrowException(new RuntimeException('no decision'));

		$messages = [];
		$logger   = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			static function ($message) use (&$messages): void {
				$messages[] = (string)$message;
			}
		);

		$entity      = self::record();
		$annotations = new RecordAnnotations(logger: $logger, archival: $archival);
		$annotations->attachRetention(entity: $entity, schema: self::schema());
		$annotations->attachDecision(entity: $entity);

		self::assertSame(
			[
				'[RenderObject] archival retention compute failed for u-1: bad annotation',
				'[RenderObject] archival decision resolve failed for u-1: no decision',
			],
			$messages
		);
		self::assertSame(['kept' => 1], $entity->getRetention());
	}//end testAFailingPortIsOnlyLogged()

	/**
	 * Without an archival reading neither block is attached.
	 *
	 * @return void
	 */
	public function testWithoutAPortNothingIsAttached(): void {
		$entity      = self::record();
		$annotations = new RecordAnnotations(logger: new NullLogger());
		$annotations->attachRetention(entity: $entity, schema: self::schema());
		$annotations->attachDecision(entity: $entity);

		self::assertSame(['kept' => 1], $entity->getRetention());
		self::assertNull($entity->getArchivalRetention());
	}//end testWithoutAPortNothingIsAttached()
}//end class

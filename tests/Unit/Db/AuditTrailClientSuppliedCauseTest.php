<?php

/**
 * A request that supplies its own audit cause leaves a mark on the sealed row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail\AuditedChange;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\WriteCause;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\AuditTrailMapper
 * @uses \OCA\OpenRegister\Db\AuditTrail
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\AuditFlowAttribution
 * @uses \OCA\OpenRegister\Db\AuditTrailPayloadHelper
 * @uses \OCA\OpenRegister\Service\Audit\PurposeAttribution
 * @uses \OCA\OpenRegister\Service\Audit\TokenAttribution
 * @uses \OCA\OpenRegister\Service\WriteCause
 * @uses \OCA\OpenRegister\Service\Audit\OrganisationContextAttribution
 * @uses \OCA\OpenRegister\Service\OrganisationContext
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope
 */
final class AuditTrailClientSuppliedCauseTest extends TestCase {

	/**
	 * The ambient cause stack is shared, so every test starts from empty.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		WriteCause::reset();
	}//end setUp()

	/**
	 * And leaves nothing behind.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		WriteCause::reset();
		parent::tearDown();
	}//end tearDown()

	/**
	 * The mapper, with nothing registered in its container.
	 *
	 * @return AuditTrailMapper
	 */
	private function mapper(): AuditTrailMapper {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not registered'));

		return new AuditTrailMapper(
			$this->createMock(IDBConnection::class),
			new AuditTrailMapperServices($container, $this->createMock(LoggerInterface::class), $this->createMock(IUserSession::class), $this->createMock(IRequest::class))
		);
	}//end mapper()

	/**
	 * A stored object.
	 *
	 * @param string $title Its title.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $title): ObjectEntity {
		$object = new ObjectEntity();
		$object->setId(7);
		$object->setUuid('u-7');
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setObject(['title' => $title]);

		return $object;
	}//end object()

	/**
	 * A client cannot claim to be a migration: the cause is derived, and the attempt is on the row.
	 *
	 * @return void
	 */
	public function testAClientSuppliedCauseIsIgnoredAndRecorded(): void {
		WriteCause::noteClientAttempt(objectUuid: 'u-7');

		$trail = $this->mapper()->buildEntry(change: new AuditedChange(old: $this->object(title: 'A'), new: $this->object(title: 'B')));

		$this->assertSame(WriteCause::PERSON, $trail->getCause(), 'the cause is the one derived from the acting context');
		$this->assertTrue($trail->getChanged()['clientSuppliedCause'] ?? false, 'the attempt is recorded on the entry');
	}//end testAClientSuppliedCauseIsIgnoredAndRecorded()

	/**
	 * The control: a request that supplied no cause carries no marker.
	 *
	 * @return void
	 */
	public function testNoAttemptLeavesNoMarker(): void {
		$trail = $this->mapper()->buildEntry(change: new AuditedChange(old: $this->object(title: 'A'), new: $this->object(title: 'B')));

		$this->assertArrayNotHasKey('clientSuppliedCause', (array)$trail->getChanged());
	}//end testNoAttemptLeavesNoMarker()

	/**
	 * The marker rides every row the request writes, a create included.
	 *
	 * @return void
	 */
	public function testACreateCarriesTheMarkerToo(): void {
		WriteCause::noteClientAttempt(objectUuid: 'u-7');

		$trail = $this->mapper()->buildEntry(change: new AuditedChange(new: $this->object(title: 'B')));

		$this->assertSame('create', $trail->getAction());
		$this->assertTrue($trail->getChanged()['clientSuppliedCause'] ?? false);
	}//end testACreateCarriesTheMarkerToo()

	/**
	 * A row for another object written later in the same request carries no marker.
	 *
	 * @return void
	 */
	public function testAnotherObjectsRowCarriesNoMarker(): void {
		WriteCause::noteClientAttempt(objectUuid: 'u-7');

		$other = $this->object(title: 'C');
		$other->setId(8);
		$other->setUuid('u-8');
		$trail = $this->mapper()->buildEntry(change: new AuditedChange(new: $other));

		$this->assertArrayNotHasKey('clientSuppliedCause', (array)$trail->getChanged());
	}//end testAnotherObjectsRowCarriesNoMarker()
}//end class

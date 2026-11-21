<?php

/**
 * A revert's audit row says it was a revert and names the point it restored.
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
 * @spec openspec/changes/history-revert-through-the-save-path/specs/content-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail\AuditedChange;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Db\ObjectEntity;
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
 * @uses \OCA\OpenRegister\Service\Audit\OrganisationContextAttribution
 * @uses \OCA\OpenRegister\Service\OrganisationContext
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope
 * @uses \OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices
 * @uses \OCA\OpenRegister\Db\AuditTrail\AuditedChange
 * @uses \OCA\OpenRegister\Service\WriteCause
 * @uses \OCA\OpenRegister\Service\Audit\AuditRowAttribution
 * @uses \OCA\OpenRegister\Service\Audit\AuditTrailCollaborators
 */
final class AuditTrailRevertMarksTest extends TestCase {

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
	 * @param string $title   Its title.
	 * @param string $version Its version.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $title, string $version): ObjectEntity {
		$object = new ObjectEntity();
		$object->setId(7);
		$object->setUuid('u-7');
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setVersion($version);
		$object->setObject(['title' => $title]);

		return $object;
	}//end object()

	/**
	 * A revert is recorded as a `revert`, with the version restored to beside the changed values.
	 *
	 * @return void
	 */
	public function testARevertRowCarriesItsActionAndTheVersionRestoredTo(): void {
		$trail = $this->mapper()->buildEntry(
			change: new AuditedChange(
				old: $this->object(title: 'third', version: '1.0.3'),
				new: $this->object(title: 'first', version: '1.0.4'),
				action: 'revert',
				marks: ['revertedTo' => '1.0.1', 'revertedToVersion' => '1.0.1']
			)
		);

		$this->assertSame('revert', $trail->getAction());
		$this->assertSame('1.0.1', $trail->getChanged()['revertedToVersion'] ?? null);
		$this->assertSame('1.0.1', $trail->getChanged()['revertedTo'] ?? null);
		$this->assertSame(['old' => 'third', 'new' => 'first'], $trail->getChanged()['title'] ?? null);
		$this->assertSame('1.0.4', $trail->getVersion());
	}//end testARevertRowCarriesItsActionAndTheVersionRestoredTo()

	/**
	 * The control: an edit carries no revert markers.
	 *
	 * @return void
	 */
	public function testAnEditCarriesNoRevertMarkers(): void {
		$trail = $this->mapper()->buildEntry(change: new AuditedChange(old: $this->object(title: 'A', version: '1.0.0'), new: $this->object(title: 'B', version: '1.0.1')));

		$this->assertSame('update', $trail->getAction());
		$this->assertArrayNotHasKey('revertedToVersion', (array)$trail->getChanged());
		$this->assertArrayNotHasKey('revertedTo', (array)$trail->getChanged());
	}//end testAnEditCarriesNoRevertMarkers()
}//end class

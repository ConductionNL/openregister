<?php

/**
 * Golden test for an inbound registry update across several subscriptions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Registry;

use OCA\OpenRegister\Service\Registry\RegistryIdentity;
use OCA\OpenRegister\Service\Registry\InboundUpdate;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegistrySubscription;
use OCA\OpenRegister\Db\RegistrySubscriptionMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Registry\RegistryInboundServices;
use OCA\OpenRegister\Service\Registry\RegistrySubscriptionNotifier;
use OCA\OpenRegister\Service\Registry\RegistrySubscriptionService;
use OCA\OpenRegister\Service\Registry\RegistryUpdateTargetGuard;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Service\Object\ObjectPatch;

/**
 * Pins the allowed, refused and failed outcomes of one inbound update that
 * matches three subscriptions, and what is written for the applied one.
 */
class RegistryInboundUpdateGoldenTest extends TestCase {

	/**
	 * One refused row, one row whose patch fails and one applied row.
	 *
	 * @return void
	 */
	public function testMixedRowsAreRefusedFailedAndAppliedIndependently(): void {
		$refused = $this->row(uuid: 'refused');
		$failing = $this->row(uuid: 'failing');
		$applied = $this->row(uuid: 'applied');

		$mapper = $this->createMock(RegistrySubscriptionMapper::class);
		$mapper->method('findActiveByIdentity')->willReturnCallback(
			function (string $registry, string $identityValue) use ($refused, $failing, $applied): array {
				self::assertSame('brp', $registry);
				self::assertSame('999990019', $identityValue);
				return [$refused, $failing, $applied];
			}
		);
		$saved = [];
		$mapper->method('save')->willReturnCallback(
			function (RegistrySubscription $subscription) use (&$saved): RegistrySubscription {
				$saved[] = $subscription->getObjectUuid();
				return $subscription;
			}
		);

		$guard = $this->createMock(RegistryUpdateTargetGuard::class);
		$guard->method('evaluate')->willReturnCallback(
			function (RegistrySubscription $row, array $properties): array {
				self::assertSame(['address' => 'Dam 1', 'givenNames' => 'S'], $properties);
				if ($row->getObjectUuid() === 'refused') {
					return ['allowed' => false, 'rejected' => ['givenNames']];
				}

				return ['allowed' => true, 'rejected' => []];
			}
		);

		$updated = new ObjectEntity();
		$updated->setUuid('applied');
		$objects = $this->createMock(ObjectService::class);
		$objects->method('patchObject')->willReturnCallback(
			function (ObjectPatch $patch, bool $_rbac = true, bool $_multitenancy = true) use ($updated): ObjectEntity {
				$objectId = $patch->objectId;
				if ($objectId === 'failing') {
					throw new RuntimeException('db gone');
				}

				return $updated;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$audits = [];
		$audit  = $this->createMock(\OCA\OpenRegister\Db\AuditTrailMapper::class);
		$audit->method('createAuditTrailEntry')->willReturnCallback(
			function (...$args) use (&$audits) {
				$audits[] = $args;
				return new \OCA\OpenRegister\Db\AuditTrail();
			}
		);
		$notifier = new RegistrySubscriptionNotifier($this->createMock(IEventDispatcher::class), $audit);

		$service = new RegistrySubscriptionService(
			$mapper,
			$notifier,
			new RegistryInboundServices(objectService: $objects, updateTargetGuard: $guard),
			$logger,
		);

		$result = $service->applyInboundUpdate(new InboundUpdate(new RegistryIdentity('brp', '999990019'), ['address' => 'Dam 1', 'givenNames' => 'S'], 'evt-2'));

		self::assertSame(
			[
				'matched'  => 3,
				'applied'  => ['applied'],
				'rejected' => [
					['objectUuid' => 'refused', 'properties' => ['givenNames']],
					['objectUuid' => 'failing', 'properties' => ['address', 'givenNames']],
				],
			],
			$result
		);
		self::assertSame(['applied'], $saved);
		self::assertSame(RegistrySubscription::STATE_ACTIVE, $applied->getState());
		self::assertSame('evt-2', $applied->getLastUpdateSource());
		self::assertNotNull($applied->getLastUpdateAt());
		self::assertNull($refused->getLastUpdateSource());
		self::assertNull($failing->getLastUpdateSource());
		self::assertCount(1, $audits);
		self::assertSame($updated, $audits[0][0]);
		self::assertSame('registry.update', $audits[0][1]);
		self::assertSame(
			['registry' => 'brp', 'eventReference' => 'evt-2', 'properties' => ['address', 'givenNames']],
			$audits[0][2]
		);
		self::assertSame('registry:brp', $audits[0][3]);
		self::assertSame('Registry (brp)', $audits[0][4]);
	}//end testMixedRowsAreRefusedFailedAndAppliedIndependently()

	/**
	 * A requested subscription row for the test to stamp.
	 *
	 * @param string $uuid The object uuid.
	 *
	 * @return RegistrySubscription
	 */
	private function row(string $uuid): RegistrySubscription {
		$row = new RegistrySubscription();
		$row->setObjectUuid($uuid);
		$row->setRegister('dossiq');
		$row->setSchema('brpPerson');
		$row->setRegistry('brp');
		$row->setIdentityValue('999990019');
		$row->setState(RegistrySubscription::STATE_REQUESTED);

		return $row;
	}//end row()
}//end class

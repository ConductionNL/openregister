<?php

/**
 * RegistrySubscriptionService: the @self state mirror for one or many objects,
 * an inbound patch that fails to save, and a malformed `owned` declaration.
 *
 * RegistrySubscriptionServiceTest covers request, end and the guarded inbound
 * update; this file covers the remaining decisions.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Registry
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Registry;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Service\Registry\RegistryIdentity;
use OCA\OpenRegister\Service\Registry\InboundUpdate;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegistrySubscription;
use OCA\OpenRegister\Db\RegistrySubscriptionMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Registry\RegistryInboundServices;
use OCA\OpenRegister\Service\Registry\RegistrySubscriptionNotifier;
use OCA\OpenRegister\Service\Registry\RegistrySubscriptionService;
use OCA\OpenRegister\Service\Registry\RegistryUpdateTargetGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Service\Object\ObjectPatch;

/**
 * @covers \OCA\OpenRegister\Service\Registry\RegistrySubscriptionService
 */
class RegistrySubscriptionStateTest extends TestCase {

	private RegistrySubscriptionMapperInterface&MockObject $mapper;
	private RegistrySubscriptionNotifier&MockObject $notifier;
	private ObjectOperationsInterface&MockObject $objects;
	private RegistryUpdateTargetGuard&MockObject $guard;
	private LoggerInterface&MockObject $logger;
	private RegistrySubscriptionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(RegistrySubscriptionMapperInterface::class);
		$this->notifier = $this->createMock(RegistrySubscriptionNotifier::class);
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->guard = $this->createMock(RegistryUpdateTargetGuard::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new RegistrySubscriptionService(
			$this->mapper,
			$this->notifier,
			new RegistryInboundServices($this->objects, $this->guard),
			$this->logger
		);
	}

	private static function row(string $uuid, string $state, ?DateTime $lastUpdate = null): RegistrySubscription {
		$row = new RegistrySubscription();
		$row->setObjectUuid($uuid);
		$row->setRegister('dossiq');
		$row->setSchema('brpPerson');
		$row->setRegistry('brp');
		$row->setState($state);
		$row->setLastUpdateAt($lastUpdate);
		$row->setLastUpdateSource($lastUpdate === null ? null : 'evt-1');
		return $row;
	}

	public function testStateForAnObjectWithoutASubscriptionIsNull(): void {
		$this->mapper->expects($this->once())->method('findForObject')->with('o-1')->willReturn(null);

		$this->assertNull($this->service->stateFor('o-1'));
	}

	public function testStateForMirrorsTheSubscriptionRow(): void {
		$at = new DateTime('2026-05-01T10:00:00', new DateTimeZone('UTC'));
		$this->mapper->method('findForObject')->willReturn(self::row('o-1', RegistrySubscription::STATE_ACTIVE, $at));

		$this->assertSame(
			['registry' => 'brp', 'state' => RegistrySubscription::STATE_ACTIVE, 'lastUpdate' => '2026-05-01T10:00:00+00:00', 'lastUpdateSource' => 'evt-1', 'refusalReason' => null],
			$this->service->stateFor('o-1')
		);
	}

	public function testStatesForMirrorsEveryRowKeyedByObject(): void {
		$this->mapper->expects($this->once())->method('findForObjects')->with(['a', 'b', 'c'])->willReturn([
			'a' => self::row('a', RegistrySubscription::STATE_REQUESTED),
			'c' => self::row('c', RegistrySubscription::STATE_ENDED),
		]);

		$states = $this->service->statesFor(['a', 'b', 'c']);

		$this->assertSame(['a', 'c'], array_keys($states));
		$this->assertSame(RegistrySubscription::STATE_REQUESTED, $states['a']['state']);
		$this->assertSame(RegistrySubscription::STATE_ENDED, $states['c']['state']);
	}

	public function testStatesForNoRowsIsEmpty(): void {
		$this->mapper->method('findForObjects')->willReturn([]);

		$this->assertSame([], $this->service->statesFor(['a']));
	}

	public function testAPatchThatFailsToSaveIsRejectedLoggedAndNotRecorded(): void {
		$failing = self::row('o-fail', RegistrySubscription::STATE_ACTIVE);
		$working = self::row('o-ok', RegistrySubscription::STATE_REQUESTED);
		$this->mapper->method('findActiveByIdentity')->with('brp', '999990019')->willReturn([$failing, $working]);
		$this->guard->method('evaluate')->willReturn(['allowed' => true, 'rejected' => []]);
		$this->objects->method('patchObject')->willReturnCallback(static function (ObjectPatch $patch, bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
			$objectId = $patch->objectId;
			if ($objectId === 'o-fail') {
				throw new RuntimeException('locked');
			}

			return new ObjectEntity();
		});
		$this->logger->expects($this->once())->method('error')
			->with('[OpenRegister.RegistrySubscriptionService] Inbound update failed to save for object o-fail: locked');
		$this->mapper->expects($this->once())->method('save')->with($working)->willReturnArgument(0);
		$this->notifier->expects($this->once())->method('auditInboundUpdate');

		$result = $this->service->applyInboundUpdate(new InboundUpdate(new RegistryIdentity('brp', '999990019'), ['address' => 'Straat 1', 'givenNames' => 'S'], 'evt-9'));

		$this->assertSame(
			['matched' => 2, 'applied' => ['o-ok'], 'rejected' => [['objectUuid' => 'o-fail', 'properties' => ['address', 'givenNames']]]],
			$result
		);
		$this->assertSame(RegistrySubscription::STATE_ACTIVE, $working->getState());
		$this->assertSame('evt-9', $working->getLastUpdateSource());
	}

	public function testANonListOwnedDeclarationOwnsNothing(): void {
		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-registry' => ['registry' => 'brp', 'identity' => 'bsn', 'owned' => 'address']]);

		$this->assertSame(['registry' => 'brp', 'identity' => 'bsn', 'owned' => []], $this->service->annotationFor($schema));
	}

	public function testANonArrayAnnotationIsNoAnnotation(): void {
		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-registry' => 'brp']);

		$this->assertNull($this->service->annotationFor($schema));
	}
}

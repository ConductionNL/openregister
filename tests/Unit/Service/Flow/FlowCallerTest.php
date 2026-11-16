<?php

/**
 * The flows a caller owns, and the owner and organisation a flow they write carries, each degrade to empty or null instead of throwing.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\Flow\FlowCaller;
use OCA\OpenRegister\Service\Flow\FlowServicePlatform;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowCaller
 * @uses   \OCA\OpenRegister\Service\Flow\FlowServicePlatform
 * @uses   \OCA\OpenRegister\Db\Organisation
 * @uses   \OCA\OpenRegister\Db\Flow\FlowSelection
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 */
final class FlowCallerTest extends TestCase {

	/**
	 * The mapper.
	 *
	 * @var FlowMapperInterface&MockObject
	 */
	private FlowMapperInterface&MockObject $mapper;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper    = $this->createMock(FlowMapperInterface::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
		$this->container = $this->createMock(ContainerInterface::class);
	}//end setUp()

	/**
	 * A caller for a session with this uid, or none.
	 *
	 * @param string|null $uid The uid, null for no session.
	 *
	 * @return FlowCaller The caller.
	 */
	private function caller(?string $uid): FlowCaller {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new FlowCaller(
			mapper: $this->mapper,
			platform: new FlowServicePlatform(userSession: $session, logger: $this->logger, container: $this->container)
		);
	}//end caller()

	/**
	 * An organisation service whose active organisation has this uuid, or none.
	 *
	 * @param string|null $uuid The uuid, null for no active organisation.
	 *
	 * @return OrganisationServiceInterface The service.
	 */
	private function organisations(?string $uuid): OrganisationServiceInterface {
		$organisation = null;
		if ($uuid !== null) {
			$organisation = new Organisation();
			$organisation->setUuid($uuid);
		}

		$service = $this->createMock(OrganisationServiceInterface::class);
		$service->method('getActiveOrganisation')->willReturn($organisation);

		return $service;
	}//end organisations()

	/**
	 * Without a session no flows are owned and the mapper is not asked.
	 *
	 * @return void
	 */
	public function testNoSessionOwnsNothing(): void {
		$this->mapper->expects(self::never())->method('findIdsOwnedBy');

		self::assertSame([], $this->caller(null)->idsOwnedByCaller());
	}//end testNoSessionOwnsNothing()

	/**
	 * An empty uid counts as no session.
	 *
	 * @return void
	 */
	public function testAnEmptyUidIsNoActingUser(): void {
		$this->mapper->expects(self::never())->method('findIdsOwnedBy');
		$caller = $this->caller('');

		self::assertNull($caller->actingUser());
		self::assertSame([], $caller->idsOwnedByCaller());
	}//end testAnEmptyUidIsNoActingUser()

	/**
	 * With a session the mapper answers for that uid.
	 *
	 * @return void
	 */
	public function testTheCallersOwnedFlowsComeFromTheMapper(): void {
		$this->mapper->expects(self::once())->method('findIdsOwnedBy')->with('alice')->willReturn(['f-1', 'f-2']);

		self::assertSame(['f-1', 'f-2'], $this->caller('alice')->idsOwnedByCaller());
	}//end testTheCallersOwnedFlowsComeFromTheMapper()

	/**
	 * An unreadable listing is logged as a warning and reads as owning nothing.
	 *
	 * @return void
	 */
	public function testAFailingListingIsLoggedAndOwnsNothing(): void {
		$this->mapper->method('findIdsOwnedBy')->willThrowException(new RuntimeException('db gone'));
		$this->logger->expects(self::once())->method('warning')->with(
			self::equalTo('[FlowCaller] Could not list the caller\'s owned flows: db gone'),
			self::callback(static fn (array $context): bool => $context['file'] !== '' && is_int($context['line']))
		);

		self::assertSame([], $this->caller('alice')->idsOwnedByCaller());
	}//end testAFailingListingIsLoggedAndOwnsNothing()

	/**
	 * Ownership is the acting uid and the active organisation's uuid.
	 *
	 * @return void
	 */
	public function testOwnershipIsTheUidAndTheActiveOrganisation(): void {
		$this->container->expects(self::once())->method('get')
			->with('OCA\OpenRegister\Service\OrganisationServiceInterface')
			->willReturn($this->organisations('org-9'));

		self::assertSame(['owner' => 'alice', 'organisation' => 'org-9'], $this->caller('alice')->ownership());
	}//end testOwnershipIsTheUidAndTheActiveOrganisation()

	/**
	 * No active organisation, or one with an empty uuid, is a null organisation.
	 *
	 * @return void
	 */
	public function testNoOrEmptyOrganisationIsNull(): void {
		$this->container->method('get')->willReturnOnConsecutiveCalls($this->organisations(null), $this->organisations(''));
		$caller = $this->caller('alice');

		self::assertNull($caller->activeOrganisation());
		self::assertNull($caller->activeOrganisation());
	}//end testNoOrEmptyOrganisationIsNull()

	/**
	 * An organisation service that cannot be resolved is logged at debug and yields a null organisation.
	 *
	 * @return void
	 */
	public function testAnUnresolvableOrganisationServiceIsNull(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('not wired'));
		$this->logger->expects(self::once())->method('debug')->with(
			self::equalTo('[FlowService] Could not resolve the active organisation: not wired'),
			self::anything()
		);

		self::assertSame(['owner' => null, 'organisation' => null], $this->caller(null)->ownership());
	}//end testAnUnresolvableOrganisationServiceIsNull()
}//end class

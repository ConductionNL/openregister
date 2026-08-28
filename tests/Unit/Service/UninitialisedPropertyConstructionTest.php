<?php

/**
 * Constructing the three services that previously had no constructor.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use GuzzleHttp\Client;
use OCA\OpenRegister\Db\EndpointLogMapper;
use OCA\OpenRegister\Service\EndpointService;
use OCA\OpenRegister\Service\NotificationService;
use OCA\OpenRegister\Service\UploadService;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Notification\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * These three classes declared `readonly` properties and had NO constructor, so
 * nothing ever assigned them. Every method that touched one died with
 *
 *     Typed property X::$y must not be accessed before initialization
 *
 * — a fatal, not a degraded path.
 *
 * WHAT THIS ADDS OVER THE STATIC DETECTOR, measured rather than assumed. The
 * companion `Contract\TypedPropertyInitialisationTest` parses constructor
 * BODIES, so it does catch a dropped assignment — verified by removing one and
 * watching it fail. It cannot catch what it never executes:
 *
 *   - it does not RUN the constructors, so their statements are uncovered, and
 *     the coverage ratchet fails a PR that adds them (which is how this file
 *     came to be written);
 *   - it checks that each property is assigned SOMETHING, not that the right
 *     collaborator lands in the right property. Verified: duplicating one
 *     assignment so a property is written twice leaves the static detector
 *     green.
 *
 * So this file constructs each service and reads back what the constructor
 * stored. `assertNotNull($service)` would not do — that passes against an empty
 * constructor body, which is the original bug exactly.
 *
 * @spec exclude Regression cover for a fatal-on-use defect; no capability spec
 *  describes "the class can be constructed".
 */
class UninitialisedPropertyConstructionTest extends TestCase {

	/**
	 * Reading a promoted/assigned readonly property back out.
	 *
	 * The properties are private, so the only honest way to assert the
	 * constructor stored the RIGHT collaborator — rather than merely stored
	 * something — is reflection. Asserting `assertNotNull($service)` would pass
	 * against a constructor with an empty body, which is the exact bug.
	 *
	 * @param object $target The constructed service.
	 * @param string $name   The property name.
	 *
	 * @return mixed The stored value.
	 */
	private function readProperty(object $target, string $name): mixed {
		$property = new \ReflectionProperty($target, $name);
		$property->setAccessible(true);
		return $property->getValue($target);
	}//end readProperty()

	/**
	 * EndpointService stores all four collaborators.
	 *
	 * @return void
	 */
	public function testEndpointServiceStoresItsCollaborators(): void {
		$mapper = $this->createMock(EndpointLogMapper::class);
		$logger = $this->createMock(LoggerInterface::class);
		$session = $this->createMock(IUserSession::class);
		$groups = $this->createMock(IGroupManager::class);

		$service = new EndpointService($mapper, $logger, $session, $groups);

		$this->assertSame($mapper, $this->readProperty($service, 'endpointLogMapper'));
		$this->assertSame($logger, $this->readProperty($service, 'logger'));
		$this->assertSame($session, $this->readProperty($service, 'userSession'));
		$this->assertSame($groups, $this->readProperty($service, 'groupManager'));
	}//end testEndpointServiceStoresItsCollaborators()

	/**
	 * NotificationService stores all three collaborators.
	 *
	 * @return void
	 */
	public function testNotificationServiceStoresItsCollaborators(): void {
		$manager = $this->createMock(IManager::class);
		$groups = $this->createMock(IGroupManager::class);
		$logger = $this->createMock(LoggerInterface::class);

		$service = new NotificationService($manager, $groups, $logger);

		$this->assertSame($manager, $this->readProperty($service, 'notificationManager'));
		$this->assertSame($groups, $this->readProperty($service, 'groupManager'));
		$this->assertSame($logger, $this->readProperty($service, 'logger'));
	}//end testNotificationServiceStoresItsCollaborators()

	/**
	 * UploadService takes an injected client.
	 *
	 * @return void
	 */
	public function testUploadServiceStoresAnInjectedClient(): void {
		$client = new Client();

		$service = new UploadService($client);

		$this->assertSame($client, $this->readProperty($service, 'client'));
	}//end testUploadServiceStoresAnInjectedClient()

	/**
	 * UploadService defaults its client rather than leaving it uninitialised.
	 *
	 * The `?Client $client = null` default is the branch that matters: the
	 * production container constructs this with no argument, so if the default
	 * did not build a Client the property would be exactly as uninitialised as
	 * it was before the constructor existed.
	 *
	 * @return void
	 */
	public function testUploadServiceDefaultsItsClientWhenNoneIsGiven(): void {
		$service = new UploadService();

		$this->assertInstanceOf(Client::class, $this->readProperty($service, 'client'));
	}//end testUploadServiceDefaultsItsClientWhenNoneIsGiven()
}//end class

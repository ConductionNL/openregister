<?php

/**
 * The DeferredObjectEvent parameter objects carry exactly the collaborators they were given.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob\Support;

use OCA\OpenRegister\BackgroundJob\Support\DeferredObjectEventActor;
use OCA\OpenRegister\BackgroundJob\Support\DeferredObjectEventLookup;
use OCA\OpenRegister\BackgroundJob\Support\DeferredObjectEventPlatform;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Behaviour of the three parameter objects.
 */
class DeferredObjectEventSupportTest extends TestCase {

	public function testTheActorBundleKeepsSessionAndManagerApart(): void {
		$session = $this->createMock(IUserSession::class);
		$manager = $this->createMock(IUserManager::class);

		$actor = new DeferredObjectEventActor($session, $manager);

		$this->assertSame($session, $actor->userSession);
		$this->assertSame($manager, $actor->userManager);
	}

	public function testTheLookupBundleKeepsEachMapperInItsOwnSlot(): void {
		$objects = $this->createMock(MagicMapperInterface::class);
		$registers = $this->createMock(RegisterMapperInterface::class);
		$schemas = $this->createMock(SchemaMapperInterface::class);

		$lookup = new DeferredObjectEventLookup($objects, $registers, $schemas);

		$this->assertSame($objects, $lookup->objectMapper);
		$this->assertSame($registers, $lookup->registerMapper);
		$this->assertSame($schemas, $lookup->schemaMapper);
	}

	public function testThePlatformBundleKeepsDispatcherAndLogger(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$logger = $this->createMock(LoggerInterface::class);

		$platform = new DeferredObjectEventPlatform($dispatcher, $logger);

		$this->assertSame($dispatcher, $platform->eventDispatcher);
		$this->assertSame($logger, $platform->logger);
	}
}

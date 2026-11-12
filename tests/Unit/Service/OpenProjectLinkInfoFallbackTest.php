<?php

/**
 * OpenProjectLinkInfoFallbackTest
 *
 * Pins the subject and url a link row gets when OpenProject gives no info
 * (link time, connector off) and when a refresh answers without a url.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\OpenProjectLink;
use OCA\OpenRegister\Db\OpenProjectLinkMapper;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider;
use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCA\OpenRegister\Service\LinkTarget;
use OCA\OpenRegister\Service\OpenProjectLinkService;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\OpenProjectLinkService
 */
class OpenProjectLinkInfoFallbackTest extends TestCase {
	/**
	 * The service over a mapper that echoes writes, with the connector on or off.
	 *
	 * @param bool        $connector Whether the connector app is enabled.
	 * @param array<int, OpenProjectLink> $stored Rows findByObjectUuid returns.
	 * @param array|null  $row       What the provider's get() answers.
	 *
	 * @return OpenProjectLinkService
	 */
	private function service(bool $connector, array $stored = [], ?array $row = null): OpenProjectLinkService {
		$mapper = $this->getMockBuilder(OpenProjectLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByObjectUuid', 'findByObjectAndWorkPackage', 'insert', 'update'])
			->getMock();
		$mapper->method('insert')->willReturnArgument(0);
		$mapper->method('update')->willReturnArgument(0);
		$mapper->method('findByObjectUuid')->willReturn($stored);
		$provider = $this->createMock(OpenProjectProvider::class);
		$provider->method('get')->willReturn($row ?? []);
		$apps  = $this->createMock(IAppManager::class);
		$match = static fn (string $id): bool => ($connector === true && $id === 'openconnector');
		$apps->method('isInstalled')->willReturnCallback($match);
		$apps->method('isEnabledForUser')->willReturnCallback($match);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new OpenProjectLinkService(
			$mapper,
			$provider,
			$this->createMock(ExternalIntegrationRouter::class),
			new LinkServiceRuntime($apps, $session, $this->createMock(LoggerInterface::class))
		);
	}//end service()

	/**
	 * Without the connector the link keeps the id as its subject and has no url.
	 *
	 * @return void
	 */
	public function testLinkWithoutInfoUsesTheIdAsSubject(): void {
		$target = new LinkTarget(objectUuid: 'obj-1', registerId: 3);
		$link   = $this->service(connector: false)->linkWorkPackage($target, 42);

		$this->assertSame('#42', $link->getSubject());
		$this->assertNull($link->getUrl());
		$this->assertSame('alice', $link->getLinkedBy());
	}//end testLinkWithoutInfoUsesTheIdAsSubject()

	/**
	 * A refresh that answers without subject or url gives the id subject and no url.
	 *
	 * @return void
	 */
	public function testRefreshWithoutUrlClearsTheUrl(): void {
		$stale = new OpenProjectLink();
		$stale->setWorkPackageId(9);
		$stale->setRegisterId(3);
		$stale->setObjectUuid('obj-1');
		$stale->setSubject('Old subject');
		$stale->setUrl('https://op.example/wp/9');
		$stale->setCachedAt(new DateTime('@0'));

		$rows = $this->service(connector: true, stored: [$stale], row: ['id' => 9, 'status' => 'Open'])
			->getLinkedWorkPackages('obj-1');

		$this->assertSame('#9', $rows[0]['subject']);
		$this->assertNull($rows[0]['url']);
		$this->assertSame('Open', $rows[0]['status']);
	}//end testRefreshWithoutUrlClearsTheUrl()
}//end class

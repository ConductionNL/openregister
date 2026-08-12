<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\XwikiLinkService}.
 *
 * Exercises the Tier-2 external (OpenConnector-routed) service contract
 * (link / createAndLink / unlink / list + available picker) against a
 * mocked XwikiLinkMapper and a mocked XwikiProvider resolved from the
 * container. The OpenConnector dispatch is mocked via the provider so we
 * assert the service's own contract — including the unconfigured-source
 * (`openconnector-down` / `openconnector-source-missing`) and
 * upstream-down graceful-degradation states (AD-23 / wave-5.1).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/integration-xwiki/tasks.md
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\XwikiLink;
use OCA\OpenRegister\Db\XwikiLinkMapper;
use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\Providers\XwikiProvider;
use OCA\OpenRegister\Service\XwikiLinkService;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * XwikiLinkServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class XwikiLinkServiceTest extends TestCase {

	private XwikiLinkMapper&MockObject $mapper;

	private ContainerInterface&MockObject $container;

	private IAppManager&MockObject $appManager;

	private IUserSession&MockObject $userSession;

	private LoggerInterface&MockObject $logger;

	private XwikiProvider&MockObject $provider;

	private XwikiLinkService $service;

	protected function setUp(): void {
		$this->mapper = $this->getMockBuilder(XwikiLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(
				[
					'findByObjectUuid',
					'findByObjectAndPage',
					'deleteByObjectAndPage',
					'insert',
					'update',
				]
			)
			->getMock();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->provider = $this->createMock(XwikiProvider::class);

		$this->service = new XwikiLinkService(
			$this->mapper,
			$this->container,
			$this->appManager,
			$this->userSession,
			$this->logger
		);
	}//end setUp()

	private function setupUser(string $uid = 'alice'): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		return $user;
	}//end setupUser()

	private function connectorAvailable(bool $available = true): void {
		$this->appManager->method('isInstalled')->with('openconnector')->willReturn($available);
	}//end connectorAvailable()

	private function providerResolves(): void {
		$this->container->method('get')->willReturnCallback(
			function (string $class) {
				if ($class === XwikiProvider::class) {
					return $this->provider;
				}

				throw new \RuntimeException('unexpected container lookup: ' . $class);
			}
		);
	}//end providerResolves()

	public function testIsOpenConnectorAvailableMirrorsAppManager(): void {
		$this->connectorAvailable(true);
		$this->assertTrue($this->service->isOpenConnectorAvailable());
	}//end testIsOpenConnectorAvailableMirrorsAppManager()

	public function testLinkPageThrowsWhenConnectorUnavailable(): void {
		$this->setupUser();
		$this->connectorAvailable(false);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(503);
		$this->service->linkPage('obj-1', 1, 2, 'Space.Page');
	}//end testLinkPageThrowsWhenConnectorUnavailable()

	public function testLinkPageRequiresReference(): void {
		$this->setupUser();

		$this->expectException(Exception::class);
		$this->expectExceptionCode(400);
		$this->service->linkPage('obj-1', 1, 2, '   ');
	}//end testLinkPageRequiresReference()

	public function testLinkPageResolvesCanonicalReferenceAndCachesMetadata(): void {
		$this->setupUser();
		$this->connectorAvailable(true);
		$this->providerResolves();

		$this->provider->method('get')->willReturn(
			[
				'reference' => 'Sales.Pitch',
				'title' => 'Pitch',
				'space' => 'Sales',
				'url' => 'https://wiki/bin/view/Sales/Pitch',
			]
		);

		$this->mapper->method('findByObjectAndPage')->with('obj-1', 'Sales.Pitch')->willReturn(null);
		$this->mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(static fn (XwikiLink $l) => $l);

		$link = $this->service->linkPage('obj-1', 1, 2, 'https://wiki/bin/view/Sales/Pitch');

		$this->assertSame('Sales.Pitch', $link->getPageReference());
		$this->assertSame('Pitch', $link->getTitle());
		$this->assertSame('Sales', $link->getSpace());
		$this->assertSame('https://wiki/bin/view/Sales/Pitch', $link->getUrl());
		$this->assertSame('alice', $link->getLinkedBy());
		$this->assertNotNull($link->getCachedAt());
	}//end testLinkPageResolvesCanonicalReferenceAndCachesMetadata()

	public function testLinkPageRejectsDuplicate(): void {
		$this->setupUser();
		$this->connectorAvailable(true);
		$this->providerResolves();

		$this->provider->method('get')->willReturn(['reference' => 'A.B', 'title' => 'B', 'space' => 'A']);
		$this->mapper->method('findByObjectAndPage')->with('obj-1', 'A.B')->willReturn(new XwikiLink());

		$this->expectException(Exception::class);
		$this->expectExceptionCode(409);
		$this->service->linkPage('obj-1', 1, 2, 'A.B');
	}//end testLinkPageRejectsDuplicate()

	public function testLinkPageSurfacesUnconfiguredSourceAs503WithCause(): void {
		$this->setupUser();
		$this->connectorAvailable(true);
		$this->providerResolves();

		$this->provider->method('get')->willThrowException(
			new ProviderUnavailableException(
				'missing',
				ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING
			)
		);

		try {
			$this->service->linkPage('obj-1', 1, 2, 'A.B');
			$this->fail('Expected a 503 exception');
		} catch (Exception $e) {
			$this->assertSame(503, $e->getCode());
			$this->assertSame('xwiki:openconnector-source-missing', $e->getMessage());
		}
	}//end testLinkPageSurfacesUnconfiguredSourceAs503WithCause()

	public function testCreateAndLinkPageRequiresSpaceAndTitle(): void {
		$this->setupUser();

		$this->expectException(Exception::class);
		$this->expectExceptionCode(400);
		$this->service->createAndLinkPage('obj-1', 1, 2, 'Sales', '   ');
	}//end testCreateAndLinkPageRequiresSpaceAndTitle()

	public function testCreateAndLinkPagePostsViaProviderAndLinks(): void {
		$this->setupUser();
		$this->connectorAvailable(true);
		$this->providerResolves();

		$this->provider->expects($this->once())
			->method('create')
			->with('1', '2', 'obj-1', ['space' => 'Sales', 'title' => 'New Page'])
			->willReturn(
				[
					'reference' => 'Sales.NewPage',
					'title' => 'New Page',
					'space' => 'Sales',
					'url' => 'https://wiki/bin/view/Sales/NewPage',
				]
			);

		$this->mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(static fn (XwikiLink $l) => $l);

		$link = $this->service->createAndLinkPage('obj-1', 1, 2, 'Sales', 'New Page');

		$this->assertSame('Sales.NewPage', $link->getPageReference());
		$this->assertSame('New Page', $link->getTitle());
		$this->assertSame('Sales', $link->getSpace());
	}//end testCreateAndLinkPagePostsViaProviderAndLinks()

	public function testUnlinkPageThrowsWhenNoMatch(): void {
		$this->setupUser();
		$this->mapper->method('deleteByObjectAndPage')->with('obj-1', 'A.B')->willReturn(0);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(404);
		$this->service->unlinkPage('obj-1', 'A.B');
	}//end testUnlinkPageThrowsWhenNoMatch()

	public function testUnlinkPageSucceeds(): void {
		$this->setupUser();
		$this->mapper->method('deleteByObjectAndPage')->with('obj-1', 'A.B')->willReturn(1);

		$this->service->unlinkPage('obj-1', 'A.B');
		$this->addToAssertionCount(1);
	}//end testUnlinkPageSucceeds()

	public function testGetLinkedPagesReturnsCachedRowsWhenConnectorUnavailable(): void {
		$this->connectorAvailable(false);

		$link = new XwikiLink();
		$link->setObjectUuid('obj-1');
		$link->setPageReference('A.B');
		$link->setTitle('B');
		$link->setSpace('A');

		$this->mapper->method('findByObjectUuid')->with('obj-1')->willReturn([$link]);

		$rows = $this->service->getLinkedPages('obj-1');
		$this->assertCount(1, $rows);
		$this->assertSame('A.B', $rows[0]['pageReference']);
		$this->assertSame('B', $rows[0]['title']);
	}//end testGetLinkedPagesReturnsCachedRowsWhenConnectorUnavailable()

	public function testGetAvailablePagesReturnsUnconfiguredWhenConnectorAbsent(): void {
		$this->connectorAvailable(false);

		$result = $this->service->getAvailablePages(null);
		$this->assertTrue($result['unavailable']);
		$this->assertSame(ProviderUnavailableException::CAUSE_OPENCONNECTOR_DOWN, $result['cause']);
		$this->assertSame([], $result['results']);
	}//end testGetAvailablePagesReturnsUnconfiguredWhenConnectorAbsent()

	public function testGetAvailablePagesSurfacesProviderCause(): void {
		$this->connectorAvailable(true);
		$this->providerResolves();

		$this->provider->method('list')->willThrowException(
			new ProviderUnavailableException(
				'auth',
				ProviderUnavailableException::CAUSE_PROVIDER_AUTH
			)
		);

		$result = $this->service->getAvailablePages('foo');
		$this->assertTrue($result['unavailable']);
		$this->assertSame(ProviderUnavailableException::CAUSE_PROVIDER_AUTH, $result['cause']);
	}//end testGetAvailablePagesSurfacesProviderCause()

	public function testGetAvailablePagesNormalisesBrowseRows(): void {
		$this->connectorAvailable(true);
		$this->providerResolves();

		$this->provider->expects($this->once())
			->method('list')
			->with('', '', '', ['_search' => 'hand'])
			->willReturn(
				[
					['reference' => 'Departments.Legal.Handbook', 'title' => 'Handbook', 'space' => 'Departments.Legal', 'url' => 'https://wiki/x'],
				]
			);

		$result = $this->service->getAvailablePages('hand');
		$this->assertArrayNotHasKey('unavailable', $result);
		$this->assertSame(1, $result['total']);
		$this->assertSame('Departments.Legal.Handbook', $result['results'][0]['reference']);
		$this->assertSame('Handbook', $result['results'][0]['title']);
	}//end testGetAvailablePagesNormalisesBrowseRows()

	public function testSearchPagesReturnsUnconfiguredWhenConnectorAbsent(): void {
		$this->connectorAvailable(false);

		$result = $this->service->searchPages('passport', 10, 0);
		$this->assertTrue($result['unavailable']);
		$this->assertSame(ProviderUnavailableException::CAUSE_OPENCONNECTOR_DOWN, $result['cause']);
		$this->assertSame([], $result['results']);
		$this->assertSame(0, $result['total']);
		// Degraded state still carries the resolved pagination envelope.
		$this->assertSame(10, $result['limit']);
		$this->assertSame(0, $result['offset']);
	}//end testSearchPagesReturnsUnconfiguredWhenConnectorAbsent()

	public function testSearchPagesSurfacesProviderCause(): void {
		$this->connectorAvailable(true);
		$this->providerResolves();

		$this->provider->method('list')->willThrowException(
			new ProviderUnavailableException(
				'auth',
				ProviderUnavailableException::CAUSE_PROVIDER_AUTH
			)
		);

		$result = $this->service->searchPages('foo', 25, 0);
		$this->assertTrue($result['unavailable']);
		$this->assertSame(ProviderUnavailableException::CAUSE_PROVIDER_AUTH, $result['cause']);
		$this->assertSame(25, $result['limit']);
	}//end testSearchPagesSurfacesProviderCause()

	public function testSearchPagesReturnsPaginatedEnvelope(): void {
		$this->connectorAvailable(true);
		$this->providerResolves();

		$this->provider->expects($this->once())
			->method('list')
			->with('', '', '', ['_limit' => 10, '_page' => 1, '_search' => 'passport'])
			->willReturn(
				[
					['reference' => 'Kennisbank.Paspoort', 'title' => 'Paspoort', 'space' => 'Kennisbank', 'url' => 'https://wiki/p'],
				]
			);

		$result = $this->service->searchPages('passport', 10, 0);
		$this->assertArrayNotHasKey('unavailable', $result);
		$this->assertSame(1, $result['total']);
		$this->assertSame(10, $result['limit']);
		$this->assertSame(0, $result['offset']);
		$this->assertSame('Kennisbank.Paspoort', $result['results'][0]['reference']);
		$this->assertSame('Paspoort', $result['results'][0]['title']);
	}//end testSearchPagesReturnsPaginatedEnvelope()

	public function testSearchPagesClampsLimitAndOffset(): void {
		$this->connectorAvailable(true);
		$this->providerResolves();

		// Limit clamped 9999 -> 100, offset clamped -5 -> 0, so page = 1.
		$this->provider->expects($this->once())
			->method('list')
			->with('', '', '', ['_limit' => 100, '_page' => 1])
			->willReturn([]);

		$result = $this->service->searchPages(null, 9999, -5);
		$this->assertArrayNotHasKey('unavailable', $result);
		$this->assertSame(100, $result['limit']);
		$this->assertSame(0, $result['offset']);
		$this->assertSame(0, $result['total']);
	}//end testSearchPagesClampsLimitAndOffset()
}//end class

<?php

/**
 * Golden tests pinning {@see \OCA\OpenRegister\Service\XwikiLinkService} paths the
 * contract test leaves open: the stale-cache refresh, the create failure modes, the
 * soft-failing page resolution and the row normalisation fallbacks.
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
 */

declare(strict_types=1);

namespace Unit\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\XwikiLink;
use OCA\OpenRegister\Db\XwikiLinkMapper;
use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\Providers\XwikiProvider;
use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCA\OpenRegister\Service\LinkTarget;
use OCA\OpenRegister\Service\XwikiLinkService;
use OCA\OpenRegister\Service\XwikiPageDraft;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * XwikiLinkServiceGoldenTest.
 *
 * @covers \OCA\OpenRegister\Service\XwikiLinkService
 * @uses   \OCA\OpenRegister\Service\LinkTarget
 */
class XwikiLinkServiceGoldenTest extends TestCase {

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
			->onlyMethods(['findByObjectUuid', 'findByObjectAndPage', 'deleteByObjectAndPage', 'insert', 'update'])
			->getMock();
		$this->container   = $this->createMock(ContainerInterface::class);
		$this->appManager  = $this->createMock(IAppManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger      = $this->createMock(LoggerInterface::class);
		$this->provider    = $this->createMock(XwikiProvider::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->container->method('get')->willReturn($this->provider);
		$this->mapper->method('insert')->willReturnArgument(0);

		$this->service = new XwikiLinkService($this->mapper, $this->container, new LinkServiceRuntime($this->appManager, $this->userSession, $this->logger));
	}//end setUp()

	private function target(): LinkTarget {
		return (new LinkTarget('obj-1', 3))->inSchema(4);
	}//end target()

	private function storedLink(?DateTime $cachedAt): XwikiLink {
		$link = new XwikiLink();
		$link->setObjectUuid('obj-1');
		$link->setRegisterId(3);
		$link->setSchemaId(4);
		$link->setPageReference('Main.Old');
		$link->setTitle('Old');
		$link->setSpace('Main');
		$link->setUrl('https://old');
		$link->setCachedAt($cachedAt);
		return $link;
	}//end storedLink()

	public function testAStaleLinkIsRefreshedFromTheProviderAndSaved(): void {
		$link = $this->storedLink(cachedAt: null);
		$this->mapper->method('findByObjectUuid')->willReturn([$link]);
		$this->provider->expects($this->once())->method('get')
			->with('3', '4', 'obj-1', 'Main.Old')
			->willReturn(['reference' => 'Main.Old', 'title' => 'New', 'space' => 'Docs', 'url' => 'https://new']);
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$rows = $this->service->getLinkedPages('obj-1');

		$this->assertSame('New', $rows[0]['title']);
		$this->assertSame('Docs', $rows[0]['space']);
		$this->assertSame('https://new', $rows[0]['url']);
		$this->assertNotNull($link->getCachedAt());
	}//end testAStaleLinkIsRefreshedFromTheProviderAndSaved()

	public function testARefreshKeepsCachedValuesTheProviderLeavesEmpty(): void {
		$link = $this->storedLink(cachedAt: null);
		$this->mapper->method('findByObjectUuid')->willReturn([$link]);
		$this->provider->method('get')->willReturn(['id' => '', 'title' => '', 'space' => '', 'url' => '']);
		$this->mapper->method('update')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('debug')
			->with('XwikiLinkService::refreshLink update failed: db down');

		$rows = $this->service->getLinkedPages('obj-1');

		$this->assertSame('Old', $rows[0]['title']);
		$this->assertSame('Main', $rows[0]['space']);
		$this->assertSame('https://old', $rows[0]['url']);
	}//end testARefreshKeepsCachedValuesTheProviderLeavesEmpty()

	public function testAFailedRefreshFetchLeavesTheLinkUntouched(): void {
		$link = $this->storedLink(cachedAt: null);
		$this->mapper->method('findByObjectUuid')->willReturn([$link]);
		$this->provider->method('get')->willThrowException(new RuntimeException('gone'));
		$this->mapper->expects($this->never())->method('update');
		$this->logger->expects($this->once())->method('debug')
			->with('XwikiLinkService::refreshLink fetch failed: gone');

		$rows = $this->service->getLinkedPages('obj-1');

		$this->assertSame('Old', $rows[0]['title']);
		$this->assertNull($link->getCachedAt());
	}//end testAFailedRefreshFetchLeavesTheLinkUntouched()

	public function testAFreshLinkIsNotRefreshed(): void {
		$this->mapper->method('findByObjectUuid')->willReturn([$this->storedLink(cachedAt: new DateTime())]);
		$this->provider->expects($this->never())->method('get');

		$rows = $this->service->getLinkedPages('obj-1');

		$this->assertSame('Old', $rows[0]['title']);
	}//end testAFreshLinkIsNotRefreshed()

	public function testAnUnresolvableProviderMakesCreate503(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no provider'));
		$service = new XwikiLinkService($this->mapper, $container, new LinkServiceRuntime($this->appManager, $this->userSession, $this->logger));
		$this->logger->expects($this->once())->method('debug')
			->with('XwikiLinkService: XwikiProvider unavailable: no provider');

		try {
			$service->createAndLinkPage($this->target(), new XwikiPageDraft('Docs', 'Page'));
			$this->fail('expected an exception');
		} catch (Exception $e) {
			$this->assertSame('OpenConnector is not available', $e->getMessage());
			$this->assertSame(503, $e->getCode());
		}
	}//end testAnUnresolvableProviderMakesCreate503()

	public function testAProviderCauseOnCreateBecomesA503WithThatCause(): void {
		$this->provider->method('create')->willThrowException(
			new ProviderUnavailableException('xwiki source missing', ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING)
		);

		try {
			$this->service->createAndLinkPage($this->target(), new XwikiPageDraft('Docs', 'Page'));
			$this->fail('expected an exception');
		} catch (Exception $e) {
			$this->assertSame('xwiki:' . ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING, $e->getMessage());
			$this->assertSame(503, $e->getCode());
		}
	}//end testAProviderCauseOnCreateBecomesA503WithThatCause()

	public function testAnyOtherCreateFailureIsLoggedAndBecomesA500(): void {
		$this->provider->method('create')->willThrowException(new RuntimeException('boom'));
		$this->logger->expects($this->once())->method('warning')
			->with('XwikiLinkService::createAndLinkPage failed: boom');

		try {
			$this->service->createAndLinkPage($this->target(), new XwikiPageDraft('Docs', 'Page'));
			$this->fail('expected an exception');
		} catch (Exception $e) {
			$this->assertSame('Failed to create xWiki page', $e->getMessage());
			$this->assertSame(500, $e->getCode());
		}
	}//end testAnyOtherCreateFailureIsLoggedAndBecomesA500()

	public function testCreateFallsBackToTheGivenSpaceTitleAndReference(): void {
		$this->provider->expects($this->once())->method('create')
			->with('3', '4', 'obj-1', ['space' => 'Docs', 'title' => 'Page'])
			->willReturn(['url' => '']);

		$link = $this->service->createAndLinkPage($this->target(), new XwikiPageDraft('  Docs ', ' Page '));

		$this->assertSame('Docs.Page', $link->getPageReference());
		$this->assertSame('Page', $link->getTitle());
		$this->assertSame('Docs', $link->getSpace());
		$this->assertNull($link->getUrl());
		$this->assertSame('alice', $link->getLinkedBy());
	}//end testCreateFallsBackToTheGivenSpaceTitleAndReference()

	public function testAnEmptyTitleIsReportedBeforeAnEmptySpace(): void {
		$this->expectExceptionMessage('Page title is required');
		$this->expectExceptionCode(400);
		$this->service->createAndLinkPage($this->target(), new XwikiPageDraft('', ''));
	}//end testAnEmptyTitleIsReportedBeforeAnEmptySpace()

	public function testASoftResolveFailureKeepsTheGivenReference(): void {
		$this->provider->method('get')->willThrowException(new RuntimeException('flaky'));
		$this->mapper->method('findByObjectAndPage')->willReturn(null);
		$this->logger->expects($this->once())->method('debug')
			->with('XwikiLinkService::resolveRemotePage soft-failed: flaky');

		$link = $this->service->linkPage($this->target(), ' Main.Page ');

		$this->assertSame('Main.Page', $link->getPageReference());
		$this->assertSame('Main.Page', $link->getTitle());
		$this->assertSame('', $link->getSpace());
		$this->assertNull($link->getUrl());
	}//end testASoftResolveFailureKeepsTheGivenReference()

	public function testAnEmptyResolvedReferenceFallsBackToTheGivenOne(): void {
		$this->provider->method('get')->willReturn(['title' => 'T']);
		$this->mapper->expects($this->once())->method('findByObjectAndPage')->with('obj-1', 'Main.Page')->willReturn(null);

		$link = $this->service->linkPage($this->target(), 'Main.Page');

		$this->assertSame('Main.Page', $link->getPageReference());
		$this->assertSame('T', $link->getTitle());
	}//end testAnEmptyResolvedReferenceFallsBackToTheGivenOne()

	public function testBrowseRowsFallBackToIdForReferenceAndTitleAndSkipNonArrays(): void {
		$this->provider->expects($this->once())->method('list')->with('', '', '', ['_search' => 'x'])
			->willReturn([['id' => 'A.B', 'url' => 7], 'junk']);

		$result = $this->service->getAvailablePages('x');

		$this->assertSame(
			['results' => [['reference' => 'A.B', 'title' => 'A.B', 'space' => '', 'url' => '7']], 'total' => 1],
			$result
		);
	}//end testBrowseRowsFallBackToIdForReferenceAndTitleAndSkipNonArrays()

	public function testAnUpstreamFailureDegradesTheBrowseAndSearch(): void {
		$this->provider->method('list')->willThrowException(new RuntimeException('down'));
		$this->logger->expects($this->exactly(2))->method('warning');

		$pages  = $this->service->getAvailablePages(null);
		$search = $this->service->searchPages(new ListQuery(limit: 10, offset: 25, search: '  q '));

		$this->assertSame(ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN, $pages['cause']);
		$this->assertSame(
			['unavailable' => true, 'cause' => ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN, 'results' => [], 'total' => 0, 'limit' => 10, 'offset' => 25],
			$search
		);
	}//end testAnUpstreamFailureDegradesTheBrowseAndSearch()

	public function testSearchSendsTrimmedQueryAndPage(): void {
		$this->provider->expects($this->once())->method('list')
			->with('', '', '', ['_limit' => 10, '_page' => 3, '_search' => 'q'])
			->willReturn([]);

		$this->assertSame(['results' => [], 'total' => 0, 'limit' => 10, 'offset' => 25], $this->service->searchPages(new ListQuery(limit: 10, offset: 25, search: '  q ')));
	}//end testSearchSendsTrimmedQueryAndPage()
}//end class

<?php

/**
 * SourcesController: writes through SourceService (422 naming the field) and "sync now" on a flow source.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\SourcesController;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Controller\Support\SourcesControllerServices;
use OCA\OpenRegister\Controller\Support\SourcesDomain;
use OCA\OpenRegister\Controller\Support\SourcesMappers;
use OCA\OpenRegister\Controller\Support\SourcesPlatform;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapperInterface;
use OCA\OpenRegister\Service\Dbal\DatabaseIntrospectionServiceInterface;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactoryInterface;
use OCA\OpenRegister\Service\Flow\FlowServiceInterface;
use OCA\OpenRegister\Service\Sync\HarvestPipelineServiceInterface;
use OCA\OpenRegister\Service\Sync\SourceFetcherLookupInterface;
use OCA\OpenRegister\Service\Sync\SourceRefused;
use OCA\OpenRegister\Service\Sync\SourceService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Controller\SourcesController
 * @uses   \OCA\OpenRegister\Controller\Support\ControllerSession
 * @uses   \OCA\OpenRegister\Controller\Support\SourcePayload
 * @uses   \OCA\OpenRegister\Controller\Support\SourcesControllerServices
 * @uses   \OCA\OpenRegister\Controller\Support\SourcesDomain
 * @uses   \OCA\OpenRegister\Controller\Support\SourcesMappers
 * @uses   \OCA\OpenRegister\Controller\Support\SourcesPlatform
 * @uses   \OCA\OpenRegister\Db\Source
 * @uses   \OCA\OpenRegister\Db\Source\SourceJson
 * @uses   \OCA\OpenRegister\Db\FlowRun
 * @uses   \OCA\OpenRegister\Service\Sync\SourceRefused
 */
class SourcesControllerHarvestFlowTest extends TestCase {

	/** @var IRequest&MockObject */
	private IRequest $request;

	/** @var SourceMapperInterface&MockObject */
	private SourceMapperInterface $mapper;

	/** @var SourceService&MockObject */
	private SourceService $service;

	/** @var FlowServiceInterface&MockObject */
	private FlowServiceInterface $flows;

	/** @var HarvestPipelineServiceInterface&MockObject */
	private HarvestPipelineServiceInterface $pipeline;

	private SourcesController $controller;

	protected function setUp(): void {
		$this->request  = $this->createMock(IRequest::class);
		$this->mapper   = $this->createMock(SourceMapperInterface::class);
		$this->service  = $this->getMockBuilder(SourceService::class)->disableOriginalConstructor()->getMock();
		$this->flows    = $this->createMock(FlowServiceInterface::class);
		$this->pipeline = $this->createMock(HarvestPipelineServiceInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new SourcesController(
			$this->request,
			new SourcesControllerServices(
				session: new ControllerSession(userSession: $userSession, groupManager: $groups, logger: new NullLogger()),
				mappers: new SourcesMappers(sourceMapper: $this->mapper),
				domain: new SourcesDomain(
					fetcherRegistry: $this->createMock(SourceFetcherLookupInterface::class),
					pipeline: $this->pipeline,
					connectionFactory: $this->createMock(DbalConnectionFactoryInterface::class),
					introspectionService: $this->createMock(DatabaseIntrospectionServiceInterface::class),
					sourceService: $this->service,
					flows: $this->flows,
				),
				platform: new SourcesPlatform(
					config: $this->createMock(IAppConfig::class),
					l10n: $l10n,
					crypto: $this->createMock(ICrypto::class)
				),
			)
		);
	}

	public function testCreateGoesThroughTheServiceAndARefusalIs422NamingTheField(): void {
		$this->request->method('getParams')->willReturn(['title' => 'Feed', 'schedule' => '0 * * * *', '_route' => 'x']);
		$this->service->expects($this->once())->method('create')
			->with(['title' => 'Feed', 'schedule' => '0 * * * *'])
			->willThrowException(new SourceRefused(field: 'runAs', message: 'needs runAs'));
		$this->mapper->expects($this->never())->method('createFromArray');

		$response = $this->controller->create();

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['error' => 'needs runAs', 'field' => 'runAs'], $response->getData());
	}

	public function testUpdateGoesThroughTheService(): void {
		$this->request->method('getParams')->willReturn(['title' => 'Renamed', 'id' => 4]);
		$stored = new Source();
		$stored->setTitle('Renamed');
		$this->service->expects($this->once())->method('update')->with(4, ['title' => 'Renamed'])->willReturn($stored);
		$this->mapper->expects($this->never())->method('updateFromArray');

		$response = $this->controller->update(4);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('Renamed', $response->getData()['title']);
	}

	public function testDestroyGoesThroughTheServiceSoTheFlowGoesToo(): void {
		$this->service->expects($this->once())->method('delete')->with(4)->willReturn(new Source());
		$this->mapper->expects($this->never())->method('delete');

		$this->assertSame([], $this->controller->destroy(4)->getData());
	}

	public function testSyncNowOnAFlowSourceStartsTheManualTriggerAndReturnsTheRunId(): void {
		$source = new Source();
		$source->setFlowId('src-1');
		$this->mapper->method('find')->with(4)->willReturn($source);
		$run = new FlowRun();
		$run->setUuid('run-9');
		$this->flows->expects($this->once())->method('run')
			->with('src-1', [], [], false, Flow::TRIGGER_MANUAL)
			->willReturn($run);
		$this->pipeline->expects($this->never())->method('run');

		$response = $this->controller->syncNow(4);

		$this->assertSame(202, $response->getStatus());
		$this->assertSame(['runId' => 'run-9', 'flowId' => 'src-1'], $response->getData());
	}

	public function testSyncNowOnAFlowThatIsGoneIs404(): void {
		$source = new Source();
		$source->setFlowId('src-1');
		$this->mapper->method('find')->willReturn($source);
		$this->flows->method('run')->willThrowException(new DoesNotExistException('gone'));

		$this->assertSame(404, $this->controller->syncNow(4)->getStatus());
	}
}

<?php

/**
 * Golden test pinning the access outcomes of AgentsController.
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
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\AgentsController;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Mcp\Service\ToolRegistry;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\AgentsControllerFactory;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins allowed and refused outcomes (status, body, mapper calls) of every
 * AgentsController action so a refactor cannot change who may do what.
 */
class AgentsControllerAccessGoldenTest extends TestCase {

	private IRequest&MockObject $request;

	private AgentMapper&MockObject $agentMapper;

	private OrganisationService&MockObject $organisationService;

	private ToolRegistry&MockObject $toolRegistry;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->request             = $this->createMock(IRequest::class);
		$this->agentMapper         = $this->createMock(AgentMapper::class);
		$this->organisationService = $this->createMock(OrganisationService::class);
		$this->toolRegistry        = $this->createMock(ToolRegistry::class);
		$this->logger              = $this->createMock(LoggerInterface::class);
	}

	private function controller(?string $userId): AgentsController {
		return AgentsControllerFactory::create(
			$this->request,
			$this->agentMapper,
			$this->organisationService,
			$this->toolRegistry,
			$this->logger,
			$userId
		);
	}

	private function organisation(string $uuid): Organisation {
		$org = new Organisation();
		$org->setUuid($uuid);
		return $org;
	}

	private function agent(string $org, string $owner): Agent {
		$agent = new Agent();
		$agent->setId(7);
		$agent->setOrganisation($org);
		$agent->setOwner($owner);
		return $agent;
	}

	public function testIndexScopesToActiveOrganisationAndUser(): void {
		$this->organisationService->method('getActiveOrganisation')->willReturn($this->organisation('org-a'));
		$this->request->method('getParams')->willReturn(['_limit' => '5', '_page' => '3', '_offset' => '99']);
		$this->agentMapper->expects($this->never())->method('findAll');
		$this->agentMapper->expects($this->once())->method('findByOrganisation')
			->with('org-a', 'alice', 5, 10)
			->willReturn(['a']);

		$response = $this->controller('alice')->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['results' => ['a']], $response->getData());
	}

	public function testIndexWithoutOrganisationListsAll(): void {
		$this->organisationService->method('getActiveOrganisation')->willReturn(null);
		$this->request->method('getParams')->willReturn(['_offset' => '4']);
		$this->agentMapper->expects($this->never())->method('findByOrganisation');
		$this->agentMapper->expects($this->once())->method('findAll')->with(50, 4)->willReturn([]);

		$response = $this->controller('alice')->index();

		$this->assertSame(['results' => []], $response->getData());
	}

	public function testIndexFailure(): void {
		$this->organisationService->method('getActiveOrganisation')->willThrowException(new Exception('x'));
		$this->logger->expects($this->once())->method('error')
			->with('[AgentsController] Failed to get agents', $this->callback(
				fn (array $c): bool => $c['error'] === 'x' && isset($c['trace'], $c['file'], $c['line'])
			));

		$response = $this->controller('alice')->index();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Failed to retrieve agents'], $response->getData());
	}

	public function testShowAllowedAndRefused(): void {
		$agent = $this->agent('org-a', 'bob');
		$this->agentMapper->method('find')->willReturn($agent);
		$this->agentMapper->method('canUserAccessAgent')
			->willReturnCallback(fn (Agent $a, string $u): bool => $u === 'bob');

		$allowed = $this->controller('bob')->show(7);
		$refused = $this->controller('alice')->show(7);
		$anonymous = $this->controller(null)->show(7);

		$this->assertSame(200, $allowed->getStatus());
		$this->assertSame($agent, $allowed->getData());
		$this->assertSame(403, $refused->getStatus());
		$this->assertSame(['error' => 'Access denied to this agent'], $refused->getData());
		$this->assertSame(403, $anonymous->getStatus());
	}

	public function testShowMissing(): void {
		$this->agentMapper->method('find')->willThrowException(new Exception('gone'));

		$response = $this->controller('bob')->show(7);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Agent not found'], $response->getData());
	}

	public function testCreateForcesOrganisationOwnerAndDefaults(): void {
		$this->organisationService->method('getActiveOrganisation')->willReturn($this->organisation('org-a'));
		$this->request->method('getParams')->willReturn(
			['_route' => 'r', 'name' => 'n', 'organisation' => 'evil', 'owner' => 'evil', 'search_files' => false]
		);
		$created = $this->agent('org-a', 'alice');
		$this->agentMapper->expects($this->once())->method('createFromArray')
			->with([
				'name' => 'n',
				'organisation' => 'org-a',
				'owner' => 'alice',
				'search_files' => false,
				'isPrivate' => true,
				'searchObjects' => true,
			])
			->willReturn($created);

		$response = $this->controller('alice')->create();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame($created, $response->getData());
	}

	public function testCreateKeepsExplicitFlags(): void {
		$this->organisationService->method('getActiveOrganisation')->willReturn(null);
		$this->request->method('getParams')->willReturn(
			['isPrivate' => false, 'searchFiles' => false, 'search_objects' => false]
		);
		$this->agentMapper->expects($this->once())->method('createFromArray')
			->with([
				'isPrivate' => false,
				'searchFiles' => false,
				'search_objects' => false,
				'organisation' => null,
				'owner' => null,
			])
			->willReturn(new Agent());

		$this->assertSame(201, $this->controller(null)->create()->getStatus());
	}

	public function testCreateFailure(): void {
		$this->organisationService->method('getActiveOrganisation')->willReturn(null);
		$this->request->method('getParams')->willReturn([]);
		$this->agentMapper->method('createFromArray')->willThrowException(new Exception('bad'));

		$response = $this->controller('alice')->create();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Failed to create agent: bad'], $response->getData());
	}

	public function testUpdateRefusedDoesNotWrite(): void {
		$this->agentMapper->method('find')->willReturn($this->agent('org-a', 'bob'));
		$this->agentMapper->method('canUserModifyAgent')->willReturn(false);
		$this->agentMapper->expects($this->never())->method('update');

		$response = $this->controller('alice')->update(7);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'You do not have permission to modify this agent'], $response->getData());
	}

	public function testUpdateAllowedPreservesOrganisationAndOwner(): void {
		$agent = $this->agent('org-a', 'bob');
		$this->agentMapper->method('find')->willReturn($agent);
		$this->agentMapper->method('canUserModifyAgent')
			->willReturnCallback(fn (Agent $a, string $u): bool => $u === 'bob');
		$this->request->method('getParams')->willReturn(
			['name' => 'new', 'organisation' => 'evil', 'owner' => 'evil', 'id' => 99, '_route' => 'x']
		);
		$this->agentMapper->expects($this->once())->method('update')
			->with($this->callback(
				fn (Agent $a): bool => $a->getName() === 'new' && $a->getOrganisation() === 'org-a'
					&& $a->getOwner() === 'bob' && $a->getId() === 7
			))
			->willReturnArgument(0);

		$response = $this->controller('bob')->update(7);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($agent, $response->getData());
	}

	public function testUpdateAnonymousAskedAsEmptyUser(): void {
		$this->agentMapper->method('find')->willReturn($this->agent('org-a', 'bob'));
		$this->agentMapper->expects($this->once())->method('canUserModifyAgent')
			->with($this->anything(), '')->willReturn(false);

		$this->assertSame(403, $this->controller(null)->patch(7)->getStatus());
	}

	public function testUpdateFailure(): void {
		$this->agentMapper->method('find')->willThrowException(new Exception('nope'));

		$response = $this->controller('bob')->update(7);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Failed to update agent: nope'], $response->getData());
	}

	public function testDestroyRefusals(): void {
		$this->agentMapper->method('find')->willReturn($this->agent('org-a', 'bob'));
		$this->agentMapper->method('canUserModifyAgent')
			->willReturnCallback(fn (Agent $a, string $u): bool => $u === 'bob');
		$this->agentMapper->expects($this->never())->method('delete');

		$anonymous = $this->controller(null)->destroy(7);
		$other = $this->controller('alice')->destroy(7);

		$this->assertSame(403, $anonymous->getStatus());
		$this->assertSame(['error' => 'User not authenticated'], $anonymous->getData());
		$this->assertSame(403, $other->getStatus());
		$this->assertSame(['error' => 'You do not have permission to delete this agent'], $other->getData());
	}

	public function testDestroyAllowed(): void {
		$agent = $this->agent('org-a', 'bob');
		$this->agentMapper->method('find')->willReturn($agent);
		$this->agentMapper->method('canUserModifyAgent')->willReturn(true);
		$this->agentMapper->expects($this->once())->method('delete')->with($agent);

		$response = $this->controller('bob')->destroy(7);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['message' => 'Agent deleted successfully'], $response->getData());
	}

	public function testDestroyFailure(): void {
		$this->agentMapper->method('find')->willThrowException(new Exception('x'));

		$response = $this->controller('bob')->destroy(7);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Failed to delete agent'], $response->getData());
	}

	public function testStatsAndTools(): void {
		$this->agentMapper->method('count')->willReturnMap([[[], 3], [['active' => true], 2], [['active' => false], 1]]);
		$this->toolRegistry->method('getAllTools')->willReturn(['t' => 1]);

		$stats = $this->controller('bob')->stats();
		$tools = $this->controller('bob')->tools();

		$this->assertSame(['total' => 3, 'active' => 2, 'inactive' => 1], $stats->getData());
		$this->assertSame(['results' => ['t' => 1]], $tools->getData());
	}

	public function testStatsAndToolsFailures(): void {
		$this->agentMapper->method('count')->willThrowException(new Exception('x'));
		$this->toolRegistry->method('getAllTools')->willThrowException(new Exception('y'));

		$stats = $this->controller('bob')->stats();
		$tools = $this->controller('bob')->tools();

		$this->assertSame(500, $stats->getStatus());
		$this->assertSame(['error' => 'Failed to retrieve statistics'], $stats->getData());
		$this->assertSame(500, $tools->getStatus());
		$this->assertSame(['error' => 'Failed to retrieve tools'], $tools->getData());
	}
}

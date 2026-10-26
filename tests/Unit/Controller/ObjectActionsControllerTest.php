<?php

/**
 * Unit tests for the object-actions endpoints: list, selection list, run, run on a selection.
 *
 * The refusal order and the three bodies are pinned in ObjectActionRunnerTest;
 * these pin what the controller itself decides: which object, which inputs,
 * and which actions a selection may run.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectActionsController;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Flow\MacroActionResolver;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionBulk;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinitions;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionHistory;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionList;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunner;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ObjectActionsControllerTest extends TestCase {

	private ObjectService&MockObject $objects;

	private MacroActionResolver&MockObject $macros;

	private ObjectActionList&MockObject $list;

	private ObjectActionRunner&MockObject $runner;

	private ObjectActionHistory&MockObject $history;

	private ObjectActionBulk&MockObject $bulk;

	private IRequest&MockObject $request;

	private LoggerInterface&MockObject $logger;

	private ObjectActionsController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->objects = $this->createMock(ObjectService::class);
		$this->macros = $this->createMock(MacroActionResolver::class);
		$this->list = $this->createMock(ObjectActionList::class);
		$this->runner = $this->createMock(ObjectActionRunner::class);
		$this->history = $this->createMock(ObjectActionHistory::class);
		$this->bulk = $this->createMock(ObjectActionBulk::class);
		$this->request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new ObjectActionsController(
			'openregister',
			$this->request,
			$this->objects,
			$this->macros,
			new ObjectActionDefinitions($this->createMock(RegisterMapper::class)),
			$this->list,
			$this->runner,
			$this->history,
			$this->bulk,
			$this->logger
		);
	}//end setUp()

	/**
	 * A schema with `assign-to-me` on bulk and `publish` not on bulk.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setSlug('ticket');
		$schema->setConfiguration(
			[
				'x-openregister-action' => [
					'assign-to-me' => [
						'name' => 'Assign to me',
						'description' => 'Take it.',
						'surfaces' => ['row', 'bulk'],
						'steps' => [['type' => 'openregister.set-fields', 'config' => ['set' => ['assignee' => 'x']]]],
					],
					'publish' => ['name' => 'Publish', 'description' => 'x', 'steps' => [['type' => 'openregister.set-fields']]],
				],
			]
		);
		return $schema;
	}//end schema()

	/**
	 * An object.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid = 'obj-1'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setSchema('5');
		return $object;
	}//end object()

	/**
	 * Request parameters.
	 *
	 * @param array $params The parameters.
	 *
	 * @return void
	 */
	private function params(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);
	}//end params()

	public function testTheListOfAMissingObjectIs404(): void {
		$this->objects->method('find')->willReturn(null);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->index('r', 'ticket', 'gone')->getStatus());
	}//end testTheListOfAMissingObjectIs404()

	public function testTheListWithoutReadIs403(): void {
		$this->objects->method('find')->willReturn($this->object());
		$this->macros->method('loadSchema')->willReturn($this->schema());
		$this->list->method('forObject')->willThrowException(new NotAuthorizedException('no'));

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->index('r', 'ticket', 'obj-1')->getStatus());
	}//end testTheListWithoutReadIs403()

	public function testTheListAnswersTheActions(): void {
		$this->objects->method('find')->willReturn($this->object());
		$this->macros->method('loadSchema')->willReturn($this->schema());
		$this->list->method('forObject')->willReturn([['key' => 'publish']]);

		$response = $this->controller->index('r', 'ticket', 'obj-1');

		$this->assertSame(['actions' => [['key' => 'publish']]], $response->getData());
	}//end testTheListAnswersTheActions()

	public function testInputsReachTheRunnerWithTheSubjectAsNamed(): void {
		$this->params(['inputs' => ['from' => '2026-11-01']]);
		$this->objects->method('find')->willReturn($this->object());
		$this->macros->method('loadSchema')->willReturn($this->schema());
		$this->runner->expects($this->once())->method('run')
			->with($this->anything(), $this->anything(), 'publish', ['from' => '2026-11-01'], ['uuid' => 'obj-1', 'register' => 'r', 'schema' => 'ticket'])
			->willReturn(['status' => Http::STATUS_ACCEPTED, 'body' => ['status' => 'queued']]);

		$response = $this->controller->invoke('r', 'ticket', 'obj-1', 'publish');

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
	}//end testInputsReachTheRunnerWithTheSubjectAsNamed()

	public function testInputsThatAreNotAnObjectAre400(): void {
		$this->params(['inputs' => 'from=today']);
		$this->runner->expects($this->never())->method('run');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->invoke('r', 'ticket', 'obj-1', 'publish')->getStatus());
	}//end testInputsThatAreNotAnObjectAre400()

	public function testAnActionNotOfferedOnBulkIsRefusedForASelection(): void {
		$this->params(['ids' => ['a', 'b']]);
		$this->macros->method('loadSchema')->willReturn($this->schema());
		$this->runner->expects($this->never())->method('run');

		$response = $this->controller->invokeOnSelection('r', 'ticket', 'publish');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('"publish"', $response->getData()['error']);
	}//end testAnActionNotOfferedOnBulkIsRefusedForASelection()

	public function testASchemaWhoseDeclarationsCannotBeReadIsNotOfferedOnASelection(): void {
		$this->params(['ids' => ['a']]);
		$broken = $this->createMock(Schema::class);
		$broken->method('getConfiguration')->willThrowException(new \RuntimeException('unreadable configuration'));
		$this->macros->method('loadSchema')->willReturn($broken);
		$this->runner->expects($this->never())->method('run');
		$this->logger->expects($this->once())->method('warning')->with(
			$this->anything(),
			$this->callback(static fn (array $context): bool => ($context['message'] ?? null) === 'unreadable configuration')
		);

		$response = $this->controller->invokeOnSelection('r', 'ticket', 'assign-to-me');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testASchemaWhoseDeclarationsCannotBeReadIsNotOfferedOnASelection()

	public function testASelectionRunsEachObjectThroughTheSinglePath(): void {
		$this->params(['ids' => ['a', 'b', 'a'], 'inputs' => []]);
		$this->macros->method('loadSchema')->willReturn($this->schema());
		$this->objects->method('find')->willReturnCallback(fn (string $id) => $this->object($id));
		$this->runner->expects($this->exactly(2))->method('run')->willReturnOnConsecutiveCalls(
			['status' => Http::STATUS_OK, 'body' => ['run' => 'r1', 'outcome' => 'completed']],
			['status' => Http::STATUS_FORBIDDEN, 'body' => ['error' => 'You may not perform "assign-to-me" on this object.']]
		);

		$data = $this->controller->invokeOnSelection('r', 'ticket', 'assign-to-me')->getData();

		$this->assertSame(['succeeded' => 1, 'failed' => 1], $data['summary']);
		$this->assertSame('list', $data['next']);
	}//end testASelectionRunsEachObjectThroughTheSinglePath()

	public function testTheHistoryAnswersTheRunsAndNeedsRead(): void {
		$this->objects->method('find')->willReturn($this->object());
		$this->macros->method('loadSchema')->willReturn($this->schema());
		$this->history->method('forObject')->willReturnOnConsecutiveCalls(
			[['action' => 'hold']],
			$this->throwException(new NotAuthorizedException('no'))
		);

		$this->assertSame(['results' => [['action' => 'hold']]], $this->controller->history('r', 'ticket', 'obj-1')->getData());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->history('r', 'ticket', 'obj-1')->getStatus());
	}//end testTheHistoryAnswersTheRunsAndNeedsRead()

	public function testASelectionOf250BecomesAPreviewedJob(): void {
		$ids = array_map(static fn (int $n): string => 't-' . $n, range(1, 250));
		$this->params(['ids' => $ids, 'inputs' => []]);
		$this->macros->method('loadSchema')->willReturn($this->schema());
		$job = new BulkJob();
		$job->setId(42);
		$job->setTotal(250);
		$this->bulk->expects($this->once())->method('start')
			->with($this->anything(), 'r', 'ticket', 'assign-to-me', $ids, [])
			->willReturn($job);
		$this->runner->expects($this->never())->method('run');

		$response = $this->controller->invokeOnSelection('r', 'ticket', 'assign-to-me');

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame(250, $response->getData()['job']['total']);
		$this->assertSame('/api/bulk-jobs/42/commit', $response->getData()['links']['commit']);
	}//end testASelectionOf250BecomesAPreviewedJob()

	public function testTheSelectionListComesFromTheSchemaNamed(): void {
		$this->macros->method('loadSchema')->with('ticket')->willReturn($this->schema());
		$this->list->expects($this->once())->method('forSelection')->with($this->anything(), 'r')->willReturn([['key' => 'assign-to-me']]);

		$this->assertSame(['actions' => [['key' => 'assign-to-me']]], $this->controller->selection('r', 'ticket')->getData());
	}//end testTheSelectionListComesFromTheSchemaNamed()
}//end class

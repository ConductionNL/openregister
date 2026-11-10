<?php

/**
 * Pins how the run-an-action bulk action finds the member's schema.
 *
 * The lookup reads the schema without RBAC and without multitenancy (the
 * declared action's own checks decide who may run it), and a schema that
 * cannot be read refuses the member as `no-schema`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BulkAction;

use Psr\Log\NullLogger;
use OCA\OpenRegister\BulkAction\BulkObjectAccess;
use OCA\OpenRegister\BulkAction\RunActionAction;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunnerInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\AppFramework\Http;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RunActionActionSchemaLookupTest extends TestCase {

	private ObjectActionRunnerInterface&MockObject $runner;

	private SchemaMapperInterface&MockObject $schemas;

	protected function setUp(): void {
		parent::setUp();
		$this->runner = $this->createMock(ObjectActionRunnerInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
	}//end setUp()

	/**
	 * The action under test, over the doubles.
	 *
	 * @return RunActionAction
	 */
	private function action(): RunActionAction {
		return new RunActionAction(
			$this->runner,
			new BulkObjectAccess($this->createMock(ObjectOperationsInterface::class), $this->schemas, new NullLogger())
		);
	}//end action()

	/**
	 * A member on schema 7.
	 *
	 * @return ObjectEntity
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('t-1');
		$object->setRegister('3');
		$object->setSchema('7');
		return $object;
	}//end object()

	public function testTheSchemaIsReadWithoutRbacOrMultitenancy(): void {
		$seen = [];
		$this->schemas->expects($this->once())->method('find')->willReturnCallback(
			function (string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true) use (&$seen): Schema {
				$seen = ['id' => $id, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return new Schema();
			}
		);
		$this->runner->method('rehearse')->willReturn([]);

		$result = $this->action()->apply($this->object(), ['action' => 'close'], false);

		$this->assertSame(['id' => '7', 'rbac' => false, 'multitenancy' => false], $seen);
		$this->assertSame(BulkJobMember::OUTCOME_APPLIED, $result->getOutcome());
	}//end testTheSchemaIsReadWithoutRbacOrMultitenancy()

	public function testAnUnreadableSchemaRefusesTheMember(): void {
		$this->schemas->method('find')->willThrowException(new RuntimeException('gone'));
		$this->runner->expects($this->never())->method('rehearse');
		$this->runner->expects($this->never())->method('run');

		$result = $this->action()->apply($this->object(), ['action' => 'close'], true);

		$this->assertSame(BulkJobMember::OUTCOME_REFUSED, $result->getOutcome());
		$this->assertSame('no-schema', $result->getReason());
	}//end testAnUnreadableSchemaRefusesTheMember()

	public function testACommitWithoutAnActorRunsDirectlyAndPassesTheCallersRefs(): void {
		$this->schemas->method('find')->willReturn(new Schema());
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->never())->method('runAs');
		$seen = [];
		$this->runner->expects($this->once())->method('run')
			->with(
				$this->callback(static function (\OCA\OpenRegister\Service\ObjectAction\ObjectActionTarget $target) use (&$seen): bool {
					$seen = [$target->subject()['register'], $target->subject()['schema']];
					return true;
				}),
				new \OCA\OpenRegister\Service\ObjectAction\ObjectActionRequest('close', ['note' => 'x'])
			)
			->willReturn(['status' => Http::STATUS_CONFLICT, 'body' => ['message' => 'already closed']]);
		$action = new RunActionAction($this->runner, new BulkObjectAccess($objects, $this->schemas, new NullLogger()));

		$result = $action->apply($this->object(), ['action' => 'close', 'inputs' => ['note' => 'x'], 'register' => 'zaken'], true);

		$this->assertSame(['zaken', '7'], $seen);
		$this->assertSame(BulkJobMember::OUTCOME_SKIPPED, $result->getOutcome());
		$this->assertSame('already closed', $result->getReason());
	}//end testACommitWithoutAnActorRunsDirectlyAndPassesTheCallersRefs()

	public function testAReadableSchemaIsNotRefused(): void {
		$this->schemas->method('find')->willReturn(new Schema());
		$this->runner->method('rehearse')->willReturn(['refusal' => ['status' => Http::STATUS_FORBIDDEN, 'body' => ['message' => 'nope']]]);

		$result = $this->action()->apply($this->object(), ['action' => 'close'], false);

		$this->assertSame(BulkJobMember::OUTCOME_REFUSED, $result->getOutcome());
		$this->assertSame('nope', $result->getReason());
	}//end testAReadableSchemaIsNotRefused()
}//end class

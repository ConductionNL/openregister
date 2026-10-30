<?php

/**
 * Unit tests for the schema-save validation of declared actions.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Flow\MacroActionValidator;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionStepsFlow;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionValidator;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ObjectActionValidatorTest extends TestCase {

	private MacroActionValidator&MockObject $macros;

	private IUserSession&MockObject $session;

	private IGroupManager&MockObject $groups;

	protected function setUp(): void {
		parent::setUp();

		$this->macros = $this->createMock(MacroActionValidator::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->groups = $this->createMock(IGroupManager::class);
	}//end setUp()

	/**
	 * A schema whose `publish` elevates, granted to `redactie`.
	 *
	 * @return Schema
	 */
	private function elevatingSchema(): Schema {
		$schema = new Schema();
		$schema->setConfiguration(
			[
				'x-openregister-action' => [
					'publish' => [
						'name' => 'Publish',
						'description' => 'Publish it.',
						'steps' => [['type' => 'openregister.set-fields']],
						'elevate' => [['register' => 'pub', 'schema' => 'publication', 'verbs' => ['create']]],
					],
				],
			]
		);
		$schema->setAuthorization(['publish' => [['group' => 'redactie']]]);
		return $schema;
	}//end elevatingSchema()

	/**
	 * Sign a user in.
	 *
	 * @param bool $admin Whether they are an administrator.
	 *
	 * @return void
	 */
	private function signIn(bool $admin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$this->session->method('getUser')->willReturn($user);
		$this->groups->method('isAdmin')->with('anna')->willReturn($admin);
	}//end signIn()

	/**
	 * The validator under test.
	 *
	 * @return ObjectActionValidator
	 */
	private function validator(): ObjectActionValidator {
		return new ObjectActionValidator($this->macros, $this->session, $this->groups);
	}//end validator()

	public function testANonAdministratorMayNotDeclareElevate(): void {
		$this->signIn(admin: false);
		$this->macros->expects($this->never())->method('refusals');

		$refusals = $this->validator()->refusals($this->elevatingSchema());

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('only an administrator', $refusals[0]);
	}//end testANonAdministratorMayNotDeclareElevate()

	public function testAnAdministratorMayDeclareElevate(): void {
		$this->signIn(admin: true);
		$this->macros->method('refusals')->willReturn([]);

		$this->assertSame([], $this->validator()->refusals($this->elevatingSchema()));
	}//end testAnAdministratorMayDeclareElevate()

	public function testAnImportWithoutASessionMayDeclareElevate(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->macros->method('refusals')->willReturn([]);

		$this->assertSame([], $this->validator()->refusals($this->elevatingSchema()));
	}//end testAnImportWithoutASessionMayDeclareElevate()

	public function testAShapeRefusalStopsBeforeTheFlowLookup(): void {
		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-action' => ['x' => ['flow' => 'f', 'steps' => [['type' => 'a.b']]]]]);
		$this->macros->expects($this->never())->method('refusals');

		$this->assertCount(1, $this->validator()->refusals($schema));
	}//end testAShapeRefusalStopsBeforeTheFlowLookup()

	public function testStepRefusalsComeBeforeTheFlowLookup(): void {
		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-action' => ['x' => ['steps' => [['type' => 'openregister.user-task']]]]]);
		$steps = $this->createMock(ObjectActionStepsFlow::class);
		$steps->expects($this->once())->method('refusals')->willReturn(['Action "x": step 1 branches.']);
		$this->macros->expects($this->never())->method('refusals');

		$validator = new ObjectActionValidator($this->macros, $this->session, $this->groups, $steps);

		$this->assertSame(['Action "x": step 1 branches.'], $validator->refusals($schema));
	}//end testStepRefusalsComeBeforeTheFlowLookup()

	public function testTheBoundFlowIsStillVerified(): void {
		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-action' => ['x' => ['macro' => true, 'flow' => 'missing']]]);
		$this->macros->expects($this->once())->method('refusals')->willReturn(['Action "x": flow "missing" does not exist.']);

		$this->assertSame(['Action "x": flow "missing" does not exist.'], $this->validator()->refusals($schema));
	}//end testTheBoundFlowIsStillVerified()
}//end class

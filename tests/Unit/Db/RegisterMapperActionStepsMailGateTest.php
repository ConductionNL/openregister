<?php

/**
 * A register's declared action steps that mail any address are judged at register save (pp-f10).
 *
 * A register's actions are merged into every schema of the register and
 * ObjectActionStepsFlow::flowFor() stores and publishes their steps as a flow
 * for whoever runs the action, so the author is judged when the step is
 * written, as SchemaMapperActionStepsMailGateTest checks for schema actions.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinition;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

class RegisterMapperActionStepsMailGateTest extends TestCase {

	private function mapper(bool $isAdmin): RegisterMapper {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);

		$mapper = $this->getMockBuilder(RegisterMapper::class)
			->disableOriginalConstructor()
			->onlyMethods([])
			->getMock();
		(new ReflectionProperty(RegisterMapper::class, 'userSession'))->setValue($mapper, $session);
		(new ReflectionProperty(RegisterMapper::class, 'groupManager'))->setValue($mapper, $groups);

		return $mapper;
	}

	private function register(string $recipients): Register {
		$register = new Register();
		$register->setConfiguration([
			ObjectActionDefinition::ACTION_BLOCK => [
				'notify' => [
					'steps' => [['type' => 'openregister.send-email', 'config' => ['externalRecipients' => $recipients, 'to' => 'x@example.org']]],
				],
			],
		]);

		return $register;
	}

	private function judge(RegisterMapper $mapper, ?Register $old, Register $new): void {
		(new ReflectionMethod(RegisterMapper::class, 'assertActionStepsMailAuthor'))->invoke($mapper, $old, $new);
	}

	public function testANonAdminCannotAddAMailAnyStepToARegister(): void {
		$this->expectException(NotAuthorizedException::class);
		$this->judge($this->mapper(isAdmin: false), $this->register('object'), $this->register('any'));
	}

	public function testANonAdminCannotCreateARegisterWithAMailAnyStep(): void {
		$this->expectException(NotAuthorizedException::class);
		$this->judge($this->mapper(isAdmin: false), null, $this->register('any'));
	}

	public function testAnUnchangedAdminAuthoredStepDoesNotBlockALaterSave(): void {
		$this->judge($this->mapper(isAdmin: false), $this->register('any'), $this->register('any'));
		$this->addToAssertionCount(1);
	}

	public function testAnAdministratorMayAddIt(): void {
		$this->judge($this->mapper(isAdmin: true), $this->register('object'), $this->register('any'));
		$this->addToAssertionCount(1);
	}
}

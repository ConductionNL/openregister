<?php

/**
 * A declared action's inline steps that mail any address are judged at schema save (pp-f3).
 *
 * ObjectActionStepsFlow::flowFor() stores and publishes an action's steps as a
 * flow for whoever runs the action, so the author is judged when the step is
 * written: a non-administrator may not add or change such a step, and an
 * unchanged step an administrator wrote does not block a later save.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinition;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class SchemaMapperActionStepsMailGateTest extends TestCase {

	private function mapper(bool $isAdmin): SchemaMapper {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);

		return new SchemaMapper(
			$this->createMock(IDBConnection::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(PropertyValidatorHandler::class),
			$this->createMock(OrganisationMapper::class),
			$session,
			$groups,
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	private function schema(string $recipients): Schema {
		$schema = new Schema();
		$schema->setConfiguration([
			ObjectActionDefinition::ACTION_BLOCK => [
				'notify' => [
					'steps' => [['type' => 'openregister.send-email', 'config' => ['externalRecipients' => $recipients, 'to' => 'x@example.org']]],
				],
			],
		]);

		return $schema;
	}

	private function judge(SchemaMapper $mapper, ?Schema $old, Schema $new): void {
		(new ReflectionMethod(SchemaMapper::class, 'assertActionStepsMailAuthor'))->invoke($mapper, $old, $new);
	}

	public function testANonAdminCannotAddAMailAnyStep(): void {
		$this->expectException(NotAuthorizedException::class);
		$this->judge($this->mapper(isAdmin: false), $this->schema('object'), $this->schema('any'));
	}

	public function testANonAdminCannotCreateASchemaWithAMailAnyStep(): void {
		$this->expectException(NotAuthorizedException::class);
		$this->judge($this->mapper(isAdmin: false), null, $this->schema('any'));
	}

	public function testAnUnchangedAdminAuthoredStepDoesNotBlockALaterSave(): void {
		$this->judge($this->mapper(isAdmin: false), $this->schema('any'), $this->schema('any'));
		$this->addToAssertionCount(1);
	}

	public function testAnAdministratorMayAddIt(): void {
		$this->judge($this->mapper(isAdmin: true), $this->schema('object'), $this->schema('any'));
		$this->addToAssertionCount(1);
	}
}

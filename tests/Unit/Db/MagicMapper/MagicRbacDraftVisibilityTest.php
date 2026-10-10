<?php

/**
 * Decision 180: a draft answers to its owner only, on every list and read path, unless the schema says otherwise.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
 */

declare(strict_types=1);

namespace Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\RbacResolvers;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The raw-SQL emitter carries the draft clause beside the owner admit; an admin and an opted-in schema do not.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 */
class MagicRbacDraftVisibilityTest extends TestCase {

	/**
	 * A handler for a caller in groups.
	 *
	 * @param array<string> $groups The caller's groups.
	 */
	private function handlerFor(string $userId, array $groups = []): MagicRbacHandler {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		return new MagicRbacHandler(
			$userSession,
			$groupManager,
			$this->createMock(IUserManager::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(ConditionMatcher::class),
			$this->createMock(ContainerInterface::class),
			new NullLogger(),
			new RbacResolvers(objectScopeResolver: null, objectGrantResolver: null, denyResolver: new DenyResolver(new DenyEntryMatcher()))
		);
	}//end handlerFor()

	/**
	 * A schema with a block and configuration.
	 *
	 * @param array<string, mixed> $authorization The block.
	 * @param array<string, mixed> $configuration The configuration.
	 */
	private function schema(array $authorization, array $configuration = []): Schema {
		$schema = new Schema();
		$schema->setId(3301);
		$schema->setAuthorization($authorization);
		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()

	/**
	 * Others' drafts are hidden; the owner admit stays beside the clause, so the owner keeps their own.
	 */
	public function testOthersDraftsAreHiddenAndTheOwnerKeepsTheirs(): void {
		$predicate = (string)$this->handlerFor('alice', ['behandelaars'])->buildRbacRowPredicateSql(
			schema: $this->schema(['read' => ['behandelaars']])
		);

		$this->assertStringContainsString("(_status IS NULL OR _status <> 'draft')", $predicate);
		$this->assertStringContainsString("_owner = 'alice'", $predicate);
	}//end testOthersDraftsAreHiddenAndTheOwnerKeepsTheirs()

	/**
	 * An open schema (no authorization) still hides other people's drafts.
	 */
	public function testAnOpenSchemaStillHidesOthersDrafts(): void {
		$predicate = (string)$this->handlerFor('bob')->buildRbacRowPredicateSql(schema: $this->schema([]));

		$this->assertStringContainsString("_status <> 'draft'", $predicate);
	}//end testAnOpenSchemaStillHidesOthersDrafts()

	/**
	 * A schema with draftsVisible shows drafts to everyone who may read; an admin is never restricted.
	 */
	public function testOptInAndAdminSeeDrafts(): void {
		$optedIn = (string)$this->handlerFor('bob', ['behandelaars'])->buildRbacRowPredicateSql(
			schema: $this->schema(['read' => ['behandelaars']], ['draftsVisible' => true])
		);
		$this->assertStringNotContainsString('_status', $optedIn);

		$this->assertNull($this->handlerFor('root', ['admin'])->buildRbacRowPredicateSql(schema: $this->schema(['read' => ['behandelaars']])));
	}//end testOptInAndAdminSeeDrafts()
}//end class

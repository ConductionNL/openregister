<?php

/**
 * Golden: the per-row verdict of MagicRbacHandler, allowed and refused.
 *
 * Pins the outcomes of the row-level PHP check (owner, object data, the row's
 * own `_authorization` and its uuid) so a change to how those facts reach the
 * handler cannot move a single allowed or refused answer.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\RbacResolvers;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacSettings;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacSupport;

/**
 * Golden allowed/refused table for the row-level verdict.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\RbacPermissionCheck
 * @uses \OCA\OpenRegister\Db\MagicMapper\RbacResolvers
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses \OCA\OpenRegister\Service\Rbac\DenyEntryMatcher
 * @uses \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses \OCA\OpenRegister\Service\Rbac\RuleColumns
 * @uses \OCA\OpenRegister\Service\Rbac\UnknownRuleKeyFindings
 * @uses \OCA\OpenRegister\Service\Rbac\StoredRuleBlocks
 */
class MagicRbacHandlerRowVerdictGoldenTest extends TestCase {

	/**
	 * The golden table: caller, schema block, row facts, expected verdict.
	 *
	 * @return array<string, array{0: string, 1: string[], 2: array, 3: string, 4: string, 5: array|null, 6: bool}>
	 */
	public static function rowCases(): array {
		$readers = ['read' => ['behandelaars']];
		$deny = ['deny' => ['read' => ['behandelaars']]];

		return [
			'group reader, plain row: allowed' => ['ana', ['behandelaars'], $readers, 'read', 'bob', null, true],
			'group reader, denied row: refused' => ['ana', ['behandelaars'], $readers, 'read', 'bob', $deny, false],
			'owner, denied row: refused' => ['ana', ['behandelaars'], $readers, 'read', 'ana', $deny, false],
			'outsider, plain row: refused' => ['eve', ['anderen'], $readers, 'read', 'bob', null, false],
			'outsider owns the row: allowed' => ['eve', ['anderen'], $readers, 'read', 'eve', null, true],
			'reader asks to delete: refused' => ['ana', ['behandelaars'], $readers, 'delete', 'bob', null, false],
			'deny on another verb: allowed' => ['ana', ['behandelaars'], $readers, 'read', 'bob', ['deny' => ['delete' => ['behandelaars']]], true],
			'anonymous, plain row: refused' => ['', [], $readers, 'read', 'bob', null, false],
		];
	}//end rowCases()

	/**
	 * Every row case answers as pinned.
	 *
	 * @param string     $userId    The caller ('' for anonymous).
	 * @param string[]   $groups    The caller's groups.
	 * @param array      $block     The schema's authorization block.
	 * @param string     $action    The verb.
	 * @param string     $owner     The row owner.
	 * @param array|null $rowAuth   The row's own `_authorization`.
	 * @param bool       $expected  The pinned verdict.
	 *
	 * @return void
	 */
	#[DataProvider('rowCases')]
	public function testTheRowVerdictIsPinned(string $userId, array $groups, array $block, string $action, string $owner, ?array $rowAuth, bool $expected): void {
		$handler = $this->handlerFor(($userId === '' ? null : $userId), $groups);

		$this->assertSame($expected, $this->rowVerdict($handler, $this->schemaWith($block), $action, $owner, $rowAuth));
	}//end testTheRowVerdictIsPinned()

	/**
	 * Mutation check: the row deny is what refuses, not the fixture.
	 *
	 * The same caller, schema and owner flip from refused to allowed the moment
	 * the deny is taken off the row, so a verdict that ignored the row's facts
	 * could not satisfy both lines.
	 *
	 * @return void
	 */
	public function testRemovingTheRowDenyFlipsTheVerdict(): void {
		$handler = $this->handlerFor('ana', ['behandelaars']);
		$schema = $this->schemaWith(['read' => ['behandelaars']]);

		$this->assertFalse($this->rowVerdict($handler, $schema, 'read', 'bob', ['deny' => ['read' => ['behandelaars']]]));
		$this->assertTrue($this->rowVerdict($handler, $schema, 'read', 'bob', null));
	}//end testRemovingTheRowDenyFlipsTheVerdict()

	/**
	 * The row-level verdict for one row.
	 *
	 * @param MagicRbacHandler $handler The handler.
	 * @param Schema           $schema  The schema.
	 * @param string           $action  The verb.
	 * @param string           $owner   The row owner.
	 * @param array|null       $rowAuth The row's own `_authorization`.
	 *
	 * @return bool The verdict.
	 */
	private function rowVerdict(MagicRbacHandler $handler, Schema $schema, string $action, string $owner, ?array $rowAuth): bool {
		return $handler->hasRowPermission(
			schema: $schema,
			action: $action,
			row: $this->row($owner, ['title' => 'rij'], $rowAuth, 'cccccccc-0000-0000-0000-000000000001')
		);
	}//end rowVerdict()

	/**
	 * A stored row carrying the facts of one check.
	 *
	 * @param string     $owner         The row owner.
	 * @param array      $data          The row's object data.
	 * @param array|null $authorization The row's own `_authorization`.
	 * @param string     $uuid          The row's uuid.
	 *
	 * @return ObjectEntity The row.
	 */
	private function row(string $owner, array $data, ?array $authorization, string $uuid): ObjectEntity {
		$row = new ObjectEntity();
		$row->setUuid($uuid);
		$row->setOwner($owner);
		$row->setObject($data);
		$row->setAuthorization($authorization);

		return $row;
	}//end row()

	/**
	 * Build an enforcing handler for one caller and their groups.
	 *
	 * @param string|null $userId The caller, or null when anonymous.
	 * @param string[]    $groups The caller's group IDs.
	 *
	 * @return MagicRbacHandler The handler under test.
	 */
	private function handlerFor(?string $userId, array $groups): MagicRbacHandler {
		$userSession = $this->createMock(IUserSession::class);
		$groupManager = $this->createMock(IGroupManager::class);

		if ($userId === null) {
			$userSession->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
			$userSession->method('getUser')->willReturn($user);
			$groupManager->method('getUserGroupIds')->willReturn($groups);
		}

		$modeConfig = $this->createMock(IAppConfig::class);
		$modeConfig->method('getValueString')->willReturn(DenyEnforcementMode::MODE_ENFORCING);

		return new MagicRbacHandler(
			identity: new MagicMapperIdentity(
				userSession: $userSession,
				groupManager: $groupManager,
				userManager: $this->createMock(IUserManager::class)
			),
			settings: new MagicRbacSettings(
				appConfig: $this->createMock(IAppConfig::class),
				denyEnforcementMode: new DenyEnforcementMode($modeConfig, new NullLogger())
			),
			support: new MagicRbacSupport(
				conditionMatcher: $this->createMock(ConditionMatcher::class),
				container: $this->createMock(ContainerInterface::class),
				logger: new NullLogger()
			),
			resolvers: new RbacResolvers(
				objectScopeResolver: null,
				objectGrantResolver: null,
				denyResolver: new DenyResolver(new DenyEntryMatcher())
			)
		);
	}//end handlerFor()

	/**
	 * A schema carrying one authorization block.
	 *
	 * @param array $authorization The block.
	 *
	 * @return Schema The schema.
	 */
	private function schemaWith(array $authorization): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setTitle('Zaak');
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schemaWith()
}//end class

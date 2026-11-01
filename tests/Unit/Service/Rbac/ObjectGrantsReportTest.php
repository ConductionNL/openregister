<?php

/**
 * The grant rows of one object: every origin, conditions, denies and names.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
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
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use DateTime;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\GrantRuleFit;
use OCA\OpenRegister\Service\Rbac\InheritedGrantLister;
use OCA\OpenRegister\Service\Rbac\ObjectAccessHistory;
use OCA\OpenRegister\Service\Rbac\ObjectAccessReport;
use OCA\OpenRegister\Service\Rbac\ObjectGrantsReport;
use OCA\OpenRegister\Service\Rbac\ObjectPermissionsResolver;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\ObjectShareReader;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IAttributes;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Task 4.1.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\ObjectGrantsReport
 * @covers \OCA\OpenRegister\Service\Rbac\GrantRuleFit
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\ConditionMatcher
 * @uses   \OCA\OpenRegister\Service\OperatorEvaluator
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectAccessHistory
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectAccessReport
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectPermissionsResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\PermissionCatalogue
 * @uses   \OCA\OpenRegister\Service\Rbac\ShareGrantAttributes
 * @uses   \OCA\OpenRegister\Support\PermissionBit
 */
class ObjectGrantsReportTest extends TestCase {

	/**
	 * The schema rule that lets the handling team of case type ct-1 read and update its cases.
	 *
	 * @var array<string, mixed>
	 */
	private const TEAM_RULE = ['group' => 'woo-team', 'match' => ['caseType' => ['$in' => ['ct-1']]]];

	/**
	 * The rows of one case.
	 *
	 * @param array<string, mixed>                                              $schemaBlock The case schema's block.
	 * @param array<string, mixed>                                              $record      The case's data.
	 * @param array<string, array{type: string, share: IShare}>                 $shares      The shares on its folder.
	 * @param array<int, array{ancestor: ObjectEntity, type: string, share: IShare}> $inherited  The shares on its ancestors.
	 * @param string                                                            $denyMode    The deny enforcement mode.
	 * @param string[]                                                          $types       The principal type filter.
	 * @param array|null                                                        $objectBlock The case's own block.
	 * @param array                                                             $roles       The register's role definitions.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function rowsFor(
		array $schemaBlock = [],
		array $record = ['caseType' => 'ct-1'],
		array $shares = [],
		array $inherited = [],
		string $denyMode = DenyEnforcementMode::MODE_STAGING,
		array $types = [],
		?array $objectBlock = null,
		array $roles = [],
	): array {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('case');
		$schema->setAuthorization($schemaBlock);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$register = new Register();
		$register->setId(1);
		$register->setSlug('dossiq');
		$register->setConfiguration(['roles' => $roles]);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($denyMode);

		$accessReport = new ObjectAccessReport(
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			auditTrailMapper: $this->createMock(AuditTrailMapper::class),
			holders: new ObjectPermissionsResolver(),
			history: new ObjectAccessHistory(),
			enforcement: new DenyEnforcementMode(appConfig: $appConfig, logger: new NullLogger())
		);

		// The complete reading only: the panel's reading misses a share a
		// colleague made and swallows a failed read, so it is never what the
		// grants list is built from.
		$shareReader = $this->createMock(ObjectShareReader::class);
		$shareReader->method('sharesOn')->willReturnCallback(
			static fn (ObjectEntity $object, array $sharers, bool $complete = false): array => $complete === true ? $shares : []
		);
		$lister = $this->createMock(InheritedGrantLister::class);
		$lister->method('inheritedSharesFor')->willReturnCallback(
			static fn (ObjectEntity $object, bool $complete = false): array => $complete === true ? $inherited : []
		);

		$matcher = new ConditionMatcher(
			$this->createMock(IUserSession::class),
			$this->createMock(ContainerInterface::class),
			new OperatorEvaluator(new NullLogger()),
			new NullLogger()
		);

		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnCallback(
			static fn (string $uid): ?string => ['pjansen' => 'Pieter Jansen', 'tvisser' => 'Tom Visser', 'bea' => 'Bea de Wit'][$uid] ?? null
		);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(
			function (string $gid): ?IGroup {
				$names = ['woo-team' => 'Team Woo en informatie', 'juridische-zaken' => 'Juridische zaken', 'team-a' => 'Team A'];
				if (isset($names[$gid]) === false) {
					return null;
				}

				$group = $this->createMock(IGroup::class);
				$group->method('getDisplayName')->willReturn($names[$gid]);
				return $group;
			}
		);

		$object = new ObjectEntity();
		$object->setUuid('case-1');
		$object->setOwner('bea');
		$object->setObject($record);
		$object->setAuthorization($objectBlock);

		$report = new ObjectGrantsReport(
			accessReport: $accessReport,
			shares: $shareReader,
			inherited: $lister,
			rules: new GrantRuleFit($matcher),
			scopeResolver: new ObjectScopeResolver(),
			userManager: $users,
			groupManager: $groups
		);

		return $report->rowsFor(object: $object, registerRef: 1, schemaRef: 7, principalTypes: $types);
	}//end rowsFor()

	/**
	 * A share as core returns it.
	 *
	 * @param string        $fullId      The provider-prefixed id.
	 * @param string        $sharedWith  The principal.
	 * @param string        $sharedBy    Who made it.
	 * @param int           $permissions The core bitmask.
	 * @param DateTime|null $expires     The end date.
	 *
	 * @return IShare The share.
	 */
	private function share(string $fullId, string $sharedWith, string $sharedBy, int $permissions = 1, ?DateTime $expires = null): IShare {
		$share = $this->createMock(IShare::class);
		$share->method('getFullId')->willReturn($fullId);
		$share->method('getSharedWith')->willReturn($sharedWith);
		$share->method('getSharedBy')->willReturn($sharedBy);
		$share->method('getPermissions')->willReturn($permissions);
		$share->method('getExpirationDate')->willReturn($expires);
		$share->method('getAttributes')->willReturn(null);
		return $share;
	}//end share()

	/**
	 * The rows that name one principal.
	 *
	 * @param array<string, mixed> $report      The report.
	 * @param string               $principalId The principal id.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rowsNaming(array $report, string $principalId): array {
		return array_values(
			array_filter($report['results'], static fn (array $row): bool => $row['principal']['id'] === $principalId)
		);
	}//end rowsNaming()

	/**
	 * A group reads a record through a conditional schema rule that fits it.
	 *
	 * @return void
	 */
	public function testAFittingConditionalSchemaRuleIsARow(): void {
		$report = $this->rowsFor(schemaBlock: ['read' => [self::TEAM_RULE], 'update' => [self::TEAM_RULE]]);

		$rows = $this->rowsNaming($report, 'woo-team');
		$this->assertCount(1, $rows);
		$this->assertSame('rule:schema:group:woo-team', $rows[0]['id']);
		$this->assertSame(['type' => 'group', 'id' => 'woo-team', 'displayName' => 'Team Woo en informatie'], $rows[0]['principal']);
		$this->assertSame('rule', $rows[0]['origin']);
		$this->assertSame('schema', $rows[0]['level']);
		$this->assertSame(['caseType'], $rows[0]['matchedOn']);
		$this->assertFalse($rows[0]['relative']);
		$this->assertSame(['read', 'update'], $rows[0]['verbs']);
	}//end testAFittingConditionalSchemaRuleIsARow()

	/**
	 * A conditional rule that does not fit the record is left out.
	 *
	 * @return void
	 */
	public function testANonFittingConditionalRuleIsLeftOut(): void {
		$report = $this->rowsFor(schemaBlock: ['read' => [self::TEAM_RULE]], record: ['caseType' => 'ct-2']);

		$this->assertSame([], $this->rowsNaming($report, 'woo-team'));
	}//end testANonFittingConditionalRuleIsLeftOut()

	/**
	 * A condition on the asking user is relative, and its static part is still evaluated.
	 *
	 * @return void
	 */
	public function testAConditionOnTheAskingUserIsRelative(): void {
		$report = $this->rowsFor(schemaBlock: ['read' => [['group' => 'authenticated', 'match' => ['assignee' => '$userId']]]]);

		$rows = $this->rowsNaming($report, 'authenticated');
		$this->assertCount(1, $rows);
		$this->assertSame('authenticated', $rows[0]['principal']['type']);
		$this->assertSame('rule', $rows[0]['origin']);
		$this->assertTrue($rows[0]['relative']);
		$this->assertSame(['assignee'], $rows[0]['matchedOn']);

		$mixed = ['group' => 'authenticated', 'match' => ['assignee' => '$userId', 'caseType' => 'ct-9']];
		$this->assertSame([], $this->rowsNaming($this->rowsFor(schemaBlock: ['read' => [$mixed]]), 'authenticated'));
	}//end testAConditionOnTheAskingUserIsRelative()

	/**
	 * A rule through a role names the role; a `user:` entry is a user row.
	 *
	 * @return void
	 */
	public function testARoleRuleNamesItsRoleAndAUserEntryIsAUser(): void {
		$report = $this->rowsFor(
			schemaBlock: ['roles' => ['behandelaar' => ['behandelaars']], 'read' => ['user:tvisser']],
			roles: [['name' => 'behandelaar', 'actions' => ['read', 'update']]]
		);

		$role = $this->rowsNaming($report, 'behandelaars');
		$this->assertSame('behandelaar', $role[0]['role']);
		$this->assertSame('rule:schema:group:behandelaars:behandelaar', $role[0]['id']);
		$this->assertSame(['read', 'update'], $role[0]['verbs']);

		$user = $this->rowsNaming($report, 'tvisser');
		$this->assertSame(['type' => 'user', 'id' => 'tvisser', 'displayName' => 'Tom Visser'], $user[0]['principal']);
	}//end testARoleRuleNamesItsRoleAndAUserEntryIsAUser()

	/**
	 * A share names who made it and when it ends.
	 *
	 * @return void
	 */
	public function testAShareNamesWhoMadeIt(): void {
		$report = $this->rowsFor(
			shares: ['ocinternal:41' => ['type' => 'group', 'share' => $this->share('ocinternal:41', 'juridische-zaken', 'pjansen', 3, new DateTime('2026-12-31T00:00:00+00:00'))]]
		);

		$rows = $this->rowsNaming($report, 'juridische-zaken');
		$this->assertCount(1, $rows);
		$this->assertSame('shared:ocinternal:41', $rows[0]['id']);
		$this->assertSame('shared', $rows[0]['origin']);
		$this->assertSame('Juridische zaken', $rows[0]['principal']['displayName']);
		$this->assertSame(['id' => 'pjansen', 'displayName' => 'Pieter Jansen'], $rows[0]['sharedBy']);
		$this->assertSame(['read', 'update'], $rows[0]['verbs']);
		$this->assertSame('2026-12-31T00:00:00+00:00', $rows[0]['expiresAt']);
	}//end testAShareNamesWhoMadeIt()

	/**
	 * A sharer whose account is gone keeps the uid and the row.
	 *
	 * @return void
	 */
	public function testADeletedSharerKeepsTheRow(): void {
		$report = $this->rowsFor(shares: ['ocinternal:42' => ['type' => 'user', 'share' => $this->share('ocinternal:42', 'tvisser', 'vertrokken')]]);

		$rows = $this->rowsNaming($report, 'tvisser');
		$this->assertSame(['id' => 'vertrokken', 'displayName' => null], $rows[0]['sharedBy']);
	}//end testADeletedSharerKeepsTheRow()

	/**
	 * A link share is not a grant row; an e-mail share is, by its address.
	 *
	 * @return void
	 */
	public function testALinkShareIsNotARow(): void {
		$report = $this->rowsFor(
			shares: [
				'ocinternal:50' => ['type' => 'link', 'share' => $this->share('ocinternal:50', '', 'bea')],
				'ocMailShare:51' => ['type' => 'email', 'share' => $this->share('ocMailShare:51', 'a@example.org', 'bea')],
			]
		);

		$shared = array_filter($report['results'], static fn (array $row): bool => $row['origin'] === 'shared');
		$this->assertCount(1, $shared);
		$this->assertSame(['type' => 'email', 'id' => 'a@example.org', 'displayName' => 'a@example.org'], array_values($shared)[0]['principal']);
	}//end testALinkShareIsNotARow()

	/**
	 * A share on the parent shows on the child, naming the parent; a local-only one does not.
	 *
	 * @return void
	 */
	public function testAShareOnTheParentShowsOnTheChild(): void {
		$parent = new ObjectEntity();
		$parent->setUuid('main-case');
		$parent->setRegister('1');
		$parent->setSchema('7');

		$attributes = $this->createMock(IAttributes::class);
		$attributes->method('getAttribute')->willReturn(false);
		$localOnly = $this->createMock(IShare::class);
		$localOnly->method('getFullId')->willReturn('ocinternal:61');
		$localOnly->method('getSharedWith')->willReturn('kvries');
		$localOnly->method('getSharedBy')->willReturn('pjansen');
		$localOnly->method('getPermissions')->willReturn(1);
		$localOnly->method('getAttributes')->willReturn($attributes);

		$report = $this->rowsFor(
			inherited: [
				['ancestor' => $parent, 'type' => 'user', 'share' => $this->share('ocinternal:60', 'tvisser', 'pjansen')],
				['ancestor' => $parent, 'type' => 'user', 'share' => $localOnly],
			]
		);

		$rows = $this->rowsNaming($report, 'tvisser');
		$this->assertCount(1, $rows);
		$this->assertSame('inherited', $rows[0]['origin']);
		$this->assertSame('main-case', $rows[0]['inheritedFrom']['uuid']);
		$this->assertSame('pjansen', $rows[0]['sharedBy']['id']);
		$this->assertSame([], $this->rowsNaming($report, 'kvries'), 'a share marked local does not travel to the child');
	}//end testAShareOnTheParentShowsOnTheChild()

	/**
	 * The owner and the owning group each get an owner row.
	 *
	 * @return void
	 */
	public function testTheOwnerAndTheOwningGroupAreOwnerRows(): void {
		$report = $this->rowsFor(objectBlock: [ObjectScopeResolver::OWNER_GROUP_KEY => 'team-a']);

		$owner = $this->rowsNaming($report, 'bea');
		$this->assertSame('owner', $owner[0]['origin']);
		$this->assertSame('Bea de Wit', $owner[0]['principal']['displayName']);
		$this->assertContains('readGrants', $owner[0]['verbs']);

		$group = $this->rowsNaming($report, 'team-a');
		$this->assertSame(['owner', 'group'], [$group[0]['origin'], $group[0]['principal']['type']]);
	}//end testTheOwnerAndTheOwningGroupAreOwnerRows()

	/**
	 * The principal type filter leaves pseudo, remote and e-mail principals out.
	 *
	 * @return void
	 */
	public function testThePrincipalTypeFilter(): void {
		$report = $this->rowsFor(
			schemaBlock: ['read' => ['public', 'authenticated', self::TEAM_RULE]],
			shares: [
				'ocMailShare:51' => ['type' => 'email', 'share' => $this->share('ocMailShare:51', 'a@example.org', 'bea')],
				'ocFederatedSharing:52' => ['type' => 'remote', 'share' => $this->share('ocFederatedSharing:52', 'x@other.example', 'bea')],
			],
			types: ['user', 'group']
		);

		$types = array_unique(array_map(static fn (array $row): string => $row['principal']['type'], $report['results']));
		sort($types);
		$this->assertSame(['group', 'user'], $types);
	}//end testThePrincipalTypeFilter()

	/**
	 * An enforcing deny takes the verb out of the row; a staged one leaves it and is reported.
	 *
	 * @return void
	 */
	public function testAnEnforcingDenyTakesTheVerbOut(): void {
		$block = ['read' => [self::TEAM_RULE], 'update' => [self::TEAM_RULE], 'deny' => ['update' => ['woo-team']]];

		$enforcing = $this->rowsFor(schemaBlock: $block, denyMode: DenyEnforcementMode::MODE_ENFORCING);
		$this->assertSame(['read'], $this->rowsNaming($enforcing, 'woo-team')[0]['verbs']);
		$this->assertSame('enforcing', $enforcing['denyEnforcement']);

		$staging = $this->rowsFor(schemaBlock: $block, denyMode: DenyEnforcementMode::MODE_STAGING);
		$this->assertSame(['read', 'update'], $this->rowsNaming($staging, 'woo-team')[0]['verbs']);
		$this->assertSame('staging', $staging['denyEnforcement']);
	}//end testAnEnforcingDenyTakesTheVerbOut()

	/**
	 * A row left with no verbs by an enforcing deny is dropped.
	 *
	 * @return void
	 */
	public function testARowLeftWithoutVerbsIsDropped(): void {
		$report = $this->rowsFor(
			schemaBlock: ['read' => ['waarnemers'], 'deny' => ['read' => ['waarnemers']]],
			denyMode: DenyEnforcementMode::MODE_ENFORCING
		);

		$this->assertSame([], $this->rowsNaming($report, 'waarnemers'));
	}//end testARowLeftWithoutVerbsIsDropped()
}//end class

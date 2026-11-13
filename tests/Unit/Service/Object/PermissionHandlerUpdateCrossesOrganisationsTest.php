<?php

/**
 * Unit tests for the cross-organisation update question.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
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
 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Pins that only an update rule explicitly marked `crossOrganisation: true`
 * lets a caller change a row of another organisation.
 *
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses   \OCA\OpenRegister\Service\Object\DenialQuestion
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleEntries
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleRequest
 * @uses   \OCA\OpenRegister\Service\Object\PermissionAsk
 * @uses   \OCA\OpenRegister\Service\Object\PermissionCheck
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionCallerServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionDataServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionDenyServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionDerivedGrantServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionGrantServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionRuleServices
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEntryMatcher
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyQuestion
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\GrantConstraints
 * @uses   \OCA\OpenRegister\Service\Rbac\McpScope
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\ReadRuleTenancy
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 */
final class PermissionHandlerUpdateCrossesOrganisationsTest extends TestCase {

	/**
	 * Hands out a distinct schema id per schema built in a case.
	 *
	 * @var integer
	 */
	private int $schemaCounter = 200;

	/**
	 * Build a handler for one signed-in caller and their groups.
	 *
	 * @param string   $userId The caller.
	 * @param string[] $groups The caller's group IDs.
	 *
	 * @return PermissionHandler The handler under test.
	 */
	private function handlerFor(string $userId, array $groups): PermissionHandler {
		$logger       = new NullLogger();
		$userSession  = $this->createMock(IUserSession::class);
		$userManager  = $this->createMock(IUserManager::class);
		$groupManager = $this->createMock(IGroupManager::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$userSession->method('getUser')->willReturn($user);
		$userManager->method('get')->willReturn($user);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(false);
		$appConfig->method('getValueString')->willReturn(DenyEnforcementMode::MODE_ENFORCING);

		return PermissionHandlerFactory::create(
			$userSession,
			$userManager,
			$groupManager,
			$this->createMock(SchemaMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(ConditionMatcher::class),
			$appConfig,
			$logger,
			$this->createMock(ContainerInterface::class),
			null,
			null,
			null,
			new DenyResolver(new DenyEntryMatcher()),
			new DenyEnforcementMode($appConfig, $logger)
		);
	}//end handlerFor()

	/**
	 * A schema carrying one authorization block.
	 *
	 * @param array|null $authorization The block.
	 *
	 * @return Schema The schema.
	 */
	private function schemaWith(?array $authorization): Schema {
		$this->schemaCounter++;

		$schema = new Schema();
		$schema->setId($this->schemaCounter);
		$schema->setTitle('Zaak');
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schemaWith()

	/**
	 * A stored row owned by someone else, in another organisation.
	 *
	 * @return ObjectEntity The row.
	 */
	private function foreignRow(): ObjectEntity {
		$row = new ObjectEntity();
		$row->setUuid('11111111-2222-3333-4444-000000000001');
		$row->setOwner('bob');
		$row->setOrganisation('other-org');
		$row->setObject(['title' => 'Elsewhere']);

		return $row;
	}//end foreignRow()

	/**
	 * A marked update rule for one of the caller's groups admits the caller.
	 *
	 * @return void
	 */
	public function testAMarkedUpdateRuleForTheCallersGroupAdmits(): void {
		$schema = $this->schemaWith(['update' => [['group' => 'behandelaars', 'crossOrganisation' => true]]]);

		$this->assertTrue($this->handlerFor('ana', ['behandelaars'])->updateCrossesOrganisations(schema: $schema, row: $this->foreignRow()));
	}//end testAMarkedUpdateRuleForTheCallersGroupAdmits()

	/**
	 * An ordinary (unmarked) update rule does not cross organisations, even
	 * though the same caller may update under it.
	 *
	 * @return void
	 */
	public function testAnUnmarkedUpdateRuleDoesNotCrossOrganisations(): void {
		$schema  = $this->schemaWith(['update' => ['behandelaars']]);
		$handler = $this->handlerFor('ana', ['behandelaars']);

		$this->assertTrue($handler->hasPermission($schema, 'update', 'ana'));
		$this->assertFalse($handler->updateCrossesOrganisations(schema: $schema, row: $this->foreignRow()));
	}//end testAnUnmarkedUpdateRuleDoesNotCrossOrganisations()

	/**
	 * Only the marked rules are consulted: a marked rule for another group does
	 * not admit a caller who updates through an unmarked one.
	 *
	 * @return void
	 */
	public function testOnlyTheMarkedRulesAreConsulted(): void {
		$schema = $this->schemaWith(
			[
				'update' => [
					'behandelaars',
					['group' => 'beheerders', 'crossOrganisation' => true],
				],
			]
		);

		$this->assertFalse($this->handlerFor('ana', ['behandelaars'])->updateCrossesOrganisations(schema: $schema, row: $this->foreignRow()));
		$this->assertTrue($this->handlerFor('cas', ['beheerders'])->updateCrossesOrganisations(schema: $schema, row: $this->foreignRow()));
	}//end testOnlyTheMarkedRulesAreConsulted()

	/**
	 * A marker set to anything but `true` is not a marker.
	 *
	 * @return void
	 */
	public function testANonTrueMarkerIsNotAMarker(): void {
		$schema = $this->schemaWith(['update' => [['group' => 'behandelaars', 'crossOrganisation' => 'yes']]]);

		$this->assertFalse($this->handlerFor('ana', ['behandelaars'])->updateCrossesOrganisations(schema: $schema, row: $this->foreignRow()));
	}//end testANonTrueMarkerIsNotAMarker()

	/**
	 * A deny on update beats a marked rule.
	 *
	 * @return void
	 */
	public function testADenyBeatsAMarkedRule(): void {
		$schema = $this->schemaWith(
			[
				'update' => [['group' => 'behandelaars', 'crossOrganisation' => true]],
				'deny'   => ['update' => ['behandelaars']],
			]
		);

		$this->assertFalse($this->handlerFor('ana', ['behandelaars'])->updateCrossesOrganisations(schema: $schema, row: $this->foreignRow()));
	}//end testADenyBeatsAMarkedRule()

	/**
	 * A schema without an authorization block marks nothing, so nothing crosses.
	 *
	 * @return void
	 */
	public function testASchemaWithoutAuthorizationDoesNotCross(): void {
		$schema = $this->schemaWith(null);

		$this->assertFalse($this->handlerFor('ana', ['behandelaars'])->updateCrossesOrganisations(schema: $schema, row: $this->foreignRow()));
	}//end testASchemaWithoutAuthorizationDoesNotCross()
}//end class

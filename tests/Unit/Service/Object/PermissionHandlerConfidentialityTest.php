<?php

/**
 * PermissionHandler and a schema's confidentiality block: a single-object read
 * below clearance and before release is refused, on top of the schema rules.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\ConditionUserResolvers;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
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
 *
 * @uses   \OCA\OpenRegister\Service\Rbac\RoleBlockExpander
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Service\Object\DenialQuestion
 * @uses \OCA\OpenRegister\Service\Object\PermissionCheck
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses \OCA\OpenRegister\Service\Rbac\DenyEntryMatcher
 * @uses \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses \OCA\OpenRegister\Service\Rbac\GrantConstraints
 * @uses \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses \OCA\OpenRegister\Service\SystemOperationContext
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleEntries
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleRequest
 * @uses   \OCA\OpenRegister\Db\JsonArrayColumn
 * @uses   \OCA\OpenRegister\Service\Object\PermissionAsk
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionCallerServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionDataServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionDenyServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionDerivedGrantServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionGrantServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionRuleServices
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyQuestion
 * @uses   \OCA\OpenRegister\Service\Rbac\GrantLookup
 * @uses   \OCA\OpenRegister\Service\Rbac\McpScope
 * @uses   \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses \OCA\OpenRegister\Db\JsonArrayColumn
 * @uses \OCA\OpenRegister\Service\Object\PermissionAsk
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionCallerServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionDataServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionDenyServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionDerivedGrantServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionGrantServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionRuleServices
 * @uses \OCA\OpenRegister\Service\Rbac\DenyQuestion
 * @uses \OCA\OpenRegister\Service\Rbac\GrantLookup
 * @uses \OCA\OpenRegister\Service\Rbac\McpScope
 * @uses   \OCA\OpenRegister\Db\Schema\AnnotationVocabulary
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 *
 * @uses   \OCA\OpenRegister\Service\Confidentiality\ConfidentialityReadGate
 * @uses   \OCA\OpenRegister\Service\Confidentiality\ConfidentialityRule
 * @uses   \OCA\OpenRegister\Service\Confidentiality\ConfidentialityAnnotationValidator
 * @uses   \OCA\OpenRegister\Service\ConditionMatcher
 * @uses   \OCA\OpenRegister\Service\ConditionUserResolvers
 * @uses   \OCA\OpenRegister\Service\OperatorEvaluator
 * @uses   \OCA\OpenRegister\Service\ComparableInstants
 * @uses   \OCA\OpenRegister\Service\RecordMetadataConditions
 * @uses   \OCA\OpenRegister\Service\UndeclaredPropertyConditions
 *
 * @spec openspec/changes/confidentiality-classification-primitive/specs/confidentiality-classification/spec.md#requirement-read-access-to-a-classified-object-must-require-tier-clearance-or-a-met-release-condition
 */
class PermissionHandlerConfidentialityTest extends TestCase {

	/**
	 * A distinct schema id per schema, so the per-request memo never answers for another.
	 *
	 * @var integer
	 */
	private int $schemaCounter = 4600;

	/**
	 * A distinct uuid per object.
	 *
	 * @var integer
	 */
	private int $objectCounter = 0;

		private function handlerFor(?string $userId, array $groups = []): PermissionHandler {
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($userId);
			$user->method('getDisplayName')->willReturn($userId);
		}

		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$userSession->method('isLoggedIn')->willReturn($user !== null);

		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);
		// Nextcloud's own administrator check, as it answers inside allowed_admin_ranges.
		$groupManager->method('isAdmin')->willReturn(in_array('admin', $groups, true));

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(false);
		$appConfig->method('getValueString')->willReturn(DenyEnforcementMode::MODE_ENFORCING);

		$logger = new NullLogger();

		return PermissionHandlerFactory::create(
			$userSession,
			$userManager,
			$groupManager,
			$this->createMock(originalClassName: SchemaMapper::class),
			$this->createMock(originalClassName: MagicMapper::class),
			new ConditionMatcher(new ConditionUserResolvers($userSession), $this->createMock(originalClassName: ContainerInterface::class), new OperatorEvaluator($logger), $logger),
			$appConfig,
			$logger,
			$this->container(),
			null,
			new ObjectScopeResolver(),
			null,
			new DenyResolver(new DenyEntryMatcher()),
			new DenyEnforcementMode($appConfig, $logger)
		);
	}//end handlerFor()

	/**
	 * A container answering with one register.
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		$register = new Register();
		$register->setId(9);
		$register->setSlug('documenten');

		$registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$registerMapper->method('getFirstRegisterWithSchema')->willReturn(9);
		$registerMapper->method('find')->willReturn($register);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($registerMapper): mixed {
				if ($id === RegisterMapperInterface::class) {
					return $registerMapper;
				}

				throw new \RuntimeException(sprintf('nothing registered for %s', $id));
			}
		);

		return $container;
	}//end container()

	/**
	 * A besluit schema whose read rule names these principals, with or without the confidentiality block.
	 *
	 * @param array<int, string> $readers    Who the schema lets read.
	 * @param bool               $classified Whether the schema declares the block.
	 *
	 * @return Schema
	 */
	private function schema(array $readers, bool $classified = true): Schema {
		$this->schemaCounter++;

		$schema = new Schema();
		$schema->setId($this->schemaCounter);
		$schema->setTitle('Besluit');
		$schema->setSlug('besluit');
		$schema->setProperties(
			[
				'confidentialityTier' => ['type' => 'string'],
				'confidentialityReleaseUntil' => ['type' => 'string', 'format' => 'date-time'],
			]
		);
		$schema->setAuthorization(['read' => $readers]);
		if ($classified === true) {
			$schema->setConfiguration(
				[
					'x-openregister-confidentiality' => [
						'tiers' => ['public', 'confidential'],
						'tierProperty' => 'confidentialityTier',
						'releaseAtProperty' => 'confidentialityReleaseUntil',
						'clearance' => ['confidential' => 'raadsleden'],
					],
				]
			);
		}

		return $schema;
	}//end schema()

	/**
	 * A besluit at a tier, optionally with a release moment, owned by bea unless said otherwise.
	 *
	 * @param string      $tier      The tier.
	 * @param string|null $releaseAt The release moment.
	 * @param string      $owner     The owner.
	 *
	 * @return ObjectEntity
	 */
	private function besluit(string $tier, ?string $releaseAt = null, string $owner = 'bea'): ObjectEntity {
		$this->objectCounter++;

		$data = ['confidentialityTier' => $tier];
		if ($releaseAt !== null) {
			$data['confidentialityReleaseUntil'] = $releaseAt;
		}

		$object = new ObjectEntity();
		$object->setUuid(sprintf('88888888-4444-5555-6666-%012d', $this->objectCounter));
		$object->setObject($data);
		$object->setOwner($owner);

		return $object;
	}//end besluit()

	/**
	 * The schema grants read, yet a confidential besluit is refused below clearance; a public one is not.
	 *
	 * @return void
	 */
	public function testReadBelowClearanceIsRefusedDespiteTheSchemaGrant(): void {
		$handler = $this->handlerFor(userId: 'pieter', groups: ['staff']);
		$schema = $this->schema(readers: ['staff', 'raadsleden']);

		$this->assertFalse($handler->hasPermission(ask: (new PermissionAsk(schema: $schema, action: 'read'))->askedBy(userId: 'pieter', objectOwner: null)->about(object: $this->besluit('confidential'), register: null)));
		$this->assertTrue($handler->hasPermission(ask: (new PermissionAsk(schema: $schema, action: 'read'))->askedBy(userId: 'pieter', objectOwner: null)->about(object: $this->besluit('public'), register: null)));
		// A question about the schema, not one object, is the list path's to answer.
		$this->assertTrue($handler->hasPermission(ask: (new PermissionAsk(schema: $schema, action: 'read'))->askedBy(userId: 'pieter', objectOwner: null)));
	}//end testReadBelowClearanceIsRefusedDespiteTheSchemaGrant()

	/**
	 * A past release admits the caller below clearance; a future one does not.
	 *
	 * @return void
	 */
	public function testAPastReleaseAdmitsAndAFutureOneDoesNot(): void {
		$handler = $this->handlerFor(userId: 'pieter', groups: ['staff']);
		$schema = $this->schema(readers: ['staff']);

		$this->assertTrue($handler->hasPermission(ask: (new PermissionAsk(schema: $schema, action: 'read'))->askedBy(userId: 'pieter', objectOwner: null)->about(object: $this->besluit('confidential', '2020-01-01T00:00:00+00:00'), register: null)));
		$this->assertFalse($handler->hasPermission(ask: (new PermissionAsk(schema: $schema, action: 'read'))->askedBy(userId: 'pieter', objectOwner: null)->about(object: $this->besluit('confidential', '2099-01-01T00:00:00+00:00'), register: null)));
	}//end testAPastReleaseAdmitsAndAFutureOneDoesNot()

	/**
	 * The cleared caller, the owner and the administrator read the confidential besluit.
	 *
	 * @return void
	 */
	public function testClearanceOwnerAndAdministratorRead(): void {
		$schema = $this->schema(readers: ['staff', 'raadsleden']);

		$this->assertTrue($this->handlerFor(userId: 'jan', groups: ['raadsleden'])->hasPermission(ask: (new PermissionAsk(schema: $schema, action: 'read'))->askedBy(userId: 'jan', objectOwner: null)->about(object: $this->besluit('confidential'), register: null)));
		$this->assertTrue($this->handlerFor(userId: 'pieter', groups: ['staff'])->hasPermission(ask: (new PermissionAsk(schema: $schema, action: 'read'))->askedBy(userId: 'pieter', objectOwner: null)->about(object: $this->besluit('confidential', null, 'pieter'), register: null)));
		$this->assertTrue($this->handlerFor(userId: 'beheer', groups: ['admin'])->hasPermission(ask: (new PermissionAsk(schema: $schema, action: 'read'))->askedBy(userId: 'beheer', objectOwner: null)->about(object: $this->besluit('confidential'), register: null)));
	}//end testClearanceOwnerAndAdministratorRead()

	/**
	 * Clearance does not stand in for the schema's own rules: a cleared caller the schema does not let read is still refused.
	 *
	 * @return void
	 */
	public function testClearanceDoesNotGrantWhatTheSchemaRefuses(): void {
		$handler = $this->handlerFor(userId: 'jan', groups: ['raadsleden']);

		$this->assertFalse($handler->hasPermission(ask: (new PermissionAsk(schema: $this->schema(readers: ['staff']), action: 'read'))->askedBy(userId: 'jan', objectOwner: null)->about(object: $this->besluit('confidential'), register: null)));
	}//end testClearanceDoesNotGrantWhatTheSchemaRefuses()

	/**
	 * A schema without the block reads as it always did.
	 *
	 * @return void
	 */
	public function testASchemaWithoutTheBlockIsUnchanged(): void {
		$handler = $this->handlerFor(userId: 'pieter', groups: ['staff']);

		$this->assertTrue($handler->hasPermission(ask: (new PermissionAsk(schema: $this->schema(readers: ['staff'], classified: false), action: 'read'))->askedBy(userId: 'pieter', objectOwner: null)->about(object: $this->besluit('confidential'), register: null)));
	}//end testASchemaWithoutTheBlockIsUnchanged()
}//end class

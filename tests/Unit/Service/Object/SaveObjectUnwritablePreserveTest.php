<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An update that omits a property the caller may not update keeps it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @license  EUPL-1.2
 * @link     https://github.com/OpenRegister/OpenRegister
 *
 * @spec openspec/specs/row-field-level-security/spec.md#requirement-an-update-that-omits-a-property-the-caller-may-not-update-keeps-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\BulkRowRules;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Tests\Support\BuildsStateFieldRuleResolver;

/**
 * A PUT that omits a property the caller may not update keeps the stored value: leaving it out
 * must not do what sending null is refused for.
 *
 * @spec openspec/specs/row-field-level-security/spec.md#requirement-an-update-that-omits-a-property-the-caller-may-not-update-keeps-it
 */
class SaveObjectUnwritablePreserveTest extends TestCase {
	use BuildsStateFieldRuleResolver;

	private SchemaMapper $schemaMapper;

	private PropertyRbacHandler $rbac;

	/** @var OrganisationService&\PHPUnit\Framework\MockObject\MockObject */
	private $organisations;

	/**
	 * A save handler acting as a member of the given groups.
	 *
	 * @param array<string> $groups The caller's groups.
	 *
	 * @return SaveObject
	 */
	private function handlerAs(array $groups): SaveObject {
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		$this->organisations = $this->createMock(OrganisationService::class);
		$this->rbac = new PropertyRbacHandler(caller: new \OCA\OpenRegister\Service\PropertyRbacCaller($session, $groupManager), rules: new \OCA\OpenRegister\Service\PropertyRbacRules(new ConditionMatcher(
				$session,
				$this->createMock(ContainerInterface::class),
				new OperatorEvaluator($this->createMock(LoggerInterface::class)),
				$this->createMock(LoggerInterface::class)
			), self::stateFieldRuleResolver($session, $groupManager)), logger: $this->createMock(LoggerInterface::class));

		return new SaveObject(
			$this->createMock(MagicMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(MetadataHydrationHandler::class),
			$this->createMock(FilePropertyHandler::class),
			$this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler::class),
			$session,
			$this->createMock(AuditTrailMapper::class),
			$this->schemaMapper,
			$this->createMock(RegisterMapper::class),
			$this->createMock(IURLGenerator::class),
			$this->organisations,
			$this->createMock(CacheHandler::class),
			$this->createMock(SettingsService::class),
			$this->rbac,
			$this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler::class),
			$this->createMock(\OCA\OpenRegister\Service\Object\TranslationHandler::class),
			$this->createMock(\OCA\OpenRegister\Service\TranslationProjectionService::class),
			$this->createMock(\OCA\OpenRegister\Service\TranslationStatusService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(\OCA\OpenRegister\Service\TmloService::class),
			$this->createMock(\OCA\OpenRegister\Service\File\FolderManagementHandler::class),
			$this->createMock(PermissionHandler::class)
		);
	}//end handlerAs()

	/**
	 * A document schema whose `restricted` and `history` only a service group may write.
	 *
	 * @return Schema
	 */
	private function documentSchema(): Schema {
		$schema = new Schema();
		$ref = new ReflectionClass($schema);
		$idProp = $ref->getProperty('id');
		$idProp->setAccessible(true);
		$idProp->setValue($schema, 314);
		$service = ['create' => [['group' => 'service']], 'update' => [['group' => 'service']]];
		$schema->setSlug('document');
		$schema->setProperties(
			[
				'title' => ['type' => 'string'],
				'restricted' => ['type' => 'boolean', 'authorization' => $service],
				'history' => ['type' => 'array', 'authorization' => $service],
			]
		);
		$this->schemaMapper->method('find')->willReturn($schema);

		return $schema;
	}//end documentSchema()

	/**
	 * Run the update path.
	 *
	 * @param SaveObject           $handler The handler.
	 * @param Schema               $schema  The schema.
	 * @param array<string, mixed> $stored  The stored object.
	 * @param array<string, mixed> $data    The PUT payload.
	 * @param string|null          $org     The stored record's organisation.
	 *
	 * @return array<string, mixed> The prepared object.
	 */
	private function prepareUpdate(SaveObject $handler, Schema $schema, array $stored, array $data, ?string $org = null): array {
		$existing = new ObjectEntity();
		$existing->setUuid('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
		$existing->setSchema((string)$schema->getId());
		$existing->setRegister('65');
		$existing->setOrganisation($org);
		$existing->setObject($stored);
		$method = new ReflectionMethod(SaveObject::class, 'prepareObjectForUpdate');
		$method->setAccessible(true);

		return $method->invokeArgs($handler, [$existing, $schema, $data, ['organisation' => 'org-a'], null, null])->getObject();
	}//end prepareUpdate()

	/**
	 * A schema whose `restricted` only staff of organisation org-a may update.
	 *
	 * @return Schema
	 */
	private function orgPinnedSchema(): Schema {
		$schema = $this->documentSchema();
		$staffOfOrgA = [['group' => 'staff', 'match' => ['_organisation' => 'org-a']]];
		$schema->setProperties(
			[
				'title' => ['type' => 'string'],
				'restricted' => ['type' => 'boolean', 'authorization' => ['read' => $staffOfOrgA, 'update' => $staffOfOrgA]],
			]
		);

		return $schema;
	}//end orgPinnedSchema()

	/**
	 * Whether an omitted `restricted` is carried forward is decided on the record, not on its stored body.
	 *
	 * The stored body of an org-b record claims org-a twice over; neither claim makes
	 * the caller someone who may clear it by leaving it out. The request's own `@self`
	 * moves the record to org-a, of which the caller is a member, and does not count
	 * either: the decision is the stored record's.
	 *
	 * @return void
	 */
	public function testTheCarryForwardIsDecidedOnTheRecord(): void {
		$handler = $this->handlerAs(['staff']);
		$this->organisations->method('hasAccessToOrganisation')->willReturn(true);
		$schema = $this->orgPinnedSchema();
		$stored = ['title' => 'Offerte', 'restricted' => true, '_organisation' => 'org-a', '@self' => ['organisation' => 'org-a']];

		$result = $this->prepareUpdate($handler, $schema, $stored, ['title' => 'Offerte v2'], 'org-b');

		$this->assertTrue($result['restricted'], 'an org-b record lost a property its caller may not update');
	}//end testTheCarryForwardIsDecidedOnTheRecord()

	/**
	 * A bulk row runs the same carry-forward decision on the record, and still takes the value from the stored body.
	 *
	 * @return void
	 */
	public function testABulkRowDecidesTheCarryForwardOnTheRecord(): void {
		$this->handlerAs(['staff']);
		$schema = $this->orgPinnedSchema();
		$bulk = (new ReflectionClass(SaveObjects::class))->newInstanceWithoutConstructor();
		$rules = new BulkRowRules(propertyRbac: $this->rbac);
		(fn () => $this->rowRules = $rules)->call($bulk);
		$apply = new ReflectionMethod(SaveObjects::class, 'applyPropertyAuthorization');
		$apply->setAccessible(true);

		$forged = new ObjectEntity();
		$forged->setOrganisation('org-b');
		$forged->setObject(['title' => 'Offerte', 'restricted' => true, '_organisation' => 'org-a', '@self' => ['organisation' => 'org-a']]);
		[$row, $refusal] = $apply->invoke($bulk, ['title' => 'Offerte v2'], $schema, $forged, false);
		$this->assertNull($refusal);
		$this->assertTrue($row['restricted'] ?? null, 'an org-b row lost a property its caller may not update');

		$own = new ObjectEntity();
		$own->setOrganisation('org-a');
		$own->setObject(['title' => 'Offerte', 'restricted' => true]);
		[$row] = $apply->invoke($bulk, ['title' => 'Offerte v2'], $schema, $own, false);
		$this->assertArrayNotHasKey('restricted', $row, 'staff of the record\'s organisation clear it by leaving it out');
	}//end testABulkRowDecidesTheCarryForwardOnTheRecord()

	/**
	 * Staff of the record's own organisation still clear `restricted` by leaving it out.
	 *
	 * @return void
	 */
	public function testTheRecordsOwnOrganisationStillClearsByOmission(): void {
		$handler = $this->handlerAs(['staff']);
		$schema = $this->orgPinnedSchema();

		$result = $this->prepareUpdate($handler, $schema, ['title' => 'Offerte', 'restricted' => true], ['title' => 'Offerte v2'], 'org-a');

		$this->assertNull($result['restricted']);
	}//end testTheRecordsOwnOrganisationStillClearsByOmission()

	public function testAPutWithoutTheProtectedPropertiesKeepsThem(): void {
		$handler = $this->handlerAs(['staff']);
		$schema = $this->documentSchema();

		$result = $this->prepareUpdate(
			$handler,
			$schema,
			['title' => 'Offerte', 'restricted' => true, 'history' => [['action' => 'set']]],
			['title' => 'Offerte v2']
		);

		$this->assertSame('Offerte v2', $result['title']);
		$this->assertTrue($result['restricted'], 'leaving it out must not clear a property the caller may not update');
		$this->assertSame([['action' => 'set']], $result['history']);
	}//end testAPutWithoutTheProtectedPropertiesKeepsThem()

	public function testTheGroupThatMayUpdateThemStillClearsThemByLeavingThemOut(): void {
		$handler = $this->handlerAs(['service']);
		$schema = $this->documentSchema();

		$result = $this->prepareUpdate($handler, $schema, ['title' => 'Offerte', 'restricted' => true], ['title' => 'Offerte']);

		$this->assertNull($result['restricted']);
	}//end testTheGroupThatMayUpdateThemStillClearsThemByLeavingThemOut()

	public function testTheHandlerNamesOnlyStoredPropertiesTheCallerMayNotUpdate(): void {
		$this->handlerAs(['staff']);
		$schema = $this->documentSchema();

		$this->assertSame(
			['restricted'],
			$this->rbac->collectOmittedProtectedProperties(schema: $schema, incoming: ['title' => 'x'], stored: ['title' => 'x', 'restricted' => true])
		);
		$this->assertSame(
			[],
			$this->rbac->collectOmittedProtectedProperties(schema: $schema, incoming: ['title' => 'x', 'restricted' => true], stored: ['restricted' => true])
		);
	}//end testTheHandlerNamesOnlyStoredPropertiesTheCallerMayNotUpdate()
}//end class

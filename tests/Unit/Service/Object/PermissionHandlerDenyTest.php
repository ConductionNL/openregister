<?php

/**
 * Deny wins, on the single-object verdict.
 *
 * The precedence rule has exactly one sentence — a deny removes the verb inside
 * its scope and no broader grant puts it back — and the tests here are that
 * sentence read against every grant the chain can produce: a schema rule, a
 * role, the `authenticated` pseudo-group, a per-object override and the object
 * owner.
 *
 * The LEAST-PRIVILEGED PROBE is the point of
 * `testACallerWithNoGrantIsRefusedAndTheDenyIsNotWhyItWasRefused`: a superuser
 * success proves almost nothing, so the control here is a caller who should be
 * refused with the deny absent, which separates "the deny works" from "nothing
 * works".
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
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
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
 * Pins the deny precedence on the single-object path.
 *
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 */
class PermissionHandlerDenyTest extends TestCase {

	/**
	 * The match evaluator, shared so a conditional deny can be steered.
	 *
	 * @var ConditionMatcher
	 */
	private ConditionMatcher $conditionMatcher;

	/**
	 * Hands out a distinct schema id per schema built in a case.
	 *
	 * @var integer
	 */
	private int $schemaCounter = 0;

	/**
	 * Hands out a distinct uuid per object built in a case.
	 *
	 * @var integer
	 */
	private int $objectCounter = 0;

	/**
	 * Build a handler for one caller and their groups.
	 *
	 * @param string|null $userId The caller, or null when anonymous.
	 * @param string[]    $groups The caller's group IDs.
	 *
	 * @return PermissionHandler The handler under test.
	 */
	private function handlerFor(?string $userId, array $groups): PermissionHandler {
		$userSession = $this->createMock(IUserSession::class);
		$userManager = $this->createMock(IUserManager::class);
		$groupManager = $this->createMock(IGroupManager::class);

		if ($userId === null) {
			$userSession->method('getUser')->willReturn(null);
			$userManager->method('get')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
			$userSession->method('getUser')->willReturn($user);
			$userManager->method('get')->willReturn($user);
			$groupManager->method('getUserGroupIds')->willReturn($groups);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(false);

		return new PermissionHandler(
			$userSession,
			$userManager,
			$groupManager,
			$this->createMock(SchemaMapper::class),
			$this->createMock(MagicMapper::class),
			$this->conditionMatcher,
			$appConfig,
			new NullLogger(),
			$this->createMock(ContainerInterface::class),
			null,
			null,
			null,
			new DenyResolver()
		);
	}//end handlerFor()

	/**
	 * A schema carrying one authorization block.
	 *
	 * Each schema gets its OWN id, and each object its own uuid, because
	 * `hasPermission()` memoises a verdict per request on
	 * `(user, schema, action, owner, uuid)`. Two schemas sharing an id inside
	 * one case would have the first verdict answer for the second, and the
	 * test would be measuring the memo rather than the rule.
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
	 * An object carrying its own block and data.
	 *
	 * @param array|null $authorization The object's `_authorization`.
	 * @param array      $data          The object's data.
	 *
	 * @return ObjectEntity The object.
	 */
	private function objectWith(?array $authorization, array $data = []): ObjectEntity {
		$this->objectCounter++;

		$object = new ObjectEntity();
		$object->setUuid(sprintf('11111111-2222-3333-4444-%012d', $this->objectCounter));
		$object->setObject($data);
		$object->setAuthorization($authorization);

		return $object;
	}//end objectWith()

	/**
	 * Reset the shared match evaluator before every case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->conditionMatcher = $this->createMock(ConditionMatcher::class);
		$this->schemaCounter = 100;
		$this->objectCounter = 0;
	}//end setUp()

	/**
	 * The baseline: the grant this whole file then takes away.
	 *
	 * Without this passing, every refusal below could be a refusal for some
	 * other reason entirely.
	 *
	 * @return void
	 */
	public function testTheGrantWorksBeforeAnyDenyIsWritten(): void {
		$handler = $this->handlerFor('ana', ['behandelaars']);
		$schema = $this->schemaWith(['read' => ['behandelaars']]);

		$this->assertTrue($handler->hasPermission($schema, 'read', 'ana'));
	}//end testTheGrantWorksBeforeAnyDenyIsWritten()

	/**
	 * A deny removes a verb a schema rule grants.
	 *
	 * @return void
	 */
	public function testADenyBeatsASchemaGrant(): void {
		$handler = $this->handlerFor('ana', ['behandelaars']);
		$schema = $this->schemaWith([
			'read' => ['behandelaars'],
			'deny' => ['read' => ['behandelaars']],
		]);

		$this->assertFalse($handler->hasPermission($schema, 'read', 'ana'));
	}//end testADenyBeatsASchemaGrant()

	/**
	 * A deny written for one of the caller's OTHER groups still reaches them.
	 *
	 * This is the scenario the spec states as "a broader grant does not restore
	 * a denied verb": the grant comes through `behandelaars`, the deny through
	 * `waarnemers`, and the caller holds both.
	 *
	 * @return void
	 */
	public function testABroaderGrantDoesNotRestoreADeniedVerb(): void {
		$handler = $this->handlerFor('ana', ['behandelaars', 'waarnemers']);
		$schema = $this->schemaWith([
			'update' => ['behandelaars'],
			'deny' => ['update' => ['waarnemers']],
		]);

		$this->assertFalse($handler->hasPermission($schema, 'update', 'ana'));
	}//end testABroaderGrantDoesNotRestoreADeniedVerb()

	/**
	 * 🔴 A deny beats the OWNER bypass.
	 *
	 * The object-level deny an author is most likely to write is one about a
	 * row somebody owns, so an owner bypass ahead of the deny would make the
	 * feature useless in its commonest case.
	 *
	 * @return void
	 */
	public function testADenyBeatsTheOwnerBypass(): void {
		$handler = $this->handlerFor('ana', ['behandelaars']);
		$schema = $this->schemaWith([
			'delete' => ['behandelaars'],
			'deny' => ['delete' => ['user:ana']],
		]);

		$this->assertFalse($handler->hasPermission($schema, 'delete', 'ana', 'ana'));
	}//end testADenyBeatsTheOwnerBypass()

	/**
	 * 🔴 An object's deny is not undone by the object replacing the schema's keys.
	 *
	 * The object's block overrides the schema's action by action. If `deny`
	 * took part in that override, an object declaring its own deny would DROP
	 * the schema's — so writing a deny would remove one.
	 *
	 * @return void
	 */
	public function testAnObjectBlockDoesNotUndoASchemaDeny(): void {
		$handler = $this->handlerFor('ana', ['behandelaars']);
		$schema = $this->schemaWith([
			'read' => ['behandelaars'],
			'deny' => ['read' => ['behandelaars']],
		]);
		$object = $this->objectWith(['deny' => ['update' => ['stagiairs']]]);

		$this->assertFalse($handler->hasPermission($schema, 'read', 'ana', null, true, $object));
	}//end testAnObjectBlockDoesNotUndoASchemaDeny()

	/**
	 * A deny written on ONE object removes the verb for that object only.
	 *
	 * This is the shape `rbac-inherits-to-children` needs from this side: the
	 * grant reaches the whole schema, and one row is taken back out of it.
	 *
	 * @return void
	 */
	public function testADenyOnOneObjectLeavesTheRestOfTheSchemaAlone(): void {
		$handler = $this->handlerFor('ana', ['behandelaars']);
		$schema = $this->schemaWith(['read' => ['behandelaars']]);

		$denied = $this->objectWith(['deny' => ['read' => ['behandelaars']]]);
		$other = $this->objectWith(null);

		$this->assertFalse($handler->hasPermission($schema, 'read', 'ana', null, true, $denied));
		$this->assertTrue($handler->hasPermission($schema, 'read', 'ana', null, true, $other));
	}//end testADenyOnOneObjectLeavesTheRestOfTheSchemaAlone()

	/**
	 * A deny reaches an anonymous caller through the `public` principal.
	 *
	 * @return void
	 */
	public function testADenyReachesAnAnonymousCaller(): void {
		$handler = $this->handlerFor(null, []);
		$schema = $this->schemaWith([
			'read' => ['public'],
			'deny' => ['read' => ['public']],
		]);

		$this->assertFalse($handler->hasPermission($schema, 'read'));
	}//end testADenyReachesAnAnonymousCaller()

	/**
	 * 🔴 An administrator is exempt, so administration cannot be denied away.
	 *
	 * @return void
	 */
	public function testAnAdministratorIsExemptFromEveryDeny(): void {
		$handler = $this->handlerFor('root', ['admin']);
		$schema = $this->schemaWith([
			'read' => ['admin'],
			'deny' => ['read' => ['admin', 'user:root']],
		]);

		$this->assertTrue($handler->hasPermission($schema, 'read', 'root'));
	}//end testAnAdministratorIsExemptFromEveryDeny()

	/**
	 * A conditional deny bites only the rows its clause selects.
	 *
	 * @return void
	 */
	public function testAConditionalDenyBitesOnlyTheMatchingRow(): void {
		$this->conditionMatcher
			->method('objectMatchesConditions')
			->willReturnCallback(
				static function (array $object, array $match): bool {
					return (($object['status'] ?? null) === ($match['status'] ?? null));
				}
			);

		$handler = $this->handlerFor('ana', ['behandelaars']);
		$schema = $this->schemaWith([
			'update' => ['behandelaars'],
			'deny' => ['update' => [['group' => 'behandelaars', 'match' => ['status' => 'gesloten']]]],
		]);

		$closed = $this->objectWith(null, ['status' => 'gesloten']);
		$open = $this->objectWith(null, ['status' => 'open']);

		$this->assertFalse($handler->hasPermission($schema, 'update', 'ana', null, true, $closed));
		$this->assertTrue($handler->hasPermission($schema, 'update', 'ana', null, true, $open));
	}//end testAConditionalDenyBitesOnlyTheMatchingRow()

	/**
	 * A conditional deny with no row in hand denies nothing.
	 *
	 * A row-scoped rule asked about the schema must not answer as a blanket
	 * one.
	 *
	 * @return void
	 */
	public function testAConditionalDenyWithNoRowDeniesNothing(): void {
		$this->conditionMatcher->method('objectMatchesConditions')->willReturn(true);

		$handler = $this->handlerFor('ana', ['behandelaars']);
		$schema = $this->schemaWith([
			'update' => ['behandelaars'],
			'deny' => ['update' => [['group' => 'behandelaars', 'match' => ['status' => 'gesloten']]]],
		]);

		$this->assertTrue($handler->hasPermission($schema, 'update', 'ana'));
	}//end testAConditionalDenyWithNoRowDeniesNothing()

	/**
	 * The LEAST-PRIVILEGED PROBE and its control.
	 *
	 * A caller in no group named anywhere is refused with the deny present AND
	 * with it absent. That separates "the deny works" from "nothing works",
	 * which is the difference a superuser success cannot show.
	 *
	 * @return void
	 */
	public function testACallerWithNoGrantIsRefusedAndTheDenyIsNotWhyItWasRefused(): void {
		$handler = $this->handlerFor('zoe', ['gasten']);

		$withDeny = $this->schemaWith([
			'read' => ['behandelaars'],
			'deny' => ['read' => ['waarnemers']],
		]);
		$withoutDeny = $this->schemaWith(['read' => ['behandelaars']]);

		$this->assertFalse($handler->hasPermission($withDeny, 'read', 'zoe'));
		$this->assertFalse($handler->hasPermission($withoutDeny, 'read', 'zoe'));
	}//end testACallerWithNoGrantIsRefusedAndTheDenyIsNotWhyItWasRefused()

	/**
	 * An instance declaring no deny resolves exactly as it did before.
	 *
	 * @return void
	 */
	public function testAnInstanceWithNoDenyIsUnchanged(): void {
		$handler = $this->handlerFor('ana', ['behandelaars']);

		// A granted group is admitted, an ungranted one is refused, the
		// `authenticated` pseudo-group still admits, and the owner bypass still
		// bypasses. A schema with NO block at all is deliberately absent: it
		// walks the register cascade, which needs a live register mapper rather
		// than a mock, and it is covered by the existing fail-closed suite.
		$this->assertTrue($handler->hasPermission($this->schemaWith(['read' => ['behandelaars']]), 'read', 'ana'));
		$this->assertFalse($handler->hasPermission($this->schemaWith(['read' => ['anderen']]), 'read', 'ana'));
		$this->assertTrue($handler->hasPermission($this->schemaWith(['read' => ['authenticated']]), 'read', 'ana'));
		$this->assertTrue($handler->hasPermission($this->schemaWith(['read' => ['anderen']]), 'read', 'ana', 'ana'));
	}//end testAnInstanceWithNoDenyIsUnchanged()

	/**
	 * The denial is reported as a rule, not only as a refusal.
	 *
	 * This is the seam provenance rides on: the resolver already knows WHICH
	 * rule decided, and the next stage only has to say it.
	 *
	 * @return void
	 */
	public function testTheDenialNamesTheRuleThatRemovedTheVerb(): void {
		$handler = $this->handlerFor('ana', ['waarnemers']);

		$denial = $handler->denialFor(
			['read' => ['behandelaars'], 'deny' => ['read' => ['waarnemers']]],
			'read',
			'ana'
		);

		$this->assertIsArray($denial);
		$this->assertSame('waarnemers', $denial['principal']);
		$this->assertSame('read', $denial['action']);
		$this->assertSame('waarnemers', $denial['rule']);
	}//end testTheDenialNamesTheRuleThatRemovedTheVerb()
}//end class

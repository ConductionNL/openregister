<?php

/**
 * The deny is IN the query, and the list agrees with the object read.
 *
 * D22, verbatim: "compiled into the query" and "checked on the result" are the
 * same sentence in English and different products in practice. A permission
 * check that runs on a fetched page gives a correct page of wrong data — the
 * total is wrong, the facet counts are wrong, and page three is missing rows
 * page two should have shown.
 *
 * So what these tests assert is the PREDICATE, not a filtered result. A test
 * that counted rows would pass just as well against a post-filter, which is
 * exactly the product this change exists to not be.
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
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
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
 * Pins the deny term the raw-SQL emitter produces.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 */
class MagicRbacHandlerDenyTest extends TestCase {

	/**
	 * The shared match evaluator, for the row-level PHP path.
	 *
	 * @var ConditionMatcher
	 */
	private ConditionMatcher $conditionMatcher;

	/**
	 * Build a handler for one caller and their groups.
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

		return new MagicRbacHandler(
			$userSession,
			$groupManager,
			$this->createMock(IUserManager::class),
			$this->createMock(IAppConfig::class),
			$this->conditionMatcher,
			$this->createMock(ContainerInterface::class),
			new NullLogger(),
			null,
			null,
			new DenyResolver()
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
		$schema = new Schema();
		$schema->setId(7);
		$schema->setTitle('Zaak');
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schemaWith()

	/**
	 * Reset the shared match evaluator before every case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->conditionMatcher = $this->createMock(ConditionMatcher::class);
	}//end setUp()

	/**
	 * The baseline: an ordinary grant emits conditions and does not bypass.
	 *
	 * @return void
	 */
	public function testTheGrantEmitsConditionsBeforeAnyDenyIsWritten(): void {
		$handler = $this->handlerFor('ana', ['behandelaars']);
		$result = $handler->buildRbacConditionsSql($this->schemaWith(['read' => ['behandelaars']]), 'read');

		$this->assertFalse($result['bypass']);
		$this->assertNotEmpty($result['conditions']);
	}//end testTheGrantEmitsConditionsBeforeAnyDenyIsWritten()

	/**
	 * 🔴 THE LEAST-PRIVILEGE PROBE, on the query rather than the result.
	 *
	 * A caller denied the action outright gets NO conditions at all, which the
	 * emitter's contract reads as deny-all: the query returns nothing, so the
	 * page is empty, the total is zero and every facet count is zero. Nothing
	 * is fetched and then filtered.
	 *
	 * The control is the line below it: the same caller, the same schema,
	 * without the deny, gets conditions. Without that line an emitter that
	 * returned nothing for everybody would pass.
	 *
	 * @return void
	 */
	public function testADeniedCallerGetsNoConditionsAndTheControlStillDoes(): void {
		$handler = $this->handlerFor('ana', ['behandelaars']);

		$denied = $handler->buildRbacConditionsSql(
			$this->schemaWith(['read' => ['behandelaars'], 'deny' => ['read' => ['behandelaars']]]),
			'read'
		);
		$this->assertSame([], $denied['conditions']);
		$this->assertFalse($denied['bypass']);

		$control = $handler->buildRbacConditionsSql($this->schemaWith(['read' => ['behandelaars']]), 'read');
		$this->assertNotEmpty($control['conditions']);
	}//end testADeniedCallerGetsNoConditionsAndTheControlStillDoes()

	/**
	 * 🔴 Every emitted condition carries the row-level deny term.
	 *
	 * The emitter's caller ORs what it is given. A term that has to hold for
	 * every row therefore has to be AND-ed into each condition — appended
	 * beside them, it would INVERT: a denied row would be admitted by the very
	 * term meant to exclude it. The owner condition is the one that matters
	 * most here, because an object-level deny is usually written about a row
	 * somebody owns.
	 *
	 * @return void
	 */
	public function testEveryConditionCarriesTheRowDenyTermIncludingTheOwnerOne(): void {
		$handler = $this->handlerFor('ana', ['behandelaars']);
		$result = $handler->buildRbacConditionsSql($this->schemaWith(['read' => ['behandelaars']]), 'read');

		$this->assertNotEmpty($result['conditions']);
		foreach ($result['conditions'] as $condition) {
			$this->assertStringContainsString('$.deny.read', $condition, 'A condition reached the OR without the deny term.');
		}

		$ownerConditions = array_filter(
			$result['conditions'],
			static fn (string $condition): bool => str_contains($condition, '_owner')
		);
		$this->assertNotEmpty($ownerConditions, 'The owner condition disappeared; the fixture no longer tests what it claims.');
	}//end testEveryConditionCarriesTheRowDenyTermIncludingTheOwnerOne()

	/**
	 * The term names the caller's principals, so it can only deny THEM.
	 *
	 * @return void
	 */
	public function testTheTermNamesTheCallersOwnPrincipals(): void {
		$handler = $this->handlerFor('ana', ['behandelaars', 'waarnemers']);
		$condition = $handler->buildRbacConditionsSql($this->schemaWith(['read' => ['behandelaars']]), 'read')['conditions'][0];

		$this->assertStringContainsString('"behandelaars"', $condition);
		$this->assertStringContainsString('"waarnemers"', $condition);
		$this->assertStringContainsString('"user:ana"', $condition);
		$this->assertStringContainsString('"public"', $condition);
	}//end testTheTermNamesTheCallersOwnPrincipals()

	/**
	 * The term is scoped to the action being filtered.
	 *
	 * @return void
	 */
	public function testTheTermIsScopedToTheActionBeingFiltered(): void {
		$handler = $this->handlerFor('ana', ['behandelaars']);
		$condition = $handler->buildRbacConditionsSql(
			$this->schemaWith(['update' => ['behandelaars']]),
			'update'
		)['conditions'][0];

		$this->assertStringContainsString('$.deny.update', $condition);
		$this->assertStringNotContainsString('$.deny.read', $condition);
	}//end testTheTermIsScopedToTheActionBeingFiltered()

	/**
	 * A schema with no block at all still carries the term.
	 *
	 * A deny may be written on an OBJECT, so the schemas that configure nothing
	 * — and are therefore watched least — are exactly the ones a skipped term
	 * would leak on. This is the same reasoning that puts the private-scope
	 * predicate on every list query.
	 *
	 * @return void
	 */
	public function testASchemaWithNoBlockStillCarriesTheTerm(): void {
		$handler = $this->handlerFor('ana', ['behandelaars']);
		$result = $handler->buildRbacConditionsSql($this->schemaWith(null), 'read');

		$this->assertNotEmpty($result['conditions']);
		foreach ($result['conditions'] as $condition) {
			$this->assertStringContainsString('$.deny.read', $condition);
		}
	}//end testASchemaWithNoBlockStillCarriesTheTerm()

	/**
	 * An administrator still bypasses everything, deny included.
	 *
	 * @return void
	 */
	public function testAnAdministratorStillBypasses(): void {
		$handler = $this->handlerFor('root', ['admin']);
		$result = $handler->buildRbacConditionsSql(
			$this->schemaWith(['read' => ['admin'], 'deny' => ['read' => ['admin']]]),
			'read'
		);

		$this->assertTrue($result['bypass']);
	}//end testAnAdministratorStillBypasses()

	/**
	 * 🔴 The list and the per-object read agree on the same object.
	 *
	 * A denied row that still appeared in a list is the worst of both answers:
	 * the caller sees the row, learns the identifier, and is refused on the
	 * read. This asserts the two halves of that sentence in one case.
	 *
	 * @return void
	 */
	public function testTheListAndTheObjectReadAgreeOnADeniedRow(): void {
		$handler = $this->handlerFor('ana', ['behandelaars']);
		$schema = $this->schemaWith(['read' => ['behandelaars']]);

		// The object read refuses the denied row.
		$this->assertFalse(
			$handler->hasPermission(
				schema: $schema,
				action: 'read',
				objectOwner: 'ana',
				objectData: ['title' => 'geheim'],
				objectAuthorization: ['deny' => ['read' => ['behandelaars']]],
				objectUuid: 'aaaaaaaa-0000-0000-0000-000000000001'
			)
		);

		// And the same caller reads an undenied row of the same schema, so the
		// refusal above is about the deny and not about the caller.
		$this->assertTrue(
			$handler->hasPermission(
				schema: $schema,
				action: 'read',
				objectOwner: 'ana',
				objectData: ['title' => 'gewoon'],
				objectAuthorization: null,
				objectUuid: 'aaaaaaaa-0000-0000-0000-000000000002'
			)
		);

		// And the list carries a term that can exclude exactly that row.
		$condition = $handler->buildRbacConditionsSql($schema, 'read')['conditions'][0];
		$this->assertStringContainsString('$.deny.read', $condition);
		$this->assertStringContainsString('"behandelaars"', $condition);
	}//end testTheListAndTheObjectReadAgreeOnADeniedRow()

	/**
	 * A deny on the row beats the owner bypass on the object path too.
	 *
	 * @return void
	 */
	public function testTheRowDenyBeatsTheOwnerBypassOnTheObjectPath(): void {
		$handler = $this->handlerFor('ana', ['behandelaars']);

		$this->assertFalse(
			$handler->hasPermission(
				schema: $this->schemaWith(['delete' => ['behandelaars']]),
				action: 'delete',
				objectOwner: 'ana',
				objectData: ['title' => 'van mij'],
				objectAuthorization: ['deny' => ['delete' => ['user:ana']]],
				objectUuid: 'aaaaaaaa-0000-0000-0000-000000000003'
			)
		);
	}//end testTheRowDenyBeatsTheOwnerBypassOnTheObjectPath()

	/**
	 * A conditional deny this emitter cannot compile denies the whole list.
	 *
	 * Over-denying is visible the moment somebody looks for a row. The other
	 * direction leaks, and is not.
	 *
	 * @return void
	 */
	public function testAnUncompilableConditionalDenyDeniesTheWholeList(): void {
		$handler = $this->handlerFor('ana', ['behandelaars']);
		$result = $handler->buildRbacConditionsSql(
			$this->schemaWith(
				[
					'read' => ['behandelaars'],
					'deny' => ['read' => [['group' => 'behandelaars', 'match' => []]]],
				]
			),
			'read'
		);

		$this->assertSame([], $result['conditions']);
	}//end testAnUncompilableConditionalDenyDeniesTheWholeList()

	/**
	 * The entity the row predicate is written against still has the column.
	 *
	 * A guard against the quiet version of this going wrong: the term reads
	 * `_authorization`, and a rename of that column would leave the predicate
	 * syntactically fine and semantically empty.
	 *
	 * @return void
	 */
	public function testTheObjectEntityStillCarriesTheAuthorizationColumn(): void {
		$object = new ObjectEntity();
		$object->setAuthorization(['deny' => ['read' => ['behandelaars']]]);

		$this->assertSame(['deny' => ['read' => ['behandelaars']]], $object->getAuthorization());
	}//end testTheObjectEntityStillCarriesTheAuthorizationColumn()
}//end class

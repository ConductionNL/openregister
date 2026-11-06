<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Whether a stored row lies inside the caller's organisations, decided on the row's own organisation.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\OrganisationScopeMembership;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @covers \OCA\OpenRegister\Db\MagicMapper\OrganisationScopeMembership
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class CallerOrganisationsHoldTest extends TestCase {

	/**
	 * The scope arguments the mapper asked the organisation handler for.
	 *
	 * @var array<int, array{0: bool, 1: int|null, 2: int|null}>
	 */
	private array $asked = [];

	/**
	 * A mapper whose organisation handler decides the given scope.
	 *
	 * @param array{mode: string, uuids: array<int, string>}|null $scope The scope, null for no handler.
	 *
	 * @return MagicMapper
	 */
	private function mapper(?array $scope, bool $adminOverride = true): MagicMapper {
		$class = new ReflectionClass(MagicMapper::class);
		$mapper = $class->newInstanceWithoutConstructor();
		if ($scope === null) {
			return $mapper;
		}

		$handler = $this->createMock(MagicOrganizationHandler::class);
		$handler->method('isAdminOverrideEnabled')->willReturn($adminOverride);
		$handler->method('resolveOrganizationScope')->willReturnCallback(
			function (bool $adminBypassEnabled = false, ?int $registerId = null, ?int $schemaId = null) use ($scope): array {
				$this->asked[] = [$adminBypassEnabled, $registerId, $schemaId];
				return $scope;
			}
		);
		$property = $class->getProperty('organizationHandler');
		$property->setAccessible(true);
		$property->setValue($mapper, $handler);

		return $mapper;
	}//end mapper()

	private static function row(?string $organisation, ?string $register = '10', ?string $schema = '681'): ObjectEntity {
		$row = new ObjectEntity();
		$row->setUuid('22222222-2222-2222-2222-222222222222');
		$row->setOrganisation($organisation);
		$row->setRegister($register);
		$row->setSchema($schema);
		return $row;
	}//end row()

	public function testARowOfAnotherOrganisationIsNotHeld(): void {
		$mapper = $this->mapper(scope: ['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-a']]);

		$this->assertFalse($mapper->callerOrganisationsHold(self::row(organisation: 'org-b')));
	}

	public function testARowOfTheCallersOrganisationIsHeld(): void {
		$mapper = $this->mapper(scope: ['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-a', 'org-parent']]);

		$this->assertTrue($mapper->callerOrganisationsHold(self::row(organisation: 'org-parent')));
	}

	public function testTheRowsRegisterAndSchemaNameTheTableAndTheAdminBypassIsAsked(): void {
		$mapper = $this->mapper(scope: ['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-a']], adminOverride: false);

		$mapper->callerOrganisationsHold(self::row(organisation: 'org-a', register: '12', schema: '34'));
		$mapper->callerOrganisationsHold(self::row(organisation: 'org-a', register: 'reg-slug', schema: null));

		$this->assertSame([[false, 12, 34], [false, null, null]], $this->asked);
	}

	public function testWithoutAnOrganisationHandlerNothingIsHeld(): void {
		$this->assertFalse($this->mapper(scope: null)->callerOrganisationsHold(self::row(organisation: 'org-a')));
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, string>, 2: string|null, 3: bool}>
	 */
	public static function scopes(): array {
		return [
			'all holds another organisation' => [MagicOrganizationHandler::SCOPE_ALL, [], 'org-b', true],
			'all holds an organisation-less row' => [MagicOrganizationHandler::SCOPE_ALL, [], null, true],
			'none holds nothing' => [MagicOrganizationHandler::SCOPE_NONE, ['org-b'], 'org-b', false],
			'none holds no organisation-less row' => [MagicOrganizationHandler::SCOPE_NONE, [], null, false],
			'null-only holds an organisation-less row' => [MagicOrganizationHandler::SCOPE_NULL_ONLY, [], null, true],
			'null-only holds no empty organisation' => [MagicOrganizationHandler::SCOPE_NULL_ONLY, [], '', false],
			'in-or-null holds no empty organisation' => [MagicOrganizationHandler::SCOPE_IN_OR_NULL, ['org-a'], '', false],
			'null-only holds no organisation row' => [MagicOrganizationHandler::SCOPE_NULL_ONLY, ['org-a'], 'org-a', false],
			'in holds a listed organisation' => [MagicOrganizationHandler::SCOPE_IN, ['org-a'], 'org-a', true],
			'in holds no other organisation' => [MagicOrganizationHandler::SCOPE_IN, ['org-a'], 'org-b', false],
			'in holds no organisation-less row' => [MagicOrganizationHandler::SCOPE_IN, ['org-a'], null, false],
			'in compares exactly' => [MagicOrganizationHandler::SCOPE_IN, ['org-a'], 'ORG-A', false],
			'in-or-null holds a listed organisation' => [MagicOrganizationHandler::SCOPE_IN_OR_NULL, ['org-a'], 'org-a', true],
			'in-or-null holds an organisation-less row' => [MagicOrganizationHandler::SCOPE_IN_OR_NULL, ['org-a'], null, true],
			'in-or-null holds no other organisation' => [MagicOrganizationHandler::SCOPE_IN_OR_NULL, ['org-a'], 'org-b', false],
			'an unknown mode holds nothing' => ['bogus', ['org-a'], 'org-a', false],
			'an unknown mode holds no organisation-less row' => ['bogus', [], null, false],
		];
	}//end scopes()

	/**
	 * @dataProvider scopes
	 *
	 * @param array<int, string> $uuids
	 */
	public function testTheScopeHoldsTheRowAsTheReadFilterWould(string $mode, array $uuids, ?string $organisation, bool $held): void {
		$this->assertSame($held, OrganisationScopeMembership::holds(['mode' => $mode, 'uuids' => $uuids], $organisation));
	}

	public function testAScopeWithoutUuidsHoldsNoOrganisation(): void {
		$this->assertFalse(OrganisationScopeMembership::holds(['mode' => MagicOrganizationHandler::SCOPE_IN], 'org-a'));
	}
}//end class

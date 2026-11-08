<?php

/**
 * Golden (characterisation) matrix for ReferenceTenantGuard.
 *
 * Pins which referenced objects are ADMITTED into a calculation and which
 * are REFUSED, the lookups each decision makes (parent chain, master-data
 * holders and their arguments), the order the rules are tried in and the
 * exact log lines, so the guard can be restructured without moving a
 * single decision.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Calculation;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Service\Calculation\ReferenceTenantGuard;
use OCA\OpenRegister\Service\SharedMasterDataService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every branch of admits() and firstAdmitted(), pinned.
 *
 * @covers \OCA\OpenRegister\Service\Calculation\ReferenceTenantGuard
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
final class ReferenceTenantGuardGoldenTest extends TestCase {

	/**
	 * What the collaborators were asked and said, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * A guard over a described world.
	 *
	 * @param array<int, mixed>|null $parents The parent chain, or null when reading it throws.
	 * @param array<int, string>     $holders The master-data holders answered.
	 *
	 * @return ReferenceTenantGuard The guard.
	 */
	private function guard(?array $parents, array $holders): ReferenceTenantGuard {
		$organisations = $this->createMock(OrganisationMapperInterface::class);
		$organisations->method('findParentChain')->willReturnCallback(
			function (string $organisationUuid) use ($parents): array {
				$this->calls[] = ['chain', $organisationUuid];
				if ($parents === null) {
					throw new RuntimeException('chain gone');
				}

				return $parents;
			}
		);

		$shared = $this->createMock(SharedMasterDataService::class);
		$shared->method('holdersForResource')->willReturnCallback(
			function (?int $registerId, ?int $schemaId, array $consumerOrgUuids) use ($holders): array {
				$this->calls[] = ['holders', $registerId, $schemaId, $consumerOrgUuids];

				return $holders;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message): void {
				$this->calls[] = ['info', $message];
			}
		);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->calls[] = ['warning', $message];
			}
		);

		return new ReferenceTenantGuard($organisations, $shared, $logger);
	}//end guard()

	/**
	 * A referenced object.
	 *
	 * @param string|null $organisation Its organisation.
	 * @param mixed       $register     Its register reference.
	 * @param mixed       $schema       Its schema reference.
	 *
	 * @return ObjectEntity The object.
	 */
	private static function object(?string $organisation, mixed $register=null, mixed $schema=null): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('ref-1');
		$object->setOrganisation($organisation);
		$object->setRegister($register);
		$object->setSchema($schema);

		return $object;
	}//end object()

	/**
	 * Every admits() branch: saving org, referenced org, register, schema,
	 * parents (null = unreadable), holders, expected answer, expected calls.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function cases(): array {
		$refused = static fn (string $saving): array => [
			'info',
			sprintf('Calculation reference to ref-1 (organisation "B") refused for an object in organisation "%s".', $saving),
		];

		return [
			'org-less reference, org-less saver' => [null, null, null, null, [], [], true, []],
			'empty-org reference' => ['A', '', null, null, [], [], true, []],
			'org-less saver claims an owned row' => [null, 'B', null, null, [], [], false, [$refused('')]],
			'empty saver claims an owned row' => ['', 'B', null, null, [], [], false, [$refused('')]],
			'same organisation' => ['B', 'B', null, null, [], [], true, []],
			'parent organisation' => ['A', 'B', null, null, ['P', 'B'], [], true, [['chain', 'A']]],
			'shared master data, int ids' => [
				'A', 'B', 4, 7, ['P', 12, null], ['B'], true,
				[['chain', 'A'], ['holders', 4, 7, ['A', 'P']]],
			],
			'shared master data, digit-string ids' => [
				'A', 'B', '4', '07', [], ['X', 'B'], true,
				[['chain', 'A'], ['holders', 4, 7, ['A']]],
			],
			'non-numeric ids are null' => [
				'A', 'B', 'reg-uuid', '-3', [], [], false,
				[['chain', 'A'], ['holders', null, null, ['A']], $refused('A')],
			],
			'unreadable chain narrows to the saver' => [
				'A', 'B', null, null, null, [], false,
				[
					['chain', 'A'],
					['warning', 'Calculation reference guard: parent chain of "A" unreadable: chain gone'],
					['holders', null, null, ['A']],
					$refused('A'),
				],
			],
			'other tenant' => [
				'A', 'B', 1, 2, ['P'], ['Q'], false,
				[['chain', 'A'], ['holders', 1, 2, ['A', 'P']], $refused('A')],
			],
		];
	}//end cases()

	/**
	 * Every admits() outcome, and the lookups and log lines it makes, are pinned.
	 *
	 * @param string|null            $saving   Saving organisation.
	 * @param string|null            $refOrg   Referenced organisation.
	 * @param mixed                  $register Referenced register.
	 * @param mixed                  $schema   Referenced schema.
	 * @param array<int, mixed>|null $parents  Parent chain or null.
	 * @param array<int, string>     $holders  Holders.
	 * @param boolean                $expected Admitted or not.
	 * @param array<int, mixed>      $calls    Expected calls.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testAdmits(
		?string $saving,
		?string $refOrg,
		mixed $register,
		mixed $schema,
		?array $parents,
		array $holders,
		bool $expected,
		array $calls
	): void {
		$guard = $this->guard(parents: $parents, holders: $holders);

		$this->assertSame($expected, $guard->admits(savingOrganisation: $saving, referenced: self::object($refOrg, $register, $schema)));
		$this->assertSame($calls, $this->calls);
	}//end testAdmits()

	/**
	 * firstAdmitted() skips non-entities, keeps order, returns null when none.
	 *
	 * @return void
	 */
	public function testFirstAdmitted(): void {
		$guard = $this->guard(parents: [], holders: []);
		$other = self::object('B');
		$mine  = self::object('A');
		$free  = self::object(null);

		$this->assertSame($mine, $guard->firstAdmitted(savingOrganisation: 'A', candidates: [null, 'row', $other, $mine, $free]));
		$this->assertSame($free, $guard->firstAdmitted(savingOrganisation: 'A', candidates: ['k' => $free, 'm' => $mine]));
		$this->assertNull($guard->firstAdmitted(savingOrganisation: 'A', candidates: [$other, ['organisation' => 'A']]));
		$this->assertNull($guard->firstAdmitted(savingOrganisation: null, candidates: []));
	}//end testFirstAdmitted()
}//end class

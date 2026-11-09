<?php

/**
 * Golden test: the soft-uniqueness alert names only holders the caller may
 * read, reports a hidden holder's existence without naming it, and runs the
 * scoped read with RBAC and tenancy on and the existence read with both off.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Quality;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Search\Service\Quality\UniqueHintAnnotationValidator;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Quality\UniqueHintChecker;
use OCA\OpenRegister\Service\Quality\UniqueHintWarnings;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the allowed and refused disclosure of {@see UniqueHintChecker}.
 *
 * @covers \OCA\OpenRegister\Service\Quality\UniqueHintChecker
 */
class UniqueHintCheckerAccessGoldenTest extends TestCase {

	/**
	 * Every findAll call: [query, _rbac, _multitenancy].
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: mixed, 2: mixed}>
	 */
	private array $reads = [];

	/**
	 * A checker whose scoped read returns $visible and whose unscoped read returns $all.
	 *
	 * @param array<int, string> $visible Uuids the caller may read.
	 * @param array<int, string> $all     Uuids that exist.
	 *
	 * @return array{0: UniqueHintChecker, 1: UniqueHintWarnings}
	 */
	private function checker(array $visible, array $all): array {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $query, $rbac = true, $multitenancy = true) use ($visible, $all): array {
				$this->reads[] = [$query, $rbac, $multitenancy];
				$uuids = $all;
				if ($rbac === true) {
					$uuids = $visible;
				}

				return array_map(
					static function (string $uuid): ObjectEntity {
						$object = new ObjectEntity();
						$object->setUuid($uuid);
						return $object;
					},
					$uuids
				);
			}
		);

		$warnings = new UniqueHintWarnings();
		$checker  = new UniqueHintChecker(
			$objects,
			new UniqueHintAnnotationValidator(),
			$warnings,
			$this->createMock(LoggerInterface::class)
		);

		return [$checker, $warnings];
	}//end checker()

	/**
	 * The nomination of `bsn`.
	 *
	 * @return array<string, mixed>
	 */
	private function configuration(): array {
		return ['x-openregister-unique-hint' => ['bsn']];
	}//end configuration()

	/**
	 * Allowed: a visible holder is named, and both reads carry the same filter.
	 *
	 * @return void
	 */
	public function testAVisibleHolderIsNamedAndTheReadsAreScopedThenUnscoped(): void {
		[$checker, $warnings] = $this->checker(['a', 'b'], ['a', 'b', 'hidden']);

		$checker->check($this->configuration(), 4, 'person', ['bsn' => 123], 'b');

		$this->assertSame(
			[['property' => 'bsn', 'matches' => ['a'], 'visible' => true]],
			$warnings->drain()
		);
		$query = ['filters' => ['register' => 4, 'schema' => 'person', 'bsn' => 123], 'limit' => 1000];
		$this->assertSame([[$query, true, true], [$query, false, false]], $this->reads);
	}//end testAVisibleHolderIsNamedAndTheReadsAreScopedThenUnscoped()

	/**
	 * Refused: a holder the caller may not read is reported, never named.
	 *
	 * @return void
	 */
	public function testAHiddenHolderIsReportedWithoutItsUuid(): void {
		[$checker, $warnings] = $this->checker([], ['hidden']);

		$checker->check($this->configuration(), 4, 'person', ['bsn' => '123']);

		$this->assertSame(
			[['property' => 'bsn', 'matches' => [], 'visible' => false]],
			$warnings->drain()
		);
	}//end testAHiddenHolderIsReportedWithoutItsUuid()

	/**
	 * The object being written is not a collision with itself, and an empty
	 * value is never looked up.
	 *
	 * @return void
	 */
	public function testSelfAndEmptyValuesWarnNothing(): void {
		[$checker, $warnings] = $this->checker(['self'], ['self']);

		$checker->check($this->configuration(), 4, 'person', ['bsn' => '123'], 'self');
		$checker->check($this->configuration(), 4, 'person', ['bsn' => '']);

		$this->assertSame([], $warnings->drain());
		$this->assertCount(2, $this->reads);
	}//end testSelfAndEmptyValuesWarnNothing()
}//end class

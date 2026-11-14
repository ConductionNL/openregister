<?php

/**
 * The preservation (statisch) regime: the schema offering it, an object being
 * in it, and the content-write refusal it produces (REQ-APS-006). Distinct
 * from the archive state.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md#requirement-a-preservation-regime-sits-between-active-and-transferred-req-aps-006
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\ObjectEntity
 * @covers \OCA\OpenRegister\Db\Schema
 * @covers \OCA\OpenRegister\Exception\ObjectStateWriteException
 * @covers \OCA\OpenRegister\Exception\ObjectStateMarker
 */
final class PreservationStateTest extends TestCase {

	/**
	 * A schema with the given configuration.
	 *
	 * @param array $configuration The schema configuration.
	 *
	 * @return Schema
	 */
	private static function schema(array $configuration): Schema {
		$schema = new Schema();
		$schema->setConfiguration($configuration);

		return $schema;
	}

	/**
	 * An object whose archival status is the given value.
	 *
	 * @param string|null $status The archiefstatus, or null for none.
	 *
	 * @return ObjectEntity
	 */
	private static function object(?string $status): ObjectEntity {
		$object = new ObjectEntity();
		if ($status !== null) {
			$object->setTmlo(['archiefstatus' => $status]);
		}

		return $object;
	}

	/**
	 * Only `x-openregister-preserve` with `enabled: true` offers the regime.
	 *
	 * @return void
	 */
	public function testOnlyAnEnabledPreserveAnnotationOffersTheRegime(): void {
		$this->assertTrue(self::schema(['x-openregister-preserve' => ['enabled' => true]])->isPreservationEnabled());
		$this->assertFalse(self::schema(['x-openregister-preserve' => ['enabled' => false]])->isPreservationEnabled());
		$this->assertFalse(self::schema(['x-openregister-preserve' => true])->isPreservationEnabled());
		$this->assertFalse(self::schema([])->isPreservationEnabled());
	}

	/**
	 * The archive and preserve annotations are independent; a schema may offer either, both or neither.
	 *
	 * @return void
	 */
	public function testTheArchiveAndPreserveAnnotationsAreIndependent(): void {
		$schema = self::schema(['x-openregister-archive' => ['enabled' => true]]);
		$this->assertTrue($schema->isArchivingEnabled());
		$this->assertFalse($schema->isPreservationEnabled(), 'the archive annotation does not offer preservation');

		$both = self::schema(['x-openregister-archive' => ['enabled' => true], 'x-openregister-preserve' => ['enabled' => true]]);
		$this->assertTrue($both->isArchivingEnabled());
		$this->assertTrue($both->isPreservationEnabled());
	}

	/**
	 * @return array<string,array{0: ?string, 1: bool}>
	 */
	public static function statuses(): array {
		return [
			'semi_static'   => ['semi_static', true],
			'semi_statisch' => ['semi_statisch', true],
			'gearchiveerd'  => ['Gearchiveerd', true],
			'actief'        => ['actief', false],
			'overgebracht'  => ['overgebracht', false],
			'vernietigd'    => ['vernietigd', false],
			'none'          => [null, false],
		];
	}

	/**
	 * An object is preserved only in a semi-static archival status.
	 *
	 * @param string|null $status    The archiefstatus.
	 * @param bool        $preserved Whether the object is then preserved.
	 *
	 * @return void
	 */
	#[DataProvider('statuses')]
	public function testAnObjectIsPreservedOnlyInASemiStaticStatus(?string $status, bool $preserved): void {
		$this->assertSame($preserved, self::object($status)->isPreserved());
	}

	/**
	 * Preservation and the archive state are distinct: neither implies the other.
	 *
	 * @return void
	 */
	public function testPreservationIsDistinctFromTheArchiveState(): void {
		$preserved = self::object('semi_statisch');
		$this->assertTrue($preserved->isPreserved());
		$this->assertFalse($preserved->isArchived(), 'a preserved object is not archived');

		$archived = self::object(null);
		$archived->setArchived(['by' => 'anna', 'at' => '2026-01-01T00:00:00+00:00']);
		$this->assertTrue($archived->isArchived());
		$this->assertFalse($archived->isPreserved(), 'an archived object is not preserved');
	}

	/**
	 * The preserved refusal names the state and that references still resolve.
	 *
	 * @return void
	 */
	public function testThePreservedRefusalNamesTheStateAndThatReferencesResolve(): void {
		$refusal = ObjectStateWriteException::preserved(self::object('semi_statisch'));

		$this->assertSame('preserved', $refusal->getState());
		$this->assertSame(409, $refusal->getCode());
		$this->assertStringContainsString('preservation', $refusal->getMessage());
		$this->assertStringContainsString('references still resolve', $refusal->getMessage());
		$this->assertStringContainsString('readable', $refusal->getMessage());
	}

	/**
	 * The refusal's response body carries the preserved state.
	 *
	 * @return void
	 */
	public function testTheRefusalBodyCarriesTheState(): void {
		$body = ObjectStateWriteException::preserved(self::object('semi_statisch'))->toResponseBody();

		$this->assertSame('preserved', $body['state']);
		$this->assertArrayHasKey('error', $body);
	}
}

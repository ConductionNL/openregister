<?php

/**
 * The preservation lens: a preserved object leaves the working views on a
 * schema that offers the regime, and a caller asks for it explicitly.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md#requirement-a-preservation-regime-sits-between-active-and-transferred-req-aps-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\PreservedListing;
use OCA\OpenRegister\Service\Object\PreservedListLens;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * REQ-APS-006: in the preservation state an object leaves the working views.
 */
#[CoversClass(PreservedListLens::class)]
#[CoversClass(PreservedListing::class)]
#[UsesClass(Schema::class)]
final class PreservedListLensTest extends TestCase {

	/**
	 * A schema that offers the regime, or one that does not.
	 *
	 * @param bool $offers Whether it offers the regime.
	 *
	 * @return Schema
	 */
	private static function schema(bool $offers): Schema {
		$schema = new Schema();
		$configuration = [];
		if ($offers === true) {
			$configuration[Schema::PRESERVE_ANNOTATION] = ['enabled' => true];
		}

		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()

	/**
	 * The clause for a mode on a platform.
	 *
	 * @param string $mode     The mode.
	 * @param string $platform The platform.
	 * @param bool   $offers   Whether the schema offers the regime.
	 * @param bool   $missing  Whether the table lacks `_tmlo`.
	 *
	 * @return string|null
	 */
	private static function clause(string $mode, string $platform = 'postgres', bool $offers = true, bool $missing = false): ?string {
		return (new PreservedListLens())->clauseFor(
			listing: new PreservedListing(mode: $mode, schema: self::schema(offers: $offers), platform: $platform, columnMissing: $missing),
			columnPrefix: 't.'
		);
	}//end clause()

	/**
	 * Without the parameter a list is the working set.
	 *
	 * @return void
	 */
	public function testNoParameterMeansTheWorkingSet(): void {
		$this->assertSame(PreservedListLens::EXCLUDE, (new PreservedListLens())->modeFor(query: ['_limit' => 20]));
	}//end testNoParameterMeansTheWorkingSet()

	/**
	 * Each spelling a query string sends names its lens; anything else is the working set.
	 *
	 * @param mixed  $value    The parameter value.
	 * @param string $expected The mode.
	 *
	 * @return void
	 */
	#[DataProvider('spellings')]
	public function testEachSpellingNamesItsLens(mixed $value, string $expected): void {
		$this->assertSame($expected, (new PreservedListLens())->modeFor(query: [PreservedListLens::PARAMETER => $value]));
	}//end testEachSpellingNamesItsLens()

	/**
	 * The spellings and the lens each names.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function spellings(): array {
		return [
			'true' => ['true', PreservedListLens::ONLY],
			'the boolean' => [true, PreservedListLens::ONLY],
			'the digit' => ['1', PreservedListLens::ONLY],
			'any' => ['any', PreservedListLens::ANY],
			'ANY with spaces' => [' ANY ', PreservedListLens::ANY],
			'false' => ['false', PreservedListLens::EXCLUDE],
			'a typo' => ['al', PreservedListLens::EXCLUDE],
			'empty' => ['', PreservedListLens::EXCLUDE],
			'null' => [null, PreservedListLens::EXCLUDE],
			'an array' => [['true'], PreservedListLens::EXCLUDE],
		];
	}//end spellings()

	/**
	 * The working set keeps every row whose archival status is not a preserved one,
	 * a row without a status included.
	 *
	 * @return void
	 */
	public function testTheWorkingSetLeavesPreservedRowsOut(): void {
		$this->assertSame(
			"COALESCE(LOWER(t._tmlo::jsonb ->> 'archiefstatus'), '') NOT IN ('semi_static', 'semi_statisch', 'gearchiveerd')",
			self::clause(mode: PreservedListLens::EXCLUDE)
		);
	}//end testTheWorkingSetLeavesPreservedRowsOut()

	/**
	 * The preserved lens holds the preserved rows alone.
	 *
	 * @return void
	 */
	public function testThePreservedLensHoldsPreservedRowsAlone(): void {
		$this->assertSame(
			"LOWER(t._tmlo::jsonb ->> 'archiefstatus') IN ('semi_static', 'semi_statisch', 'gearchiveerd')",
			self::clause(mode: PreservedListLens::ONLY)
		);
	}//end testThePreservedLensHoldsPreservedRowsAlone()

	/**
	 * Both at once puts no condition on the list.
	 *
	 * @return void
	 */
	public function testAnyPutsNoCondition(): void {
		$this->assertNull(self::clause(mode: PreservedListLens::ANY));
		$this->assertNull(self::clause(mode: PreservedListLens::ANY, offers: false));
	}//end testAnyPutsNoCondition()

	/**
	 * Each platform reads the JSON member in its own spelling, guarded where
	 * an invalid document would otherwise fail the query.
	 *
	 * @return void
	 */
	public function testEachPlatformReadsTheStatusInItsOwnSpelling(): void {
		$this->assertSame(
			"COALESCE(LOWER(CASE WHEN json_valid(t._tmlo) THEN json_extract(t._tmlo, '$.archiefstatus') END), '') NOT IN ('semi_static', 'semi_statisch', 'gearchiveerd')",
			self::clause(mode: PreservedListLens::EXCLUDE, platform: 'sqlite')
		);
		$this->assertSame(
			"LOWER(CASE WHEN JSON_VALID(t._tmlo) THEN JSON_UNQUOTE(JSON_EXTRACT(t._tmlo, '$.archiefstatus')) END) IN ('semi_static', 'semi_statisch', 'gearchiveerd')",
			self::clause(mode: PreservedListLens::ONLY, platform: 'mysql')
		);
	}//end testEachPlatformReadsTheStatusInItsOwnSpelling()

	/**
	 * On a schema that does not offer the regime, or a table without `_tmlo`,
	 * no row is preserved: the working set is every row, the preserved lens empty.
	 *
	 * @return void
	 */
	public function testWithoutTheRegimeNoRowIsPreserved(): void {
		$this->assertNull(self::clause(mode: PreservedListLens::EXCLUDE, offers: false));
		$this->assertSame('1=0', self::clause(mode: PreservedListLens::ONLY, offers: false));
		$this->assertNull(self::clause(mode: PreservedListLens::EXCLUDE, missing: true));
		$this->assertSame('1=0', self::clause(mode: PreservedListLens::ONLY, missing: true));
		$this->assertNull(
			(new PreservedListLens())->clauseFor(
				listing: new PreservedListing(mode: PreservedListLens::EXCLUDE, schema: null, platform: 'postgres'),
				columnPrefix: ''
			)
		);
	}//end testWithoutTheRegimeNoRowIsPreserved()

	/**
	 * The list and the single read agree on which statuses are preserved.
	 *
	 * @return void
	 */
	public function testTheLensAndTheObjectShareTheStatuses(): void {
		foreach (ObjectEntity::PRESERVED_ARCHIVAL_STATUSES as $state) {
			$this->assertStringContainsString("'" . $state . "'", (string)self::clause(mode: PreservedListLens::ONLY));
		}
	}//end testTheLensAndTheObjectShareTheStatuses()
}//end class

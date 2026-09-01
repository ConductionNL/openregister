<?php

/**
 * Unit tests for retired-schema unlinking.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\PruneRetiredSchemasCommand;
use PHPUnit\Framework\TestCase;

/**
 * Locks unlinkSchemaId(): every stored form of an id goes, everything else stays.
 */
class PruneRetiredSchemasCommandTest extends TestCase {

	/**
	 * The plain integer form is removed.
	 *
	 * @return void
	 */
	public function testRemovesTheIntegerForm(): void {
		$this->assertSame(
			[159, 161],
			PruneRetiredSchemasCommand::unlinkSchemaId(schemaRefs: [74, 159, 161], schemaId: 74)
		);

	}//end testRemovesTheIntegerForm()

	/**
	 * The string form is the same reference and must go too.
	 *
	 * A strict comparison would keep "74" and leave the register pointing at a
	 * row cascadeDeleteSchema() has just removed.
	 *
	 * @return void
	 */
	public function testRemovesTheStringForm(): void {
		$this->assertSame(
			['159', 161],
			PruneRetiredSchemasCommand::unlinkSchemaId(schemaRefs: ['74', '159', 161], schemaId: 74)
		);

	}//end testRemovesTheStringForm()

	/**
	 * Both forms in one list are removed together.
	 *
	 * @return void
	 */
	public function testRemovesBothFormsAtOnce(): void {
		$this->assertSame(
			[159],
			PruneRetiredSchemasCommand::unlinkSchemaId(schemaRefs: [74, '74', 159], schemaId: 74)
		);

	}//end testRemovesBothFormsAtOnce()

	/**
	 * A different id that merely starts with the same digits is kept.
	 *
	 * @return void
	 */
	public function testKeepsIdsThatOnlyLookSimilar(): void {
		$this->assertSame(
			[7, 740, '7400'],
			PruneRetiredSchemasCommand::unlinkSchemaId(schemaRefs: [7, 740, '7400', 74], schemaId: 74)
		);

	}//end testKeepsIdsThatOnlyLookSimilar()

	/**
	 * Non-numeric entries are preserved: they are not this id, and dropping
	 * them would corrupt the register's list.
	 *
	 * @return void
	 */
	public function testKeepsNonNumericEntries(): void {
		$this->assertSame(
			['some-slug', 159],
			PruneRetiredSchemasCommand::unlinkSchemaId(schemaRefs: ['some-slug', 74, 159], schemaId: 74)
		);

	}//end testKeepsNonNumericEntries()

	/**
	 * A list that never referenced the id comes back unchanged, and reindexed.
	 *
	 * @return void
	 */
	public function testUnreferencedListIsUnchanged(): void {
		$this->assertSame(
			[159, 161],
			PruneRetiredSchemasCommand::unlinkSchemaId(schemaRefs: [159, 161], schemaId: 74)
		);

	}//end testUnreferencedListIsUnchanged()

	/**
	 * An empty list stays empty rather than throwing.
	 *
	 * @return void
	 */
	public function testEmptyListStaysEmpty(): void {
		$this->assertSame(
			[],
			PruneRetiredSchemasCommand::unlinkSchemaId(schemaRefs: [], schemaId: 74)
		);

	}//end testEmptyListStaysEmpty()
}//end class

<?php

/**
 * Unit tests for reading a handoff annotation entry that is not the shape it should be.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Handoff
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Handoff;

use OCA\OpenRegister\Service\Handoff\HandoffAnnotationEntry;
use PHPUnit\Framework\TestCase;

/**
 * A non-object entry, or one whose mapping is no map, reads as empty and is named by its position.
 *
 * @covers \OCA\OpenRegister\Service\Handoff\HandoffAnnotationEntry
 */
class HandoffAnnotationEntryShapeTest extends TestCase {

	/**
	 * A scalar entry has no fields, no id, no mapping, and faults name it by index.
	 *
	 * @return void
	 */
	public function testAScalarEntryReadsAsEmpty(): void {
		$entry = new HandoffAnnotationEntry('just a string', 3, null, []);

		$this->assertFalse($entry->isObject());
		$this->assertSame('fallback', $entry->field('id', 'fallback'));
		$this->assertSame('', $entry->kindUri());
		$this->assertSame([], $entry->mappingFields());
		$this->assertNull($entry->expressionFor('title'));
		$this->assertSame(['code' => 'x', 'message' => 'Handoff "entry-3": broken'], $entry->fault('x', 'broken'));

	}//end testAScalarEntryReadsAsEmpty()

	/**
	 * A mapping that is no map has no fields and no expressions; a real map has both.
	 *
	 * @return void
	 */
	public function testAMappingMustBeAMap(): void {
		$broken = new HandoffAnnotationEntry(['id' => 'to-zaak', 'mapping' => 'title'], 0, null, []);
		$this->assertSame([], $broken->mappingFields());
		$this->assertNull($broken->expressionFor('title'));
		$this->assertSame('Handoff "to-zaak": m', $broken->about('m'));

		$mapped = new HandoffAnnotationEntry(['id' => 'Bad Id', 'mapping' => ['title' => '{{ naam }}', 7 => 'x']], 1, null, []);
		$this->assertSame(['title', '7'], $mapped->mappingFields());
		$this->assertSame('{{ naam }}', $mapped->expressionFor('title'));
		$this->assertSame('entry-1', $mapped->label(), 'an id that is not kebab-case is no id');

	}//end testAMappingMustBeAMap()
}//end class

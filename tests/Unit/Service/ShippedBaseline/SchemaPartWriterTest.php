<?php

/**
 * One leaf of a schema's guarded parts written, every sibling left as stored.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ShippedBaseline\LeafReset;
use OCA\OpenRegister\Service\ShippedBaseline\SchemaPartWriter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ShippedBaseline\SchemaPartWriter
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\LeafReset
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\LiveDescriptor
 * @uses \OCA\OpenRegister\Service\ShippedBaseline\GuardedAnnotations
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 */
class SchemaPartWriterTest extends TestCase {

	/**
	 * A leaf is replaced, created along a missing path, or removed, and its siblings keep their order.
	 *
	 * @return void
	 */
	public function testALeafIsReplacedCreatedOrRemoved(): void {
		$writer = new SchemaPartWriter();
		$tree = ['a' => ['x' => 1, 'y' => [3, 1, 2]], 'b' => 2];

		$this->assertSame(['a' => ['x' => 9, 'y' => [3, 1, 2]], 'b' => 2], $writer->into(tree: $tree, leaf: new LeafReset(segments: ['a', 'x'], to: 9)));
		$this->assertSame(['a' => ['x' => 1, 'y' => [3, 1, 2]], 'b' => 2, 'c' => ['d' => true]], $writer->into(tree: $tree, leaf: new LeafReset(segments: ['c', 'd'], to: true)));
		$this->assertSame(['b' => 2], $writer->into(tree: $tree, leaf: new LeafReset(segments: ['a'], to: null)));
		$this->assertSame('whole', $writer->into(tree: $tree, leaf: new LeafReset(segments: [], to: 'whole')));
		$this->assertSame([], $writer->into(tree: $tree, leaf: new LeafReset(segments: [], to: null)));
		$this->assertSame(['k' => 1], $writer->into(tree: 'scalar', leaf: new LeafReset(segments: ['k'], to: 1)));
	}//end testALeafIsReplacedCreatedOrRemoved()

	/**
	 * Each top-level part lands on its own setter; the others are not touched.
	 *
	 * @return void
	 */
	public function testEachPartIsWrittenToItsOwnField(): void {
		$schema = new Schema();
		$schema->setProperties(['level' => ['type' => 'string', 'enum' => ['b', 'a']], 'code' => ['type' => 'string']]);
		$schema->setRequired(['level']);
		$schema->setAuthorization(['read' => ['hr']]);
		$writer = new SchemaPartWriter();

		$writer->write(schema: $schema, leaf: new LeafReset(segments: ['properties', 'code'], to: null));
		$writer->write(schema: $schema, leaf: new LeafReset(segments: ['required'], to: ['level', 'code']));
		$writer->write(schema: $schema, leaf: new LeafReset(segments: ['authorization', 'update'], to: ['hr']));

		$this->assertSame(['level' => ['type' => 'string', 'enum' => ['b', 'a']]], $schema->getProperties());
		$this->assertSame(['level', 'code'], $schema->getRequired());
		$this->assertSame(['read' => ['hr'], 'update' => ['hr']], $schema->getAuthorization());
	}//end testEachPartIsWrittenToItsOwnField()

	/**
	 * A part whose whole value is removed is written as empty, never as null.
	 *
	 * @return void
	 */
	public function testARemovedWholePartIsWrittenEmpty(): void {
		$schema = new Schema();
		$schema->setRequired(['level']);

		(new SchemaPartWriter())->write(schema: $schema, leaf: new LeafReset(segments: ['required'], to: null));

		$this->assertSame([], $schema->getRequired());
	}//end testARemovedWholePartIsWrittenEmpty()
}//end class

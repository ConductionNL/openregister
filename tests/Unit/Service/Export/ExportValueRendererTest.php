<?php

/**
 * Unit test for ExportValueRenderer: the stored text of a value and the text a
 * reader sees, with names, enum labels and ISO moments.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Export;

use OCA\OpenRegister\Service\Export\ColumnRendering;
use OCA\OpenRegister\Service\Export\ExportValueRenderer;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \OCA\OpenRegister\Service\Export\ExportValueRenderer
 * @uses \OCA\OpenRegister\Service\Export\ColumnRendering
 */
class ExportValueRendererTest extends TestCase {

	/**
	 * The stored text keeps booleans as true/false, structures as JSON and null as empty.
	 *
	 * @return void
	 */
	public function testStoredText(): void {
		$renderer = new ExportValueRenderer();
		$object   = new stdClass();
		$object->a = 1;

		$this->assertSame('', $renderer->stored(value: null));
		$this->assertSame('true', $renderer->stored(value: true));
		$this->assertSame('false', $renderer->stored(value: false));
		$this->assertSame('[1,"x"]', $renderer->stored(value: [1, 'x']));
		$this->assertSame('{"a":1}', $renderer->stored(value: $object));
		$this->assertSame('42', $renderer->stored(value: 42));
		$this->assertSame('2026-01-02T10:00:00Z', $renderer->stored(value: '2026-01-02T10:00:00Z'));
	}//end testStoredText()

	/**
	 * The rendered text says yes/no, joins lists, encodes objects and leaves null empty.
	 *
	 * @return void
	 */
	public function testRenderedScalarsAndStructures(): void {
		$renderer = new ExportValueRenderer();
		$object   = new stdClass();
		$object->a = 1;

		$this->assertSame('', $renderer->rendered(value: null, column: new ColumnRendering([], [])));
		$this->assertSame('yes', $renderer->rendered(value: true, column: new ColumnRendering([], [])));
		$this->assertSame('no', $renderer->rendered(value: false, column: new ColumnRendering([], [])));
		$this->assertSame('{"a":1}', $renderer->rendered(value: $object, column: new ColumnRendering([], [])));
		$this->assertSame('a; yes; Anna', $renderer->rendered(value: ['a', true, 'u-1'], column: new ColumnRendering([], ['u-1' => 'Anna'])));
		$this->assertSame('plain', $renderer->rendered(value: 'plain', column: new ColumnRendering([], [])));
	}//end testRenderedScalarsAndStructures()

	/**
	 * A resolved name wins over an enum label, which is read from enumNames or else enumLabels.
	 *
	 * @return void
	 */
	public function testNamesAndEnumLabels(): void {
		$renderer = new ExportValueRenderer();
		$names    = ['open' => 'Named open'];

		$this->assertSame('Named open', $renderer->rendered(value: 'open', column: new ColumnRendering(['enum' => ['open'], 'enumNames' => ['Open']], $names)));
		$this->assertSame('Gesloten', $renderer->rendered(value: 'closed', column: new ColumnRendering(['enum' => ['open', 'closed'], 'enumNames' => ['Open', 'Gesloten']], [])));
		$this->assertSame('Dicht', $renderer->rendered(value: 'closed', column: new ColumnRendering(['enum' => ['open', 'closed'], 'enumLabels' => ['Open', 'Dicht']], [])));
		$this->assertSame('Open', $renderer->rendered(value: '1', column: new ColumnRendering(['enum' => ['1'], 'enumNames' => ['Open']], [])));
	}//end testNamesAndEnumLabels()

	/**
	 * An enum without labels, a value outside the enum and a label that is no text all fall through to the value.
	 *
	 * @return void
	 */
	public function testEnumFallThrough(): void {
		$renderer = new ExportValueRenderer();

		$this->assertSame('open', $renderer->rendered(value: 'open', column: new ColumnRendering(['enum' => ['open']], [])));
		$this->assertSame('open', $renderer->rendered(value: 'open', column: new ColumnRendering(['enum' => 'open', 'enumNames' => ['Open']], [])));
		$this->assertSame('other', $renderer->rendered(value: 'other', column: new ColumnRendering(['enum' => ['open'], 'enumNames' => ['Open']], [])));
		$this->assertSame('open', $renderer->rendered(value: 'open', column: new ColumnRendering(['enum' => ['open'], 'enumNames' => [7]], [])));
		$this->assertSame('5', $renderer->rendered(value: 5, column: new ColumnRendering(['enum' => [5], 'enumNames' => ['Five']], [])));
	}//end testEnumFallThrough()

	/**
	 * A text with a T is read as an ISO moment and written out in its own offset; one that is no moment stays.
	 *
	 * @return void
	 */
	public function testIsoMomentsAreWrittenOut(): void {
		$renderer = new ExportValueRenderer();

		$this->assertSame('2026-01-02 10:30:00', $renderer->rendered(value: '2026-01-02T10:30:00+00:00', column: new ColumnRendering([], [])));
		$this->assertSame('2026-01-02 10:30:00', $renderer->rendered(value: '2026-01-02T10:30:00+05:00', column: new ColumnRendering([], [])));
		$this->assertSame('Total', $renderer->rendered(value: 'Total', column: new ColumnRendering([], [])));
		$this->assertSame('2026-01-02', $renderer->rendered(value: '2026-01-02', column: new ColumnRendering([], [])));
	}//end testIsoMomentsAreWrittenOut()
}//end class

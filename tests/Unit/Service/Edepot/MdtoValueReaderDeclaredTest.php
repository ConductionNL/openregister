<?php

/**
 * Characterises MdtoValueReader::declared(): retention, then TMLO, then the schema annotation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\ArchivalFactKey;
use OCA\OpenRegister\Service\Edepot\ArchivalSource;
use OCA\OpenRegister\Service\Edepot\MdtoValueReader;
use PHPUnit\Framework\TestCase;

/**
 * Pins which layer a declared value comes from.
 *
 * @covers \OCA\OpenRegister\Service\Edepot\MdtoValueReader
 */
class MdtoValueReaderDeclaredTest extends TestCase {

	public function testTheRetentionBlockWinsThenTmloThenTheAnnotation(): void {
		$annotation = ['bewaartermijn' => 'P1Y'];

		$this->assertSame('P10Y', $this->declared(retention: ['bewaartermijn' => 'P10Y'], tmlo: ['bewaarTermijn' => 'P5Y'], annotation: $annotation));
		$this->assertSame('P5Y', $this->declared(retention: ['other' => 'x'], tmlo: ['bewaarTermijn' => 'P5Y'], annotation: $annotation));
		$this->assertSame('P1Y', $this->declared(retention: [], tmlo: ['bewaartermijn' => 'P5Y'], annotation: $annotation));
		$this->assertNull($this->declared(retention: [], tmlo: [], annotation: ['bewaarTermijn' => 'P1Y']));
	}

	public function testANullInTheRetentionBlockFallsThroughButAFalseDoesNot(): void {
		$this->assertSame('P5Y', $this->declared(retention: ['bewaartermijn' => null], tmlo: ['bewaarTermijn' => 'P5Y'], annotation: []));
		$this->assertFalse($this->declared(retention: ['bewaartermijn' => false], tmlo: ['bewaarTermijn' => 'P5Y'], annotation: []));
	}

	/**
	 * The value the reader declares for `bewaartermijn` / `bewaarTermijn`.
	 *
	 * @param array<string, mixed> $retention  The retention block.
	 * @param array<string, mixed> $tmlo       The TMLO block.
	 * @param array<string, mixed> $annotation The evaluated annotation.
	 *
	 * @return mixed The declared value.
	 */
	private function declared(array $retention, array $tmlo, array $annotation): mixed {
		$object = new ObjectEntity();
		$object->setRetention($retention);
		$object->setTmlo($tmlo);

		return (new MdtoValueReader())->declared(
			source: new ArchivalSource(object: $object, annotation: $annotation),
			fact: new ArchivalFactKey(abstractKey: 'bewaartermijn', tmloKey: 'bewaarTermijn')
		);
	}
}//end class

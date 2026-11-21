<?php

/**
 * Golden test for SchemaAttribution::signature().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\SharedSchema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\SharedSchema;

use OCA\OpenRegister\Service\SharedSchema\SchemaAttribution;
use PHPUnit\Framework\TestCase;

/**
 * The normalised signature, pinned whole.
 */
class SchemaAttributionSignatureGoldenTest extends TestCase {

	public function testTheSignatureIsLowerCasedUniqueAndSorted(): void {
		$attribution = (new \ReflectionClass(SchemaAttribution::class))->newInstanceWithoutConstructor();

		$this->assertSame(
			['properties' => ['a', 'hours', 'zeta'], 'required' => ['1', 'hours', 'zeta']],
			$attribution->signature(
				definition: [
					'properties' => ['Zeta' => [], 'hours' => ['type' => 'number'], 'A' => [], 'zeta' => []],
					'required' => ['Zeta', 'hours', ['nested'], 1, 'HOURS'],
				]
			)
		);
		$this->assertSame(['properties' => [], 'required' => []], $attribution->signature(definition: ['properties' => 'x', 'required' => 'y']));
		$this->assertSame(['properties' => [], 'required' => []], $attribution->signature(definition: []));
	}//end testTheSignatureIsLowerCasedUniqueAndSorted()
}//end class

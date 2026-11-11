<?php

/**
 * Tests for {@see \OCA\OpenRegister\Service\Configuration\ExportSlugMaps}:
 * a numeric id is swapped for its slug when one is known, anything else is
 * passed through unchanged.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\ExportSlugMaps;
use PHPUnit\Framework\TestCase;

/**
 * ExportSlugMapsTest.
 */
class ExportSlugMapsTest extends TestCase {
	private function maps(): ExportSlugMaps {
		return new ExportSlugMaps(schemaSlugs: ['3' => 'person'], registerSlugs: ['7' => 'zaken']);
	}

	public function testAKnownNumericIdBecomesItsSlug(): void {
		$this->assertSame('person', $this->maps()->schemaSlugOrValue(3));
		$this->assertSame('zaken', $this->maps()->registerSlugOrValue('7'));
	}

	public function testAnUnknownNumericIdIsPassedThrough(): void {
		$this->assertSame(4, $this->maps()->schemaSlugOrValue(4));
		$this->assertSame('8', $this->maps()->registerSlugOrValue('8'));
	}

	public function testANonNumericValueIsPassedThroughWithoutLookup(): void {
		$this->assertSame('3a', $this->maps()->schemaSlugOrValue('3a'));
		$this->assertNull($this->maps()->registerSlugOrValue(null));
	}

	public function testDirectLookupsAnswerNullForUnknownIds(): void {
		$this->assertSame('person', $this->maps()->schemaSlug('3'));
		$this->assertNull($this->maps()->schemaSlug('7'));
		$this->assertSame('zaken', $this->maps()->registerSlug('7'));
		$this->assertNull($this->maps()->registerSlug('3'));
	}
}

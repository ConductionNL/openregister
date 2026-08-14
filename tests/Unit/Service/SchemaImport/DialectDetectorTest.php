<?php

/**
 * Unit tests for DialectDetector.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\SchemaImport
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\SchemaImport;

use OCA\OpenRegister\Service\SchemaImport\DialectDetector;
use PHPUnit\Framework\TestCase;

class DialectDetectorTest extends TestCase {
	private DialectDetector $detector;

	protected function setUp(): void {
		$this->detector = new DialectDetector();
	}

	public function testDetectsJsonSchemaByDollarSchema(): void {
		$doc = ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'title' => 'X'];
		$this->assertSame(DialectDetector::DIALECT_JSON_SCHEMA, $this->detector->detect($doc));
	}

	public function testDetectsJsonSchemaByShape(): void {
		$doc = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];
		$this->assertSame(DialectDetector::DIALECT_JSON_SCHEMA, $this->detector->detect($doc));
	}

	public function testDetectsOpenApi(): void {
		$doc = ['openapi' => '3.1.0', 'components' => ['schemas' => []]];
		$this->assertSame(DialectDetector::DIALECT_OPENAPI, $this->detector->detect($doc));
	}

	public function testDetectsSchemaOrgByContext(): void {
		$doc = ['@context' => 'https://schema.org/', '@type' => 'Person'];
		$this->assertSame(DialectDetector::DIALECT_SCHEMA_ORG, $this->detector->detect($doc));
	}

	public function testDetectsSchemaOrgByTypeIri(): void {
		$doc = ['@type' => 'https://schema.org/Person'];
		$this->assertSame(DialectDetector::DIALECT_SCHEMA_ORG, $this->detector->detect($doc));
	}

	public function testDetectsGgmByStandardMarker(): void {
		$doc = ['standard' => 'ggm', 'objecttypen' => []];
		$this->assertSame(DialectDetector::DIALECT_GGM, $this->detector->detect($doc));
	}

	public function testDetectsGgmBySingleObjecttype(): void {
		$doc = ['naam' => 'Zaak', 'attribuutsoorten' => []];
		$this->assertSame(DialectDetector::DIALECT_GGM, $this->detector->detect($doc));
	}

	public function testUndetectableReturnsNull(): void {
		$doc = ['foo' => 'bar', 'baz' => 42];
		$this->assertNull($this->detector->detect($doc));
	}

	public function testSupportedDialectsList(): void {
		$this->assertSame(
			['json-schema', 'openapi', 'schema.org', 'ggm'],
			DialectDetector::supportedDialects()
		);
	}
}

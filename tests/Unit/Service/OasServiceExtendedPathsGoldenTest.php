<?php

/**
 * Golden record of the extended path items OasService builds (audit trails, files, lock, unlock).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\OasService;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Service\OasService
 * @uses \OCA\OpenRegister\Service\Oas\OasNames
 * @uses \OCA\OpenRegister\Service\Oas\OasPropertySanitizer
 * @uses \OCA\OpenRegister\Service\Oas\OasReferenceRepair
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Oas\OasRbacAnnotator
 * @uses \OCA\OpenRegister\Service\Rbac\EffectiveAuthorization
 */
class OasServiceExtendedPathsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/oas-extended-paths-golden.json';

	public function testExtendedPathItemsMatchGolden(): void {
		$service = new OasService(
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(IURLGenerator::class)
		);
		$method = new ReflectionMethod($service, 'extendedPathItem');

		$actual = [];
		foreach (['Zaak', 'mijn klant-dossier', ''] as $title) {
			$schema = new Schema();
			$schema->setTitle($title);
			foreach (['audit-trails', 'files', 'lock', 'unlock', 'other'] as $endpoint) {
				$actual[$title . ' ' . $endpoint] = $method->invoke($service, $endpoint, $schema);
			}
		}

		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $actual);
	}//end testExtendedPathItemsMatchGolden()
}//end class

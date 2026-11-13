<?php

/**
 * Tests for SeedReportController: GET /api/settings/seed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Settings
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use OCA\OpenRegister\Controller\Settings\SeedReportController;
use OCA\OpenRegister\Service\Configuration\SeedDirectoryImporter;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(SeedReportController::class)]
class SeedReportControllerTest extends TestCase {

	/**
	 * Nextcloud's security middleware answers 403 to a non-administrator on
	 * every controller method that does not opt out with NoAdminRequired (or
	 * PublicPage, or a delegated admin setting); this asserts the method opts
	 * out of none of them.
	 */
	public function testANonAdministratorGets403(): void {
		$method = new ReflectionMethod(SeedReportController::class, 'show');

		self::assertSame([], $method->getAttributes(NoAdminRequired::class));
		self::assertSame([], array_filter(array_map(static fn ($attribute) => $attribute->getName(), $method->getAttributes()), static fn (string $name) => str_contains($name, 'PublicPage') || str_contains($name, 'AuthorizedAdminSetting') || str_contains($name, 'NoAdminRequired')));
		self::assertStringNotContainsString('@NoAdminRequired', (string)$method->getDocComment());
		self::assertStringNotContainsString('@PublicPage', (string)$method->getDocComment());
	}//end testANonAdministratorGets403()

	public function testTheLastReportIsReturned(): void {
		$importer = $this->createMock(SeedDirectoryImporter::class);
		$importer->expects(self::once())->method('lastReport')->willReturn(['status' => 'partial', 'files' => []]);

		$response = (new SeedReportController(request: $this->createMock(IRequest::class), importer: $importer))->show();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['report' => ['status' => 'partial', 'files' => []]], $response->getData());
	}//end testTheLastReportIsReturned()

	public function testNoRunAnswersANullReport(): void {
		$importer = $this->createMock(SeedDirectoryImporter::class);
		$importer->method('lastReport')->willReturn(null);

		$response = (new SeedReportController(request: $this->createMock(IRequest::class), importer: $importer))->show();

		self::assertSame(['report' => null], $response->getData());
	}//end testNoRunAnswersANullReport()
}//end class

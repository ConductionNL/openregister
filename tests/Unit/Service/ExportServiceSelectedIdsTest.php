<?php

/**
 * An export of selected ids holds only those objects.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md#requirement-an-export-of-selected-ids-holds-only-those-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * The `_ids` of an export request reach the search as its `ids`.
 */
class ExportServiceSelectedIdsTest extends TestCase {

	/**
	 * The ids an export with these filters hands to searchObjects().
	 *
	 * @param array<string, mixed> $filters The export request's filters.
	 *
	 * @return array<int, string>|null
	 */
	private function idsFor(array $filters): ?array {
		$captured = ['unset'];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('searchObjects')->willReturnCallback(
			static function (array $query, bool $_rbac = true, bool $_multitenancy = true, ?array $ids = null) use (&$captured): array {
				$captured = $ids;
				return [];
			}
		);

		$service = new ExportService(
			$this->createMock(RegisterMapper::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$objectService,
			$this->createMock(CacheHandler::class),
			$this->createMock(PropertyRbacHandler::class),
			$this->createMock(TranslationHandler::class)
		);

		$register = new Register();
		$register->setId(3);
		$schema = new Schema();
		$schema->setId(9);

		$service->countExportRows(register: $register, schema: $schema, filters: $filters);

		return $captured;
	}//end idsFor()

	/**
	 * A list of ids narrows the export to them.
	 *
	 * @return void
	 */
	public function testAListOfIdsNarrowsTheExport(): void {
		$this->assertSame(['a', 'b'], $this->idsFor(filters: ['format' => 'json', '_ids' => ['a', 'b']]));
	}//end testAListOfIdsNarrowsTheExport()

	/**
	 * A comma-separated string of ids narrows it too.
	 *
	 * @return void
	 */
	public function testACommaSeparatedSelectionNarrowsTheExport(): void {
		$this->assertSame(['a', 'b'], $this->idsFor(filters: ['format' => 'csv', '_ids' => 'a, b,']));
	}//end testACommaSeparatedSelectionNarrowsTheExport()

	/**
	 * Without `_ids` the export is not narrowed.
	 *
	 * @return void
	 */
	public function testWithoutIdsNothingIsNarrowed(): void {
		$this->assertNull($this->idsFor(filters: ['format' => 'json']));
	}//end testWithoutIdsNothingIsNarrowed()
}//end class

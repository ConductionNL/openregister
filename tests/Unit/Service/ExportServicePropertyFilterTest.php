<?php

/**
 * An export keeps the property filters of the list it came from (openregister#4088).
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
 * Before openregister#4088 every filter that was not an `@self.` filter was
 * skipped, so `?status=open` exported every row the caller could read.
 */
class ExportServicePropertyFilterTest extends TestCase {

	/**
	 * The query an export with these filters hands to searchObjects().
	 *
	 * @param array<string, mixed> $filters The export request's filters.
	 *
	 * @return array<string, mixed>
	 */
	private function queryFor(array $filters): array {
		$captured = [];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('searchObjects')->willReturnCallback(
			static function (array $query) use (&$captured): array {
				$captured = $query;
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
	}//end queryFor()

	/**
	 * A property filter reaches the query; request plumbing does not.
	 *
	 * @return void
	 */
	public function testAPropertyFilterNarrowsTheExport(): void {
		$query = $this->queryFor(
			[
				'register' => 'cases',
				'schema' => 'case',
				'format' => 'csv',
				'status' => 'open',
				'@self.owner' => 'alice',
			]
		);

		$this->assertSame('open', ($query['status'] ?? null));
		$this->assertSame('alice', $query['@self']['owner']);
		$this->assertSame(9, $query['@self']['schema']);
		$this->assertArrayNotHasKey('format', $query);
		$this->assertArrayNotHasKey('register', $query);
		$this->assertArrayNotHasKey('schema', $query);
	}//end testAPropertyFilterNarrowsTheExport()
}//end class

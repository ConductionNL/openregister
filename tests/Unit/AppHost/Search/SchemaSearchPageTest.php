<?php

/**
 * The page a schema search asks for: term, limit and offset as they reach the object query.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Search;

use OCA\OpenRegister\AppHost\Search\AbstractSchemaSearchProvider;
use OCA\OpenRegister\AppHost\SchemaScopeLookups;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter;
use OCA\OpenRegister\Service\Reference\ObjectPreviewSources;
use OCA\OpenRegister\Service\Search\ObjectSearchResultFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Drives AbstractSchemaSearchProvider::search() and reads the page off the query it sends.
 */
class SchemaSearchPageTest extends TestCase {

	/**
	 * Search with this term, limit and cursor and return the query handed to the object service.
	 *
	 * @param mixed $cursor The cursor the search asks for.
	 *
	 * @return array<string, mixed> The object query.
	 */
	private function queryFor(string $term, int $limit, mixed $cursor): array {
		$register = new Register();
		$register->setId(5);
		$schema = new Schema();
		$schema->setId(12);
		$schema->setSmartPickerEnabled(true);
		$schema->setTitle('Lead');

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturn($register);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($schema);

		$sent = null;
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->once())
			->method('searchObjectsPaginated')
			->willReturnCallback(
				static function (array $query) use (&$sent): array {
					$sent = $query;

					return ['results' => [], 'total' => 0];
				}
			);

		$urls = $this->createMock(IURLGenerator::class);
		$deepLinks = $this->createMock(DeepLinkRegistryService::class);
		$formatter = new ObjectSearchResultFormatter(
			$urls,
			$deepLinks,
			new ObjectPreviewFormatter(
				$urls,
				$this->createMock(IL10N::class),
				new ObjectPreviewSources(objectService: $objects, deepLinkRegistry: $deepLinks, schemaMapper: $schemas, registerMapper: $registers),
				$this->createMock(LoggerInterface::class)
			)
		);

		$provider = new class($objects, $formatter, new SchemaScopeLookups(new SchemaCatalog($registers, $schemas, $this->createMock(MagicMapperInterface::class)), $this->createMock(LoggerInterface::class))) extends AbstractSchemaSearchProvider {
			public function getRegisterSlug(): string {
				return 'pipelinq';
			}//end getRegisterSlug()

			public function getSchemaSlug(): string {
				return 'lead';
			}//end getSchemaSlug()
		};

		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn($term);
		$query->method('getLimit')->willReturn($limit);
		$query->method('getCursor')->willReturn($cursor);

		$provider->search($this->createMock(IUser::class), $query);

		return $sent;
	}//end queryFor()

	public function testTermLimitAndNumericCursorBecomeTheQueryPage(): void {
		$this->assertSame(
			['_search' => 'acme', '@self' => ['register' => 5, 'schema' => 12], '_content_search' => true, '_limit' => 5, '_offset' => 10],
			$this->queryFor(term: 'acme', limit: 5, cursor: '10')
		);
	}//end testTermLimitAndNumericCursorBecomeTheQueryPage()

	public function testAnEmptyTermIsNoSearchAndAnUnusableLimitAndCursorFallBack(): void {
		$this->assertSame(
			['@self' => ['register' => 5, 'schema' => 12], '_content_search' => true, '_limit' => 25, '_offset' => 0],
			$this->queryFor(term: '', limit: 0, cursor: 'not-a-number')
		);
	}//end testAnEmptyTermIsNoSearchAndAnUnusableLimitAndCursorFallBack()

	public function testALimitAboveThePageSizeAndANegativeCursorAreClamped(): void {
		$query = $this->queryFor(term: 'x', limit: 500, cursor: -4);

		$this->assertSame(25, $query['_limit']);
		$this->assertSame(0, $query['_offset']);
	}//end testALimitAboveThePageSizeAndANegativeCursorAreClamped()
}//end class

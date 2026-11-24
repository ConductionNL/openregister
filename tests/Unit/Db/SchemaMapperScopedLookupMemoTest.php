<?php

/**
 * SchemaMapper answers a register-scoped schema lookup once per request, and
 * forgets the answer when a schema is written or cleared.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaMapper\SchemaMapperServices;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\SchemaMapper
 * @uses   \OCA\OpenRegister\Db\RequestIdentityMap
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaLookup
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\WritePhaseProbe
 * @uses   \OCA\OpenRegister\Db\MultiTenancyTrait
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaComposition
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\ParentReference
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\PropertyMerge
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\PropertyDelta
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\ExtendedByReads
 * @uses   \OCA\OpenRegister\Db\MappingMapper
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaMapperServices
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaReadTrace
 * @uses   \OCA\OpenRegister\Db\SchemaPropertyInheritance
 * @uses   \OCA\OpenRegister\Db\Tenancy\OrganisationFilterOptions
 * @uses   \OCA\OpenRegister\Db\Tenancy\TenancyServices
 * @uses   \OCA\OpenRegister\Service\WritePhase
 * @uses   \OCA\OpenRegister\Db\Tenancy\OrganisationFilterTrait
 * @uses   \OCA\OpenRegister\Db\Tenancy\TenancyIdentityTrait
 */
class SchemaMapperScopedLookupMemoTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private function executedQueries(): int {
		return count(array_filter($this->queryLog, static fn (string $line): bool => str_ends_with($line, ' executeQuery()')));
	}//end executedQueries()

	public function testAScopedSlugIsReadOnceForTheSameIdSet(): void {
		$mapper = $this->makeMapper();
		$this->queuedRows = [[['id' => 7, 'uuid' => 'uuid-7', 'slug' => 'seven']]];

		$first = $mapper->findBySlugInIds('seven', [7, 9]);
		$second = $mapper->findBySlugInIds('SEVEN', ['9', 7, 7]);

		$this->assertSame(1, $this->executedQueries());
		$this->assertSame(7, $first?->getId());
		$this->assertSame($first, $second);
	}//end testAScopedSlugIsReadOnceForTheSameIdSet()

	public function testAnotherIdSetIsReadAgain(): void {
		$mapper = $this->makeMapper();
		$this->queuedRows = [[['id' => 7, 'slug' => 'seven']], [['id' => 8, 'slug' => 'seven']]];

		$inFirst = $mapper->findBySlugInIds('seven', [7]);
		$inSecond = $mapper->findBySlugInIds('seven', [8]);

		$this->assertSame(2, $this->executedQueries());
		$this->assertSame(7, $inFirst?->getId());
		$this->assertSame(8, $inSecond?->getId());
	}//end testAnotherIdSetIsReadAgain()

	public function testAMissIsAskedAgain(): void {
		$mapper = $this->makeMapper();
		$this->queuedRows = [[], [['id' => 7, 'slug' => 'seven']]];

		$miss = $mapper->findBySlugInIds('seven', [7]);
		$hit = $mapper->findBySlugInIds('seven', [7]);

		$this->assertNull($miss);
		$this->assertSame(7, $hit?->getId());
		$this->assertSame(2, $this->executedQueries());
	}//end testAMissIsAskedAgain()

	public function testClearingASchemaForgetsItsScopedLookups(): void {
		$mapper = $this->makeMapper();
		$this->queuedRows = [[['id' => 7, 'slug' => 'seven']], [['id' => 7, 'slug' => 'seven']]];

		$before = $mapper->findBySlugInIds('seven', [7]);
		$mapper->clearFindCache(7);
		$after = $mapper->findBySlugInIds('seven', [7]);

		$this->assertSame(2, $this->executedQueries());
		$this->assertNotSame($before, $after);
	}//end testClearingASchemaForgetsItsScopedLookups()

	public function testAScopedIdentifierIsReadOnce(): void {
		$mapper = $this->makeMapper();
		$this->queuedRows = [[['id' => 7, 'slug' => 'seven']]];

		$first = $mapper->findInIds(7, [7, 9]);
		$second = $mapper->findInIds('7', [9, 7]);

		$this->assertSame(1, $this->executedQueries());
		$this->assertSame(7, $first?->getId());
		$this->assertSame($first, $second);
	}//end testAScopedIdentifierIsReadOnce()

	public function testAScopedAnswerDoesNotServeTheUnscopedFind(): void {
		$mapper = $this->makeMapper();
		$this->queuedRows = [[['id' => 7, 'slug' => 'seven']], [['id' => 8, 'slug' => 'seven']]];

		$scoped = $mapper->findInIds('seven', [7]);
		$unscoped = $mapper->find('seven', [], true, false);

		$this->assertSame(2, $this->executedQueries());
		$this->assertSame(7, $scoped?->getId());
		$this->assertSame(8, $unscoped->getId());
	}//end testAScopedAnswerDoesNotServeTheUnscopedFind()

	public function testTheSlugAndIdentifierLookupsDoNotShareAnswers(): void {
		$mapper = $this->makeMapper();
		$this->queuedRows = [[['id' => 7, 'slug' => 'seven']], [['id' => 7, 'slug' => 'seven']]];

		$mapper->findBySlugInIds('seven', [7]);
		$mapper->findInIds('seven', [7]);

		$this->assertSame(2, $this->executedQueries());
	}//end testTheSlugAndIdentifierLookupsDoNotShareAnswers()

	private function makeMapper(): SchemaMapper {
		$this->queryLog = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());

		return new SchemaMapper(
			db: $db,
			tenancy: new TenancyServices(
				organisationMapper: $this->createMock(OrganisationMapper::class),
				userSession: $this->createMock(IUserSession::class),
				groupManager: $this->createMock(IGroupManager::class)
			),
			appConfig: $this->createMock(IAppConfig::class),
			services: new SchemaMapperServices(
				eventDispatcher: $this->createMock(IEventDispatcher::class),
				validator: $this->createMock(PropertyValidatorHandler::class),
				logger: $this->createMock(LoggerInterface::class)
			)
		);
	}//end makeMapper()
}//end class

<?php

/**
 * Unit tests for MagicSearchHandler's encrypted-field filter rejection.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/field-level-object-encryption/specs/field-level-encryption/spec.md#requirement-encrypted-fields-are-excluded-from-search-and-facets
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\EncryptedFieldFilterException;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class MagicSearchHandlerEncryptedFilterTest extends TestCase {
	private IDBConnection&MockObject $db;

	private LoggerInterface&MockObject $logger;

	private MagicRbacHandler&MockObject $rbacHandler;

	private MagicOrganizationHandler&MockObject $organizationHandler;

	private MagicSearchHandler $handler;

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		// isPostgresPlatform() reads getDatabasePlatform()::class; return a concrete
		// non-Postgres platform object so the unknown-field path (which reaches it)
		// does not crash. The throwing paths reject before ever reaching it.
		$this->db->method('getDatabasePlatform')->willReturn(new \stdClass());
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->rbacHandler = $this->createMock(MagicRbacHandler::class);
		$this->organizationHandler = $this->createMock(MagicOrganizationHandler::class);

		$this->handler = new MagicSearchHandler(
			db: $this->db,
			logger: $this->logger,
			rbacHandler: $this->rbacHandler,
			organizationHandler: $this->organizationHandler,
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer($this->logger)
		);
	}//end setUp()

	private function invokeApplyObjectFilters(array $filters, array $properties, IQueryBuilder&MockObject $qb): void {
		$schema = new Schema();
		$schema->setProperties($properties);

		$method = new ReflectionMethod(MagicSearchHandler::class, 'applyObjectFilters');
		$method->setAccessible(true);
		$method->invoke($this->handler, $qb, $filters, $schema);
	}//end invokeApplyObjectFilters()

	public function testFilteringOnEncryptedPropertyThrows(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->expects($this->never())->method('andWhere');

		$this->expectException(EncryptedFieldFilterException::class);

		$this->invokeApplyObjectFilters(
			filters: ['bsn' => '123456789'],
			properties: ['bsn' => ['type' => 'string', 'x-openregister-encrypted' => true]],
			qb: $qb
		);
	}//end testFilteringOnEncryptedPropertyThrows()

	public function testExceptionCarriesThePropertyName(): void {
		$qb = $this->createMock(IQueryBuilder::class);

		try {
			$this->invokeApplyObjectFilters(
				filters: ['medicalRecord' => 'x'],
				properties: ['medicalRecord' => ['type' => 'string', 'x-openregister-encrypted' => true]],
				qb: $qb
			);
			$this->fail('Expected EncryptedFieldFilterException');
		} catch (EncryptedFieldFilterException $e) {
			$this->assertSame('medicalRecord', $e->getProperty());
			$this->assertStringContainsString('medicalRecord', $e->getMessage());
		}
	}//end testExceptionCarriesThePropertyName()

	public function testFilteringOnAnUnknownFieldStillHitsTheExistingIgnoredFilterPath(): void {
		// A field absent from the schema entirely must still fall through to the
		// pre-existing "ignored filter" behaviour (andWhere('1 = 0')), proving the
		// new encrypted-property guard only intercepts flagged properties and does
		// not change behaviour for every other filter.
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->expects($this->once())->method('andWhere')->with('1 = 0');

		$this->invokeApplyObjectFilters(
			filters: ['doesNotExist' => 'value'],
			properties: ['name' => ['type' => 'string']],
			qb: $qb
		);
	}//end testFilteringOnAnUnknownFieldStillHitsTheExistingIgnoredFilterPath()
}

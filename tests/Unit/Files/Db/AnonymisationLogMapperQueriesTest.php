<?php

/**
 * AnonymisationLogMapper's find by id (one row, or DoesNotExist) and its
 * insert, which stamps the creation time before writing the row.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Db;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use DateTime;
use OCA\OpenRegister\Files\Db\AnonymisationLog;
use OCA\OpenRegister\Files\Db\AnonymisationLogMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordsQueries;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * AnonymisationLogMapper::find() and insert().
 *
 * @covers \OCA\OpenRegister\Files\Db\AnonymisationLogMapper
 * @uses \OCA\OpenRegister\Files\Db\AnonymisationLog
 */
final class AnonymisationLogMapperQueriesTest extends TestCase {
	use RecordsQueries;

	private AnonymisationLogMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new AnonymisationLogMapper($this->recordingDb());
	}//end setUp()

	public function testALogRowIsFoundById(): void {
		$this->results[] = [['id' => 5, 'file_id' => 77, 'object_uuid' => 'obj-1']];

		$log = $this->mapper->find(5);

		$this->assertSame('select(*) from(openregister_anonymisation_log) where(eq(id, :p0)) executeQuery()', $this->sql());
		$this->assertSame([5, IQueryBuilder::PARAM_INT], $this->params[':p0']);
		$this->assertInstanceOf(AnonymisationLog::class, $log);
		$this->assertSame(5, $log->getId());
		$this->assertSame('obj-1', $log->getObjectUuid());
	}//end testALogRowIsFoundById()

	public function testAMissingLogRowDoesNotExist(): void {
		$this->expectException(DoesNotExistException::class);

		$this->mapper->find(404);
	}//end testAMissingLogRowDoesNotExist()

	public function testAnInsertedLogIsStampedWithItsCreationTime(): void {
		$log = new AnonymisationLog();
		$log->setObjectUuid('obj-2');
		$log->setCreated(new DateTime('2001-01-01 00:00:00'));
		$before = new DateTime('-1 minute');

		$inserted = $this->mapper->insert($log);

		$this->assertSame($log, $inserted);
		$this->assertInstanceOf(DateTime::class, $log->getCreated());
		$this->assertGreaterThanOrEqual($before, $log->getCreated());
		$this->assertSame(42, $log->getId());
		$this->assertStringStartsWith('insert(openregister_anonymisation_log)', $this->sql());
		$this->assertStringEndsWith('executeStatement()', $this->sql());
	}//end testAnInsertedLogIsStampedWithItsCreationTime()
}//end class

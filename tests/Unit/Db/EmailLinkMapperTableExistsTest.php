<?php

/**
 * The email link table is looked up through the connection, which knows the
 * configured table prefix, so the check holds on any `dbtableprefix`.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\EmailLinkMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\EmailLinkMapper
 */
class EmailLinkMapperTableExistsTest extends TestCase {

	/**
	 * The answer is the connection's, asked once for the unprefixed name.
	 *
	 * @return void
	 */
	public function testTheConnectionAnswersOnce(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('tableExists')->with('openregister_email_links')->willReturn(true);

		$mapper = new EmailLinkMapper(db: $db);

		$this->assertTrue($mapper->tableExists());
		$this->assertTrue($mapper->tableExists());
	}//end testTheConnectionAnswersOnce()

	/**
	 * A missing table reads as missing.
	 *
	 * @return void
	 */
	public function testAMissingTableReadsAsMissing(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(false);

		$this->assertFalse((new EmailLinkMapper(db: $db))->tableExists());
	}//end testAMissingTableReadsAsMissing()

	/**
	 * A failing lookup reads as missing, so callers keep their empty result.
	 *
	 * @return void
	 */
	public function testAFailingLookupReadsAsMissing(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willThrowException(new RuntimeException('no schema access'));

		$this->assertFalse((new EmailLinkMapper(db: $db))->tableExists());
	}//end testAFailingLookupReadsAsMissing()
}//end class

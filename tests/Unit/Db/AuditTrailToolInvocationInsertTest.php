<?php

/**
 * An MCP tool invocation writes its audit row into the real table.
 *
 * `createToolInvocationEntry()` never set `changed`, and
 * `openregister_audit_trails.changed` is NOT NULL (Version1Date20241020231700).
 * QBMapper inserts only the fields a setter touched, so the column reached the
 * database as NULL and PostgreSQL refused every attribute tool's audit row
 * (#4279). A mocked query builder accepts any row; this test inserts through
 * the table the app's own migrations create, which enforces NOT NULL.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Db\AuditTrailMapper
 * @uses \OCA\OpenRegister\Db\AuditFlowAttribution
 * @uses \OCA\OpenRegister\Db\AuditTrail
 * @uses \OCA\OpenRegister\Service\Audit\PurposeAttribution
 * @uses \OCA\OpenRegister\Service\Audit\TokenAttribution
 * @uses \OCA\OpenRegister\AppInfo\ClassAliasMap
 * @uses \OCA\OpenRegister\Service\OrganisationContext
 * @uses \OCA\OpenRegister\Service\Audit\OrganisationContextAttribution
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope
 */
class AuditTrailToolInvocationInsertTest extends TestCase {

	private MigratedSqliteDatabase $database;

	private AuditTrailMapper $mapper;

	protected function setUp(): void {
		$this->database = new MigratedSqliteDatabase($this, ['openregister_audit_trails']);

		$this->mapper = new AuditTrailMapper(
			db: $this->database->idbConnection(),
			userSession: $this->createMock(IUserSession::class),
			request: $this->createMock(IRequest::class),
			services: new AuditTrailMapperServices(container: $this->createMock(ContainerInterface::class), logger: new NullLogger())
		);
	}//end setUp()

	/**
	 * A tool invocation inserts a row, with an empty change set.
	 */
	public function testAToolInvocationIsWrittenToTheAuditTable(): void {
		$entry = $this->mapper->createToolInvocationEntry(
			toolId: 'keepiq.listEntries',
			paramsDigest: hash('sha256', '{}'),
			resultSummary: ['count' => 3]
		);

		$rows = $this->database->connection()->fetchAllAssociative(
			'SELECT action, tool_id, changed, "user" FROM openregister_audit_trails WHERE id = ?',
			[$entry->getId()]
		);

		$this->assertCount(1, $rows);
		$this->assertSame('mcp.listEntries', $rows[0]['action']);
		$this->assertSame('keepiq.listEntries', $rows[0]['tool_id']);
		$this->assertSame([], json_decode((string) $rows[0]['changed'], true));
		$this->assertSame('system', $rows[0]['user']);
	}//end testAToolInvocationIsWrittenToTheAuditTable()

	/**
	 * A row written inside an organisation context names the organisation in its sealed summary.
	 */
	public function testARowInsideAnOrganisationContextNamesTheOrganisation(): void {
		$entry = \OCA\OpenRegister\Service\OrganisationContext::run(
			'org-a',
			fn () => $this->mapper->createToolInvocationEntry(
				toolId: 'keepiq.listEntries',
				paramsDigest: hash('sha256', '{}'),
				resultSummary: ['count' => 3]
			)
		);

		$rows = $this->database->connection()->fetchAllAssociative(
			'SELECT result_summary FROM openregister_audit_trails WHERE id = ?',
			[$entry->getId()]
		);

		$this->assertSame(['count' => 3, 'organisationContext' => 'org-a'], json_decode((string) $rows[0]['result_summary'], true));
	}//end testARowInsideAnOrganisationContextNamesTheOrganisation()
}//end class

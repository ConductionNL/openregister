<?php

/**
 * Every console run and download is an audit fact that keeps the query and the
 * variable names, never a variable value.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\GraphQL
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\GraphQL;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\GraphQL\AdminQueryAudit;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\GraphQL\AdminQueryAudit
 * @uses \OCA\OpenRegister\Db\AuditTrail
 * @uses \OCA\OpenRegister\Db\Organisation
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
 */
class AdminQueryAuditTest extends TestCase {

	/** @var AuditTrailMapperInterface&MockObject */
	private AuditTrailMapperInterface $auditTrailMapper;

	/** @var IUserSession&MockObject */
	private IUserSession $userSession;

	/** @var OrganisationServiceInterface&MockObject */
	private OrganisationServiceInterface $organisations;

	/** @var array<int, AuditTrail> */
	private array $inserted = [];

	protected function setUp(): void {
		$this->auditTrailMapper = $this->createMock(AuditTrailMapperInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->organisations = $this->createMock(OrganisationServiceInterface::class);

		$this->auditTrailMapper->method('insertAuditTrails')->willReturnCallback(
			function (array $entries): array {
				foreach ($entries as $entry) {
					$this->inserted[] = $entry;
				}

				return $entries;
			}
		);
	}//end setUp()

	private function audit(): AdminQueryAudit {
		return new AdminQueryAudit($this->auditTrailMapper, $this->userSession, $this->organisations);
	}//end audit()

	private function signedInAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signedInAs()

	private function activeOrganisation(string $uuid): void {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		$this->organisations->method('getActiveOrganisation')->willReturn($organisation);
	}//end activeOrganisation()

	public function testARunRowKeepsTheQueryItsHashTheNamesAndTheOutcomeButNoValues(): void {
		$this->signedInAs('alice');
		$this->activeOrganisation('org-7');

		$this->audit()->record(
			action: AdminQueryAudit::ACTION_RUN,
			query: 'query Q($bsn: String) { people(bsn: $bsn) { id } }',
			variables: ['bsn' => '123456789'],
			operation: 'Q',
			outcome: ['rows' => 3, 'truncated' => false, 'durationMs' => 12, 'outcome' => 'ok']
		);

		$this->assertCount(1, $this->inserted);
		$row = $this->inserted[0];
		$changed = $row->getChanged();

		$this->assertSame('query.run', $row->getAction());
		$this->assertSame('alice', $row->getUser());
		$this->assertSame('org-7', $row->getOrganisationId());
		$this->assertNotNull($row->getUuid());

		$this->assertSame(['bsn'], $changed['variableNames']);
		$this->assertStringContainsString('people(bsn: $bsn)', $changed['query']);
		$this->assertSame(hash('sha256', 'query Q($bsn: String) { people(bsn: $bsn) { id } }'), $changed['queryHash']);
		$this->assertSame('Q', $changed['operation']);
		$this->assertSame(3, $changed['rows']);
		$this->assertSame('ok', $changed['outcome']);

		// The value of the variable is nowhere in the row.
		$this->assertStringNotContainsString('123456789', json_encode($changed));
	}//end testARunRowKeepsTheQueryItsHashTheNamesAndTheOutcomeButNoValues()

	public function testAnExportRowCarriesTheFormat(): void {
		$this->signedInAs('alice');
		$this->activeOrganisation('org-7');

		$this->audit()->record(
			action: AdminQueryAudit::ACTION_EXPORT,
			query: '{ things { id } }',
			variables: [],
			operation: null,
			outcome: ['rows' => 1, 'truncated' => false, 'durationMs' => 4, 'outcome' => 'ok', 'format' => 'csv']
		);

		$changed = $this->inserted[0]->getChanged();
		$this->assertSame('query.export', $this->inserted[0]->getAction());
		$this->assertSame('csv', $changed['format']);
		$this->assertSame([], $changed['variableNames']);
	}//end testAnExportRowCarriesTheFormat()

	public function testTheQueryTextIsCappedAtEightKilobytes(): void {
		$this->signedInAs('alice');
		$this->organisations->method('getActiveOrganisation')->willReturn(null);

		$huge = str_repeat('a', 9000);
		$this->audit()->record(
			action: AdminQueryAudit::ACTION_RUN,
			query: $huge,
			variables: [],
			operation: null,
			outcome: ['rows' => 0, 'truncated' => false, 'durationMs' => 0, 'outcome' => 'ok']
		);

		$changed = $this->inserted[0]->getChanged();
		$this->assertSame(8192, mb_strlen($changed['query']));
		// The hash is of the whole query, not the capped text.
		$this->assertSame(hash('sha256', $huge), $changed['queryHash']);
		$this->assertNull($this->inserted[0]->getOrganisationId());
	}//end testTheQueryTextIsCappedAtEightKilobytes()
}//end class

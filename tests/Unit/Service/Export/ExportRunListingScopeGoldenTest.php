<?php

/**
 * Golden: whose export runs a caller is shown, and what reaches the mapper.
 *
 * Pins the allowed and refused outcomes of the export run listing before its
 * signature moves onto a ListQuery: an anonymous or empty caller is refused
 * without a query, a non-administrator is scoped to their own runs even when
 * they filter on another actor, an administrator is not scoped, and the page
 * and filters reach the mapper unchanged.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Export;

use DateTime;
use OCA\OpenRegister\Db\ExportRun;
use OCA\OpenRegister\Db\ExportRunMapper;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Service\Export\ExportRunRecorder;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Golden for ExportRunRecorder's run listing scope.
 */
class ExportRunListingScopeGoldenTest extends TestCase {

	/**
	 * Every findForActor() call: [actor, filters, limit, offset].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * The recorder under test.
	 *
	 * @var ExportRunRecorder
	 */
	private ExportRunRecorder $recorder;

	/**
	 * Build the recorder on a capturing mapper with one stored run.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->calls = [];

		$run = new ExportRun();
		$run->setActor('alice');
		$run->setStatus('served');
		$run->setFileId(7);
		$run->setExpiresAt(new DateTime('@1900000000'));

		$mapper = $this->createMock(ExportRunMapper::class);
		$mapper->method('listForActor')->willReturnCallback(
			function (?string $actor, ListQuery $query) use ($run): array {
				$this->calls[] = [$actor, $query->filters, $query->limit, $query->offset];

				return [$run];
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('@1800000000'));

		$this->recorder = new ExportRunRecorder(
			$mapper,
			$this->createMock(IRootFolder::class),
			$time,
			new NullLogger()
		);
	}//end setUp()

	/**
	 * An anonymous or empty caller is refused before any query.
	 *
	 * @return void
	 */
	public function testAnAnonymousOrEmptyCallerIsRefusedWithoutAQuery(): void {
		$this->assertSame([], $this->recorder->listFor(actor: null, isAdmin: true));
		$this->assertSame([], $this->recorder->listFor(actor: '', isAdmin: true));
		$this->assertSame([], $this->calls, 'A caller without an identity reached the mapper.');
	}//end testAnAnonymousOrEmptyCallerIsRefusedWithoutAQuery()

	/**
	 * A non-administrator is scoped to their own runs, filters and page forwarded.
	 *
	 * @return void
	 */
	public function testACallerIsScopedToTheirOwnRuns(): void {
		$rows = $this->recorder->listFor(
			actor: 'alice',
			isAdmin: false,
			filters: ['actor' => 'bob', 'status' => 'served'],
			limit: 20,
			offset: 40
		);

		$this->assertSame([['alice', ['actor' => 'bob', 'status' => 'served'], 20, 40]], $this->calls);
		$this->assertCount(1, $rows);
		$this->assertFalse($rows[0]['expired']);
		$this->assertTrue($rows[0]['downloadable']);
	}//end testACallerIsScopedToTheirOwnRuns()

	/**
	 * An administrator is not scoped to an actor.
	 *
	 * @return void
	 */
	public function testAnAdministratorIsNotScoped(): void {
		$this->recorder->listFor(actor: 'root', isAdmin: true);

		$this->assertSame([[null, [], 50, 0]], $this->calls);
	}//end testAnAdministratorIsNotScoped()
}//end class

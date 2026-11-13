<?php

/**
 * FlowRunViewerScopeTest: which runs a caller sees: their own and those of the flows they own.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\FlowRunListing;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunViewer;
use OCA\OpenRegister\Tests\Unit\Db\Support\RecordsQueries;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\FlowRunViewer
 * @uses   \OCA\OpenRegister\Db\FlowRunMapper
 * @uses \OCA\OpenRegister\Db\FlowRunFilter
 * @uses \OCA\OpenRegister\Db\FlowRunListing
 */
class FlowRunViewerScopeTest extends TestCase {
	use RecordsQueries;

	/**
	 * The filters of one run listing.
	 *
	 * @param string|null        $requester The caller.
	 * @param array<int, string> $owned     Flows the caller owns.
	 *
	 * @return list<string>
	 */
	private function filtersFor(?string $requester, array $owned): array {
		(new FlowRunMapper($this->recordingConnection()))->findAllRuns(listing: new FlowRunListing(), viewer: new FlowRunViewer(requesterUid: $requester, ownedFlowIds: $owned));

		return array_values(array_filter($this->queries[0], static fn (string $call): bool => str_starts_with($call, 'andWhere') || str_starts_with($call, 'where')));
	}//end filtersFor()

	public function testWithoutARequesterEveryRunIsVisible(): void {
		$this->assertSame([], $this->filtersFor(null, ['f1']));
	}//end testWithoutARequesterEveryRunIsVisible()

	public function testARequesterSeesOnlyRunsTheyTriggered(): void {
		$this->assertSame(["andWhere(orX(eq(triggered_by, 'ann')))"], $this->filtersFor('ann', []));
	}//end testARequesterSeesOnlyRunsTheyTriggered()
}//end class

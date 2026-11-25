<?php

/**
 * A groupBy answer downloads its buckets.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\GraphQL;

use OCA\OpenRegister\Exception\NoTabularResultException;
use OCA\OpenRegister\Service\GraphQL\AdminQueryExport;
use PHPUnit\Framework\TestCase;

/**
 * The first list of a result includes a connection's `groupBy` buckets when its edges were not asked for.
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
 */
class AdminQueryExportGroupsTest extends TestCase {

	public function testAGroupByConnectionWithoutEdgesDownloadsItsBuckets(): void {
		$table = (new AdminQueryExport())->tableOf(result: ['data' => ['zaken' => [
			'totalCount' => 3,
			'groups' => [['key' => 'open', 'value' => 2], ['key' => 'closed', 'value' => 1]],
		]]]);

		$this->assertSame(['key', 'value'], $table['columns']);
		$this->assertSame([['key' => 'open', 'value' => 2], ['key' => 'closed', 'value' => 1]], $table['rows']);
	}

	public function testEdgesStillWinOverBucketsWhenBothAreAsked(): void {
		$table = (new AdminQueryExport())->tableOf(result: ['data' => ['zaken' => [
			'groups' => [['key' => 'open', 'value' => 1]],
			'edges' => [['node' => ['id' => 'a']]],
		]]]);

		$this->assertSame([['id' => 'a']], $table['rows']);
	}

	public function testARecordWithoutAListIsStillNotTabular(): void {
		$this->expectException(NoTabularResultException::class);

		(new AdminQueryExport())->tableOf(result: ['data' => ['zaak' => ['id' => 'a', 'title' => 'x']]]);
	}
}

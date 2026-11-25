<?php

/**
 * Unit test for TaskInboxRequest.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
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

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\TaskInboxRequest;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Exception\ActionParameterTypeException;
use PHPUnit\Framework\TestCase;

/**
 * The task inbox query string becomes a scope plus a list query of filters, paging and one sort.
 *
 * @covers \OCA\OpenRegister\Controller\Support\TaskInboxRequest
 * @uses \OCA\OpenRegister\Controller\Support\ActionParameters
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 */
class TaskInboxRequestTest extends TestCase {
	public function testDefaultsAreTheAssignedScopeSortedByDueDateAscending(): void {
		$request = TaskInboxRequest::fromParams([]);

		$this->assertSame(TaskInboxCriteria::SCOPE_ASSIGNED, $request->scope);
		$this->assertSame(25, $request->query->limit);
		$this->assertSame(0, $request->query->offset);
		$this->assertSame([TaskInboxCriteria::SORT_DUE => 'asc'], $request->query->sort);
	}

	public function testEveryKnownFilterIsPresentAndNullWhenAbsent(): void {
		$filters = TaskInboxRequest::fromParams([])->query->filters;

		$this->assertSame(
			['state', 'isTerminal', 'priority', 'kind', 'objectUuid', 'runUuid', 'overdue', 'dueAfter', 'dueBefore', 'includeBlocked', 'openFirst', 'flow', 'node'],
			array_keys($filters)
		);
		$this->assertSame([], array_filter($filters, static fn (?string $value): bool => $value !== null));
	}

	public function testGivenFiltersPagingAndScopeAreRead(): void {
		$request = TaskInboxRequest::fromParams(
			['scope' => 'group', 'state' => 'open', 'priority' => 'high', 'limit' => '10', 'offset' => '30', 'unknown' => 'dropped']
		);

		$this->assertSame('group', $request->scope);
		$this->assertSame('open', $request->query->filters['state']);
		$this->assertSame('high', $request->query->filters['priority']);
		$this->assertArrayNotHasKey('unknown', $request->query->filters);
		$this->assertSame(10, $request->query->limit);
		$this->assertSame(30, $request->query->offset);
	}

	public function testALeadingMinusSortsDescending(): void {
		$this->assertSame(['priority' => 'desc'], TaskInboxRequest::fromParams(['sort' => '-priority'])->query->sort);
		$this->assertSame(['createdAt' => 'asc'], TaskInboxRequest::fromParams(['sort' => 'createdAt'])->query->sort);
	}

	public function testANonStringFilterIsRefused(): void {
		$this->expectException(ActionParameterTypeException::class);

		TaskInboxRequest::fromParams(['state' => ['open', 'closed']]);
	}
}

<?php

/**
 * The admin query console's runner: it refuses a writing document before it
 * runs, and otherwise executes bounded.
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

use OCA\OpenRegister\Exception\ReadOnlyQueryException;
use OCA\OpenRegister\Search\Service\GraphQL\GraphQLService;
use OCA\OpenRegister\Service\GraphQL\AdminQueryRunner;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\GraphQL\AdminQueryRunner
 * @uses \OCA\OpenRegister\Exception\ReadOnlyQueryException
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
 */
class AdminQueryRunnerTest extends TestCase {

	/** @var GraphQLService&MockObject */
	private GraphQLService $graphql;

	/** @var AdminQueryRunner */
	private AdminQueryRunner $runner;

	protected function setUp(): void {
		$this->graphql = $this->createMock(GraphQLService::class);
		$this->runner = new AdminQueryRunner($this->graphql);
	}//end setUp()

	public function testAReadOnlyQueryRunsBoundedAtAThousandRowsAndThirtySeconds(): void {
		$deadline = null;
		$this->graphql->expects($this->once())->method('executeBounded')
			->willReturnCallback(
				function (string $query, $variables, $operationName, int $rowCap, float $given) use (&$deadline): array {
					$deadline = $given;
					$this->assertSame(1000, $rowCap);
					return ['data' => ['x' => []], 'extensions' => ['rows' => 0, 'truncated' => false, 'durationMs' => 2]];
				}
			);

		$before = microtime(true);
		$result = $this->runner->run('{ things { id } }');
		$after = microtime(true);

		$this->assertSame(['rows' => 0, 'truncated' => false, 'durationMs' => 2], $result['extensions']);
		$this->assertGreaterThanOrEqual($before + 30, $deadline);
		$this->assertLessThanOrEqual($after + 30, $deadline);
	}//end testAReadOnlyQueryRunsBoundedAtAThousandRowsAndThirtySeconds()

	public function testAMutationIsRefusedAndNeverReachesTheSchema(): void {
		$this->graphql->expects($this->never())->method('executeBounded');

		$this->expectException(ReadOnlyQueryException::class);
		$this->expectExceptionMessage('mutation is not allowed');

		$this->runner->run('mutation { createThing(input: {}) { id } }');
	}//end testAMutationIsRefusedAndNeverReachesTheSchema()

	public function testASubscriptionIsRefusedToo(): void {
		$this->graphql->expects($this->never())->method('executeBounded');

		$this->expectException(ReadOnlyQueryException::class);
		$this->expectExceptionMessage('subscription is not allowed');

		$this->runner->run('subscription { thingChanged { id } }');
	}//end testASubscriptionIsRefusedToo()

	public function testAWritingOperationIsRefusedEvenBesideAQuery(): void {
		$this->graphql->expects($this->never())->method('executeBounded');

		$this->expectException(ReadOnlyQueryException::class);

		$this->runner->run('query Read { a { id } } mutation Write { b(input: {}) { id } }');
	}//end testAWritingOperationIsRefusedEvenBesideAQuery()

	public function testAMalformedDocumentIsRefusedAsReadOnly(): void {
		$this->graphql->expects($this->never())->method('executeBounded');

		$this->expectException(ReadOnlyQueryException::class);
		$this->expectExceptionMessage('could not be read');

		$this->runner->run('{ this is not graphql');
	}//end testAMalformedDocumentIsRefusedAsReadOnly()

	public function testTheRunnerPassesTheVariablesAndOperationThroughAndReturnsTheResultVerbatim(): void {
		$this->graphql->method('executeBounded')
			->with('query Q($s: String) { things(status: $s) { id } }', ['s' => 'open'], 'Q', 1000, $this->anything())
			->willReturn(['data' => ['things' => []], 'extensions' => ['rows' => 0, 'truncated' => true, 'durationMs' => 9]]);

		$result = $this->runner->run('query Q($s: String) { things(status: $s) { id } }', ['s' => 'open'], 'Q');

		$this->assertTrue($result['extensions']['truncated']);
	}//end testTheRunnerPassesTheVariablesAndOperationThroughAndReturnsTheResultVerbatim()
}//end class

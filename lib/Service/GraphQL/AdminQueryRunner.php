<?php

/**
 * OpenRegister AdminQueryRunner
 *
 * Runs an administrator's ad hoc GraphQL question read-only, under a row cap
 * and a deadline.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\GraphQL
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\GraphQL;

use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
use OCA\OpenRegister\Exception\ReadOnlyQueryException;
use OCA\OpenRegister\Search\Service\GraphQL\GraphQLService;

/**
 * The read-only, bounded entry the admin query console runs through.
 *
 * It refuses a document that writes BEFORE anything executes: a `mutation` or
 * `subscription` operation is rejected while the document is still just text,
 * so a write never reaches the schema. What it lets through runs through the
 * same {@see GraphQLService} as the public API, under a row cap and a
 * deadline, so the console never reads a record the administrator's own API
 * calls could not.
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
 */
class AdminQueryRunner {

	/**
	 * The most rows a run may return across all its lists.
	 *
	 * @var int
	 */
	public const ROW_CAP = 1000;

	/**
	 * The seconds a run may take before the next list is refused.
	 *
	 * @var int
	 */
	public const DEADLINE_SECONDS = 30;

	/**
	 * Constructor.
	 *
	 * @param GraphQLService $graphql The bounded GraphQL executor.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly GraphQLService $graphql,
	) {
	}//end __construct()

	/**
	 * Run a read-only query under the row cap and the deadline.
	 *
	 * @param string                    $query         The GraphQL document.
	 * @param array<string, mixed>|null $variables     Its variables.
	 * @param string|null               $operationName The operation to run.
	 *
	 * @return array<string, mixed> The execution result, with `extensions.rows`, `extensions.truncated` and `extensions.durationMs`.
	 *
	 * @throws ReadOnlyQueryException When the document holds a mutation or subscription.
	 *
	 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
	 */
	public function run(string $query, ?array $variables = null, ?string $operationName = null): array {
		$this->assertReadOnly(query: $query);

		return $this->graphql->executeBounded(
			query: $query,
			variables: $variables,
			operationName: $operationName,
			rowCap: self::ROW_CAP,
			deadline: (microtime(true) + self::DEADLINE_SECONDS)
		);
	}//end run()

	/**
	 * Refuse a document that holds any writing or streaming operation, before it runs.
	 *
	 * The parse also refuses a document that is not valid GraphQL, with the
	 * same exception, so a malformed query is a 400 and never an execution
	 * error the caller cannot distinguish from a server fault.
	 *
	 * @param string $query The GraphQL document.
	 *
	 * @return void
	 *
	 * @throws ReadOnlyQueryException When the document is not a read-only query, or does not parse.
	 *
	 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `Parser::parse()` is the library's entry point.
	 */
	private function assertReadOnly(string $query): void {
		try {
			$document = Parser::parse($query);
		} catch (\Throwable $e) {
			throw new ReadOnlyQueryException(message: 'The query could not be read: ' . $e->getMessage());
		}

		foreach ($document->definitions as $definition) {
			if ($definition instanceof OperationDefinitionNode === true && $definition->operation !== 'query') {
				throw new ReadOnlyQueryException(
					message: 'The query console runs read-only queries. A ' . $definition->operation . ' is not allowed here.'
				);
			}
		}
	}//end assertReadOnly()
}//end class

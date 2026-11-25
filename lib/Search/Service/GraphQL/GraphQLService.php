<?php

/**
 * GraphQL Service
 *
 * Main service for executing GraphQL queries with schema caching and complexity analysis.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\GraphQL
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
 */

namespace OCA\OpenRegister\Search\Service\GraphQL;

use GraphQL\Error\Error;
use GraphQL\GraphQL;
use GraphQL\Language\Parser;
use GraphQL\Type\Schema;
use Psr\Log\LoggerInterface;

/**
 * Main service for executing GraphQL queries.
 *
 * Orchestrates schema generation, query complexity analysis, the request guard
 * (rate limiting and introspection control) and the resolver context that
 * carries RBAC, DataLoader batching and audit trailing.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class GraphQLService {
	/**
	 * Constructor.
	 *
	 * @param GraphQLRequestGuard   $guard          Rate limit and introspection guard
	 * @param GraphQLSchemaServices $schema         Schema generation, pricing and error formatting
	 * @param GraphQLContextFactory $contextFactory Resolver context factory
	 * @param LoggerInterface       $logger         Logger
	 */
	public function __construct(
		private readonly GraphQLRequestGuard $guard,
		private readonly GraphQLSchemaServices $schema,
		private readonly GraphQLContextFactory $contextFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Execute a GraphQL query.
	 *
	 * @param string $query The GraphQL query string
	 * @param array<string, mixed>|null $variables Query variables
	 * @param string|null $operationName Operation name
	 *
	 * @return array<string, mixed> The execution result
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-log-operations-to-the-audit-trail
	 */
	public function execute(string $query, ?array $variables = null, ?string $operationName = null): array {
		return $this->executeRequest(
			request: new GraphQLRequest(query: $query, variables: $variables, operationName: $operationName)
		);
	}//end execute()

	/**
	 * Execute a GraphQL request.
	 *
	 * @param GraphQLRequest $request The query, its variables and the operation name
	 *
	 * @return array<string, mixed> The execution result
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-log-operations-to-the-audit-trail
	 */
	public function executeRequest(GraphQLRequest $request): array {
		try {
			return $this->run(request: $request);
		} catch (Error $e) {
			return [
				'errors' => [$this->schema->errorFormatter->format($e)],
			];
		} catch (\Exception $e) {
			return $this->internalError(exception: $e);
		}

	}//end executeRequest()

	/**
	 * Execute a query under a row cap and a deadline, reporting the rows
	 * returned, whether a cap truncated a list, and how long it took.
	 *
	 * The caps ride in the resolver context on a {@see BoundedRun}, which
	 * {@see \OCA\OpenRegister\Service\GraphQL\GraphQLResolver::resolveList()}
	 * reads: it clamps each list's page size to what is left and refuses the
	 * rest once the deadline has passed.
	 *
	 * @param string                    $query         The GraphQL document.
	 * @param array<string, mixed>|null $variables     Its variables.
	 * @param string|null               $operationName The operation to run.
	 * @param int                       $rowCap        The rows allowed across the run.
	 * @param float                     $deadline      The microtime the run must stop after.
	 *
	 * @return array<string, mixed> The result, with `extensions.rows`, `extensions.truncated` and `extensions.durationMs`.
	 *
	 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
	 */
	public function executeBounded(string $query, ?array $variables, ?string $operationName, int $rowCap, float $deadline): array {
		$bounded = new BoundedRun(rowCap: $rowCap, deadline: $deadline);
		$startedAt = microtime(true);

		try {
			$output = $this->run(request: new GraphQLRequest(query: $query, variables: $variables, operationName: $operationName), bounded: $bounded);
		} catch (Error $e) {
			$output = ['errors' => [$this->schema->errorFormatter->format($e)]];
		} catch (\Exception $e) {
			$output = $this->internalError(exception: $e);
		}//end try

		$output['extensions'] = ($output['extensions'] ?? []);
		$output['extensions']['rows'] = $bounded->rowsReturned();
		$output['extensions']['truncated'] = $bounded->truncated();
		$output['extensions']['durationMs'] = (int) round((microtime(true) - $startedAt) * 1000);

		return $output;
	}//end executeBounded()

	/**
	 * Guard, price and execute a query.
	 *
	 * @param GraphQLRequest $request The query, its variables and the operation name
	 * @param BoundedRun|null $bounded The row and time budget to run under, or null for an unbounded request
	 *
	 * @return array<string, mixed> The execution result with its complexity extension
	 *
	 * @throws Error When the request is refused or the query does not parse
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-query-complexity-analysis-must-prevent-resource-abuse
	 */
	private function run(GraphQLRequest $request, ?BoundedRun $bounded = null): array {
		$this->guard->checkRateLimit();
		$document = Parser::parse($request->query);
		$this->guard->checkIntrospection(document: $document);
		$complexity = $this->schema->complexityAnalyzer->analyze($document, $request->variables);

		$context = $this->contextFactory->create(schemaGenerator: $this->schema->schemaGenerator, operationName: $request->operationName);
		if ($bounded !== null) {
			$context[BoundedRun::CONTEXT_KEY] = $bounded;
		}

		$result = GraphQL::executeQuery(
			$this->getSchema(),
			$request->query,
			null,
			$context,
			$request->variables,
			$request->operationName,
		);

		// Errors are already formatted by the GraphQL executor.
		$output = $result->toArray();
		$output['extensions'] = ($output['extensions'] ?? []);
		$output['extensions']['complexity'] = $this->complexityExtension(complexity: $complexity);
		return $output;
	}//end run()

	/**
	 * Shape the complexity analysis as the response extension.
	 *
	 * @param array<string, mixed> $complexity The analysis
	 *
	 * @return array<string, mixed> The extension
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-query-complexity-analysis-must-prevent-resource-abuse
	 */
	private function complexityExtension(array $complexity): array {
		return [
			'estimated' => $complexity['cost'],
			'max' => $complexity['maxCost'],
			'depth' => $complexity['depth'],
			'maxDepth' => $complexity['maxDepth'],
		];
	}//end complexityExtension()

	/**
	 * Log an unexpected failure and answer without its details.
	 *
	 * @param \Exception $exception The failure
	 *
	 * @return array<string, mixed> The execution result
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-log-operations-to-the-audit-trail
	 */
	private function internalError(\Exception $exception): array {
		$this->logger->error(
			'GraphQL execution error: ' . $exception->getMessage(),
			[
				'exception' => $exception,
			]
		);

		return [
			'errors' => [
				[
					'message' => 'Internal server error',
					'extensions' => ['code' => 'INTERNAL_ERROR'],
				],
			],
		];
	}//end internalError()

	/**
	 * Get the GraphQL schema.
	 *
	 * @return Schema The GraphQL schema
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
	 */
	private function getSchema(): Schema {
		// Wire the resolver into the schema generator.
		$this->schema->resolver->reset();
		$this->schema->schemaGenerator->setResolver($this->schema->resolver);

		// Generate fresh each request.
		// APCu caching of webonyx Schema objects is complex because they contain closures.
		// We rely on the fact that schema generation is fast (~50ms for typical installs).
		return $this->schema->schemaGenerator->generate();
	}//end getSchema()
}//end class

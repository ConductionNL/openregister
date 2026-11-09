<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\BuiltIn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction BV
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\BuiltIn;

use OCA\OpenRegister\Db\AuditTrail\AuditedObject;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs one tool invocation and writes exactly one immutable, hash-chained
 * audit record for it, success or failure, before returning or rethrowing.
 *
 * The audit write is fail-soft: a broken write is logged and MUST NOT mask
 * (or replace) the invocation's real result or error — losing an audit row
 * is bad, losing the invocation outcome to an audit hiccup is worse.
 */
final class ToolInvocationAudit {

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapperInterface $auditTrailMapper Immutable hash-chained audit-trail writer.
	 * @param LoggerInterface           $logger           Logger for a failed audit write.
	 * @param string                    $source           Short class name used in the log message.
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $auditTrailMapper,
		private readonly LoggerInterface $logger,
		private readonly string $source,
	) {
	}//end __construct()

	/**
	 * Run the invocation and audit its outcome.
	 *
	 * @param ToolAuditTarget                              $target    What the record is about.
	 * @param array<string, mixed>                         $arguments The tool arguments (digested, never stored).
	 * @param callable(): array<string, mixed>             $call      The invocation.
	 * @param callable(array<string, mixed>): array<string, mixed> $summarise The success summary of a result.
	 *
	 * @return array<string, mixed> The invocation result.
	 *
	 * @throws Throwable Whatever the invocation throws, after auditing it.
	 */
	public function run(ToolAuditTarget $target, array $arguments, callable $call, callable $summarise): array {
		$paramsDigest = ToolInvocationDigest::paramsDigest(arguments: $arguments);

		try {
			$result = $call();
			$this->write(target: $target, paramsDigest: $paramsDigest, resultSummary: $summarise($result), result: $result);

			return $result;
		} catch (Throwable $e) {
			$this->write(target: $target, paramsDigest: $paramsDigest, resultSummary: ToolInvocationDigest::errorSummary(error: $e), result: null);

			throw $e;
		}
	}//end run()

	/**
	 * Write the audit record; a failed write is logged, never thrown.
	 *
	 * @param ToolAuditTarget           $target        What the record is about.
	 * @param string                    $paramsDigest  SHA-256 hex digest of the arguments.
	 * @param array<string, mixed>      $resultSummary Structured outcome summary.
	 * @param array<string, mixed>|null $result        The result, or null on failure.
	 *
	 * @return void
	 */
	private function write(ToolAuditTarget $target, string $paramsDigest, array $resultSummary, ?array $result): void {
		try {
			$this->auditTrailMapper->createToolInvocationEntry(
				toolId: $target->toolId,
				paramsDigest: $paramsDigest,
				resultSummary: $resultSummary,
				about: new AuditedObject(register: $target->register, schema: $target->schema, objectUuid: ToolInvocationDigest::resultId(result: $result))
			);
		} catch (Throwable $e) {
			$message = '[' . $this->source . '] Failed to write invocation audit record';
			$this->logger->error($message, ['toolId' => $target->toolId, 'error' => $e->getMessage()]);
		}
	}//end write()
}//end class

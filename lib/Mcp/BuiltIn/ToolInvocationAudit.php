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
use OCA\OpenRegister\Db\AuditTrail\ToolInvocation;
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
	 * @param ToolAuditTarget   $target     What the record is about.
	 * @param AuditedInvocation $invocation The arguments, the invocation and its success summary.
	 *
	 * @return array<string, mixed> The invocation result.
	 *
	 * @throws Throwable Whatever the invocation throws, after auditing it.
	 */
	public function run(ToolAuditTarget $target, AuditedInvocation $invocation): array {
		$paramsDigest = ToolInvocationDigest::paramsDigest(arguments: $invocation->arguments);

		try {
			$result = ($invocation->call)();
			$outcome = new ToolInvocationOutcome(paramsDigest: $paramsDigest, resultSummary: ($invocation->summarise)($result), result: $result);
			$this->write(target: $target, outcome: $outcome);

			return $result;
		} catch (Throwable $e) {
			$outcome = new ToolInvocationOutcome(paramsDigest: $paramsDigest, resultSummary: ToolInvocationDigest::errorSummary(error: $e), result: null);
			$this->write(target: $target, outcome: $outcome);

			throw $e;
		}
	}//end run()

	/**
	 * Write the audit record; a failed write is logged, never thrown.
	 *
	 * @param ToolAuditTarget       $target  What the record is about.
	 * @param ToolInvocationOutcome $outcome What happened, digest included.
	 *
	 * @return void
	 */
	private function write(ToolAuditTarget $target, ToolInvocationOutcome $outcome): void {
		try {
			$this->auditTrailMapper->recordToolInvocation(
				invocation: new ToolInvocation(
					toolId: $target->toolId,
					paramsDigest: $outcome->paramsDigest,
					resultSummary: $outcome->resultSummary,
					about: new AuditedObject(register: $target->register, schema: $target->schema, objectUuid: $outcome->objectUuid())
				)
			);
		} catch (Throwable $e) {
			$message = '[' . $this->source . '] Failed to write invocation audit record';
			$this->logger->error($message, ['toolId' => $target->toolId, 'error' => $e->getMessage()]);
		}
	}//end write()
}//end class

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

use OCA\OpenRegister\Contract\ToolInvocationRecorderInterface;
use OCA\OpenRegister\Db\AuditTrail\AuditedObject;
use OCA\OpenRegister\Db\AuditTrail\ToolInvocation;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes an app provider's tool invocation as the same hash-chained record
 * the built-in providers write ({@see ToolInvocationAudit}): the argument
 * digest and a result summary, never the raw arguments.
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */
final class ToolInvocationRecorder implements ToolInvocationRecorderInterface {

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapperInterface $auditTrailMapper Immutable hash-chained audit-trail writer.
	 * @param LoggerInterface           $logger           Logger for a failed audit write.
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $auditTrailMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record one invocation; a failed write is logged, never thrown.
	 *
	 * @param string               $toolId    The full tool id.
	 * @param array<string, mixed> $arguments The arguments.
	 * @param array<string, mixed> $result    The tool's answer.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function record(string $toolId, array $arguments, array $result): void {
		try {
			$this->auditTrailMapper->recordToolInvocation(
				invocation: new ToolInvocation(
					toolId: $toolId,
					paramsDigest: ToolInvocationDigest::paramsDigest(arguments: $arguments),
					resultSummary: self::summary(result: $result),
					about: new AuditedObject(objectUuid: ToolInvocationDigest::resultId(result: $result))
				)
			);
		} catch (Throwable $e) {
			$this->logger->error('[ToolInvocationRecorder] Failed to write invocation audit record', ['toolId' => $toolId, 'error' => $e->getMessage()]);
		}
	}//end record()

	/**
	 * The result summary: a refusal by its code, a success by its id.
	 *
	 * @param array<string, mixed> $result The tool's answer.
	 *
	 * @return array<string, mixed>
	 */
	private static function summary(array $result): array {
		if (($result['isError'] ?? false) === true) {
			return ['isError' => true, 'error' => substr((string)($result['error'] ?? ''), 0, 64)];
		}

		return ToolInvocationDigest::idSummary(result: $result);
	}//end summary()
}//end class

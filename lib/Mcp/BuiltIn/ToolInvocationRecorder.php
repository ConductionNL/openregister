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

/**
 * Writes an app provider's tool invocation as the same hash-chained record
 * the built-in providers write ({@see ToolInvocationAudit}): the argument
 * digest and a result summary, never the raw arguments.
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */
final class ToolInvocationRecorder implements ToolInvocationRecorderInterface {

	/**
	 * The built-in providers' audit writer, logging failures under this class.
	 *
	 * @var ToolInvocationAudit
	 */
	private readonly ToolInvocationAudit $audit;

	/**
	 * Constructor.
	 *
	 * @param ToolInvocationAudit $audit The hash-chained invocation audit writer the built-in providers use.
	 */
	public function __construct(ToolInvocationAudit $audit) {
		$this->audit = $audit->withSource(source: 'ToolInvocationRecorder');
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
		// The tool already answered: the call hands its result back, and the
		// writer records the digest, the summary and the result's id, logging
		// (never throwing) a failed write.
		$this->audit->run(
			target: new ToolAuditTarget(toolId: $toolId),
			invocation: new AuditedInvocation(
				arguments: $arguments,
				call: static fn (): array => $result,
				summarise: static fn (array $answer): array => self::summary(result: $answer)
			)
		);
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

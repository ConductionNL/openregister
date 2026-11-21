<?php

/**
 * What an assistant reply is grounded in: the RAG context, the earlier messages and the agent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Mcp\Service\Chat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/chat-ai/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Service\Chat;

use OCA\OpenRegister\Mcp\Db\Agent;

/**
 * The grounding of one assistant reply.
 *
 * @psalm-immutable
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class ReplyGrounding {
	/**
	 * Hold the grounding.
	 *
	 * @param array      $context        RAG context with 'text' and 'sources' keys.
	 * @param array      $messageHistory Array of LLPhantMessage objects.
	 * @param Agent|null $agent          Agent configuration, null when the conversation has none.
	 *
	 * @psalm-param array{text: string, sources: list<array>} $context
	 */
	public function __construct(
		public readonly array $context,
		public readonly array $messageHistory,
		public readonly ?Agent $agent,
	) {
	}//end __construct()
}//end class

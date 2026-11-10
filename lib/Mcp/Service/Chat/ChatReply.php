<?php

/**
 * An assistant reply as it is stored: its text and the RAG sources it cites.
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

/**
 * An assistant reply to store.
 *
 * @psalm-immutable
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class ChatReply {
	/**
	 * Hold the reply.
	 *
	 * @param string     $content Reply content.
	 * @param array|null $sources Optional RAG sources.
	 */
	public function __construct(
		public readonly string $content,
		public readonly ?array $sources,
	) {
	}//end __construct()
}//end class

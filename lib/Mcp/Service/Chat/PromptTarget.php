<?php

/**
 * The LLM a housekeeping prompt (title, summary) is sent to: the provider and its configuration.
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

namespace OCA\OpenRegister\Mcp\Service\Chat;

use LLPhant\OllamaConfig;
use LLPhant\OpenAIConfig;

/**
 * The provider and configuration a prompt runs against.
 *
 * @psalm-immutable
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class PromptTarget {
	/**
	 * Hold the target.
	 *
	 * @param mixed                     $chatProvider Configured chat provider.
	 * @param OllamaConfig|OpenAIConfig $config       Provider configuration.
	 */
	public function __construct(
		public readonly mixed $chatProvider,
		public readonly OllamaConfig|OpenAIConfig $config,
	) {
	}//end __construct()
}//end class

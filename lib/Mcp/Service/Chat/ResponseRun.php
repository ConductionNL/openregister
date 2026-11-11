<?php

/**
 * ResponseRun
 *
 * One response generation of ResponseGenerationHandler: the turn and the
 * message history it answers, when it started, the tools it loaded and how
 * long that took, and the LLM settings and provider it runs on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/chat-ai/spec.md
 */

namespace OCA\OpenRegister\Mcp\Service\Chat;

/**
 * The turn, history, timings, tools and provider of one response generation.
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class ResponseRun {

	/**
	 * When the generation started (microtime).
	 *
	 * @var float
	 */
	public float $startTime = 0.0;

	/**
	 * The agent's enabled tools, filtered by the selected tools.
	 *
	 * @var array
	 */
	public array $tools = [];

	/**
	 * How long loading the tools took, in seconds.
	 *
	 * @var float
	 */
	public float $toolsTime = 0.0;

	/**
	 * The LLM settings.
	 *
	 * @var array
	 */
	public array $llmConfig = [];

	/**
	 * The chat provider the settings select.
	 *
	 * @var mixed
	 */
	public mixed $provider = null;

	/**
	 * Constructor.
	 *
	 * @param array $turn           The user's message, the RAG context, the agent, the selected tools,
	 *                              the streaming channel and the app context.
	 * @param array $messageHistory Array of LLPhantMessage objects.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		public readonly array $turn,
		public readonly array $messageHistory,
	) {
	}//end __construct()
}//end class

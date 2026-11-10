<?php

/**
 * ChatProbe
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Mcp\Service\Chat
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/chat-ai/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Service\Chat;

/**
 * One trial chat: which provider, with which settings, with which message.
 *
 * @psalm-immutable
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class ChatProbe {

	/**
	 * The message a probe sends when the caller names none.
	 *
	 * @var string
	 */
	public const DEFAULT_MESSAGE = 'Hello! Please respond with a brief greeting.';

	/**
	 * Describe a probe.
	 *
	 * @param string $provider    Provider to test (openai, fireworks, ollama).
	 * @param array  $config      Provider-specific configuration.
	 * @param string $testMessage Message to send.
	 */
	public function __construct(
		public readonly string $provider,
		public readonly array $config,
		public readonly string $testMessage = self::DEFAULT_MESSAGE,
	) {
	}//end __construct()

	/**
	 * The model the probe names, or 'unknown'.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function model(): mixed {
		return $this->config['chatModel'] ?? $this->config['model'] ?? 'unknown';
	}//end model()
}//end class

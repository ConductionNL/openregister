<?php

/**
 * FireworksBaseUrl
 *
 * The Fireworks AI base URL a chat config points at: the configured base URL
 * (or Fireworks' default) without a trailing slash, ending in `/v1`. Shared by
 * the chat handlers that build a Fireworks (OpenAI-compatible) config.
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
 * Normalises the Fireworks AI base URL.
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class FireworksBaseUrl {
	/**
	 * The base URL of a Fireworks config, ending in `/v1`.
	 *
	 * @param array $fireworksConfig The Fireworks LLM settings.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public static function of(array $fireworksConfig): string {
		$baseUrl = rtrim($fireworksConfig['baseUrl'] ?? 'https://api.fireworks.ai/inference/v1', '/');
		if (str_ends_with($baseUrl, '/v1') === false) {
			$baseUrl .= '/v1';
		}

		return $baseUrl;
	}//end of()
}//end class

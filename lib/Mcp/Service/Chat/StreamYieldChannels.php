<?php

/**
 * StreamYieldChannels: builds the streaming chat channel the chat-stream controller registers its callbacks on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Mcp\Service\Chat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Service\Chat;

use OCA\OpenRegister\Kernel\Contracts\ChatStreamChannelInterface;

/**
 * Constructs exactly what the controller used to construct itself with `new`:
 * a fresh StreamYieldChannel with no arguments, typed as the channel contract.
 * Types only: no logic lives here.
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */
final class StreamYieldChannels {

	/**
	 * A fresh channel with no callbacks registered.
	 *
	 * @return ChatStreamChannelInterface A fresh channel.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public static function channel(): ChatStreamChannelInterface {
		return new StreamYieldChannel();
	}//end channel()
}//end class

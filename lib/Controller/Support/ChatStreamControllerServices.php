<?php

/**
 * OpenRegister chat stream controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * The service bundles of the chat stream API.
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class ChatStreamControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ChatStreamChat     $chat     The chat bundle.
	 * @param ChatStreamPlatform $platform The platform bundle.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		public readonly ChatStreamChat $chat,
		public readonly ChatStreamPlatform $platform,
	) {
	}//end __construct()
}//end class

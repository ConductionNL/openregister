<?php

/**
 * OpenRegister chat controller services
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
 * The service bundles of the chat API.
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class ChatControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ChatMappers  $mappers  The mappers bundle.
	 * @param ChatDomain   $domain   The domain bundle.
	 * @param ChatPlatform $platform The platform bundle.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		public readonly ChatMappers $mappers,
		public readonly ChatDomain $domain,
		public readonly ChatPlatform $platform,
	) {
	}//end __construct()
}//end class

<?php

/**
 * OpenRegister llm settings platform
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

use Psr\Container\ContainerInterface;

/**
 * The platform services of the llm settings API.
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class LlmSettingsPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param ContainerInterface $container DI container.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		public readonly ContainerInterface $container,
	) {
	}//end __construct()
}//end class

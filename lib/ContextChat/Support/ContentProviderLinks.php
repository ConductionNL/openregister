<?php

/**
 * OpenRegister content provider links
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category ContextChat
 * @package  OCA\OpenRegister\ContextChat\Support
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

namespace OCA\OpenRegister\ContextChat\Support;

use OCA\OpenRegister\Service\DeepLinkResolverInterface;
use OCP\IURLGenerator;

/**
 * The deep-link resolver and URL generator the Context Chat provider links each item with.
 *
 * @spec openspec/specs/context-chat-provider/spec.md
 */
final readonly class ContentProviderLinks {
	/**
	 * Bundle the services.
	 *
	 * @param DeepLinkResolverInterface $deepLinkRegistry Resolves an object's deep link.
	 * @param IURLGenerator             $urlGenerator     Builds the fallback URL.
	 *
	 * @spec openspec/specs/context-chat-provider/spec.md
	 */
	public function __construct(
		public DeepLinkResolverInterface $deepLinkRegistry,
		public IURLGenerator $urlGenerator,
	) {
	}//end __construct()
}//end class

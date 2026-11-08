<?php

/**
 * The container Talk's classes resolve from and the translator of room fields.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCP\IL10N;
use Psr\Container\ContainerInterface;

/**
 * The container Talk's classes resolve from and the translator of room fields.
 *
 * @spec openspec/specs/integration-talk/spec.md
 */
final class TalkLinkPlatform {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ContainerInterface $container Container the Talk app's services are resolved from.
	 * @param IL10N              $l10n      Translator for the room fields.
	 *
	 * @spec openspec/specs/integration-talk/spec.md
	 */
	public function __construct(
		public readonly ContainerInterface $container,
		public readonly IL10N $l10n,
	) {
	}//end __construct()
}//end class

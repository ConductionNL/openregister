<?php

/**
 * ExternalLinkResolverInterface: what callers outside the component use of ExternalLinkResolver.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExternalLink
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExternalLink;

/**
 * Declares only what callers outside the component use of ExternalLinkResolver; the rest
 * of the class stays its own.
 */
interface ExternalLinkResolverInterface {

	/**
	 * The schema configuration key carrying the declarations.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-external-links';
}//end interface

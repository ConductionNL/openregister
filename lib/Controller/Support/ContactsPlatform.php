<?php

/**
 * OpenRegister contacts platform
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

use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\PersonLinkServiceInterface;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Services of the contacts API.
 *
 * @spec openspec/specs/contacts-actions/spec.md
 */
final class ContactsPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param PersonLinkServiceInterface $personLinks People on objects: user and contact links
	 * @param IL10N             $l10n        Localization service
	 * @param LoggerInterface   $logger      Logger
	 * @param PermissionHandlerInterface $permissions Answers the object update right for contact writes
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function __construct(
		public readonly PersonLinkServiceInterface $personLinks,
		public readonly IL10N $l10n,
		public readonly LoggerInterface $logger,
		public readonly PermissionHandlerInterface $permissions,
	) {
	}//end __construct()
}//end class

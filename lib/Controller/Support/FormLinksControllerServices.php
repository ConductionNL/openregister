<?php

/**
 * OpenRegister FormLinksController services
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

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\FormLinkServiceInterface;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * The collaborators of FormLinksController, wired by DI autowiring.
 *
 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
 */
final class FormLinksControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param FormLinkServiceInterface $formLinkService Form-link service.
	 * @param ObjectOperationsInterface $objectService Object service.
	 * @param IL10N $l10n Localisation.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function __construct(
		public readonly FormLinkServiceInterface $formLinkService,
		public readonly ObjectOperationsInterface $objectService,
		public readonly IL10N $l10n,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class

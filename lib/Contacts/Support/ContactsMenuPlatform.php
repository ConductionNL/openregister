<?php

/**
 * OpenRegister contacts menu platform
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contacts
 * @package  OCA\OpenRegister\Contacts\Support
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

namespace OCA\OpenRegister\Contacts\Support;

use OCP\Contacts\ContactsMenu\IActionFactory;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * The action factory, URL generator, translations and logger the contacts menu builds its actions with.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final readonly class ContactsMenuPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IActionFactory  $actionFactory Builds the menu actions.
	 * @param IURLGenerator   $urlGenerator  Builds absolute URLs.
	 * @param IL10N           $l10n          Translations.
	 * @param LoggerInterface $logger        Logger.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public IActionFactory $actionFactory,
		public IURLGenerator $urlGenerator,
		public IL10N $l10n,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class

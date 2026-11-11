<?php

/**
 * OpenRegister Form Submit Platform
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
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

namespace OCA\OpenRegister\Service\Form;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * What a submit answers and reports with: translations, the log, the receipt event and the clock.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */
final readonly class FormSubmitPlatform {

	/**
	 * Bundle the collaborators.
	 *
	 * @param IL10N $l10n Translations, for answers a resident reads.
	 * @param LoggerInterface $logger Records each compensation.
	 * @param IEventDispatcher $events Announces a draft that left draft (receipt).
	 * @param ITimeFactory $time The moment of receipt.
	 */
	public function __construct(
		public IL10N $l10n,
		public LoggerInterface $logger,
		public IEventDispatcher $events,
		public ITimeFactory $time,
	) {
	}//end __construct()
}//end class

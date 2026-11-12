<?php

/**
 * OpenRegister draft activation signals: how a draft that left draft is announced
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Draft activation signals: the event dispatcher and the clock for the moment of receipt.
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
 */
final class DraftActivationSignals {
	/**
	 * Bundle the services.
	 *
	 * @param IEventDispatcher $events Announces the object that left draft.
	 * @param ITimeFactory     $time   The clock, for the moment of receipt.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
	 */
	public function __construct(
		public readonly IEventDispatcher $events,
		public readonly ITimeFactory $time,
	) {
	}//end __construct()
}//end class

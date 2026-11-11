<?php

/**
 * One message to the parties on an object.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Party
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-a-parties-notification-asks-integriq-before-it-mails-a-party-req-ero-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Party;

use OCA\OpenRegister\Notifications\Service\Notification\OptOutAuthority;

/**
 * The object, subject, body and category of one message to an object's parties.
 *
 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-a-parties-notification-asks-integriq-before-it-mails-a-party-req-ero-003
 */
final class PartyMessage {

	/**
	 * The object whose parties are mailed.
	 *
	 * @var string
	 */
	public readonly string $objectUuid;

	/**
	 * The subject.
	 *
	 * @var string
	 */
	public readonly string $subject;

	/**
	 * The body.
	 *
	 * @var string
	 */
	public readonly string $body;

	/**
	 * The message category; absent or unknown reads as `service`.
	 *
	 * @var string
	 */
	public readonly string $category;

	/**
	 * Constructor.
	 *
	 * @param string $objectUuid The object.
	 * @param string $subject    The subject.
	 * @param string $body       The body.
	 * @param string $category   The message category.
	 */
	public function __construct(
		string $objectUuid,
		string $subject,
		string $body,
		string $category=OptOutAuthority::DEFAULT_CATEGORY
	) {
		$this->objectUuid = $objectUuid;
		$this->subject    = $subject;
		$this->body       = $body;
		$this->category   = $category;
	}//end __construct()
}//end class

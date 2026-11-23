<?php

/**
 * What a notice is about: an object, and the sub-resource of it when the notice is narrower.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * The subject a notice is about.
 *
 * @psalm-immutable
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
 */
final class NoticeSubject {
	/**
	 * Hold the values.
	 *
	 * @param string  $objectUuid The object.
	 * @param ?string $subjectId  The sub-resource, or null for the object as a whole.
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly ?string $subjectId,
	) {
	}//end __construct()
}//end class

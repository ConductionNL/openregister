<?php

/**
 * What fills the placeholders of a notification template: the data, the context and the language chain.
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
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * The values a template's placeholders are answered from.
 *
 * @psalm-immutable
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class PlaceholderValues {
	/**
	 * Hold the values.
	 *
	 * @param array<string, mixed>    $data      The primary data (object data, or a flow item's json).
	 * @param array<string, mixed>    $context   Secondary lookup values.
	 * @param array<int, string|null> $languages Language chain for a language map, first wins (recipient, then register default).
	 */
	public function __construct(
		public readonly array $data,
		public readonly array $context,
		public readonly array $languages = [],
	) {
	}//end __construct()
}//end class

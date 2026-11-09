<?php

/**
 * How a shareable configuration type presents itself: its id, display name and topic.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config\Types
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config\Types;

/**
 * The identity a shareable configuration type is listed and bundled under.
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */
final class ShareableTypeIdentity {
	/**
	 * Hold the identity.
	 *
	 * @param string $id    Stable type id, written into every bundle.
	 * @param string $name  Human display name.
	 * @param string $topic Topic the type is filed under.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $topic,
	) {
	}//end __construct()
}//end class

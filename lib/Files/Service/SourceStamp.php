<?php

/**
 * OpenRegister SourceStamp.
 *
 * A text source and the time it last changed, compared with the newest chunk stored for it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service;

/**
 * A text source and the time it last changed, compared with the newest chunk stored for it.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class SourceStamp {
	/**
	 * Constructor.
	 *
	 * @param string $sourceType The source type: 'file' or 'object'.
	 * @param int    $sourceId   The source id.
	 * @param int    $timestamp  When the source last changed (Unix time).
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $sourceType,
		public readonly int $sourceId,
		public readonly int $timestamp,
	) {
	}//end __construct()
}//end class

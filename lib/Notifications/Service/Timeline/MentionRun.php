<?php

/**
 * One pass over the people an entry names.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Timeline
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Timeline;

/**
 * The entry just written, the register and schema as the caller addressed
 * them, and who wrote it: what every mention in that entry is handled against.
 *
 * Built by {@see EntryMentionService::apply()} and never handed outside it.
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
final class MentionRun {

	/**
	 * Constructor.
	 *
	 * @param WrittenEntry $written  The entry just written: its object, uuid and text.
	 * @param string|null  $register The register as the caller addressed it.
	 * @param string|null  $schema   The schema as the caller addressed it.
	 * @param string|null  $author   Who wrote the entry.
	 */
	public function __construct(
		public readonly WrittenEntry $written,
		public readonly ?string $register,
		public readonly ?string $schema,
		public readonly ?string $author,
	) {
	}//end __construct()
}//end class

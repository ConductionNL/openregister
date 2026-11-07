<?php

/**
 * An entry just written, with the object and text its derived steps read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Notifications\Service\Timeline
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Timeline;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Db\TimelineEntry;

/**
 * An entry, the object it hangs on and its text.
 */
class WrittenEntry {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity  $object  The object.
	 * @param TimelineEntry $entry   The entry.
	 * @param string        $message Its text.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly TimelineEntry $entry,
		public readonly string $message,
	) {
	}//end __construct()

	/**
	 * The entry's id.
	 *
	 * @return string The id.
	 */
	public function uuid(): string {
		return (string)$this->entry->getUuid();
	}//end uuid()

	/**
	 * What the reference index reads.
	 *
	 * @return ReferenceSource The source.
	 */
	public function referenceSource(): ReferenceSource {
		return new ReferenceSource(
			entryUuid: $this->uuid(),
			sourceUuid: (string)$this->object->getUuid(),
			text: $this->message
		);
	}//end referenceSource()
}//end class

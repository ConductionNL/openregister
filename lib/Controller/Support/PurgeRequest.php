<?php

/**
 * One purge of a trashed object: the object, its schema, its recovery window and the waiver.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Deletion\DeletionWindowInterface;

/**
 * What a purge is refused or carried out on.
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
final class PurgeRequest {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity                 $object The object being purged.
	 * @param Schema|null                  $schema The object's resolved schema.
	 * @param DeletionWindowInterface|null $window The published recovery window.
	 * @param bool                         $force  Whether the caller explicitly waived the window.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly ?Schema $schema,
		public readonly ?DeletionWindowInterface $window,
		public readonly bool $force,
	) {
	}//end __construct()

	/**
	 * Whether the recovery window is still open and the caller did not waive it.
	 *
	 * @return bool True when the window refuses the purge.
	 */
	public function windowRefuses(): bool {
		return $this->window !== null && $this->window->hasLapsed() === false && $this->force === false;
	}//end windowRefuses()
}//end class

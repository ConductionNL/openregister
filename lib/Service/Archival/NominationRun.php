<?php

/**
 * One nomination of one record, as it is derived and written.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * The record and its schema, and what the nomination has found so far: the
 * archiving declaration, the effective category and the derivation to write.
 *
 * Never leaves ArchivalNominationService.
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */
final class NominationRun {

	/**
	 * The archiving the schema declares for this record; empty until read.
	 *
	 * @var array<string, mixed>
	 */
	public array $archive = [];

	/**
	 * The effective selectielijst category, once derived.
	 *
	 * @var string|null
	 */
	public ?string $classification = null;

	/**
	 * The derivation to write onto the record; empty when nothing was derived.
	 *
	 * @var array<string, mixed>
	 */
	public array $derived = [];

	/**
	 * Start a nomination.
	 *
	 * @param ObjectEntity $object The record.
	 * @param Schema       $schema Its schema.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly Schema $schema,
	) {
	}//end __construct()
}//end class

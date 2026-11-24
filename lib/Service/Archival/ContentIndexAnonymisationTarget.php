<?php

/**
 * The content search index of one record, as a store an anonymisation reaches.
 *
 * Text extraction keeps the record's text as chunks (source_type 'object'),
 * and keyword search over content reads those chunks. The chunks were cut from
 * the record before it was anonymised, so they still carry the name. They are
 * dropped; the next extraction cuts them again from the anonymised record.
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
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md#requirement-anonymising-reaches-every-derived-copy-or-does-nothing-req-aao-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Kernel\Contracts\ChunkMapperInterface;

/**
 * Drops the content-search chunks of one record.
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md#requirement-anonymising-reaches-every-derived-copy-or-does-nothing-req-aao-003
 */
final class ContentIndexAnonymisationTarget implements AnonymisationTarget {

	/**
	 * The chunk source type of a record.
	 */
	private const SOURCE_TYPE = 'object';

	/**
	 * Constructor.
	 *
	 * @param ChunkMapperInterface $chunks   The chunk store.
	 * @param int                  $objectId The record's database id, which the chunks are keyed on.
	 */
	public function __construct(
		private readonly ChunkMapperInterface $chunks,
		private readonly int $objectId,
	) {
	}//end __construct()

	/**
	 * What this target is.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md#requirement-anonymising-reaches-every-derived-copy-or-does-nothing-req-aao-003
	 */
	public function name(): string {
		return 'the content search index';
	}//end name()

	/**
	 * Reach the chunks without writing.
	 *
	 * @param AnonymisationPlan $plan The plan.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md#requirement-anonymising-reaches-every-derived-copy-or-does-nothing-req-aao-003
	 */
	public function prepare(AnonymisationPlan $plan): void {
		$this->chunks->findBySource(sourceType: self::SOURCE_TYPE, sourceId: $this->objectId);
	}//end prepare()

	/**
	 * Drop the chunks.
	 *
	 * @param AnonymisationPlan $plan The plan.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md#requirement-anonymising-reaches-every-derived-copy-or-does-nothing-req-aao-003
	 */
	public function apply(AnonymisationPlan $plan): void {
		$this->chunks->deleteBySource(sourceType: self::SOURCE_TYPE, sourceId: $this->objectId);
	}//end apply()
}//end class

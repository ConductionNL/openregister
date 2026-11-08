<?php

/**
 * The record an anonymisation is planned for.
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

/**
 * One record as an anonymisation sees it: its uuid, its payload, its schema's
 * archival annotation and the name its profile goes by in the report.
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
 */
final class AnonymisationSubject {
	/**
	 * Hold the record.
	 *
	 * @param string               $objectUuid  The record.
	 * @param array<string, mixed> $payload     Its properties.
	 * @param array<string, mixed> $annotation  Its schema's archival annotation.
	 * @param string               $profileName What to call the profile in the report.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly array $payload,
		public readonly array $annotation,
		public readonly string $profileName = '',
	) {
	}//end __construct()
}//end class

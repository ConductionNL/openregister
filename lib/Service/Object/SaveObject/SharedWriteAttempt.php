<?php

/**
 * A write attempted into shared master data: where it was aimed and by which organisation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/tenant-isolation-audit/spec.md#requirement-a-log-line-names-the-tenant-pseudonymously-and-never-carries-a-secret-req-sle-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

/**
 * The target of the write and the organisation that attempted it.
 *
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/tenant-isolation-audit/spec.md#requirement-a-log-line-names-the-tenant-pseudonymously-and-never-carries-a-secret-req-sle-003
 */
final class SharedWriteAttempt {
	/**
	 * The attempt.
	 *
	 * @param ObjectWriteTarget $target             The register and schema being written, and the object uuid when named.
	 * @param string|null       $sourceOrganisation The organisation that attempted the write.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/tenant-isolation-audit/spec.md#requirement-a-log-line-names-the-tenant-pseudonymously-and-never-carries-a-secret-req-sle-003
	 */
	public function __construct(
		public readonly ObjectWriteTarget $target,
		public readonly ?string $sourceOrganisation,
	) {
	}//end __construct()
}//end class

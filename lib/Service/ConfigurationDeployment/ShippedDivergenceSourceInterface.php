<?php

/**
 * Where a subject differs from what its app shipped, for the effective-configuration explainer.
 *
 * The explainer reports the divergence beside the layers (local-changes-to-app-shipped-configuration
 * D-6). The baseline lives in this namespace's value store, but comparing it with the live
 * definition is ShippedBaseline's work, so the explainer depends on this contract and not on
 * that namespace, which already depends on this one.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ConfigurationDeployment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ConfigurationDeployment;

/**
 * Reads the divergence of one subject from its shipped baseline.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
interface ShippedDivergenceSourceInterface {

	/**
	 * The configuration key under which a subject's shipped baseline is kept.
	 *
	 * @var string
	 */
	public const BASELINE_KEY = 'schema.shippedBaseline';

	/**
	 * Where a subject differs from what its app shipped, or null when the subject is not one that can have a baseline.
	 *
	 * @param string $subject The subject reference, e.g. `schema:zaak`.
	 *
	 * @return array<string, mixed>|null The report.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function forSubject(string $subject): ?array;
}//end interface

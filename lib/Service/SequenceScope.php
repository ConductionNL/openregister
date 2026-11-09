<?php

/**
 * OpenRegister SequenceScope
 *
 * The (register, schema, scope key) triple one running-number counter belongs to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The scope of one running-number counter.
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-generated-identifier-is-frozen-after-creation
 */
final class SequenceScope {

	/**
	 * Constructor.
	 *
	 * @param int    $registerId The register the sequence is scoped to.
	 * @param int    $schemaId   The schema the sequence is scoped to.
	 * @param string $scopeKey   The scope discriminator (e.g. "2026", "2026-06" or "" for global).
	 */
	public function __construct(
		public readonly int $registerId,
		public readonly int $schemaId,
		public readonly string $scopeKey,
	) {
	}//end __construct()

	/**
	 * The scope as the named arguments the sequence mapper takes.
	 *
	 * @return array{registerId: int, schemaId: int, scopeKey: string}
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-generated-identifier-is-frozen-after-creation
	 */
	public function toArray(): array {
		return ['registerId' => $this->registerId, 'schemaId' => $this->schemaId, 'scopeKey' => $this->scopeKey];
	}//end toArray()
}//end class

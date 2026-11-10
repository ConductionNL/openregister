<?php

/**
 * One property of one object shown to one user: the fact a reveal entry records.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Who saw which property of which object, and under which schema.
 *
 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
 */
final class PropertyReveal {
	/**
	 * Name the reveal.
	 *
	 * @param string   $userId     Who saw it.
	 * @param string   $objectUuid Which object.
	 * @param string   $property   Which property.
	 * @param int|null $schemaId   The schema, for the entry.
	 */
	public function __construct(
		public readonly string $userId,
		public readonly string $objectUuid,
		public readonly string $property,
		public readonly ?int $schemaId = null,
	) {
	}//end __construct()

	/**
	 * The identity a repeat of this reveal shares within one request.
	 *
	 * @return string The user, object and property joined.
	 */
	public function key(): string {
		return $this->userId . '|' . $this->objectUuid . '|' . $this->property;
	}//end key()

	/**
	 * Whether the reveal names all three of who, what and which property.
	 *
	 * @return bool False when any of them is empty.
	 */
	public function isComplete(): bool {
		return $this->userId !== '' && $this->objectUuid !== '' && $this->property !== '';
	}//end isComplete()
}//end class

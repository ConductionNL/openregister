<?php

/**
 * OpenRegister Gdpr CasePayload
 *
 * The two small rules every DSAR case write path applies the same way: a case
 * that {@see CaseObjectAccessor::load()} could not load (absent, or not
 * readable under the caller's RBAC + tenant scope) refuses the operation
 * before anything is read or written, and a list sub-collection of the case
 * (`evidence`, `redactions`) that is missing or not an array starts empty.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Case
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

namespace OCA\OpenRegister\Service\Gdpr\Case;

use OCA\OpenRegister\Db\ObjectEntity;
use RuntimeException;

/**
 * Shared refusal + sub-collection read for DSAR case write paths.
 *
 * @spec openspec/specs/dsar-case-api/spec.md
 */
final class CasePayload {

	/**
	 * The loaded case, or a refusal when it could not be loaded.
	 *
	 * @param ObjectEntity|null $case     What CaseObjectAccessor::load() answered.
	 * @param string            $caseUuid The requested case uuid.
	 *
	 * @return ObjectEntity The loaded case.
	 *
	 * @throws RuntimeException When the case is absent or not authorised.
	 *
	 * @spec openspec/specs/dsar-case-api/spec.md
	 */
	public static function loaded(?ObjectEntity $case, string $caseUuid): ObjectEntity {
		if ($case === null) {
			throw new RuntimeException(
				message: sprintf('Case "%s" not found or not authorised.', $caseUuid)
			);
		}

		return $case;

	}//end loaded()

	/**
	 * A list sub-collection of the case payload, re-indexed; empty when absent.
	 *
	 * @param array<string, mixed> $data The case payload.
	 * @param string               $key  The sub-collection field.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/specs/dsar-case-api/spec.md
	 */
	public static function listField(array $data, string $key): array {
		if (isset($data[$key]) === true && is_array($data[$key]) === true) {
			return array_values($data[$key]);
		}

		return [];

	}//end listField()
}//end class

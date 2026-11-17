<?php

/**
 * DeclaresListExtraKeys: the top-level keys an integration's list answer carries next to the envelope.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-registry/spec.md#requirement-an-integration-may-declare-extra-top-level-keys-of-its-list-answer
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * An integration that returns documented extra top-level keys from list().
 *
 * The object-integration route answers `{items, results, total, nextCursor}`.
 * A provider whose list() answer also carries other top-level keys (for
 * example `appliedLayers` or `withheld`, which explain how the answer came
 * about) names them here; the route then passes exactly those keys through,
 * unchanged, next to the envelope. Keys it does not name are dropped as
 * before, and the envelope keys themselves cannot be overridden.
 *
 * Optional: a provider that does not implement this interface answers exactly
 * as before.
 */
interface DeclaresListExtraKeys {

	/**
	 * The top-level keys of the list() answer to pass through.
	 *
	 * @return array<int,string> The key names.
	 *
	 * @spec openspec/specs/integration-registry/spec.md#requirement-an-integration-may-declare-extra-top-level-keys-of-its-list-answer
	 */
	public function listExtraKeys(): array;
}//end interface

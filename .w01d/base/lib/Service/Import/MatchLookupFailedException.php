<?php

/**
 * The match key could not be resolved for a row.
 *
 * Distinct from "the key matched nothing" on purpose: an unavailable lookup
 * read as "no match" would create a duplicate of every object in the
 * register, which is the fail-open ADR-005 forbids. A row whose lookup threw
 * is refused with this reason.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use RuntimeException;

/**
 * Thrown when a row's match lookup cannot be completed.
 */
class MatchLookupFailedException extends RuntimeException {
}//end class

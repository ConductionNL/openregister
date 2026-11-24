<?php

/**
 * OpenRegister NoTabularResultException
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * A console export was asked to download a result that has no list in it, so
 * there is nothing tabular to flatten. The controller answers it as a 422.
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
 */
class NoTabularResultException extends Exception {
}//end class

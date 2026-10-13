<?php

/**
 * OpenRegister Example Set Exception
 *
 * Thrown when an example set cannot be gathered: a file that does not parse, a
 * cast outside OpenRegister's file, a cycle in `after`. The message names the
 * file or the apps, so an administrator can act on it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use RuntimeException;

/**
 * An example set that cannot be gathered.
 *
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
 */
class ExampleSetException extends RuntimeException {
}//end class

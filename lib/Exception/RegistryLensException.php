<?php

/**
 * OpenRegister RegistryLensException
 *
 * Raised when a `_registry[state]` / `_registry[updatedBefore]` lens cannot be read.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-subscription-state-and-freshness-are-query-lenses
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use InvalidArgumentException;

/**
 * A registry lens the query could not read.
 *
 * Dropping it would answer the whole list to a deliberately narrow question,
 * so the query refuses; the middleware turns the refusal into an HTTP 400.
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-subscription-state-and-freshness-are-query-lenses
 */
class RegistryLensException extends InvalidArgumentException {
}//end class

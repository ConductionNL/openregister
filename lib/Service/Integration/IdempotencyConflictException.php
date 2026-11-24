<?php

/**
 * An integration create refused because its Idempotency-Key is taken.
 *
 * Answered with a 409: the key belongs to a create that is still running, to
 * another user, or to another payload. The message names none of them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-registry/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use RuntimeException;

/**
 * The Idempotency-Key of an integration create is taken.
 *
 * @spec openspec/specs/integration-registry/spec.md
 */
final class IdempotencyConflictException extends RuntimeException {
}//end class

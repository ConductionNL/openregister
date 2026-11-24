<?php

/**
 * Marks a controller method that is refused while the database is under pressure.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Resilience
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Resilience;

use Attribute;

/**
 * A heavy route: an export, an aggregation, GraphQL, a bulk operation or a
 * report run. While {@see DatabasePressureMeter::underPressure()} holds,
 * `LoadSheddingMiddleware` refuses it with 503 and `Retry-After: 30`, so a
 * caseworker's single-object reads and writes keep working (design D-4).
 *
 * The set is code, reviewed with the code; `SheddableRoutesTest` lists it.
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Sheddable {
}//end class

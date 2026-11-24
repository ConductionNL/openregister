<?php

/**
 * AdvisoryAnnotationRule: an advisory annotation another area validates.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

use Closure;

/**
 * The key of an advisory annotation, how its block is validated, and what an
 * ignored block means for the schema.
 *
 * AdvisoryAnnotationChecks::checkContributed() reads it; the area that owns the
 * annotation's meaning builds it.
 *
 * @spec exclude schema save plumbing moved out of SchemaMapper, not a capability of its own
 */
final class AdvisoryAnnotationRule {

	/**
	 * Constructor.
	 *
	 * @param string  $key         The annotation key, which is also its family in the save response.
	 * @param string  $consequence What an ignored block means, as the warning says it.
	 * @param Closure $errors      fn (SchemaAnnotation, Schema): array<int, array{message: string}>.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $consequence,
		public readonly Closure $errors,
	) {
	}//end __construct()
}//end class

<?php

/**
 * OpenRegister StringTypedPatch
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;


/**
 * What restoring string-typed values needs to know about a patch.
 *
 * @spec openspec/specs/schema-driven-read-coercion/spec.md
 */
final class StringTypedPatch {
	/**
	 * Hold the schema and the keys the caller sent.
	 *
	 * @param mixed        $schema       The resolved schema entity, or whatever resolution produced.
	 * @param list<string> $suppliedKeys Keys the caller actually sent in the patch payload.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	public function __construct(
		public readonly mixed $schema,
		public readonly array $suppliedKeys,
	) {
	}//end __construct()
}//end class

<?php

/**
 * OpenRegister RegisterSlugProbe
 *
 * One register slug lookup: the canonical slug and the candidate slugs probed for it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Contract\RegisterSlugResolution;

/**
 * The canonical slug and the probe list of one RegisterSlugResolver::resolve() call.
 *
 * Built by the entry point and never handed outside the resolver (ADR-011 Rule 3).
 *
 * @spec openspec/specs/register-slug-resolution/spec.md
 */
final class RegisterSlugProbe {

	/**
	 * Constructor.
	 *
	 * @param string       $canonical The canonical register slug.
	 * @param list<string> $probe     The probe list, newest first.
	 */
	public function __construct(
		public readonly string $canonical,
		public readonly array $probe,
	) {
	}//end __construct()

	/**
	 * A resolution for this probe; the slug is the first match, or null when none matched.
	 *
	 * @param string       $state   One of the RegisterSlugResolution states.
	 * @param list<string> $matched The slugs that exist here, in probe order.
	 *
	 * @return RegisterSlugResolution The resolution.
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function resolved(string $state, array $matched): RegisterSlugResolution {
		return new RegisterSlugResolution(
			canonical: $this->canonical,
			slug: ($matched[0] ?? null),
			state: $state,
			candidates: $this->probe,
			matched: $matched
		);
	}//end resolved()
}//end class

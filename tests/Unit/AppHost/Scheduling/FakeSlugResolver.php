<?php

/**
 * AppHost scheduling: a register-slug resolver bound to a fixed instance state.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Scheduling;

use OCA\OpenRegister\Contract\RegisterSlugResolution;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCA\OpenRegister\Support\RegisterSlugAliases;

/**
 * Answers as an instance that carries exactly the given register slugs.
 *
 * A hand-written double rather than a generated mock because the tests below
 * are about the DIFFERENCE between a migrated and an unmigrated instance, and
 * that difference is the resolver's whole behaviour. Stubbing `slugOrNull()` to
 * return a constant would make every test pass on both.
 */
class FakeSlugResolver implements RegisterSlugResolverInterface {

	/**
	 * Constructor.
	 *
	 * @param list<string> $present The register slugs this instance carries.
	 */
	public function __construct(private readonly array $present = []) {
	}//end __construct()

	/**
	 * Resolve against the fixed instance state.
	 *
	 * @param string       $canonical  The canonical register slug.
	 * @param list<string> $candidates Explicit candidates, newest first.
	 *
	 * @return RegisterSlugResolution The resolution.
	 */
	public function resolve(string $canonical, array $candidates=[]): RegisterSlugResolution {
		$probe = $candidates;
		if ($probe === []) {
			$probe = RegisterSlugAliases::candidatesFor(canonical: $canonical);
		}

		$matched = array_values(array_filter(
			$probe,
			fn (string $slug): bool => in_array($slug, $this->present, true)
		));

		$state = RegisterSlugResolution::RESOLVED;
		if ($matched === []) {
			$state = RegisterSlugResolution::ABSENT;
		} else if (count($matched) > 1) {
			$state = RegisterSlugResolution::AMBIGUOUS;
		}

		return new RegisterSlugResolution(
			canonical: $canonical,
			slug: ($matched[0] ?? null),
			state: $state,
			candidates: $probe,
			matched: $matched
		);
	}//end resolve()

	/**
	 * The slug to read with, or null.
	 *
	 * @param string       $canonical  The canonical register slug.
	 * @param list<string> $candidates Explicit candidates, newest first.
	 *
	 * @return string|null The slug, or null.
	 */
	public function slugOrNull(string $canonical, array $candidates=[]): ?string {
		return $this->resolve(canonical: $canonical, candidates: $candidates)->slug;
	}//end slugOrNull()
}//end class

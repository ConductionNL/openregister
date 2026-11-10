<?php

/**
 * RegisterSlugResolverInterface: the published probe for "which slug does this
 * register answer to here?".
 *
 * ## Why this is published from OpenRegister and not copied into each app
 *
 * Register slugs live in `openregister_registers`. No consuming app can answer
 * the question without reaching into this app's storage, so every per-app copy
 * of the answer is a copy of OpenRegister's truth maintained by someone who
 * does not own it. ADR-084 already settled that argument once for
 * {@see ObjectServiceInterface}: ten apps hand-rolled a double of
 * `ObjectService`, declaring between 0 and 13 methods against a real class of
 * 88, and the fix was one definition owned by the app that implements it.
 * The same reasoning applies here with less room for doubt, because the
 * candidate slugs are not derivable. See {@see \OCA\OpenRegister\Support\RegisterSlugAliases}.
 *
 * ## What a caller does with the answer
 *
 * Ask once, per operation, and branch on the result:
 *
 *     $resolution = $resolver->resolve(canonical: 'buildiq');
 *     if ($resolution->isResolved() === false) {
 *         // The register is genuinely not here. Say so. Do NOT read with the
 *         // canonical slug and report the empty result as "no data".
 *         return ...;
 *     }
 *     $rows = $objectService->findAll(config: ['filters' => [
 *         'register' => $resolution->slug,
 *         'schema'   => 'job',
 *     ]]);
 *
 * The branch is the point. A caller that skips it and uses `?->slug ?? 'buildiq'`
 * has reinstated the defect: on an unmigrated instance the read returns zero
 * rows, and zero rows is what a healthy empty register returns too.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Contract
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Contract;

/**
 * Resolves a register by any of the slugs it has answered to.
 *
 * @spec openspec/specs/register-slug-resolution/spec.md
 */
interface RegisterSlugResolverInterface {

	/**
	 * Which slug this instance's copy of a register actually answers to.
	 *
	 * @param string       $canonical  The canonical (current) register slug, e.g. `buildiq`.
	 * @param list<string> $candidates Explicit candidates, newest first, for a
	 *                                 register whose rename this app does not
	 *                                 know about. Empty means "use the declared
	 *                                 alias list", which is the normal call.
	 *
	 * @return RegisterSlugResolution The slug to use, or an explicit absence.
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function resolve(string $canonical, array $candidates=[]): RegisterSlugResolution;

	/**
	 * The slug to read with, or null when the register is not on this instance.
	 *
	 * The convenience form, for a caller that has nothing useful to say about
	 * ambiguity. It still cannot fall open: null is the only "not here" answer
	 * it can give, and null is not a slug any read will accept.
	 *
	 * @param string       $canonical  The canonical (current) register slug.
	 * @param list<string> $candidates Explicit candidates, newest first.
	 *
	 * @return string|null The slug to use, or null.
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function slugOrNull(string $canonical, array $candidates=[]): ?string;
}//end interface

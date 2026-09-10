<?php

/**
 * RegisterSlugResolution: the answer to "which slug does this register
 * actually answer to on this instance?".
 *
 * The type exists so that "not found" cannot be mistaken for "found nothing".
 * A resolver returning a bare string has to return SOMETHING on a miss, and
 * whatever it returns is passed straight into a read that then comes back
 * empty. An empty result set is indistinguishable from a register that holds no
 * objects, so the caller records "no data" and nobody is alerted. That is the
 * exact failure this whole mechanism exists to remove, so the absence is a
 * state the caller has to branch on rather than a string it can use by
 * accident.
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
 * An immutable register-slug resolution.
 *
 * @spec openspec/specs/register-slug-resolution/spec.md
 */
final class RegisterSlugResolution {

	/**
	 * Exactly one candidate slug exists on this instance.
	 *
	 * @var string
	 */
	public const RESOLVED = 'resolved';

	/**
	 * No candidate slug exists on this instance.
	 *
	 * @var string
	 */
	public const ABSENT = 'absent';

	/**
	 * More than one candidate slug exists on this instance.
	 *
	 * The repair steps refuse to merge in this case rather than guessing, so
	 * the state is reachable and means an operator has to act.
	 *
	 * @var string
	 */
	public const AMBIGUOUS = 'ambiguous';

	/**
	 * Constructor.
	 *
	 * @param string       $canonical  The canonical slug that was asked for.
	 * @param string|null  $slug       The slug this instance answers to, or null when absent.
	 * @param string       $state      One of RESOLVED, ABSENT, AMBIGUOUS.
	 * @param list<string> $candidates The candidate slugs that were probed, newest first.
	 * @param list<string> $matched    The candidate slugs that exist here, in candidate order.
	 */
	public function __construct(
		public readonly string $canonical,
		public readonly ?string $slug,
		public readonly string $state,
		public readonly array $candidates,
		public readonly array $matched,
	) {
	}//end __construct()

	/**
	 * Whether a slug was found, whether or not it was the only one.
	 *
	 * @return bool True when {@see $slug} is usable.
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function isResolved(): bool {
		return $this->slug !== null;
	}//end isResolved()

	/**
	 * Whether this register is absent from this instance under every known slug.
	 *
	 * @return bool True when nothing matched.
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function isAbsent(): bool {
		return $this->state === self::ABSENT;
	}//end isAbsent()

	/**
	 * Whether more than one of the register's slugs exists here.
	 *
	 * @return bool True when the instance carries two rows the rename should
	 *              have collapsed into one.
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function isAmbiguous(): bool {
		return $this->state === self::AMBIGUOUS;
	}//end isAmbiguous()
}//end class

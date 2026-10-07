<?php

/**
 * What a schema declares about losing the person and keeping the record.
 *
 * 🔴 THE PROFILE IS DECLARED, NEVER SCRIPTED. ADR-031: which properties are
 * anonymised, and with what, is an annotation on the schema beside the
 * retention block. A script per register is a rule nobody can read from the
 * schema, and the one question an auditor asks about an anonymised record is
 * what is still in there. That has to be answerable from configuration, not
 * from whichever job happened to run.
 *
 * FOUR TREATMENTS, and the difference between them is what somebody can still
 * learn from the record afterwards:
 *
 *  - `remove` takes the property out. Nothing is left to read.
 *  - `fixed` writes one declared value over every record. Two rows that held
 *    different people become indistinguishable.
 *  - `pseudonym` writes a stable token derived from the value, so two rows that
 *    held the SAME person still join, and neither names them. This is the one
 *    that keeps statistics usable and the one that carries the residual risk:
 *    a token that joins is a token that can be correlated, and if the salt
 *    leaks the mapping is recoverable by anyone holding the original values.
 *  - `generalise` coarsens: a date to its year, a postcode to its district. The
 *    value still says something true and says it about too many people to
 *    identify one.
 *
 * ANYTHING NOT NAMED IS KEPT, and kept deliberately. The report says so by
 * name, because "we anonymised it" without a list is a claim nobody can check.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * The anonymisation treatments, and the annotation key they are declared under.
 */
final class AnonymisationProfile {

	/**
	 * The annotation key holding the profile, inside `x-openregister-archival`.
	 *
	 * @var string
	 */
	public const ANNOTATION_KEY = 'anonymisation';

	/**
	 * Take the property out of the record entirely.
	 *
	 * @var string
	 */
	public const REMOVE = 'remove';

	/**
	 * Write one declared value over every record.
	 *
	 * @var string
	 */
	public const FIXED = 'fixed';

	/**
	 * Write a stable token, so rows that held the same person still join.
	 *
	 * @var string
	 */
	public const PSEUDONYM = 'pseudonym';

	/**
	 * Coarsen the value: a date to its year, a postcode to its district.
	 *
	 * @var string
	 */
	public const GENERALISE = 'generalise';

	/**
	 * Every treatment a profile may name.
	 *
	 * @var string[]
	 */
	public const TREATMENTS = [
		self::REMOVE,
		self::FIXED,
		self::PSEUDONYM,
		self::GENERALISE,
	];

	/**
	 * Generalise a date down to its year.
	 *
	 * @var string
	 */
	public const GRAIN_YEAR = 'year';

	/**
	 * Generalise a date down to its month.
	 *
	 * @var string
	 */
	public const GRAIN_MONTH = 'month';

	/**
	 * Generalise a postcode down to its district (the numeric part, here).
	 *
	 * @var string
	 */
	public const GRAIN_POSTCODE_DISTRICT = 'postcode_district';

	/**
	 * Every grain `generalise` accepts.
	 *
	 * @var string[]
	 */
	public const GRAINS = [
		self::GRAIN_YEAR,
		self::GRAIN_MONTH,
		self::GRAIN_POSTCODE_DISTRICT,
	];
}//end class

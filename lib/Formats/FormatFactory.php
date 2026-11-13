<?php

/**
 * FormatFactory: builds the format objects callers outside Formats use.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Formats
 * @package  OCA\OpenRegister\Formats
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

namespace OCA\OpenRegister\Formats;

use Closure;
use OCP\IUserManager;

/**
 * Builds the custom JSON-schema string formats and the extended field type
 * validator, so callers outside the Formats component do not name the
 * concrete format classes. Each method constructs exactly what its caller
 * constructed before.
 *
 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-005
 */
final class FormatFactory {

	/**
	 * The custom string formats ValidateObject registers on its opis validator.
	 *
	 * `date-time` accepts ISO 8601 input (optional seconds/timezone), overriding the
	 * opis built-in format whose regex mandates seconds.
	 *
	 * @param IUserManager $userManager Resolves the `user` format's uids.
	 * @param Closure      $planned     Whether a uid is planned in the same save (fn (string $uid): bool).
	 *
	 * @return array<string, object> The resolvers by format.
	 */
	public static function customStringFormats(IUserManager $userManager, Closure $planned): array {
		return [
			'bsn'       => new BsnFormat(),
			'semver'    => new SemVerFormat(),
			'cron'      => new CronFormat(),
			'user'      => new UserFormat(userManager: $userManager, planned: $planned),
			'date-time' => new Iso8601DateTimeFormat(),
			'website'   => new WebsiteFormat(),
			'nl-phone'  => new NlPhoneFormat(),
		];
	}//end customStringFormats()

	/**
	 * A new extended field type validator.
	 *
	 * @return ExtendedFieldTypeValidatorInterface The validator.
	 *
	 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-005
	 */
	public static function extendedFieldTypes(): ExtendedFieldTypeValidatorInterface {
		return new ExtendedFieldTypeValidator();
	}//end extendedFieldTypes()
}//end class

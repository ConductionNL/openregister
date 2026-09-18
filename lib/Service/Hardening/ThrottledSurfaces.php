<?php

/**
 * The surfaces that register a failed attempt with Nextcloud's brute-force
 * throttler, named once.
 *
 * WHY A SHARED LIST RATHER THAN A STRING PER CONTROLLER. The hardening report
 * answers "which anonymous surfaces are throttled?", and the only honest way to
 * answer it is to read the same constant the controller passes to
 * `#[BruteForceProtection]`. A list maintained beside the controllers drifts
 * the first time somebody adds a surface, and the report then says six on an
 * instance that has seven, which reads as reassurance.
 *
 * Adding a throttled surface means adding a constant here and naming it in
 * {@see self::ALL}. The controller then references this class, never a literal.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hardening;

/**
 * The throttler actions this app registers attempts against.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 */
final class ThrottledSurfaces {

	/**
	 * A scoped, expiring link that opens one record for somebody with no account.
	 *
	 * @var string
	 */
	public const ACCESS_LINK = 'openregister_access_link';

	/**
	 * A calendar feed served against a token.
	 *
	 * @var string
	 */
	public const CALENDAR_FEED = 'openregister_calendar_feed';

	/**
	 * A case token handed to a party outside the instance.
	 *
	 * @var string
	 */
	public const CASE_TOKEN = 'openregister_case_token';

	/**
	 * A share link on a single object.
	 *
	 * @var string
	 */
	public const OBJECT_SHARE_LINK = 'openregister_object_share_link';

	/**
	 * A federation share token from another instance.
	 *
	 * @var string
	 */
	public const FEDERATION_SHARE_TOKEN = 'openregister_federation_share_token';

	/**
	 * The OAuth2 callback the credential broker completes a grant on.
	 *
	 * @var string
	 */
	public const OAUTH2_CALLBACK = 'openregisterOauth2Callback';

	/**
	 * The password confirmation that elevates an administration session.
	 *
	 * Throttled because it is the one surface where a correct guess buys the
	 * right to weaken every other control on this list.
	 *
	 * @var string
	 */
	public const ELEVATION = 'openregister_elevation';

	/**
	 * Every throttled surface, as `name => throttler action`.
	 *
	 * @var array<string, string>
	 */
	public const ALL = [
		'accessLink' => self::ACCESS_LINK,
		'calendarFeed' => self::CALENDAR_FEED,
		'caseToken' => self::CASE_TOKEN,
		'objectShareLink' => self::OBJECT_SHARE_LINK,
		'federationShareToken' => self::FEDERATION_SHARE_TOKEN,
		'oauth2Callback' => self::OAUTH2_CALLBACK,
		'elevation' => self::ELEVATION,
	];

	/**
	 * How many surfaces register attempts.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public static function count(): int {
		return count(self::ALL);

	}//end count()
}//end class

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
 * {@see self::ALL}. The controller then references this interface, never a literal.
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
 * The throttler actions this app registers attempts against, as constants.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 */
interface ThrottledSurfacesInterface {

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
	public const CASE_LINK_SURFACE = 'openregister_case_token';

	/**
	 * Former name of CASE_LINK_SURFACE, kept for existing callers.
	 *
	 * @deprecated Use CASE_LINK_SURFACE.
	 *
	 * @var string
	 */
	public const CASE_TOKEN = self::CASE_LINK_SURFACE;

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
	public const FEDERATION_SHARE_SURFACE = 'openregister_federation_share_token';

	/**
	 * Former name of FEDERATION_SHARE_SURFACE, kept for existing callers.
	 *
	 * @deprecated Use FEDERATION_SHARE_SURFACE.
	 *
	 * @var string
	 */
	public const FEDERATION_SHARE_TOKEN = self::FEDERATION_SHARE_SURFACE;

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
	 * A form submit or upload into a destination object (decision 179).
	 *
	 * An unknown form id registers an attempt, so walking form ids to find
	 * one that accepts writes slows down like any other token guess.
	 *
	 * @var string
	 */
	public const FORM_SUBMIT = 'openregister_form_submit';

	/**
	 * Starting or answering a journey run.
	 *
	 * An unknown journey id registers an attempt, so walking journey ids to
	 * find one that accepts anonymous writes slows down like a token guess.
	 *
	 * @var string
	 */
	public const JOURNEY_RUN = 'openregister_journey_run';

	/**
	 * Every throttled surface, as `name => throttler action`.
	 *
	 * @var array<string, string>
	 */
	public const ALL = [
		'accessLink' => self::ACCESS_LINK,
		'calendarFeed' => self::CALENDAR_FEED,
		'caseToken' => self::CASE_LINK_SURFACE,
		'objectShareLink' => self::OBJECT_SHARE_LINK,
		'federationShareToken' => self::FEDERATION_SHARE_SURFACE,
		'oauth2Callback' => self::OAUTH2_CALLBACK,
		'elevation' => self::ELEVATION,
		'formSubmit' => self::FORM_SUBMIT,
		'journeyRun' => self::JOURNEY_RUN,
	];
}//end interface

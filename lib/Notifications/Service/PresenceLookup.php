<?php

/**
 * PresenceLookup
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service;

use DateTimeInterface;

/**
 * Who is asked about on an object: the object, a reader to leave out, and the moment.
 *
 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
 */
final class PresenceLookup {

	/**
	 * Constructor.
	 *
	 * @param string                 $objectUuid The object.
	 * @param string                 $exceptUser A reader to leave out, usually the caller.
	 * @param DateTimeInterface|null $now        The clock, for tests; null is now.
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly string $exceptUser = '',
		public readonly ?DateTimeInterface $now = null,
	) {
	}//end __construct()
}//end class

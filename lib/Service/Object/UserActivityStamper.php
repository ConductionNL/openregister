<?php

/**
 * Stamps `@self.lastUserActivity` on a write made by a person.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCA\OpenRegister\Service\WriteCause;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Decides whether the write in progress was made by a person, and stamps it.
 *
 * `updated` moves on every write, so a recompute job that rewrites a derived
 * field makes every object look freshly touched. `lastUserActivity` moves only
 * when all of these hold:
 *
 * - the process is a web request, not the CLI (occ, CLI cron, repair steps);
 * - the request is not Nextcloud's web or AJAX cron (`cron.php`);
 * - a user is signed in;
 * - the write is not inside {@see SystemOperationContext};
 * - the write's {@see WriteCause} is `person`, so a caller that labels its
 *   writes as `scheduled`, `rule`, `import`, `migration` or `cascade` is left out.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
class UserActivityStamper {

	/**
	 * The script Nextcloud's web and AJAX cron run under.
	 */
	private const CRON_SCRIPT = 'cron.php';

	/**
	 * Constructor
	 *
	 * @param IUserSession $userSession The session that names the acting user.
	 * @param IRequest     $request     The current request.
	 * @param string       $sapi        The PHP SAPI, `PHP_SAPI` outside tests.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IRequest $request,
		private readonly string $sapi = PHP_SAPI,
	) {
	}//end __construct()

	/**
	 * Whether the write in progress was made by a person.
	 *
	 * @return bool True for a signed-in user's own write in a web request.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause and SystemOperationContext are ambient frames; there is no instance to inject.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function isUserWrite(): bool {
		if ($this->sapi === 'cli' || str_ends_with($this->request->getScriptName(), self::CRON_SCRIPT) === true) {
			return false;
		}

		if ($this->userSession->getUser() === null || SystemOperationContext::isActive() === true) {
			return false;
		}

		return WriteCause::current()['cause'] === WriteCause::PERSON;
	}//end isUserWrite()

	/**
	 * Set the object's `lastUserActivity` to now when a person is writing it.
	 *
	 * @param ObjectEntity $object The object about to be saved.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function stamp(ObjectEntity $object): void {
		if ($this->isUserWrite() === true) {
			$object->setLastUserActivity(new DateTime());
		}
	}//end stamp()
}//end class

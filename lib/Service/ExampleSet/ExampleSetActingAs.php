<?php

/**
 * OpenRegister Example Set Acting As
 *
 * Runs one call as a cast account, so a service that reads the session (the
 * sharing guard, the link mint guard) decides for that account as it would for
 * a live click. Only from occ or the daily refresh job, never while somebody
 * is signed in, and the previous user is always put back.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCP\IUserManager;
use OCP\IUserSession;

/**
 * One call as a cast account, scoped and restored.
 *
 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
 */
class ExampleSetActingAs {
	/**
	 * How many daily refresh runs are open in this process; a background run outside the CLI (AJAX or web cron) opens one.
	 *
	 * @var integer
	 */
	private static int $backgroundRuns = 0;

	/**
	 * Whether this process is the command line (occ, system cron).
	 *
	 * @var boolean
	 */
	private readonly bool $cli;

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession Holds the active user.
	 * @param IUserManager $userManager Finds the account to act as.
	 * @param bool|null    $cli         Whether this process is the command line; null reads PHP_SAPI.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		?bool $cli=null,
	) {
		$this->cli = ($cli ?? (PHP_SAPI === 'cli'));
	}//end __construct()

	/**
	 * Run the daily refresh, which may act as cast accounts even when cron runs inside a web request.
	 *
	 * @param callable $run The refresh.
	 *
	 * @return mixed What the refresh returns.
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-refresh-makes-the-access-links-and-grants-of-a-set-again
	 */
	public static function inBackgroundJob(callable $run): mixed {
		self::$backgroundRuns++;
		try {
			return $run();
		} finally {
			self::$backgroundRuns--;
		}
	}//end inBackgroundJob()

	/**
	 * Run one call as an account, and put the previous user back however the call ends.
	 *
	 * @param string   $uid       The account to act as.
	 * @param callable $operation The call.
	 *
	 * @return mixed What the call returns.
	 *
	 * @throws ExampleSetException When this is no command line or background run, somebody is signed in, or the
	 *                             account does not exist.
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
	 */
	public function run(string $uid, callable $operation): mixed {
		if ($this->cli === false && self::$backgroundRuns === 0) {
			throw new ExampleSetException('an example set acts as an account only from occ or the background job');
		}

		$previous = $this->userSession->getUser();
		if ($previous !== null) {
			throw new ExampleSetException('an example set does not act as an account while "'.$previous->getUID().'" is signed in');
		}

		$user = $this->userManager->get($uid);
		if ($user === null) {
			throw new ExampleSetException('no account "'.$uid.'" to act as');
		}

		$this->userSession->setVolatileActiveUser($user);
		try {
			return $operation();
		} finally {
			$this->userSession->setVolatileActiveUser($previous);
		}
	}//end run()
}//end class

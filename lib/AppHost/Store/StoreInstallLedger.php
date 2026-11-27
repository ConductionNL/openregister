<?php

/**
 * OpenRegister AppHost — Store Install Ledger
 *
 * Remembers, per app, which store item was installed into which target, so the
 * store page can say where an item is installed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Store
 * @package  OCA\OpenRegister\AppHost\Store
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Store;

use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Where each store item of one app is installed: item slug to the targets it went into.
 *
 * Kept in the CALLING app's IAppConfig under one key, so each app's ledger is
 * its own and a reset of the app resets it. An install without a target is
 * recorded as the empty target ''. The ledger is bounded: a slug or target that
 * is not an id is never recorded, and past the caps the oldest entries go
 * first, so a long-lived instance cannot grow the value without limit.
 *
 * @spec openspec/changes/store-installed-in/specs/apphost-store-plane/spec.md#requirement-the-store-reports-where-each-item-is-installed
 */
class StoreInstallLedger {

	/**
	 * The IAppConfig key the ledger is kept under.
	 */
	public const CONFIG_KEY = 'store_installed_in';

	/**
	 * At most this many items are remembered per app.
	 */
	public const MAX_ITEMS = 500;

	/**
	 * At most this many targets are remembered per item.
	 */
	public const MAX_TARGETS = 50;

	/**
	 * What a slug or a target may look like: the same ids the store accepts.
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $appConfig The config store the ledger is kept in.
	 * @param LoggerInterface $logger    Where a failed write is reported.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record that an item was installed into a target ('' for none).
	 *
	 * A failure to write is logged and swallowed: the install itself has
	 * succeeded, and a missing "installed in" line is no reason to report it
	 * as failed.
	 *
	 * @param string $appId  The app whose store installed the item.
	 * @param string $slug   The installed item's slug.
	 * @param string $target The target it went into, '' for none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/store-installed-in/specs/apphost-store-plane/spec.md#requirement-the-store-reports-where-each-item-is-installed
	 */
	public function record(string $appId, string $slug, string $target=''): void {
		if (self::isId(value: $slug) === false || ($target !== '' && self::isId(value: $target) === false)) {
			return;
		}

		$ledger  = $this->installedIn(appId: $appId);
		$targets = ($ledger[$slug] ?? []);
		unset($ledger[$slug]);

		if (in_array($target, $targets, true) === false) {
			$targets[] = $target;
		}

		// The item moves to the end, so the caps below drop the least recent first.
		$ledger[$slug] = array_slice($targets, -self::MAX_TARGETS);
		$ledger        = array_slice($ledger, -self::MAX_ITEMS, null, true);

		try {
			$this->appConfig->setValueString($appId, self::CONFIG_KEY, (string)json_encode($ledger));
		} catch (Throwable $e) {
			$this->logger->error(
				message: sprintf('[AppHost\\Store] recording an install for %s failed: %s', $appId, $e->getMessage()),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end record()

	/**
	 * Every item the app's store installed, with the targets each went into.
	 *
	 * A value that does not read as a ledger reads as empty, entry by entry:
	 * one bad row never hides the others.
	 *
	 * @param string $appId The app whose ledger is read.
	 *
	 * @return array<string, array<int, string>> Slug to targets; '' is an install without target.
	 *
	 * @spec openspec/changes/store-installed-in/specs/apphost-store-plane/spec.md#requirement-the-store-reports-where-each-item-is-installed
	 */
	public function installedIn(string $appId): array {
		try {
			$raw = $this->appConfig->getValueString($appId, self::CONFIG_KEY, '');
		} catch (Throwable $e) {
			return [];
		}

		$ledger = [];
		foreach (self::storedRows(raw: $raw) as $slug => $targets) {
			if (is_string($slug) === false || self::isId(value: $slug) === false || is_array($targets) === false) {
				continue;
			}

			$ledger[$slug] = self::validTargets(targets: $targets);
		}

		return $ledger;
	}//end installedIn()

	/**
	 * The rows of a stored value; none when it is not a JSON object or list.
	 *
	 * @param string $raw The stored value.
	 *
	 * @return array<mixed>
	 */
	private static function storedRows(string $raw): array {
		$decoded = json_decode($raw, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return [];
	}//end storedRows()

	/**
	 * The targets of one stored row that are ids or the empty target.
	 *
	 * @param array<mixed> $targets The stored targets.
	 *
	 * @return array<int, string>
	 */
	private static function validTargets(array $targets): array {
		$valid = [];
		foreach ($targets as $target) {
			if (is_string($target) === true && ($target === '' || self::isId(value: $target) === true)) {
				$valid[] = $target;
			}
		}

		return array_values(array_unique($valid));
	}//end validTargets()

	/**
	 * Whether a value is an id the ledger may hold.
	 *
	 * @param string $value The slug or target.
	 *
	 * @return bool
	 */
	private static function isId(string $value): bool {
		return preg_match(self::ID_PATTERN, $value) === 1;
	}//end isId()
}//end class

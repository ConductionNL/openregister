<?php

/**
 * ImportProgress: how far a running record import has got, readable while it runs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import\RecordImport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import\RecordImport;

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Keeps the row count of a running import in the distributed cache.
 *
 * The import request itself runs synchronously; a second request reads this
 * entry to show how many rows have been read so far. Entries are keyed by the
 * user and a token the client chooses, so one user cannot read another's
 * progress, and they expire on their own when an import never finishes.
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
 */
class ImportProgress {

	/**
	 * Report every this many rows; the last count is always reported.
	 *
	 * @var int
	 */
	public const EVERY = 50;

	/**
	 * Seconds an entry lives after its last report.
	 *
	 * @var int
	 */
	private const TTL = 3600;

	/**
	 * A client token: 8 to 64 letters, digits, dashes or underscores.
	 *
	 * @var string
	 */
	private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{8,64}$/';

	/**
	 * The cache, or null when no cache backend is available.
	 *
	 * @var ICache|null
	 */
	private ?ICache $cache = null;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $cacheFactory The cache factory.
	 */
	public function __construct(ICacheFactory $cacheFactory) {
		if ($cacheFactory->isAvailable() === true) {
			$this->cache = $cacheFactory->createDistributed('openregister_record_import_progress');
		}
	}//end __construct()

	/**
	 * The cache key of a user's import, or null when the token is not a valid token.
	 *
	 * @param string $userId The user running the import.
	 * @param string $token  The client's token.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
	 */
	public static function key(string $userId, string $token): ?string {
		if ($userId === '' || preg_match(self::TOKEN_PATTERN, $token) !== 1) {
			return null;
		}

		return hash('sha256', $userId) . ':' . $token;
	}//end key()

	/**
	 * Record how many rows an import has read.
	 *
	 * @param string $key  The import's key.
	 * @param int    $rows The rows read so far.
	 * @param bool   $done Whether the file has been read to the end.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
	 */
	public function report(string $key, int $rows, bool $done = false): void {
		$this->cache?->set($key, ['rows' => $rows, 'done' => $done], self::TTL);
	}//end report()

	/**
	 * The progress of an import, or null when nothing has been reported under the key.
	 *
	 * @param string $key The import's key.
	 *
	 * @return array{rows: int, done: bool}|null
	 *
	 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
	 */
	public function read(string $key): ?array {
		$entry = $this->cache?->get($key);
		if (is_array($entry) === false || isset($entry['rows']) === false) {
			return null;
		}

		return ['rows' => (int)$entry['rows'], 'done' => (($entry['done'] ?? false) === true)];
	}//end read()
}//end class

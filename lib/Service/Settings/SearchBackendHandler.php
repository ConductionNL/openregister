<?php

/**
 * OpenRegister Search Backend Settings Handler
 *
 * This file contains the handler class for managing search backend configuration.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-backend-agnostic-search-architecture
 */

namespace OCA\OpenRegister\Service\Settings;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper\AccentFoldingSetting;
use OCA\OpenRegister\Db\MagicMapper\MagicUnaccentProbe;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Handler for search backend settings operations.
 *
 * This handler reports the active search backend. Since the external Solr and
 * Elasticsearch backends were removed, the built-in database (Magic-Tables)
 * search is the sole backend.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */
class SearchBackendHandler {

	/**
	 * Whether the database can fold accents; built on first use.
	 *
	 * @var MagicUnaccentProbe|null
	 */
	private ?MagicUnaccentProbe $probe = null;

	/**
	 * Build the handler.
	 *
	 * Every dependency is optional so the handler still reports the backend
	 * when built without them; accent folding then reads as unavailable.
	 *
	 * @param IAppConfig|null      $appConfig The app config holding the accent choice.
	 * @param IDBConnection|null   $db        The connection the probe asks.
	 * @param LoggerInterface|null $logger    Receives a failed probe.
	 *
	 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md#requirement-accent-folding-is-visible-to-administrators-and-can-be-bypassed
	 */
	public function __construct(
		private readonly ?IAppConfig $appConfig = null,
		private readonly ?IDBConnection $db = null,
		private readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * Get search backend configuration.
	 *
	 * The external Solr/Elasticsearch backends were removed; the built-in
	 * database (Magic-Tables) search is the only backend.
	 *
	 * It also reports whether object search ignores accents: `enabled` (the
	 * administrator's choice, on by default), `available` (whether this
	 * database can) and `reason` (why not, '' when it can).
	 *
	 * @return array Backend configuration with 'active', 'available' and 'accentInsensitive' keys.
	 *
	 * @throws \RuntimeException If backend configuration retrieval fails.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-backend-agnostic-search-architecture
	 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md#requirement-accent-folding-is-visible-to-administrators-and-can-be-bypassed
	 */
	public function getSearchBackendConfig(): array {
		return [
			'active' => 'database',
			'available' => ['database'],
			'accentInsensitive' => $this->accentInsensitive(),
		];
	}//end getSearchBackendConfig()

	/**
	 * The accent folding state: enabled, available and the reason it is not.
	 *
	 * @return array{enabled: bool, available: bool, reason: string}
	 *
	 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md#requirement-accent-folding-is-visible-to-administrators-and-can-be-bypassed
	 */
	public function accentInsensitive(): array {
		$reason    = $this->unavailableReason();
		$available = ($reason === '');
		$enabled   = false;
		if ($this->appConfig !== null) {
			$enabled = (new AccentFoldingSetting(appConfig: $this->appConfig))->enabled();
		}

		return [
			'enabled' => ($enabled === true && $available === true),
			'available' => $available,
			'reason' => $reason,
		];
	}//end accentInsensitive()

	/**
	 * Switch accent-insensitive search on or off.
	 *
	 * Switching it off always works. Switching it on where the database
	 * cannot fold accents is refused, naming the reason.
	 *
	 * @param bool $enabled Whether object search is to ignore accents.
	 *
	 * @return array{enabled: bool, available: bool, reason: string} The state afterwards.
	 *
	 * @throws InvalidArgumentException When enabling it where it is unavailable, or without app config.
	 *
	 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md#requirement-accent-folding-is-visible-to-administrators-and-can-be-bypassed
	 */
	public function setAccentInsensitive(bool $enabled): array {
		if ($this->appConfig === null) {
			throw new InvalidArgumentException('The search settings cannot be stored.');
		}

		$reason = $this->unavailableReason();
		if ($enabled === true && $reason !== '') {
			throw new InvalidArgumentException('Accent-insensitive search is not available: ' . $reason . '.');
		}

		(new AccentFoldingSetting(appConfig: $this->appConfig))->store(enabled: $enabled);

		return $this->accentInsensitive();
	}//end setAccentInsensitive()

	/**
	 * Why the database cannot fold accents, or '' when it can.
	 *
	 * @return string
	 */
	private function unavailableReason(): string {
		if ($this->db === null || $this->logger === null) {
			return MagicUnaccentProbe::REASON_UNSUPPORTED;
		}

		$this->probe ??= new MagicUnaccentProbe(db: $this->db, logger: $this->logger);

		try {
			return $this->probe->reason();
		} catch (\Throwable $e) {
			return MagicUnaccentProbe::REASON_UNSUPPORTED;
		}
	}//end unavailableReason()
}//end class

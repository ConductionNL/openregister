<?php

/**
 * Brings secrets stored before encryption existed under it.
 *
 * - A webhook's signing secret is sealed with ICrypto.
 * - The secret values of a consumer's authorization configuration are sealed
 *   with ICrypto.
 * - A calendar feed token is replaced by its SHA-256, which is what the feed
 *   now looks up, so the subscribed URL keeps working. A token minted without
 *   an expiry gets the default lifetime from now, so it keeps working until
 *   then.
 *
 * Idempotent: a sealed value is recognised by its prefix and a hashed token by
 * its form, and neither is touched twice.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use DateTime;
use OCA\OpenRegister\Db\Support\SecretAtRest;
use OCA\OpenRegister\Service\Calendar\CalendarFeedTokenServiceInterface;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\Security\ICrypto;

/**
 * Seals stored webhook and consumer secrets and hashes stored feed tokens.
 *
 * @spec openspec/specs/auth-system/spec.md
 */
class ProtectStoredSecrets implements IRepairStep {
	/**
	 * Seals and opens stored secrets.
	 *
	 * @var SecretAtRest
	 */
	private readonly SecretAtRest $secrets;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db     The database.
	 * @param ICrypto       $crypto Nextcloud's crypto service.
	 * @param IAppConfig    $config The default feed token lifetime.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		ICrypto $crypto,
		private readonly IAppConfig $config,
	) {
		$this->secrets = new SecretAtRest(crypto: $crypto);
	}//end __construct()

	/**
	 * The step's name, as shown by `occ maintenance:repair`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function getName(): string {
		return 'Encrypt stored webhook and consumer secrets and hash stored calendar feed tokens';
	}//end getName()

	/**
	 * Seal and hash what is still stored in clear.
	 *
	 * @param IOutput $output Progress output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function run(IOutput $output): void {
		$output->info(
			sprintf(
				'Encrypted %d webhook secret(s) and %d consumer configuration(s); hashed %d calendar feed token(s).',
				$this->sealWebhookSecrets(),
				$this->sealConsumerConfigurations(),
				$this->hashFeedTokens()
			)
		);
	}//end run()

	/**
	 * Seal every webhook secret still stored in clear.
	 *
	 * @return int How many were sealed.
	 */
	private function sealWebhookSecrets(): int {
		$sealed = 0;
		foreach ($this->rows(table: 'openregister_webhooks', columns: ['id', 'secret']) as $row) {
			$secret = $row['secret'];
			if (is_string($secret) === false || $secret === '' || SecretAtRest::isSealed(value: $secret) === true) {
				continue;
			}

			$this->updateRow(table: 'openregister_webhooks', id: (int)$row['id'], values: ['secret' => $this->secrets->seal(value: $secret)]);
			$sealed++;
		}

		return $sealed;
	}//end sealWebhookSecrets()

	/**
	 * Seal the secret values of every consumer configuration that still holds one in clear.
	 *
	 * @return int How many configurations changed.
	 */
	private function sealConsumerConfigurations(): int {
		$sealed = 0;
		foreach ($this->rows(table: 'openregister_consumers', columns: ['id', 'authorization_configuration']) as $row) {
			$configuration = json_decode((string)($row['authorization_configuration'] ?? ''), true);
			if (is_array($configuration) === false) {
				continue;
			}

			$protected = $this->secrets->sealConfiguration(configuration: $configuration);
			if ($protected === $configuration) {
				continue;
			}

			$this->updateRow(table: 'openregister_consumers', id: (int)$row['id'], values: ['authorization_configuration' => json_encode($protected)]);
			$sealed++;
		}

		return $sealed;
	}//end sealConsumerConfigurations()

	/**
	 * Replace every feed token still stored in clear by its hash, and give a never-expiring one the default lifetime.
	 *
	 * @return int How many were hashed.
	 */
	private function hashFeedTokens(): int {
		$hashed = 0;
		$expiry = $this->defaultExpiry();
		foreach ($this->rows(table: 'openregister_calendar_feed_tokens', columns: ['id', 'token', 'expires_at']) as $row) {
			$token = (string)$row['token'];
			if (self::isStoredForm(token: $token) === true) {
				continue;
			}

			$values = ['token' => hash('sha256', $token)];
			if ($row['expires_at'] === null && $expiry !== null) {
				$values['expires_at'] = $expiry;
			}

			$this->updateRow(table: 'openregister_calendar_feed_tokens', id: (int)$row['id'], values: $values);
			$hashed++;
		}

		return $hashed;
	}//end hashFeedTokens()

	/**
	 * Whether a stored feed token is already a SHA-256 hex digest (a minted token is 43 characters).
	 *
	 * @param string $token The stored token.
	 *
	 * @return bool
	 */
	private static function isStoredForm(string $token): bool {
		return strlen($token) === 64 && ctype_xdigit($token) === true;
	}//end isStoredForm()

	/**
	 * When a never-expiring token now expires, or null when the default is no expiry.
	 *
	 * @return string|null The expiry as a database datetime.
	 */
	private function defaultExpiry(): ?string {
		$seconds = $this->config->getValueInt(
			'openregister',
			CalendarFeedTokenServiceInterface::DEFAULT_TTL_KEY,
			CalendarFeedTokenServiceInterface::DEFAULT_TTL_SECONDS
		);
		if ($seconds <= 0) {
			return null;
		}

		return (new DateTime())->modify('+' . $seconds . ' seconds')->format('Y-m-d H:i:s');
	}//end defaultExpiry()

	/**
	 * Every row of a table, with the given columns; none when the table does not exist yet.
	 *
	 * @param string             $table   The table.
	 * @param array<int, string> $columns The columns.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(string $table, array $columns): array {
		if ($this->db->tableExists($table) === false) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select(...$columns)->from($table);
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}//end rows()

	/**
	 * Write values to one row.
	 *
	 * @param string                $table  The table.
	 * @param int                   $id     The row id.
	 * @param array<string, string> $values Column => value.
	 *
	 * @return void
	 */
	private function updateRow(string $table, int $id, array $values): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($table);
		foreach ($values as $column => $value) {
			$qb->set($column, $qb->createNamedParameter($value));
		}

		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}//end updateRow()
}//end class

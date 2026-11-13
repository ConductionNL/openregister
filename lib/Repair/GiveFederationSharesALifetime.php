<?php

/**
 * GiveFederationSharesALifetime: gives every federated share that has no expiry
 * its lifetime from the upgrade.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\Federation\ShareLifetime;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step: set `expires_at` to the share lifetime from now on every share that has none.
 *
 * A share without an expiry is not served, so without this step every share that
 * existed before expiry was introduced would stop at the upgrade.
 *
 * Idempotent: only rows without an expiry are written, so a second run changes nothing.
 * Never throws: a failed update is logged, and the shares stay unserved until it runs again.
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md
 */
class GiveFederationSharesALifetime implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db       The database.
	 * @param ShareLifetime   $lifetime How long a share is served.
	 * @param LoggerInterface $logger   Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ShareLifetime $lifetime,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name surfaced in occ and the admin UI.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md
	 */
	public function getName(): string {
		return 'Give every federated share without an expiry its lifetime from now';
	}//end getName()

	/**
	 * Set the expiry of every share that has none.
	 *
	 * @param IOutput $output Migration output handle.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md
	 */
	public function run(IOutput $output): void {
		if ($this->db->tableExists('openregister_federated_shares') === false) {
			return;
		}

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->update('openregister_federated_shares')
				->set('expires_at', $qb->createNamedParameter($this->lifetime->newExpiry(), IQueryBuilder::PARAM_DATE))
				->where($qb->expr()->isNull('expires_at'));
			$count = $qb->executeStatement();
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[GiveFederationSharesALifetime] Could not give federated shares an expiry',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return;
		}

		$output->info(sprintf('%d federated share(s) now expire in %d days.', $count, ShareLifetime::DAYS));
	}//end run()
}//end class

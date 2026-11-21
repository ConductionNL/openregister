<?php

/**
 * Starts the keyed audit hash chain after the existing rows.
 *
 * The rows written before this step keep their unkeyed links. The step first
 * verifies them, records the verdict in app config and in the repair output,
 * and then sets the keyed-from point: the next row links onto the last
 * existing one with an HMAC under the chain key, which is generated here on
 * first use and held encrypted by TenantKeyService. Verification afterwards
 * accepts the unkeyed segment up to that point and reports how many rows it
 * holds.
 *
 * Idempotent: once a keyed-from point is stored, the step does nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/audit-hash-chain/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\AuditRechainInterface;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use RuntimeException;

/**
 * Re-links the existing audit chain into a keyed one, once.
 *
 * @spec openspec/specs/audit-hash-chain/spec.md
 */
class KeyAuditHashChain implements IRepairStep {
	/**
	 * App config key holding the verdict on the unkeyed rows, as JSON.
	 *
	 * @var string
	 */
	public const VERDICT_KEY = 'audit_chain_unkeyed_verdict';

	/**
	 * Constructor.
	 *
	 * @param AuditRechainInterface $hashes Verifies the existing rows and starts the keyed chain.
	 * @param IAppConfig            $config Stores the verdict.
	 */
	public function __construct(
		private readonly AuditRechainInterface $hashes,
		private readonly IAppConfig $config,
	) {
	}//end __construct()

	/**
	 * The step's name, as shown by `occ maintenance:repair`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function getName(): string {
		return 'Key the OpenRegister audit hash chain from the next row on';
	}//end getName()

	/**
	 * Verify the unkeyed rows, record the verdict and start the keyed chain.
	 *
	 * @param IOutput $output Progress output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$result = $this->hashes->startKeyedChain();
		} catch (RuntimeException $e) {
			$output->warning($e->getMessage() . ' Re-run `occ maintenance:repair` to key the audit chain.');
			return;
		}

		if ($result['started'] === false) {
			$output->info(sprintf('Audit hash chain already keyed from row %s; nothing to do.', (string)($result['keyedFrom'] ?? '-')));
			return;
		}

		$verdict = (array)$result['unkeyedChain'];
		$this->config->setValueString('openregister', self::VERDICT_KEY, (string)json_encode(['keyedFrom' => $result['keyedFrom'], 'verdict' => $verdict]));

		if (($verdict['brokenAt'] ?? null) !== null) {
			$output->warning(
				sprintf(
					'The unkeyed audit chain is broken at row %d; the verdict is kept under %s. Rows from %d on are keyed.',
					(int)$verdict['brokenAt'],
					self::VERDICT_KEY,
					(int)$result['keyedFrom']
				)
			);
			return;
		}

		$output->info(
			sprintf(
				'Verified %d unkeyed audit row(s); rows from %d on are chained with the key.',
				(int)($verdict['entriesVerified'] ?? 0),
				(int)$result['keyedFrom']
			)
		);
	}//end run()
}//end class

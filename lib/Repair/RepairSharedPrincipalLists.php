<?php

/**
 * Repair step: recompute the derived share lists on every brokered credential.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/shared-credentials-and-flows/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Sharing\SharePrincipalDeriverInterface;
use OCA\OpenRegister\Service\Sharing\SharePrincipalRepair;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * A credential written before the derived lists existed, or by a path that
 * bypassed the deriver, is brought in line with its `sharedWith`. Flows carry
 * the same lists, but flow sharing is not enabled yet (tasks group 6), so no
 * flow write path derives them; they join this step when it is.
 *
 * @spec openspec/changes/shared-credentials-and-flows/tasks.md
 */
class RepairSharedPrincipalLists implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the object service on run, so an install without the register still repairs.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/shared-credentials-and-flows/tasks.md
	 */
	public function getName(): string {
		return 'Recompute the derived share lists of brokered credentials';
	}//end getName()

	/**
	 * Recompute the lists, reporting how many were stale.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/shared-credentials-and-flows/tasks.md
	 */
	public function run(IOutput $output): void {
		try {
			// The operations key, as LocalStoreRegistry does: not every container aliases the contract interface.
			$objects = $this->container->get(ObjectOperationsInterface::class);
			$deriver = $this->container->get(SharePrincipalDeriverInterface::class);
			if ($objects instanceof ObjectServiceInterface === false) {
				$output->info('Shared credential lists not checked: no object service');
				return;
			}

			$objects->setRegister(CredentialBrokerService::REGISTER)->setSchema(CredentialBrokerService::SCHEMA);
		} catch (Throwable $e) {
			$output->info('Shared credential lists not checked: ' . $e->getMessage());
			return;
		}

		$report = (new SharePrincipalRepair(deriver: $deriver))->repair(
			page: static fn (int $offset, int $limit): array => $objects->findAll(
				config: ['limit' => $limit, 'offset' => $offset],
				_rbac: false,
				_multitenancy: false
			),
			write: static function (ObjectEntity $object, array $data) use ($objects): void {
				$objects->saveObject(
					object: $data,
					options: new ObjectSaveOptions(
						target: new ObjectFindTarget(register: CredentialBrokerService::REGISTER, schema: CredentialBrokerService::SCHEMA),
						uuid: (string)$object->getUuid()
					),
					_rbac: false,
					_multitenancy: false
				);
			}
		);

		$output->info(sprintf('Shared credential lists: %d checked, %d recomputed.', $report['checked'], $report['repaired']));
		if ($report['failed'] !== []) {
			$output->warning('Shared credential lists could not be rewritten for: ' . implode(', ', $report['failed']));
		}
	}//end run()
}//end class

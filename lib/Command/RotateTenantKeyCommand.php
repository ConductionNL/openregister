<?php

/**
 * occ openregister:tenant-key:rotate
 *
 * Retires a tenant's active HMAC signing key and stores a fresh one. Prints
 * only the rotation metadata, never key material.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/saas-multi-tenant/spec.md#new-active-keys-must-be-stored-encrypted-with-status-active
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\TenantKeyService;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Rotate one tenant's HMAC signing key.
 *
 * @spec openspec/specs/saas-multi-tenant/spec.md#new-active-keys-must-be-stored-encrypted-with-status-active
 */
class RotateTenantKeyCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param TenantKeyService $tenantKeys The tenant key store.
	 */
	public function __construct(
		private readonly TenantKeyService $tenantKeys,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md#new-active-keys-must-be-stored-encrypted-with-status-active
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:tenant-key:rotate')
			->setDescription(description: 'Retire a tenant\'s active HMAC signing key and store a new one')
			->addArgument(name: 'tenant', mode: InputArgument::REQUIRED, description: 'The tenant identifier');
	}//end configure()

	/**
	 * Rotate the key and print the metadata.
	 *
	 * @param InputInterface  $input  Input.
	 * @param OutputInterface $output Output.
	 *
	 * @return int 0 when the key was rotated, 1 when it was refused or failed.
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md#new-active-keys-must-be-stored-encrypted-with-status-active
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$tenant = trim((string)$input->getArgument('tenant'));
		if ($tenant === '') {
			$output->writeln('<error>A tenant identifier is required.</error>');
			return 1;
		}

		try {
			$rotation = $this->tenantKeys->rotateTenantKey(tenantId: $tenant);
		} catch (RuntimeException $e) {
			$output->writeln('<error>The key was not rotated: '.$e->getMessage().'</error>');
			return 1;
		}

		$output->writeln($this->describe(rotation: $rotation));
		return 0;
	}//end execute()

	/**
	 * One line of rotation metadata.
	 *
	 * @param array{tenant_id: string, rotated_at: string, retired_key_id: int|null} $rotation The metadata.
	 *
	 * @return string The line.
	 */
	private function describe(array $rotation): string {
		$retired = 'no earlier key existed';
		if ($rotation['retired_key_id'] !== null) {
			$retired = sprintf('key %d retired', $rotation['retired_key_id']);
		}

		return sprintf('Tenant %s: new key active since %s, %s.', $rotation['tenant_id'], $rotation['rotated_at'], $retired);
	}//end describe()
}//end class

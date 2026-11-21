<?php

/**
 * OpenRegister FederationSharesExpiringCommand
 *
 * Lists the live outgoing federated shares that expire soon, across organisations.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapperInterface;
use OCA\OpenRegister\Service\Federation\ShareLifetime;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ openregister:federation:expiring [--days=14]`: the live outgoing shares that expire
 * within the given days, or have expired, with their creator so they can be renewed.
 *
 * Report only; it changes nothing.
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md
 */
class FederationSharesExpiringCommand extends Command {

	/**
	 * The days ahead reported when none are given.
	 *
	 * @var int
	 */
	private const DEFAULT_DAYS = 14;

	/**
	 * Constructor.
	 *
	 * @param FederatedShareMapperInterface $shareMapper The shares.
	 * @param ShareLifetime                 $lifetime    How long a share is served.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md
	 */
	public function __construct(
		private readonly FederatedShareMapperInterface $shareMapper,
		private readonly ShareLifetime $lifetime,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, description and the days option.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:federation:expiring')
			->setDescription(description: 'List the federated shares that expire within the given days, or have expired (report only)')
			->addOption(name: 'days', mode: InputOption::VALUE_REQUIRED, description: 'Days ahead', default: (string)self::DEFAULT_DAYS);
	}//end configure()

	/**
	 * Print the expiring shares.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return int The exit code.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$days   = max(0, (int)$input->getOption('days'));
		$shares = $this->shareMapper->findLiveOutgoingAcrossOrganisations();
		$rows   = [];
		foreach ($shares as $share) {
			if ($this->lifetime->expiresWithin(share: $share, days: $days) === true) {
				$rows[] = self::row(share: $share);
			}
		}

		if ($rows !== []) {
			(new Table(output: $output))
				->setHeaders(['id', 'organisation', 'grant', 'target', 'creator', 'expires'])
				->setRows($rows)
				->render();
		}

		$output->writeln(messages: sprintf('%d of %d live outgoing shares expire within %d days.', count($rows), count($shares), $days));

		return Command::SUCCESS;
	}//end execute()

	/**
	 * One table row.
	 *
	 * @param FederatedShare $share The share.
	 *
	 * @return array<int, int|string|null>
	 */
	private static function row(FederatedShare $share): array {
		return [
			$share->getId(),
			(string)$share->getOrganisation(),
			$share->getScope() . ' ' . $share->getPermissions(),
			trim($share->getRegister() . ' / ' . $share->getSchema() . ' ' . $share->getObjectUri()),
			(string)$share->getOwner(),
			($share->getExpiresAt()?->format('Y-m-d H:i') ?? 'no expiry'),
		];
	}//end row()
}//end class

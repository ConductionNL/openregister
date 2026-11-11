<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Report the federated shares that hand out more than their creator holds.
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
 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapperInterface;
use OCA\OpenRegister\Service\Federation\ShareGrantorInterface;
use OCP\IUserManager;
use OCP\IUserSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Report only: lists every live outgoing share whose creator does not hold the rights it grants,
 * judged as that creator now, and every share whose creator was never recorded. Nothing is revoked.
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
 */
class FederationShareRightsCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param FederatedShareMapperInterface $shareMapper Reads every live outgoing share.
	 * @param ShareGrantorInterface         $grantor     Judges a share for the signed-in user.
	 * @param IUserManager                  $userManager Finds a share's creator.
	 * @param IUserSession                  $userSession Signs the creator in while their share is judged.
	 */
	public function __construct(
		private readonly FederatedShareMapperInterface $shareMapper,
		private readonly ShareGrantorInterface $grantor,
		private readonly IUserManager $userManager,
		private readonly IUserSession $userSession,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:federation:share-rights')
			->setDescription(
				description: 'List the federated shares whose creator does not hold the rights the share grants (report only)'
			);
	}//end configure()

	/**
	 * List the shares that need review.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int Always success: the command changes nothing.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Symfony's.
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$shares = $this->shareMapper->findLiveOutgoingAcrossOrganisations();
		$rows   = $this->reviewRows(shares: $shares);

		if ($rows !== []) {
			(new Table(output: $output))
				->setHeaders(['id', 'organisation', 'grant', 'target', 'creator', 'finding'])
				->setRows($rows)
				->render();
		}

		$output->writeln(messages: sprintf('%d of %d live outgoing shares need review.', count($rows), count($shares)));

		return Command::SUCCESS;
	}//end execute()

	/**
	 * One table row for every share with a finding, in share order.
	 *
	 * @param array<int, FederatedShare> $shares The live outgoing shares.
	 *
	 * @return array<int, array<int, mixed>> The rows.
	 */
	private function reviewRows(array $shares): array {
		$rows = [];
		foreach ($shares as $share) {
			$finding = $this->finding(share: $share);
			if ($finding !== null) {
				$rows[] = self::row(share: $share, finding: $finding);
			}
		}

		return $rows;
	}//end reviewRows()

	/**
	 * The table row of a share that needs review.
	 *
	 * @param FederatedShare $share   The share.
	 * @param string         $finding What is wrong with it.
	 *
	 * @return array<int, mixed> The row: id, organisation, grant, target, creator, finding.
	 */
	private static function row(FederatedShare $share, string $finding): array {
		return [
			$share->getId(),
			(string)$share->getOrganisation(),
			$share->getScope() . ' ' . $share->getPermissions(),
			trim($share->getRegister() . ' / ' . $share->getSchema() . ' ' . $share->getObjectUri()),
			(string)$share->getOwner(),
			$finding,
		];
	}//end row()

	/**
	 * What is wrong with one share, or null when its creator holds every right it grants.
	 *
	 * @param FederatedShare $share The share.
	 *
	 * @return string|null The finding.
	 */
	private function finding(FederatedShare $share): ?string {
		$owner = (string)$share->getOwner();
		if ($owner === '') {
			return 'creator not recorded (created before creators were recorded)';
		}

		$creator = $this->userManager->get($owner);
		if ($creator === null) {
			return 'creator no longer exists';
		}

		$previous = $this->userSession->getUser();
		$this->userSession->setUser($creator);
		try {
			return $this->grantor->refusal(share: $share);
		} finally {
			$this->userSession->setUser($previous);
		}
	}//end finding()
}//end class

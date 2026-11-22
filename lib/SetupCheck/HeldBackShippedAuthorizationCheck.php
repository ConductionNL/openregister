<?php

/**
 * A setup check that warns when shipped access rules wait for acceptance.
 *
 * An unattended upgrade keeps an instance's authorization block where the
 * app changed it too, and waits for an administrator. Until then the schema
 * runs without the access policy its app ships, which can mean a schema that
 * anyone may read. The register descriptor inventory names those blocks; this
 * puts them on the administration overview, where an administrator looks.
 *
 * @category SetupCheck
 * @package  OCA\OpenRegister\SetupCheck
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\SetupCheck;

use OCA\OpenRegister\Service\RegisterDescriptorServiceInterface;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Warns about shipped authorization blocks an upgrade held back.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) SetupResult's named constructors are the OCP API for a setup check result.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
class HeldBackShippedAuthorizationCheck implements ISetupCheck {

	/**
	 * Constructor.
	 *
	 * @param RegisterDescriptorServiceInterface $descriptors The register descriptor inventory.
	 * @param IL10N                              $l10n        Translations.
	 * @param LoggerInterface                    $logger      The logger.
	 */
	public function __construct(
		private readonly RegisterDescriptorServiceInterface $descriptors,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The category the check is listed under.
	 *
	 * @return string The category.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function getCategory(): string {
		return 'security';
	}//end getCategory()

	/**
	 * The name of the check.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function getName(): string {
		return $this->l10n->t('OpenRegister shipped access rules');
	}//end getName()

	/**
	 * Warn when any register holds back an authorization block its app ships.
	 *
	 * @return SetupResult The result.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function run(): SetupResult {
		try {
			$rows = $this->descriptors->inventory();
		} catch (Throwable $e) {
			$this->logger->warning('[HeldBackShippedAuthorizationCheck] inventory unreadable: ' . $e->getMessage());
			return SetupResult::info($this->l10n->t('Could not check whether shipped access rules wait for acceptance: %s', [$e->getMessage()]));
		}

		$held = [];
		foreach ($rows as $row) {
			if (($row['heldBack'] ?? null) !== null) {
				$held[] = sprintf('%s/%s (%s)', $row['appId'], $row['slug'], $row['heldBack']);
			}
		}

		if ($held === []) {
			return SetupResult::success($this->l10n->t('No shipped access rules are waiting for acceptance.'));
		}

		// phpcs:disable Generic.Files.LineLength.MaxExceeded -- the translation extractor reads t() literals whole.
		return SetupResult::warning(
			$this->l10n->t('Access rules shipped by an app were not applied because this instance changed them too, so these schemas run without the access policy their app ships: %s. Review them with "occ openregister:descriptors:list --problems-only" and accept them with "occ openregister:descriptors:list --import=<register> --app=<app>".', [implode('; ', $held)])
		);
		// phpcs:enable Generic.Files.LineLength.MaxExceeded
	}//end run()
}//end class

<?php

/**
 * AnalyticsProvider — exposes NC Analytics reports linked to an
 * OpenRegister object via the Tier-2 `openregister_analytics_links`
 * table.
 *
 * Pre-Tier-2 the provider matched a `[or:{objectUuid}]` marker embedded
 * in the report's `name` field (wave-2.2). Tier-2 (this file) reads the
 * dedicated link table instead — the marker convention is retained as a
 * backwards-compat fallback for reports that pre-date the link table.
 *
 * Storage strategy is `link-table` — the link rows live in OR; the
 * upstream `analytics_report` table is only read for live title / type
 * data via the wrapping
 * {@see \OCA\OpenRegister\Service\AnalyticsLinkService}.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing

use OCA\OpenRegister\Db\AnalyticsLink;
use OCA\OpenRegister\Db\AnalyticsLinkMapperInterface;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class AnalyticsProvider extends AbstractIntegrationProvider {

	use NcAppLinkTableProvider;
	use MarkerSourceFallback;
	use MarkerLookupTrait;

	private const REQUIRED_APP = 'analytics';

	private const MARKER_PREFIX = '[or:';

	/**
	 * Where the legacy `[or:{uuid}]` marker lives: table, marker column, extra
	 * columns, id column, and the app URL a row's id is appended to.
	 *
	 * @var array{table: string, column: string, extra: list<string>, id: string, url: string}
	 */
	private const MARKER_SOURCE = [
		'table' => 'analytics_report',
		'column' => 'name',
		'extra' => ['subheader', 'type'],
		'id' => 'id',
		'url' => '/index.php/apps/analytics/#/r/',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db NC DB connection.
	 * @param IAppManager $appManager NC app manager.
	 * @param IL10N $l10n Localisation.
	 * @param AnalyticsLinkMapperInterface $analyticsLinkMapper Analytics-link mapper (Tier-2 link table).
	 * @param LoggerInterface $logger Logger for the degraded marker-scan path.
	 */
	public function __construct(
		private IDBConnection $db,
		private IAppManager $appManager,
		private IL10N $l10n,
		private AnalyticsLinkMapperInterface $analyticsLinkMapper,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	public function getId(): string {
		return 'analytics';
	}//end getId()

	public function getLabel(): string {
		return $this->l10n->t('Analytics');
	}//end getLabel()

	public function getIcon(): string {
		return 'ChartBar';
	}//end getIcon()

	public function getGroup(): ?string {
		return 'workflow';
	}//end getGroup()

	/**
	 * The link mapper this provider reads first.
	 *
	 * @return AnalyticsLinkMapperInterface
	 */
	protected function linkMapper(): AnalyticsLinkMapperInterface {
		return $this->analyticsLinkMapper;
	}//end linkMapper()

	/**
	 * Convert an AnalyticsLink row into the registry leaf-row shape.
	 *
	 * @param AnalyticsLink $link Link row from the mapper.
	 *
	 * @return array<string,mixed>
	 */
	protected function rowFromLink(AnalyticsLink $link): array {
		$reportId = (int)$link->getReportId();
		$data = $link->jsonSerialize();

		return [
			'id' => (string)$reportId,
			'title' => (string)$link->getReportTitle(),
			'url' => '/index.php/apps/analytics/#/r/' . $reportId,
			'subheader' => $link->getSubheader(),
			'data' => $data,
		];
	}//end rowFromLink()

	/**
	 * Provider health descriptor (enabled/disabled echo).
	 *
	 * @return array<string,mixed>
	 *
	 * @spec exclude Static enabled/disabled descriptor echoing isEnabled() — no standalone health behaviour;
	 *              the health/OCS contract is owned by pluggable-integration-registry task-2.
	 */
	public function health(): array {
		return $this->installedHealth(available: $this->isEnabled(), missing: 'NC Analytics app is not installed');
	}//end health()
}//end class

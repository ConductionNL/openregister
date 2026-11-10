<?php

/**
 * CollectivesProvider — exposes NC Knowledge pages linked to an
 * OpenRegister object via the Tier-2 `openregister_collective_links`
 * table.
 *
 * Pre-Tier-2 the provider matched a `[or:{objectUuid}]` marker embedded
 * in the page's `slug` field. Tier-2 (this file) reads the dedicated
 * link table instead — the marker convention is retained as a
 * backwards-compat fallback for pages that pre-date the link table.
 *
 * Storage strategy is `link-table` — the link rows live in OR; the
 * upstream `collectives_pages` table is only read for the legacy marker
 * fallback via the wrapping
 * {@see \OCA\OpenRegister\Service\CollectiveLinkService}.
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

use OCA\OpenRegister\Db\CollectiveLink;
use OCA\OpenRegister\Db\CollectiveLinkMapperInterface;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class CollectivesProvider extends AbstractIntegrationProvider {

	use NcAppLinkTableProvider;
	use MarkerSourceFallback;
	use MarkerLookupTrait;

	private const REQUIRED_APP = 'collectives';

	private const MARKER_PREFIX = '[or:';

	/**
	 * Where the legacy `[or:{uuid}]` marker lives: table, marker column, extra
	 * columns, id column, and the app URL a row's id is appended to.
	 *
	 * @var array{table: string, column: string, extra: list<string>, id: string, url: string}
	 */
	private const MARKER_SOURCE = [
		'table' => 'collectives_pages',
		'column' => 'slug',
		'extra' => ['emoji', 'last_user_id'],
		'id' => 'id',
		'url' => '/index.php/apps/collectives/',
	];

	/**
	 * NC DB connection.
	 *
	 * @var IDBConnection
	 */
	private IDBConnection $db;

	/**
	 * NC app manager.
	 *
	 * @var IAppManager
	 */
	private IAppManager $appManager;

	/**
	 * Localisation.
	 *
	 * @var IL10N
	 */
	private IL10N $l10n;

	/**
	 * Logger for the degraded marker-scan path.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param LeafMarkerScanning            $leaf                 The DB connection, app manager, localisation and marker-scan logger.
	 * @param CollectiveLinkMapperInterface $collectiveLinkMapper Collective-link mapper (Tier-2 link table).
	 */
	public function __construct(
		LeafMarkerScanning $leaf,
		private CollectiveLinkMapperInterface $collectiveLinkMapper,
	) {
		$this->db = $leaf->db;
		$this->appManager = $leaf->appManager;
		$this->l10n = $leaf->l10n;
		$this->logger = $leaf->logger;
	}//end __construct()

	public function getId(): string {
		return 'collectives';
	}//end getId()

	public function getLabel(): string {
		return $this->l10n->t('Knowledge');
	}//end getLabel()

	public function getIcon(): string {
		return 'BookOpenPageVariant';
	}//end getIcon()

	public function getGroup(): ?string {
		return 'docs';
	}//end getGroup()

	/**
	 * The link mapper this provider reads first.
	 *
	 * @return CollectiveLinkMapperInterface
	 */
	protected function linkMapper(): CollectiveLinkMapperInterface {
		return $this->collectiveLinkMapper;
	}//end linkMapper()

	/**
	 * Convert a CollectiveLink row into the registry leaf-row shape.
	 *
	 * @param CollectiveLink $link Link row from the mapper.
	 *
	 * @return array<string,mixed>
	 */
	protected function rowFromLink(CollectiveLink $link): array {
		return [
			'id' => (string)(int)$link->getPageId(),
			'title' => (string)$link->getPageTitle(),
			'url' => $this->pageUrl(link: $link),
			'emoji' => $link->getEmoji(),
			'collectiveName' => $link->getCollectiveName(),
			'data' => $link->jsonSerialize(),
		];
	}//end rowFromLink()

	/**
	 * The page URL the link stored, or the Collectives file URL of its page.
	 *
	 * @param CollectiveLink $link The link.
	 *
	 * @return string
	 */
	private function pageUrl(CollectiveLink $link): string {
		$url = $link->getUrl();
		if ($url === null || $url === '') {
			return '/index.php/apps/collectives/?fileId=' . (int)$link->getPageId();
		}

		return $url;
	}//end pageUrl()

	/**
	 * Provider health descriptor (enabled/disabled echo).
	 *
	 * @return array<string,mixed>
	 *
	 * @spec exclude Static enabled/disabled descriptor echoing isEnabled() — no standalone health behaviour;
	 *              the health/OCS contract is owned by pluggable-integration-registry task-2.
	 */
	public function health(): array {
		return $this->installedHealth(available: $this->isEnabled(), missing: 'NC Knowledge app is not installed');
	}//end health()
}//end class

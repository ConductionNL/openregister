<?php

/**
 * OpenRegister operations widget on the Nextcloud dashboard, for administrators.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Dashboard
 * @package  OCA\OpenRegister\Dashboard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-nextcloud-dashboard-integration
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Dashboard;

use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\Service\Observability\ObjectCountMetricsProvider;
use OCA\OpenRegister\Service\Observability\RequestAlertEvaluator;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IConditionalWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists total objects, registers and schemas, and the errors and average response time of
 * the last five-minute window that `RequestAlertJob` judged.
 *
 * It is an API widget, so the Nextcloud dashboard renders its items itself and the widget
 * ships no script. It is shown to administrators only: the figures cover every register,
 * whoever may read them. The error item links to the OpenRegister admin settings; the other
 * items link to the OpenRegister dashboard. A figure that cannot be read is left out.
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-nextcloud-dashboard-integration
 */
class OperationsDashboardWidget implements IAPIWidgetV2, IConditionalWidget, IIconWidget {

	/**
	 * The widget id.
	 *
	 * @var string
	 */
	public const ID = 'openregister-operations';

	/**
	 * Constructor.
	 *
	 * @param IL10N                      $l10n    Translations.
	 * @param IURLGenerator              $urls    Builds the links and the icon.
	 * @param IUserSession               $session The viewer.
	 * @param IGroupManager              $groups  Decides who is an administrator.
	 * @param IDBConnection              $db      Counts registers and schemas.
	 * @param ObjectCountMetricsProvider $objects Counts the live objects.
	 * @param RequestAlertEvaluator      $windows Holds the last judged request window.
	 * @param LoggerInterface            $logger  Logs a figure that cannot be read.
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
		private readonly IUserSession $session,
		private readonly IGroupManager $groups,
		private readonly IDBConnection $db,
		private readonly ObjectCountMetricsProvider $objects,
		private readonly RequestAlertEvaluator $windows,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-nextcloud-dashboard-integration
	 */
	public function getId(): string {
		return self::ID;
	}//end getId()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-nextcloud-dashboard-integration
	 */
	public function getTitle(): string {
		return $this->l10n->t('OpenRegister operations');
	}//end getTitle()

	/**
	 * {@inheritDoc}
	 *
	 * @return int
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-nextcloud-dashboard-integration
	 */
	public function getOrder(): int {
		return 30;
	}//end getOrder()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-nextcloud-dashboard-integration
	 */
	public function getIconClass(): string {
		return '';
	}//end getIconClass()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-nextcloud-dashboard-integration
	 */
	public function getIconUrl(): string {
		return $this->urls->getAbsoluteURL($this->urls->imagePath('openregister', 'app-dark.svg'));
	}//end getIconUrl()

	/**
	 * {@inheritDoc}
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-nextcloud-dashboard-integration
	 */
	public function getUrl(): ?string {
		return $this->urls->linkToRouteAbsolute('openregister.dashboard.page');
	}//end getUrl()

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-nextcloud-dashboard-integration
	 */
	public function load(): void {
	}//end load()

	/**
	 * Shown to administrators only.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-nextcloud-dashboard-integration
	 */
	public function isEnabled(): bool {
		$user = $this->session->getUser();
		return $user !== null && $this->groups->isAdmin($user->getUID()) === true;
	}//end isEnabled()

	/**
	 * The figures, for an administrator; none for anyone else.
	 *
	 * @param string      $userId The viewer.
	 * @param string|null $since  Unused: the figures are a snapshot.
	 * @param int         $limit  Unused: there are at most five figures.
	 *
	 * @return WidgetItems
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $since and $limit are part of the IAPIWidgetV2 contract.
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-nextcloud-dashboard-integration
	 */
	public function getItemsV2(string $userId, ?string $since=null, int $limit=7): WidgetItems {
		if ($this->groups->isAdmin($userId) === false) {
			return new WidgetItems(items: [], emptyContentMessage: $this->l10n->t('Only administrators see the OpenRegister operations figures.'));
		}

		$dashboard = $this->urls->linkToRouteAbsolute('openregister.dashboard.page');
		$items     = [];

		$objects = $this->objectTotal();
		if ($objects !== null) {
			$items[] = new WidgetItem(title: (string) $objects, subtitle: $this->l10n->t('Objects'), link: $dashboard);
		}

		foreach (['openregister_registers' => $this->l10n->t('Registers'), 'openregister_schemas' => $this->l10n->t('Schemas')] as $table => $label) {
			$count = $this->rowCount(table: $table);
			if ($count !== null) {
				$items[] = new WidgetItem(title: (string) $count, subtitle: $label, link: $dashboard);
			}
		}

		$window = $this->lastWindow();
		if ($window !== null) {
			$minutes  = (string) $window['minutes'];
			$settings = $this->urls->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'openregister']);
			$items[]  = new WidgetItem(
				title: (string) $window['errors'],
				subtitle: $this->l10n->t('Server errors in the last %s minutes', [$minutes]),
				link: $settings
			);
			$average  = $this->l10n->t('no requests');
			if ($window['averageMs'] !== null) {
				$average = $window['averageMs'].' ms';
			}

			$items[] = new WidgetItem(
				title: $average,
				subtitle: $this->l10n->t('Average response time in the last %s minutes', [$minutes]),
				link: $dashboard
			);
		}

		return new WidgetItems(items: $items, emptyContentMessage: $this->l10n->t('No OpenRegister figures are available yet.'));
	}//end getItemsV2()

	/**
	 * The live objects over every register and schema, or null when they cannot be counted.
	 *
	 * @return int|null
	 */
	private function objectTotal(): ?int {
		try {
			$families = $this->objects->metrics();
		} catch (Throwable $e) {
			$this->logger->warning('[OperationsDashboardWidget] objects could not be counted: '.$e->getMessage());
			return null;
		}

		$samples = array_merge(...array_map(static fn (MetricSample $family): array => $family->samples, $families));
		return array_sum(array_map(static fn (array $sample): int => (int) $sample['value'], $samples));
	}//end objectTotal()

	/**
	 * The row count of a table, or null when it cannot be counted.
	 *
	 * @param string $table The table.
	 *
	 * @return int|null
	 */
	private function rowCount(string $table): ?int {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*', 'cnt'))->from($table);
			$result = $qb->executeQuery();
			$count  = (int) $result->fetchOne();
			$result->closeCursor();
		} catch (Throwable $e) {
			$this->logger->warning('[OperationsDashboardWidget] '.$table.' could not be counted: '.$e->getMessage());
			return null;
		}

		return $count;
	}//end rowCount()

	/**
	 * The last judged request window, or null before there is one or when it cannot be read.
	 *
	 * @return array{minutes: int, requests: int, errors: int, averageMs: int|null}|null
	 */
	private function lastWindow(): ?array {
		try {
			return $this->windows->lastWindow();
		} catch (Throwable $e) {
			$this->logger->warning('[OperationsDashboardWidget] the request window could not be read: '.$e->getMessage());
			return null;
		}
	}//end lastWindow()
}//end class

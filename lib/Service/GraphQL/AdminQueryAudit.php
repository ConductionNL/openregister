<?php

/**
 * OpenRegister AdminQueryAudit
 *
 * Records a console run or download as an audit fact, with the query but never
 * a variable value.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\GraphQL
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\GraphQL;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\IUserSession;
use Symfony\Component\Uid\Uuid;

/**
 * Each console run writes a `query.run` audit row and each download a
 * `query.export` row, on the hash chain through
 * {@see AuditTrailMapper::insertAuditTrails()}.
 *
 * 🔑 THE ROW HOLDS THE QUERY BUT NEVER A VARIABLE VALUE. A filter value is
 * often a BSN or a name, so the row keeps the variable NAMES and drops their
 * values. The query text is capped so one enormous document cannot bloat the
 * trail, and its sha256 lets two runs of the same query be recognised as one.
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
 */
class AdminQueryAudit {

	/**
	 * The audit action of a run.
	 *
	 * @var string
	 */
	public const ACTION_RUN = 'query.run';

	/**
	 * The audit action of a download.
	 *
	 * @var string
	 */
	public const ACTION_EXPORT = 'query.export';

	/**
	 * The most query text bytes a row keeps.
	 *
	 * @var int
	 */
	private const QUERY_CAP = 8192;

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapperInterface     $auditTrailMapper The audit trail writer.
	 * @param IUserSession                  $userSession      The acting administrator.
	 * @param OrganisationServiceInterface  $organisations    The active organisation.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $auditTrailMapper,
		private readonly IUserSession $userSession,
		private readonly OrganisationServiceInterface $organisations,
	) {
	}//end __construct()

	/**
	 * Write an audit row for a run or a download.
	 *
	 * @param string               $action    `query.run` or `query.export`.
	 * @param string               $query     The GraphQL document.
	 * @param array<string, mixed> $variables The variables, read for their names only.
	 * @param string|null          $operation The operation name.
	 * @param array<string, mixed> $outcome   `rows`, `truncated`, `durationMs`, `outcome` and, for an export, `format`.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `Uuid::v4()` is the standard Symfony UID pattern used across this codebase.
	 *
	 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
	 */
	public function record(string $action, string $query, array $variables, ?string $operation, array $outcome): void {
		$row = new AuditTrail();
		$row->setUuid((string) Uuid::v4());
		$row->setAction($action);
		$row->setUser($this->userSession->getUser()?->getUID());
		$row->setOrganisationId($this->activeOrganisationId());
		$row->setCreated(new DateTime());
		$row->setChanged($this->changed(query: $query, variables: $variables, operation: $operation, outcome: $outcome));

		$this->auditTrailMapper->insertAuditTrails([$row]);
	}//end record()

	/**
	 * The `changed` payload: the query and its hash, the operation, the
	 * variable names, and the run's outcome.
	 *
	 * @param string               $query     The GraphQL document.
	 * @param array<string, mixed> $variables The variables, read for their names only.
	 * @param string|null          $operation The operation name.
	 * @param array<string, mixed> $outcome   The run outcome.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function changed(string $query, array $variables, ?string $operation, array $outcome): array {
		return array_merge(
			[
				'query' => mb_substr($query, 0, self::QUERY_CAP),
				'queryHash' => hash('sha256', $query),
				'operation' => $operation,
				'variableNames' => array_values(array_map('strval', array_keys($variables))),
			],
			$outcome
		);
	}//end changed()

	/**
	 * The active organisation's uuid, or null when none is active.
	 *
	 * @return string|null The organisation uuid.
	 */
	private function activeOrganisationId(): ?string {
		$uuid = $this->organisations->getActiveOrganisation()?->getUuid();
		if ($uuid === null || $uuid === '') {
			return null;
		}

		return (string) $uuid;
	}//end activeOrganisationId()
}//end class

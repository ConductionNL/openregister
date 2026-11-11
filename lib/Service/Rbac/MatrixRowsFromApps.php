<?php

/**
 * Matrix rows an app contributes at check time.
 *
 * A matrix that declares `rowsFrom: "<provider>"` asks the app behind that id
 * for rows through `MatrixRowsRequestedEvent`. The rows change with the app's
 * own records (a published version, a role whose group moved, an end that
 * passed), which is why they are asked for rather than written into the
 * schema: a schema used as a cache goes stale, and a stale grant is a
 * disclosure.
 *
 * 🔴 EVERY FAILURE IS NO ROWS. No dispatcher, a listener that throws, a row
 * that does not read: each contributes nothing. A matrix only adds ways in, so
 * nothing is the closed direction. A row that does not read is logged with the
 * provider, because a grant that silently never fires looks configured.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\MatrixRowsRequestedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Asks apps for matrix rows, once per request per schema and provider.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
class MatrixRowsFromApps {

	/**
	 * Groups a provider row may not grant to: they would open the schema to
	 * everybody (public, authenticated) or name the group that already bypasses.
	 * A schema author who means that declares the row in the schema itself.
	 */
	public const RESERVED_GROUPS = ['public', 'authenticated', 'admin'];

	/**
	 * Rows per schema and provider, for the rest of the request.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $cache = [];

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher|null     $dispatcher The dispatcher; no rows without one.
	 * @param DepartmentMatrixValidator $validator  Reads each row as the compiler will.
	 * @param LoggerInterface           $logger     Names rows that do not read.
	 */
	public function __construct(
		private readonly ?IEventDispatcher $dispatcher,
		private readonly DepartmentMatrixValidator $validator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The matrix with the provider's rows added beside its declared rows.
	 *
	 * A matrix without `rowsFrom` comes back unchanged.
	 *
	 * @param Schema               $schema The schema whose matrix this is.
	 * @param array<string, mixed> $matrix The declared matrix.
	 *
	 * @return array<string, mixed> The matrix to compile.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function withAppRows(Schema $schema, array $matrix): array {
		$provider = ($matrix[DepartmentMatrixValidator::ROWS_FROM_KEY] ?? null);
		if (is_string($provider) === false || trim($provider) === '') {
			return $matrix;
		}

		$declared = ($matrix['rows'] ?? []);
		if (is_array($declared) === false) {
			$declared = [];
		}

		$matrix['rows'] = array_merge(
			array_values($declared),
			$this->rowsFor(run: new AppRowsRun(schema: $schema, provider: trim($provider), field: trim((string)($matrix['field'] ?? ''))))
		);

		return $matrix;
	}//end withAppRows()

	/**
	 * The rows one provider contributes for one schema, read once per request.
	 *
	 * @param AppRowsRun $run The schema, the provider id and the matrix field.
	 *
	 * @return array<int, array<string, mixed>> The rows that read.
	 */
	private function rowsFor(AppRowsRun $run): array {
		$key = (string)$run->schema->getId() . '|' . $run->provider;
		if (array_key_exists($key, $this->cache) === false) {
			$this->cache[$key] = $this->readable(rows: $this->ask(schema: $run->schema, provider: $run->provider), run: $run);
		}

		return $this->cache[$key];
	}//end rowsFor()

	/**
	 * Dispatch the request; any failure is no rows. Only rows added by the app
	 * that owns the provider id are kept.
	 *
	 * @param Schema $schema   The schema.
	 * @param string $provider The provider id.
	 *
	 * @return array<int, array<string, mixed>> The rows the owning app added.
	 */
	private function ask(Schema $schema, string $provider): array {
		if ($this->dispatcher === null) {
			return [];
		}

		$event = new MatrixRowsRequestedEvent(schema: $schema, provider: $provider);
		if ($this->dispatched(event: $event, provider: $provider) === false) {
			return [];
		}

		return $this->ownedRows(event: $event, provider: $provider);
	}//end ask()

	/**
	 * Dispatch the request; a failing listener is logged and means no rows.
	 *
	 * @param MatrixRowsRequestedEvent $event    The request.
	 * @param string                   $provider The provider id, for the log.
	 *
	 * @return bool False when the dispatch failed.
	 */
	private function dispatched(MatrixRowsRequestedEvent $event, string $provider): bool {
		try {
			$this->dispatcher?->dispatchTyped($event);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[MatrixRowsFromApps] A matrix row provider failed; it contributes no rows',
				context: ['file' => __FILE__, 'line' => __LINE__, 'provider' => $provider, 'error' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end dispatched()

	/**
	 * The rows added by the app that owns the provider id; every other row is dropped and logged.
	 *
	 * @param MatrixRowsRequestedEvent $event    The answered request.
	 * @param string                   $provider The provider id.
	 *
	 * @return array<int, array<string, mixed>> The rows the owning app added.
	 */
	private function ownedRows(MatrixRowsRequestedEvent $event, string $provider): array {
		$owner = self::owningApp(provider: $provider);
		$kept  = [];
		foreach ($event->getRows() as $index => $row) {
			$appId = $event->answeredBy(index: $index);
			if ($appId === '' || $appId !== $owner) {
				$this->logForeignRow(provider: $provider, appId: $appId);
				continue;
			}

			$kept[] = $row;
		}

		return $kept;
	}//end ownedRows()

	/**
	 * Say that a provider row from an app that does not own the provider was dropped.
	 *
	 * @param string $provider The provider id.
	 * @param string $appId    The app that answered, '' when unknown.
	 *
	 * @return void
	 */
	private function logForeignRow(string $provider, string $appId): void {
		$this->logger->warning(
			message: '[MatrixRowsFromApps] A provider row from an app that does not own the provider was dropped',
			context: ['provider' => $provider, 'answeredBy' => $appId]
		);
	}//end logForeignRow()

	/**
	 * The app that owns a provider id: the part before the first dot (`dossiq`
	 * in `dossiq.case-type-rights`), or the whole id when it has none.
	 *
	 * @param string $provider The provider id.
	 *
	 * @return string The app id.
	 */
	private static function owningApp(string $provider): string {
		$dot = strpos($provider, '.');
		if ($dot === false) {
			return $provider;
		}

		return substr($provider, 0, $dot);
	}//end owningApp()

	/**
	 * The rows that read, each one that does not logged with its findings.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 * @param AppRowsRun                       $run  The schema (properties, declared actions), the matrix field and the provider id, for the log.
	 *
	 * @return array<int, array<string, mixed>> The rows that read.
	 */
	private function readable(array $rows, AppRowsRun $run): array {
		$shape = new MatrixShape(properties: ($run->schema->getProperties() ?? []), field: $run->field);
		$validator = $this->validator->withDeclaredActions(declaredActions: $run->schema->declaredActionNames());
		$kept = [];
		foreach ($rows as $row) {
			if ($this->grantsAReservedGroup(row: $row, run: $run) === true) {
				continue;
			}

			$findings = $validator->findingsForRow(row: $row, shape: $shape);
			if ($findings !== []) {
				$this->logger->warning(
					message: '[MatrixRowsFromApps] A provider row does not read and was dropped',
					context: ['provider' => $run->provider, 'findings' => array_column($findings, 'message')]
				);
				continue;
			}

			$kept[] = $row;
		}

		return $kept;
	}//end readable()

	/**
	 * Whether a provider row grants a reserved group; such a row is logged and dropped.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param AppRowsRun           $run The request, for the provider id in the log.
	 *
	 * @return bool True when the row names a reserved group.
	 */
	private function grantsAReservedGroup(array $row, AppRowsRun $run): bool {
		$group = '';
		if (is_string($row['group'] ?? null) === true) {
			$group = strtolower(trim($row['group']));
		}

		if (in_array($group, self::RESERVED_GROUPS, true) === false) {
			return false;
		}

		$this->logger->warning(
			message: '[MatrixRowsFromApps] A provider row granting a reserved group was dropped',
			context: ['provider' => $run->provider, 'group' => $group]
		);

		return true;
	}//end grantsAReservedGroup()
}//end class

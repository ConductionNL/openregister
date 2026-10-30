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
 * @spec openspec/changes/rbac-matrix-app-rows-and-file-verbs/specs/rbac-scopes/spec.md
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
 * @spec openspec/changes/rbac-matrix-app-rows-and-file-verbs/specs/rbac-scopes/spec.md
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
	 * @spec openspec/changes/rbac-matrix-app-rows-and-file-verbs/specs/rbac-scopes/spec.md
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
			$this->rowsFor(schema: $schema, provider: trim($provider), field: trim((string)($matrix['field'] ?? '')))
		);

		return $matrix;
	}//end withAppRows()

	/**
	 * The rows one provider contributes for one schema, read once per request.
	 *
	 * @param Schema $schema   The schema.
	 * @param string $provider The provider id.
	 * @param string $field    The matrix field.
	 *
	 * @return array<int, array<string, mixed>> The rows that read.
	 */
	private function rowsFor(Schema $schema, string $provider, string $field): array {
		$key = (string)$schema->getId() . '|' . $provider;
		if (array_key_exists($key, $this->cache) === true) {
			return $this->cache[$key];
		}

		$this->cache[$key] = $this->readable(
			rows: $this->ask(schema: $schema, provider: $provider),
			properties: ($schema->getProperties() ?? []),
			field: $field,
			provider: $provider
		);

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
		try {
			$this->dispatcher->dispatchTyped($event);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[MatrixRowsFromApps] A matrix row provider failed; it contributes no rows',
				context: ['file' => __FILE__, 'line' => __LINE__, 'provider' => $provider, 'error' => $e->getMessage()]
			);
			return [];
		}

		$owner = self::owningApp(provider: $provider);
		$kept  = [];
		foreach ($event->getRows() as $index => $row) {
			$appId = $event->answeredBy(index: $index);
			if ($appId === '' || $appId !== $owner) {
				$this->logger->warning(
					message: '[MatrixRowsFromApps] A provider row from an app that does not own the provider was dropped',
					context: ['provider' => $provider, 'answeredBy' => $appId]
				);
				continue;
			}

			$kept[] = $row;
		}

		return $kept;
	}//end ask()

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
	 * @param array<int, array<string, mixed>> $rows       The rows.
	 * @param array<string, mixed>             $properties The schema's properties.
	 * @param string                           $field      The matrix field.
	 * @param string                           $provider   The provider id, for the log.
	 *
	 * @return array<int, array<string, mixed>> The rows that read.
	 */
	private function readable(array $rows, array $properties, string $field, string $provider): array {
		$kept = [];
		foreach ($rows as $row) {
			$group = '';
			if (is_string($row['group'] ?? null) === true) {
				$group = strtolower(trim($row['group']));
			}

			if (in_array($group, self::RESERVED_GROUPS, true) === true) {
				$this->logger->warning(
					message: '[MatrixRowsFromApps] A provider row granting a reserved group was dropped',
					context: ['provider' => $provider, 'group' => $group]
				);
				continue;
			}

			$findings = $this->validator->findingsForRow(row: $row, properties: $properties, field: $field);
			if ($findings !== []) {
				$this->logger->warning(
					message: '[MatrixRowsFromApps] A provider row does not read and was dropped',
					context: ['provider' => $provider, 'findings' => array_column($findings, 'message')]
				);
				continue;
			}

			$kept[] = $row;
		}

		return $kept;
	}//end readable()
}//end class

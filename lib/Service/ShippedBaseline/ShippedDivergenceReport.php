<?php

/**
 * Where an app-shipped schema differs from what its app shipped (REQ-LCA-002).
 *
 * Reads the stored baseline and the live schema and lists, part by part, what
 * the instance changed. Only the parts the baseline holds are compared: a
 * baseline recorded before the annotations part was guarded has none, and
 * comparing the live annotations against nothing would report every one of
 * them as a local change. An untouched schema reports an empty list.
 *
 * Who changed a part and when is not in the report: a schema is an entity and
 * its edits do not reach the object audit trail (task 2.2), and a `null` actor
 * would read as "nobody".
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ShippedBaseline
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

namespace OCA\OpenRegister\Service\ShippedBaseline;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\ConfigurationDeployment\ShippedDivergenceSourceInterface;
use Throwable;

/**
 * The divergence of one app-shipped schema from its baseline.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
class ShippedDivergenceReport implements ShippedDivergenceSourceInterface {

	/**
	 * The subject prefix of a schema.
	 *
	 * @var string
	 */
	private const SCHEMA_PREFIX = 'schema:';

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface $schemas    Where the live schema is read.
	 * @param ShippedBaselineStore  $baselines  Where the baseline is kept.
	 * @param DivergenceComparator  $comparator Lists the parts that differ.
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemas,
		private readonly ShippedBaselineStore $baselines,
		private readonly DivergenceComparator $comparator,
	) {
	}//end __construct()

	/**
	 * Where a schema subject differs from what its app shipped.
	 *
	 * @param string $subject The subject reference, `schema:<slug>`.
	 *
	 * @return array{schema: string, shipped: bool, found: bool, app: string|null, appVersion: string|null,
	 *     recordedAt: string|null, divergences: list<array<string, mixed>>}|null
	 *     The report, or null for a subject that is not a schema.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function forSubject(string $subject): ?array {
		if (str_starts_with($subject, self::SCHEMA_PREFIX) === false) {
			return null;
		}

		$slug = substr($subject, strlen(self::SCHEMA_PREFIX));
		$report = [
			'schema' => $slug,
			'shipped' => false,
			'found' => false,
			'app' => null,
			'appVersion' => null,
			'recordedAt' => null,
			'divergences' => [],
		];

		$baseline = $this->baselines->read(subject: $subject);
		if ($this->comparator->hasBaseline(baseline: ($baseline['definition'] ?? null)) === false) {
			return $report;
		}

		$report['shipped'] = true;
		$report['app'] = $baseline['app'];
		$report['appVersion'] = $baseline['appVersion'];
		$report['recordedAt'] = $baseline['recordedAt'];

		$schema = $this->schema(slug: $slug);
		if ($schema === null) {
			return $report;
		}

		$definition = $baseline['definition'];
		$report['found'] = true;
		$report['divergences'] = $this->comparator->report(
			baseline: $definition,
			live: array_intersect_key(LiveDescriptor::of(schema: $schema), $definition)
		);

		return $report;
	}//end forSubject()

	/**
	 * The live schema, or null when it is gone.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return Schema|null The schema.
	 */
	private function schema(string $slug): ?Schema {
		if ($slug === '') {
			return null;
		}

		try {
			return $this->schemas->find(id: $slug, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			return null;
		}
	}//end schema()
}//end class

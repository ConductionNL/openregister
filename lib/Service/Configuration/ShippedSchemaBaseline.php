<?php

/**
 * Keeps local changes to an app-shipped schema through an import.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ShippedBaseline\SchemaUpgrade;
use OCA\OpenRegister\Service\ShippedBaseline\SchemaUpgradeOptions;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedConfigurationGuard;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedDefinition;
use Psr\Log\LoggerInterface;

/**
 * Asks the shipped-baseline guard what an import may write, and records what shipped.
 *
 * A baseline is resolved first and recorded only after the write succeeds.
 * Recorded earlier, a write that then fails leaves a baseline the instance
 * never ran, and the next import reads every old live part as a local edit.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
final class ShippedSchemaBaseline
{

	/**
	 * The schema parts the guard protects.
	 *
	 * Annotations are not guarded: `setConfiguration()` drops an unknown
	 * `x-openregister-*` key, so a guarded annotation would read as removed on
	 * every import and conflict with itself forever.
	 *
	 * @var array<int, string>
	 */
	private const GUARDED_PARTS = ['properties', 'required', 'authorization'];

	/**
	 * The schema parts the guard kept local since the last report.
	 *
	 * @var array<int, array{slug: string, paths: array<int, string>}>
	 */
	private array $keptLocal = [];

	/**
	 * Build the baseline step.
	 *
	 * @param ShippedConfigurationGuard|null $guard  The guard, null when none is wired.
	 * @param LoggerInterface                $logger The logger.
	 */
	public function __construct(
		private readonly ?ShippedConfigurationGuard $guard,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve an incoming shipped schema against what the instance changed.
	 *
	 * Without a guard, an app or a slug the incoming definition comes back untouched.
	 *
	 * @param ImportedSchemaUpdate $update  The incoming definition and the stored schema.
	 * @param SchemaImportOptions  $options The caller's settings.
	 *
	 * @return PendingSchemaBaseline The definition to write and the baseline to record after it.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function resolve(ImportedSchemaUpdate $update, SchemaImportOptions $options): PendingSchemaBaseline
	{
		$result = $this->answer(update: $update, options: $options);
		if ($result === null) {
			return new PendingSchemaBaseline(data: $update->data, baseline: null, slug: '');
		}

		$pending = new PendingSchemaBaseline(data: $update->data, baseline: $result['baseline'], slug: $this->slugOf(update: $update));

		return $this->merged(pending: $pending, result: $result);
	}//end resolve()

	/**
	 * Record the baseline the guard resolved, now that the schema is written.
	 *
	 * @param PendingSchemaBaseline $pending What resolve() returned.
	 * @param SchemaImportOptions   $options The caller's settings.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function recordPending(PendingSchemaBaseline $pending, SchemaImportOptions $options): void
	{
		if ($pending->baseline === null) {
			return;
		}

		$this->guard?->recordShippedSchema(
			slug: $pending->slug,
			shipped: new ShippedDefinition(definition: $pending->baseline, app: (string) $options->appId, appVersion: ($options->version ?? ''))
		);
	}//end recordPending()

	/**
	 * Record what the app shipped for a schema that has just been created.
	 *
	 * @param array<string, mixed> $data    The schema definition.
	 * @param SchemaImportOptions  $options The caller's settings.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function recordCreated(array $data, SchemaImportOptions $options): void
	{
		$slug = (string) ($data['slug'] ?? '');
		if ($this->guard === null || $options->appId === null || $slug === '') {
			return;
		}

		$pending = new PendingSchemaBaseline(data: $data, baseline: $this->shippedParts(data: $data), slug: $slug);
		$this->recordPending(pending: $pending, options: $options);
	}//end recordCreated()

	/**
	 * The schema parts the guard kept local since the last call, and a fresh list.
	 *
	 * @return array<int, array{slug: string, paths: array<int, string>}> One entry per schema.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function takeKeptLocal(): array
	{
		$report = $this->keptLocal;
		$this->keptLocal = [];

		return $report;
	}//end takeKeptLocal()

	/**
	 * The guard's answer for an update, null when there is nothing to ask.
	 *
	 * @param ImportedSchemaUpdate $update  The incoming definition and the stored schema.
	 * @param SchemaImportOptions  $options The caller's settings.
	 *
	 * @return array<string, mixed>|null
	 */
	private function answer(ImportedSchemaUpdate $update, SchemaImportOptions $options): ?array
	{
		$slug = $this->slugOf(update: $update);
		if ($options->appId === null || $slug === '') {
			return null;
		}

		return $this->guard?->guardUpgrade(
			upgrade: new SchemaUpgrade(
				slug: $slug,
				live: $this->liveParts(schema: $update->existing),
				incoming: new ShippedDefinition(definition: $this->shippedParts(data: $update->data), app: $options->appId, appVersion: ($options->version ?? ''))
			),
			options: new SchemaUpgradeOptions(record: false)
		);
	}//end answer()

	/**
	 * The slug of an update, empty when neither side has one.
	 *
	 * @param ImportedSchemaUpdate $update The incoming definition and the stored schema.
	 *
	 * @return string
	 */
	private function slugOf(ImportedSchemaUpdate $update): string
	{
		return (string) ($update->data['slug'] ?? $update->existing->getSlug() ?? '');
	}//end slugOf()

	/**
	 * The guarded parts of the stored schema.
	 *
	 * @param Schema $schema The stored schema.
	 *
	 * @return array<string, mixed>
	 */
	private function liveParts(Schema $schema): array
	{
		return [
			'properties'    => $schema->getProperties(),
			'required'      => $schema->getRequired(),
			'authorization' => ($schema->getAuthorization() ?? []),
		];
	}//end liveParts()

	/**
	 * The guarded parts a definition carries.
	 *
	 * @param array<string, mixed> $data The definition.
	 *
	 * @return array<string, mixed>
	 */
	private function shippedParts(array $data): array
	{
		$parts = [];
		foreach (self::GUARDED_PARTS as $part) {
			if (array_key_exists($part, $data) === true) {
				$parts[$part] = $data[$part];
			}
		}

		return $parts;
	}//end shippedParts()

	/**
	 * The pending baseline with the guard's answer on its guarded parts.
	 *
	 * @param PendingSchemaBaseline $pending The incoming definition and baseline.
	 * @param array<string, mixed>  $result  The guard's answer.
	 *
	 * @return PendingSchemaBaseline
	 */
	private function merged(PendingSchemaBaseline $pending, array $result): PendingSchemaBaseline
	{
		if ($result['guarded'] === false) {
			return $pending;
		}

		$this->noteKeptLocal(slug: $pending->slug, conflicts: $result['conflicts']);
		$data = array_replace($pending->data, $this->shippedParts(data: $result['definition']));

		return new PendingSchemaBaseline(data: $data, baseline: $pending->baseline, slug: $pending->slug);
	}//end merged()

	/**
	 * Report the parts that stayed local although the app changed them too.
	 *
	 * @param string                           $slug      The schema slug.
	 * @param array<int, array<string, mixed>> $conflicts The guard's conflicts.
	 *
	 * @return void
	 */
	private function noteKeptLocal(string $slug, array $conflicts): void
	{
		if ($conflicts === []) {
			return;
		}

		$paths = array_column($conflicts, 'path');
		$this->keptLocal[] = ['slug' => $slug, 'paths' => $paths];
		$this->logger->warning(
			message: ImportLogTag::PREFIX.'schema kept its local definition for parts the app also changed',
			context: ['file' => __FILE__, 'line' => __LINE__, 'schema_slug' => $slug, 'conflicts' => $paths]
		);
	}//end noteKeptLocal()
}//end class

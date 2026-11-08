<?php

/**
 * One register re-import, run under an administrator's decision and counted.
 *
 * The counts come from the database, before and after: an import's own
 * return value differs per app and says what it processed, not what changed.
 * Every authorization block the decision replaced over a local change is
 * counted and listed.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedAuthorizationDecision;

/**
 * Runs a re-import under the decision and counts registers and schemas.
 *
 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
 */
class CountedRegisterImport {

	/**
	 * Constructor.
	 *
	 * @param RegisterMapperInterface               $registerMapper Reads the registers.
	 * @param SchemaMapperInterface                 $schemaMapper   Reads the schemas a re-import touched.
	 * @param ShippedAuthorizationDecision $decision       The administrator's decision, shared with the guard.
	 */
	public function __construct(
		private readonly RegisterMapperInterface $registerMapper,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly ShippedAuthorizationDecision $decision,
	) {
	}//end __construct()

	/**
	 * Run an import under the administrator's decision, and count what it changed.
	 *
	 * @param string             $slug      The register slug the caller named.
	 * @param array<int, string> $slugs     Every register slug the imported document declares.
	 * @param string             $version   The version of the imported document.
	 * @param int                $fragments How many register.d fragments it carries.
	 * @param string             $via       Who asked, for the audit trail.
	 * @param callable           $import    Runs the import; returns success, path, via, message, and importJobId and
	 *                                      importAppId when it recorded an import job.
	 *
	 * @return array<string, mixed> The reimport() result.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function run(
		string $slug,
		array $slugs,
		string $version,
		int $fragments,
		string $via,
		callable $import
	): array {
		$document = (new RegisterImportDocument(slug: $slug, slugs: $slugs))
			->release(version: $version, fragments: $fragments);

		return $this->counted(
			document: $document,
			import: fn (): mixed => $this->decision->run(via: $via, operation: $import)
		);
	}//end run()

	/**
	 * Snapshot, import, snapshot again, and report the counts and the outcome.
	 *
	 * @param RegisterImportDocument $document The imported document.
	 * @param \Closure               $import   Runs the import under the decision; returns its result array.
	 *
	 * @return array<string, mixed> The reimport() result.
	 */
	private function counted(RegisterImportDocument $document, \Closure $import): array {
		$before   = $this->snapshot(slugs: $document->slugs);
		$run      = $import();
		$replaced = $this->decision->replaced();
		$after    = $this->snapshot(slugs: $document->slugs);

		$result = [
			'outcome'   => 'imported',
			'reason'    => null,
			'path'      => $run['path'],
			'via'       => $run['via'],
			'version'   => $document->version(),
			'fragments' => $document->fragments(),
			'counts'    => $this->counts(before: $before, after: $after) + ['authorizationReplaced' => count($replaced)],
			'replaced'  => $replaced,
		];

		$result = $this->withImportJob(result: $result, run: $run);
		$reason = $this->failureReason(
			run: $run,
			missingSlug: $this->missingSlug(document: $document, registers: $after['registers'])
		);
		if ($reason !== null) {
			$result['outcome'] = 'failed';
			$result['reason']  = $reason;
		}

		return $result;
	}//end counted()

	/**
	 * The register and schema counts between two snapshots.
	 *
	 * @param array{registers: array<string, string>, schemas: array<string, string>} $before Before the import.
	 * @param array{registers: array<string, string>, schemas: array<string, string>} $after  After the import.
	 *
	 * @return array{registers: array{created: int, updated: int, unchanged: int}, schemas: array{created: int, updated: int, unchanged: int}}
	 */
	private function counts(array $before, array $after): array {
		return [
			'registers' => $this->compare(before: $before['registers'], after: $after['registers']),
			'schemas'   => $this->compare(before: $before['schemas'], after: $after['schemas']),
		];
	}//end counts()

	/**
	 * The result with the job the import ran under, when it recorded one.
	 *
	 * So a caller that must undo exactly this import (the example-set loader) is told which one it was and does not
	 * have to guess it from a before/after diff of every job on the instance.
	 *
	 * @param array<string, mixed> $result The result so far.
	 * @param mixed                $run    The import's own result.
	 *
	 * @return array<string, mixed>
	 */
	private function withImportJob(array $result, mixed $run): array {
		if (is_string($run['importJobId'] ?? null) === true) {
			$result['importJobId'] = $run['importJobId'];
			$result['importAppId'] = (string) ($run['importAppId'] ?? '');
		}

		return $result;
	}//end withImportJob()

	/**
	 * Why the import failed: it said so, or it did not create the register asked for; null when it did not fail.
	 *
	 * @param mixed       $run         The import's own result.
	 * @param string|null $missingSlug The register slug asked for when it does not exist afterwards, else null.
	 *
	 * @return string|null
	 */
	private function failureReason(mixed $run, ?string $missingSlug): ?string {
		if ($run['success'] === false) {
			return sprintf('The import through %s failed: %s', $run['via'], $run['message']);
		}

		if ($missingSlug !== null) {
			return sprintf('The import reported no error but register "%s" still does not exist.', $missingSlug);
		}

		return null;
	}//end failureReason()

	/**
	 * The register slug the caller named when no register with it exists after the import, else null.
	 *
	 * @param RegisterImportDocument $document  The imported document.
	 * @param array<string, string>  $registers Lowercased slug to fingerprint, after the import.
	 *
	 * @return string|null
	 */
	private function missingSlug(RegisterImportDocument $document, array $registers): ?string {
		if (array_key_exists(strtolower($document->slug), $registers) === true) {
			return null;
		}

		return $document->slug;
	}//end missingSlug()

	/**
	 * A fingerprint of every register with one of the slugs, and of their schemas.
	 *
	 * @param array<int, string> $slugs Lowercased register slugs.
	 *
	 * @return array{registers: array<string, string>, schemas: array<string, string>} Key to fingerprint.
	 */
	private function snapshot(array $slugs): array {
		$registers = [];
		$schemaIds = [];
		foreach ($this->registerMapper->findAll(_rbac: false, _multitenancy: false) as $register) {
			$slug = strtolower((string)$register->getSlug());
			if (in_array($slug, $slugs, true) === false) {
				continue;
			}

			$registers[$slug] = $this->registerFingerprint(register: $register);
			$schemaIds        = array_replace($schemaIds, $this->schemaIdsOf(register: $register));
		}

		return ['registers' => $registers, 'schemas' => $this->schemaFingerprints(schemaIds: $schemaIds)];
	}//end snapshot()

	/**
	 * The scalar schema ids of a register, keyed by their string form.
	 *
	 * @param Register $register The register.
	 *
	 * @return array<string, int|float|string|bool>
	 */
	private function schemaIdsOf(Register $register): array {
		$schemaIds = [];
		foreach ($register->getSchemas() as $schemaId) {
			if (is_scalar($schemaId) === true) {
				$schemaIds[(string)$schemaId] = $schemaId;
			}
		}

		return $schemaIds;
	}//end schemaIdsOf()

	/**
	 * A fingerprint of each schema with one of the ids.
	 *
	 * @param array<string, int|float|string|bool> $schemaIds Schema ids, keyed by their string form.
	 *
	 * @return array<string, string> "schema:{id}" to fingerprint.
	 */
	private function schemaFingerprints(array $schemaIds): array {
		$schemas = [];
		if ($schemaIds === []) {
			return $schemas;
		}

		$found = $this->schemaMapper->findMultiple(ids: array_values($schemaIds), _rbac: false, _multitenancy: false);
		foreach ($found as $schema) {
			// Keyed by a string, never a bare id PHP would turn into an int key.
			$schemas['schema:' . (string)$schema->getId()] = $this->schemaFingerprint(schema: $schema);
		}

		return $schemas;
	}//end schemaFingerprints()

	/**
	 * What of a register an import writes.
	 *
	 * @param Register $register The register.
	 *
	 * @return string The fingerprint.
	 */
	private function registerFingerprint(Register $register): string {
		$schemas = array_map('strval', array_filter($register->getSchemas(), 'is_scalar'));
		sort($schemas);

		return md5(
			(string)json_encode(
				[$register->getVersion(), $register->getTitle(), $register->getDescription(), $schemas]
			)
		);
	}//end registerFingerprint()

	/**
	 * What of a schema an import writes.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return string The fingerprint.
	 */
	private function schemaFingerprint(Schema $schema): string {
		return md5(
			(string)json_encode(
				[
					$schema->getVersion(),
					$schema->getTitle(),
					$schema->getDescription(),
					$schema->getProperties(),
					$schema->getRequired(),
					$schema->getAuthorization(),
					$schema->getConfiguration(),
				]
			)
		);
	}//end schemaFingerprint()

	/**
	 * How many entries were created, updated and left unchanged.
	 *
	 * @param array<string, string> $before Key to fingerprint, before.
	 * @param array<string, string> $after  Key to fingerprint, after.
	 *
	 * @return array{created: int, updated: int, unchanged: int}
	 */
	private function compare(array $before, array $after): array {
		$counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
		foreach ($after as $key => $fingerprint) {
			if (array_key_exists($key, $before) === false) {
				$counts['created']++;
				continue;
			}

			if ($before[$key] === $fingerprint) {
				$counts['unchanged']++;
				continue;
			}

			$counts['updated']++;
		}

		return $counts;
	}//end compare()
}//end class

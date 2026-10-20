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
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
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
	 * @param RegisterMapper               $registerMapper Reads the registers.
	 * @param SchemaMapper                 $schemaMapper   Reads the schemas a re-import touched.
	 * @param ShippedAuthorizationDecision $decision       The administrator's decision, shared with the guard.
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
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
		$before = $this->snapshot(slugs: $slugs);
		$run    = $this->decision->run(via: $via, operation: $import);
		$replaced = $this->decision->replaced();
		$after    = $this->snapshot(slugs: $slugs);

		$result = [
			'outcome'   => 'imported',
			'reason'    => null,
			'path'      => $run['path'],
			'via'       => $run['via'],
			'version'   => $version,
			'fragments' => $fragments,
			'counts'    => [
				'registers'             => $this->compare(before: $before['registers'], after: $after['registers']),
				'schemas'               => $this->compare(before: $before['schemas'], after: $after['schemas']),
				'authorizationReplaced' => count($replaced),
			],
			'replaced'  => $replaced,
		];

		// The job the import ran under, so a caller that must undo exactly this import (the example-set loader) is
		// told which one it was and does not have to guess it from a before/after diff of every job on the instance.
		if (is_string($run['importJobId'] ?? null) === true) {
			$result['importJobId'] = $run['importJobId'];
			$result['importAppId'] = (string) ($run['importAppId'] ?? '');
		}

		if ($run['success'] === false) {
			$result['outcome'] = 'failed';
			$result['reason']  = sprintf('The import through %s failed: %s', $run['via'], $run['message']);
			return $result;
		}

		if (array_key_exists(strtolower($slug), $after['registers']) === false) {
			$result['outcome'] = 'failed';
			$result['reason']  = sprintf('The import reported no error but register "%s" still does not exist.', $slug);
		}

		return $result;
	}//end run()

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
			foreach ($register->getSchemas() as $schemaId) {
				if (is_scalar($schemaId) === true) {
					$schemaIds[(string)$schemaId] = $schemaId;
				}
			}
		}

		$schemas = [];
		if ($schemaIds !== []) {
			$found = $this->schemaMapper->findMultiple(ids: array_values($schemaIds), _rbac: false, _multitenancy: false);
			foreach ($found as $schema) {
				// Keyed by a string, never a bare id PHP would turn into an int key.
				$schemas['schema:' . (string)$schema->getId()] = $this->schemaFingerprint(schema: $schema);
			}
		}

		return ['registers' => $registers, 'schemas' => $schemas];
	}//end snapshot()

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

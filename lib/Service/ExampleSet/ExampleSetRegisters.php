<?php

/**
 * OpenRegister Example Set Registers
 *
 * The OpenRegister registers an example set needs, named in the cast file's
 * `requires` list. A required register that is not on the instance is
 * imported from OpenRegister's own descriptor, the same way
 * `POST /api/register-descriptors/openregister/{slug}/import` does. The
 * import jobs that import created are returned, so the set can remove exactly
 * those objects again and leave an import somebody else ran alone.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCA\OpenRegister\Kernel\Contracts\AuditTrailMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\RegisterMapperInterface;
use OCA\OpenRegister\Service\Configuration\AppImportJobRecorder;
use OCA\OpenRegister\Service\RegisterDescriptorService;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Imports and removes the registers a set requires.
 *
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
 */
class ExampleSetRegisters {
	/**
	 * The app whose descriptors a set may require.
	 */
	private const APP = 'openregister';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out the descriptor, import and register services on first use.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Whether a register with this slug is on the instance.
	 *
	 * @param string $slug The register slug.
	 *
	 * @return bool
	 *
	 * @phpstan-impure It reads the instance, which an import changes.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
	 */
	public function exists(string $slug): bool {
		try {
			$this->container->get(RegisterMapperInterface::class)->find(id: $slug, _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			return false;
		}

		return true;
	}//end exists()

	/**
	 * Import a register from OpenRegister's own descriptor.
	 *
	 * The import job is the one the import itself reports. It is not worked out
	 * from the jobs the instance holds before and after: a job another process
	 * records in that window (a repair step during an upgrade, an
	 * administrator's re-import) would be taken for the set's, and remove would
	 * soft-delete its objects.
	 *
	 * @param string $slug The register slug.
	 *
	 * @return array{error: string, jobs: array<int, array{appId: string, jobId: string}>} Why it failed, or ''; and
	 *                                                                                     the import job it ran under.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
	 */
	public function import(string $slug): array {
		return $this->recordedImport(
			import: fn (RegisterDescriptorService $descriptors): array => $descriptors->reimport(
				appId: self::APP,
				slug: $slug,
				via: 'example set install'
			)
		);
	}//end import()

	/**
	 * Import a register from OpenRegister's mock (sample) descriptor that declares the slug.
	 *
	 * @param string $slug The register slug.
	 *
	 * @return array{error: string, jobs: array<int, array{appId: string, jobId: string}>} Why it failed, or ''; and
	 *                                                                                     the import job it ran under.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
	 */
	public function importMock(string $slug): array {
		return $this->recordedImport(
			import: fn (RegisterDescriptorService $descriptors): array => $descriptors->reimportMock(appId: self::APP, slug: $slug)
		);
	}//end importMock()

	/**
	 * Whether the instance holds an older version of the register than
	 * OpenRegister's descriptor ships, so the set imports it again. A
	 * descriptor that cannot be read counts as not behind.
	 *
	 * @param string $slug The register slug.
	 * @param bool   $mock True when the set requires the mock descriptor.
	 *
	 * @return bool
	 *
	 * @phpstan-impure It reads the instance, which an import changes.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
	 */
	public function isBehind(string $slug, bool $mock): bool {
		try {
			return $this->container->get(RegisterDescriptorService::class)->isBehind(appId: self::APP, slug: $slug, mock: $mock);
		} catch (Throwable) {
			return false;
		}
	}//end isBehind()

	/**
	 * Run one descriptor import and return why it failed and the import job it reports.
	 *
	 * @param callable(RegisterDescriptorService): array<string, mixed> $import The import.
	 *
	 * @return array{error: string, jobs: array<int, array{appId: string, jobId: string}>}
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
	 */
	private function recordedImport(callable $import): array {
		try {
			$result = $import($this->container->get(RegisterDescriptorService::class));
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'jobs' => []];
		}

		$jobs = [];
		if (is_string($result['importJobId'] ?? null) === true && $result['importJobId'] !== '') {
			$jobs[] = ['appId' => (string) ($result['importAppId'] ?? self::APP), 'jobId' => $result['importJobId']];
		}

		$error = '';
		if (($result['outcome'] ?? '') === 'failed') {
			$error = (string) ($result['reason'] ?? 'the import failed');
		}

		return ['error' => $error, 'jobs' => $jobs];
	}//end recordedImport()

	/**
	 * Soft-delete the objects one import job created, and forget the job when that went cleanly.
	 *
	 * Deleted without access rules, like every other delete of the loader: the
	 * caller is `occ`, so there is no user whose rights could be asked, and no
	 * elevation to a system principal is needed for that.
	 *
	 * @param string $appId The configuration id the import ran under.
	 * @param string $jobId The import job id.
	 *
	 * @return bool True when nothing of the job is left.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
	 */
	public function removeImport(string $appId, string $jobId): bool {
		try {
			$uuids   = $this->container->get(AuditTrailMapperInterface::class)->objectUuidsByImportJobId(importJobId: $jobId);
			$objects = $this->container->get(ObjectService::class);
		} catch (Throwable) {
			return false;
		}

		$clean = true;
		foreach ($uuids as $uuid) {
			try {
				$objects->deleteObject(uuid: $uuid, _rbac: false, _multitenancy: false);
			} catch (Throwable) {
				$clean = false;
			}
		}

		if ($clean === true) {
			$this->container->get(AppImportJobRecorder::class)->forget(appId: $appId, importJobId: $jobId);
		}

		return $clean;
	}//end removeImport()
}//end class

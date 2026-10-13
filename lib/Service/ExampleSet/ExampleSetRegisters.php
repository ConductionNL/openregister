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

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\Configuration\AppImportJobRecorder;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\RegisterDescriptorService;
use OCA\OpenRegister\Service\SystemOperationContext;
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
	 * The configuration ids OpenRegister's descriptor imports run under: its
	 * own registers, and its mock registers, which import under their own id.
	 */
	private const IMPORT_IDS = ['openregister', 'openregister.mock'];

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
			$this->container->get(RegisterMapper::class)->find(id: $slug, _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			return false;
		}

		return true;
	}//end exists()

	/**
	 * Import a register from OpenRegister's own descriptor.
	 *
	 * @param string $slug The register slug.
	 *
	 * @return array{error: string, jobs: array<int, array{appId: string, jobId: string}>} Why it failed, or ''; and
	 *                                                                                     the import jobs it created.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
	 */
	public function import(string $slug): array {
		try {
			$before = $this->jobIds();
			$result = $this->container->get(RegisterDescriptorService::class)->reimport(appId: self::APP, slug: $slug);
			$after  = $this->jobIds();
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'jobs' => []];
		}

		$jobs = [];
		foreach ($after as $key => $job) {
			if (isset($before[$key]) === false) {
				$jobs[] = $job;
			}
		}

		$error = '';
		if (($result['outcome'] ?? '') === 'failed') {
			$error = (string) ($result['reason'] ?? 'the import failed');
		}

		return ['error' => $error, 'jobs' => $jobs];
	}//end import()

	/**
	 * Soft-delete the objects one import job created, and forget the job when that went cleanly.
	 *
	 * @param string $appId The configuration id the import ran under.
	 * @param string $jobId The import job id.
	 *
	 * @return bool True when nothing of the job is left.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SystemOperationContext::run is the scoped elevation the import itself ran under.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
	 */
	public function removeImport(string $appId, string $jobId): bool {
		try {
			$report = SystemOperationContext::run(
				fn (): array => $this->container->get(ImportService::class)->softDeleteByImportJobId(importJobId: $jobId)
			);
			if ($report['errors'] !== []) {
				return false;
			}

			$this->container->get(AppImportJobRecorder::class)->forget(appId: $appId, importJobId: $jobId);
		} catch (Throwable) {
			return false;
		}

		return true;
	}//end removeImport()

	/**
	 * Every recorded import job of OpenRegister's own descriptors.
	 *
	 * @return array<string, array{appId: string, jobId: string}> Keyed by `appId/jobId`.
	 */
	private function jobIds(): array {
		$recorder = $this->container->get(AppImportJobRecorder::class);
		$jobs     = [];
		foreach (self::IMPORT_IDS as $appId) {
			foreach ($recorder->jobs(appId: $appId) as $job) {
				$jobs[$appId.'/'.$job['jobId']] = ['appId' => $appId, 'jobId' => (string) $job['jobId']];
			}
		}

		return $jobs;
	}//end jobIds()
}//end class

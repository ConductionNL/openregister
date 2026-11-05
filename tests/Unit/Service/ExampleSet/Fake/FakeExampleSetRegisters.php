<?php

/**
 * In-memory register imports for the example-set tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet\Fake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet\Fake;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetRegisters;

/**
 * Registers present on the instance, descriptors that can be imported, and the import jobs.
 */
class FakeExampleSetRegisters extends ExampleSetRegisters {
	/** @var array<string, bool> Register slugs on the instance. */
	public array $present = [];

	/** @var array<string, bool> Register slugs OpenRegister ships a descriptor for. */
	public array $importable = [];

	/** @var array<string, bool> Register slugs OpenRegister ships a mock descriptor for. */
	public array $mockImportable = [];

	/** @var array<string, string> Live import job ids, mapped to their register. */
	public array $jobs = [];

	/** @var int Number of imports. */
	public int $imports = 0;

	/** @var int Next job number. */
	private int $next = 1;

	/**
	 * Slugs whose descriptor ships a newer version than the instance holds.
	 *
	 * @var array<string, true>
	 */
	public array $behind = [];

	/**
	 * Whether the slug is in `behind`.
	 *
	 * @param string $slug The register slug.
	 * @param bool   $mock Whether the mock descriptor is meant.
	 *
	 * @return bool
	 */
	public function isBehind(string $slug, bool $mock): bool {
		return isset($this->behind[$slug]);
	}

	/**
	 * No container.
	 */
	public function __construct() {
	}

	public function exists(string $slug): bool {
		return isset($this->present[$slug]);
	}

	public function importMock(string $slug): array {
		if (isset($this->mockImportable[$slug]) === false) {
			$this->imports++;
			return ['error' => 'openregister ships no mock descriptor declaring register "'.$slug.'".', 'jobs' => []];
		}

		$this->importable[$slug] = true;

		return $this->import(slug: $slug);
	}

	public function import(string $slug): array {
		$this->imports++;
		if (isset($this->importable[$slug]) === false) {
			return ['error' => 'openregister ships no descriptor declaring register "'.$slug.'".', 'jobs' => []];
		}

		$this->present[$slug] = true;
		$jobId                = 'job-'.$this->next++;
		$this->jobs[$jobId]   = $slug;

		return ['error' => '', 'jobs' => [['appId' => 'openregister.mock', 'jobId' => $jobId]]];
	}

	public function removeImport(string $appId, string $jobId): bool {
		unset($this->jobs[$jobId]);

		return true;
	}
}

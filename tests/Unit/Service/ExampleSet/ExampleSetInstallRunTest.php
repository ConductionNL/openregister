<?php

/**
 * Unit tests for ExampleSetInstallRun — the state of one example-set install.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\ExampleSet\ExampleSetCheckRun;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetInstallRun;
use PHPUnit\Framework\TestCase;

final class ExampleSetInstallRunTest extends TestCase {

	public function testTheSetIsReadFromThePlanAsText(): void {
		$this->assertSame('demo', (new ExampleSetInstallRun(['set' => 'demo'], []))->set());
		$this->assertSame('12', (new ExampleSetInstallRun(['set' => 12], []))->set());
	}

	public function testRefreshIsOnlyAnExplicitTrue(): void {
		$this->assertTrue((new ExampleSetInstallRun(['set' => 's', 'refresh' => true], []))->refresh());
		$this->assertFalse((new ExampleSetInstallRun(['set' => 's', 'refresh' => 1], []))->refresh());
		$this->assertFalse((new ExampleSetInstallRun(['set' => 's', 'refresh' => 'yes'], []))->refresh());
		$this->assertFalse((new ExampleSetInstallRun(['set' => 's'], []))->refresh());
	}

	public function testTheObjectContextPutsTheRunsObjectsAndFilesOverTheBase(): void {
		$run          = new ExampleSetInstallRun(['set' => 's'], []);
		$run->base    = ['objects' => ['stale'], 'files' => ['stale'], 'users' => ['ann' => 'u1']];
		$run->objects = ['case' => 'uuid-1'];
		$run->files   = ['doc' => 7];

		$this->assertSame(
			['objects' => ['case' => 'uuid-1'], 'files' => ['doc' => 7], 'users' => ['ann' => 'u1']],
			$run->objectContext()
		);
	}

	public function testTheRecordStaysWritable(): void {
		$run = new ExampleSetInstallRun(['set' => 's'], ['made' => []]);
		$run->record['made'][] = 'x';

		$this->assertSame(['made' => ['x']], $run->record);
	}

	public function testACheckRunBuildsTheSameObjectContext(): void {
		$run          = new ExampleSetCheckRun(['set' => 's'], []);
		$run->base    = ['objects' => ['stale'], 'groups' => ['g']];
		$run->objects = ['a' => 'b'];

		$this->assertSame(['objects' => ['a' => 'b'], 'files' => [], 'groups' => ['g']], $run->objectContext());
	}
}

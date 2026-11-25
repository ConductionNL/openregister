<?php

/**
 * The library an evaluation point resolves `$condition` against is the
 * schema's, carried beside the lifecycle annotation.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use OCA\OpenRegister\Service\Rules\NamedConditionScope;
use PHPUnit\Framework\TestCase;

class NamedConditionScopeTest extends TestCase {

	public function testTheSchemasLibraryIsAttachedAndReadBack(): void {
		$annotation = NamedConditionScope::withLibrary(
			['field' => 'status'],
			[NamedConditionScope::KEY => ['groot' => ['description' => 'd', 'expression' => ['==' => [1, 1]]]]]
		);

		$this->assertSame('status', $annotation['field']);
		$this->assertSame(['==' => [1, 1]], NamedConditionScope::libraryOf($annotation)['groot']['expression']);
	}

	public function testAnAnnotationsOwnLibraryIsReplacedByTheSchemas(): void {
		$annotation = NamedConditionScope::withLibrary(
			['field' => 'status', NamedConditionScope::KEY => ['eigen' => ['expression' => true]]],
			[]
		);

		$this->assertSame([], NamedConditionScope::libraryOf($annotation));
	}

	public function testNoOrAMalformedLibraryReadsAsEmpty(): void {
		$this->assertSame([], NamedConditionScope::libraryFrom(null));
		$this->assertSame([], NamedConditionScope::libraryFrom([NamedConditionScope::KEY => 'not a map']));
		$this->assertSame([], NamedConditionScope::libraryOf(['field' => 'status']));
		$this->assertSame([], NamedConditionScope::libraryOf([NamedConditionScope::KEY => 'x']));
	}
}

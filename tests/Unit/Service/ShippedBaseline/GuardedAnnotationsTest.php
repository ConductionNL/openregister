<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ShippedBaseline;

use OCA\OpenRegister\Service\ShippedBaseline\GuardedAnnotations;
use PHPUnit\Framework\TestCase;

/**
 * The guarded annotations: vocabulary keys only, nested form wins, maintained keys untouched.
 *
 * @covers \OCA\OpenRegister\Service\ShippedBaseline\GuardedAnnotations
 */
final class GuardedAnnotationsTest extends TestCase {

	public function testOnlyVocabularyAnnotationsAreReadFromTheStoredConfiguration(): void {
		$stored = [
			'objectNameField' => 'title',
			'x-openregister-quota' => ['perOrganisation' => 3],
			'x-openregister-lifecycle-exception' => ['dropped' => true],
		];
		$this->assertSame(['x-openregister-quota' => ['perOrganisation' => 3]], (new GuardedAnnotations())->ofStored($stored));
		$this->assertSame([], (new GuardedAnnotations())->ofStored(null));
	}

	public function testTheShippedAnnotationsAreReadFromBothLevelsNestedWinning(): void {
		$data = [
			'slug' => 'case',
			'x-openregister-quota' => ['perOrganisation' => 1],
			'x-openregister-list' => ['columns' => ['a']],
			'configuration' => ['x-openregister-quota' => ['perOrganisation' => 2], 'objectNameField' => 'title'],
		];
		$this->assertSame(
			['x-openregister-quota' => ['perOrganisation' => 2], 'x-openregister-list' => ['columns' => ['a']]],
			(new GuardedAnnotations())->ofShipped($data)
		);
		$this->assertSame([], (new GuardedAnnotations())->ofShipped(['configuration' => 'junk']));
	}

	public function testADefinitionDeclaresAnnotationsWithAConfigurationOrATopLevelKey(): void {
		$annotations = new GuardedAnnotations();
		$this->assertTrue($annotations->isDeclaredIn(['configuration' => []]));
		$this->assertTrue($annotations->isDeclaredIn(['x-openregister-quota' => []]));
		$this->assertFalse($annotations->isDeclaredIn(['properties' => [], 'x-openregister-typo' => []]));
	}

	public function testWritingBackKeepsMaintainedKeysAndReplacesEveryAnnotation(): void {
		$stored = ['objectNameField' => 'title', 'x-openregister-quota' => ['perOrganisation' => 3], 'x-openregister-list' => ['columns' => ['a']]];
		$this->assertSame(
			['objectNameField' => 'title', 'x-openregister-quota' => ['perOrganisation' => 9]],
			(new GuardedAnnotations())->intoStored($stored, ['x-openregister-quota' => ['perOrganisation' => 9], 'x-openregister-typo' => 1])
		);
	}

	public function testTheShippedDefinitionCarriesTheAnnotationsNestedAndNoTopLevelCopy(): void {
		$data = ['slug' => 'case', 'x-openregister-quota' => ['perOrganisation' => 1], 'configuration' => ['objectNameField' => 'title', 'x-openregister-list' => []]];
		$this->assertSame(
			['slug' => 'case', 'configuration' => ['objectNameField' => 'title', 'x-openregister-quota' => ['perOrganisation' => 4]]],
			(new GuardedAnnotations())->intoShipped($data, ['x-openregister-quota' => ['perOrganisation' => 4]])
		);
		$this->assertSame(['configuration' => []], (new GuardedAnnotations())->intoShipped(['configuration' => 'junk'], []));
	}

	public function testAnOldBaselineIsSeededFromTheInstanceAndARealOneIsNot(): void {
		$annotations = new GuardedAnnotations();
		$live = ['configuration' => ['x-openregister-quota' => ['perOrganisation' => 3]]];
		$this->assertSame(['properties' => [], 'configuration' => $live['configuration']], $annotations->seededBaseline(['properties' => []], $live));
		$real = ['configuration' => []];
		$this->assertSame($real, $annotations->seededBaseline($real, $live));
		$this->assertSame(['properties' => []], $annotations->seededBaseline(['properties' => []], ['properties' => []]));
	}
}

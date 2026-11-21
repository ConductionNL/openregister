<?php

/**
 * Golden test pinning the inheritedVerbs findings of the hierarchy annotation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\HierarchyAnnotationValidator;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander;
use PHPUnit\Framework\TestCase;

/**
 * Named verbs are accepted; a non-list and a blank entry are refused, each with its own message.
 */
class HierarchyInheritedVerbsGoldenTest extends TestCase {

	/**
	 * The bad-verbs findings for an inheritedVerbs value.
	 *
	 * @param mixed $verbs The declared value.
	 *
	 * @return array<int, array{code: string, message: string, severity: string}>
	 */
	private function verbFindings(mixed $verbs): array {
		$findings = (new HierarchyAnnotationValidator())->validate(
			schema: [HierarchyGrantExpander::ANNOTATION => ['parent' => 'parent', 'inheritedVerbs' => $verbs], 'properties' => []]
		);

		return array_values(array_filter($findings, static fn (array $f): bool => $f['code'] === 'hierarchy.bad-verbs'));
	}//end verbFindings()

	/**
	 * A list of named verbs, and an absent key, are allowed.
	 *
	 * @return void
	 */
	public function testNamedVerbsAreAllowed(): void {
		$this->assertSame([], $this->verbFindings(verbs: ['read', 'update']));
		$this->assertSame([], $this->verbFindings(verbs: []));
	}//end testNamedVerbsAreAllowed()

	/**
	 * A non-list, and a list with a blank or non-string entry, are refused with their own messages, once each.
	 *
	 * @return void
	 */
	public function testBadVerbsAreRefused(): void {
		$notAList = ['code' => 'hierarchy.bad-verbs', 'message' => 'inheritedVerbs must be a list of verbs.', 'severity' => 'error'];
		$blank = ['code' => 'hierarchy.bad-verbs', 'message' => 'inheritedVerbs must hold non-empty verb names.', 'severity' => 'error'];

		$this->assertSame([$notAList], $this->verbFindings(verbs: 'read'));
		$this->assertSame([$blank], $this->verbFindings(verbs: ['read', ' ', 7, '']));
		$this->assertSame([$blank], $this->verbFindings(verbs: [7]));
	}//end testBadVerbsAreRefused()
}//end class

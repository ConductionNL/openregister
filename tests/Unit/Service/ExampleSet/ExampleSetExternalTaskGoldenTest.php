<?php

/**
 * Golden: what an example set's external task refuses, and the metadata it carries,
 * pinned on exact messages.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-a-resident-a-portal-task
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetExternalTask;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetExternalTask
 */
class ExampleSetExternalTaskGoldenTest extends TestCase {
	/**
	 * Task rows and their exact refusal.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function cases(): array {
		$assigneeRefusal = 'an external task is assigned to a party ("party:<reference>"), not "%s"';
		return [
			'internal'                  => [['assignee' => 'alice'], ''],
			'internal with upload rules' => [['assignee' => 'alice', 'uploadRules' => []], 'uploadRules apply to an external task only (performerType: external)'],
			'internal other performer'  => [['performerType' => 'user', 'uploadRules' => ['required' => true]], 'uploadRules apply to an external task only (performerType: external)'],
			'internal null rules'       => [['performerType' => 'user', 'uploadRules' => null], ''],
			'external fine'             => [['performerType' => 'external', 'assignee' => 'party:abc'], ''],
			'external fine with rules'  => [['performerType' => 'external', 'assignee' => 'party:abc', 'uploadRules' => ['required' => true, 'maxFiles' => 2, 'maxSizeBytes' => 9, 'acceptedTypes' => ['pdf']]], ''],
			'external no assignee'      => [['performerType' => 'external'], sprintf($assigneeRefusal, '')],
			'external user assignee'    => [['performerType' => 'external', 'assignee' => 'alice'], sprintf($assigneeRefusal, 'alice')],
			'external empty party'      => [['performerType' => 'external', 'assignee' => 'party:  '], sprintf($assigneeRefusal, 'party:  ')],
			'external completed'        => [['performerType' => 'external', 'assignee' => 'party:abc', 'completedBy' => 'alice', 'uploadRules' => 'x'], 'an external task is completed by its party in the portal, not by completedBy'],
			'external rules not object' => [['performerType' => 'external', 'assignee' => 'party:abc', 'uploadRules' => 'x'], 'uploadRules is an object'],
			'external null rules'       => [['performerType' => 'external', 'assignee' => 'party:abc', 'uploadRules' => null], ''],
			'external unknown rules'    => [['performerType' => 'external', 'assignee' => 'party:abc', 'uploadRules' => ['colour' => 1, 'required' => true, 'size' => 2]], 'upload rule "colour", "size" is not one the portal reads'],
		];
	}//end cases()

	/**
	 * A task row gets exactly its refusal.
	 *
	 * @param array<string, mixed> $data     The filled fields.
	 * @param string               $expected The refusal.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testRefusal(array $data, string $expected): void {
		$this->assertSame($expected, ExampleSetExternalTask::refusal($data));
	}//end testRefusal()

	/**
	 * Only `performerType: external` is external, and the upload rules become metadata.
	 *
	 * @return void
	 */
	public function testIsExternalAndMetadata(): void {
		$this->assertTrue(ExampleSetExternalTask::isExternal(['performerType' => 'external']));
		$this->assertFalse(ExampleSetExternalTask::isExternal(['performerType' => 'External']));
		$this->assertFalse(ExampleSetExternalTask::isExternal([]));
		$this->assertSame([], ExampleSetExternalTask::metadata([]));
		$this->assertSame([], ExampleSetExternalTask::metadata(['uploadRules' => null]));
		$this->assertSame(['metadata' => ['upload' => ['required' => true]]], ExampleSetExternalTask::metadata(['uploadRules' => ['required' => true]]));
	}//end testIsExternalAndMetadata()
}//end class

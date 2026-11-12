<?php

/**
 * Characterisation of why an example set's saved view cannot be written.
 *
 * Pins every refusal of ExampleSetViews::refusal and its share check, in the
 * order they are tried, so both can be split into named steps without
 * changing a message.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\ExampleSet
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service\ExampleSet;

use OCA\OpenRegister\Search\Service\ExampleSet\ExampleSetViews;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Every refusal of a saved view, and the views that pass.
 *
 * @covers \OCA\OpenRegister\Search\Service\ExampleSet\ExampleSetViews
 */
class ExampleSetViewsRefusalTest extends TestCase {

	/**
	 * Views and the refusal each gets, '' when it may be written.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}> The cases.
	 */
	public static function views(): array {
		$ok = ['name' => 'Open cases', 'owner' => 'ann'];
		return [
			'valid, no shares'          => [$ok, ''],
			'valid with objects/shares' => [$ok + ['query' => [], 'presentation' => ['a' => 1], 'alert' => [], 'sharedWith' => [['user' => 'bob', 'mode' => 'write'], ['group' => 'staff']]], ''],
			'unknown fields'            => [$ok + ['colour' => 'red', 'size' => 2], 'field "colour", "size" is not a view field a set may write'],
			'unknown beats no name'     => [['owner' => 'ann', 'x' => 1], 'field "x" is not a view field a set may write'],
			'no name'                   => [['owner' => 'ann'], 'a view needs a name'],
			'non-string name'           => [['name' => 5, 'owner' => 'ann'], 'a view needs a name'],
			'blank name'                => [['name' => '  ', 'owner' => 'ann'], 'a view needs a name'],
			'no owner'                  => [['name' => 'n'], 'owner "" is not an account the set creates or adopts'],
			'foreign owner'             => [['name' => 'n', 'owner' => 'eve'], 'owner "eve" is not an account the set creates or adopts'],
			'query not an object'       => [$ok + ['query' => 'x', 'alert' => 'y'], 'query must be an object'],
			'presentation not object'   => [$ok + ['presentation' => 1], 'presentation must be an object'],
			'alert not an object'       => [$ok + ['alert' => true], 'alert must be an object'],
			'null object field is fine' => [$ok + ['query' => null], ''],
			'shares not an array'       => [$ok + ['sharedWith' => 'bob'], 'sharedWith must be a list'],
			'shares not a list'         => [$ok + ['sharedWith' => ['a' => ['user' => 'bob']]], 'sharedWith must be a list'],
			'share names neither'       => [$ok + ['sharedWith' => [['mode' => 'read']]], 'a share names one user or one group'],
			'share names both'          => [$ok + ['sharedWith' => [['user' => 'bob', 'group' => 'g']]], 'a share names one user or one group'],
			'share to a foreign user'   => [$ok + ['sharedWith' => [['user' => 'eve']]], 'share to "eve" is not an account the set creates or adopts'],
			'bad share mode'            => [$ok + ['sharedWith' => [['group' => 'g', 'mode' => 'admin']]], 'share mode "admin" is not one of read, write'],
			'first bad share wins'      => [$ok + ['sharedWith' => [['group' => 'g'], ['user' => 'eve'], []]], 'share to "eve" is not an account the set creates or adopts'],
			'scalar share'              => [$ok + ['sharedWith' => ['bob']], 'a share names one user or one group'],
		];
	}//end views()

	/**
	 * The refusal of one view.
	 *
	 * @param array<string, mixed> $data     The view.
	 * @param string               $expected The refusal.
	 *
	 * @dataProvider views
	 *
	 * @return void
	 */
	public function testRefusal(array $data, string $expected): void {
		$views = new ExampleSetViews($this->createMock(ContainerInterface::class));

		$this->assertSame($expected, $views->refusal($data, ['ann', 'bob']));
	}//end testRefusal()
}//end class

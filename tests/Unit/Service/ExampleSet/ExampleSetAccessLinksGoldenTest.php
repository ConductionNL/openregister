<?php

/**
 * Golden: which access links an example set may mint and which it refuses, without and with the set's objects
 * and the install day, pinned on the exact refusal.
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
 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-access-links-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetAccessLinks;
use OCA\OpenRegister\Service\ExampleSet\FilledRow;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetAccessLinks
 */
class ExampleSetAccessLinksGoldenTest extends TestCase {
	/**
	 * An access link row, the set's accounts and the run's context, and the exact refusal without and with the context
	 * ('' when the link may be minted).
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, string>, 2: array<string, mixed>, 3: string, 4: string}>
	 */
	public static function cases(): array {
		$ctx = ['objects' => ['a' => ['uuid' => 'obj-1']], 'today' => new DateTimeImmutable('2026-03-10 09:00', new DateTimeZone('UTC'))];
		$ok = ['object' => 'obj-1', 'createdBy' => 'alice', 'expiresAt' => '2026-04-01T10:00:00Z'];
		$u = ['alice', 'bob'];
		$cases = [
		 'unknown field' => [['colour' => 1] + $ok, $u, $ctx],
		 'no creator' => [array_diff_key($ok, ['createdBy' => 1]), $u, $ctx],
		 'stranger creator' => [['createdBy' => 'eve'] + $ok, $u, $ctx],
		 'creator number' => [['createdBy' => 4] + $ok, $u, $ctx],
		 'label number' => [['label' => 3] + $ok, $u, $ctx],
		 'label null' => [['label' => null] + $ok, $u, $ctx],
		 'label text' => [['label' => 'Demo'] + $ok, $u, $ctx],
		 'capabilities text' => [['capabilities' => 'read'] + $ok, $u, $ctx],
		 'capability unknown' => [['capabilities' => ['read', 'own']] + $ok, $u, $ctx],
		 'capability number' => [['capabilities' => [1]] + $ok, $u, $ctx],
		 'capabilities empty' => [['capabilities' => []] + $ok, $u, $ctx],
		 'object not of set' => [['object' => 'obj-9'] + $ok, $u, $ctx],
		 'object missing' => [array_diff_key($ok, ['object' => 1]), $u, $ctx],
		 'no expiry' => [array_diff_key($ok, ['expiresAt' => 1]), $u, $ctx],
		 'expiry null' => [['expiresAt' => null] + $ok, $u, $ctx],
		 'expiry bad' => [['expiresAt' => 'never'] + $ok, $u, $ctx],
		 'expiry blank' => [['expiresAt' => ' '] + $ok, $u, $ctx],
		 'expiry past' => [['expiresAt' => '2026-03-09T10:00:00Z'] + $ok, $u, $ctx],
		 'expiry today' => [['expiresAt' => '2026-03-10T00:00:00Z'] + $ok, $u, $ctx],
		 'fine' => [$ok, $u, $ctx],
		];
		$expected = [
			'unknown field' => ['field "colour" is not an access link field a set may write', 'field "colour" is not an access link field a set may write'],
			'no creator' => ['createdBy null is no account the set creates or adopts', 'createdBy null is no account the set creates or adopts'],
			'stranger creator' => ['createdBy "eve" is no account the set creates or adopts', 'createdBy "eve" is no account the set creates or adopts'],
			'creator number' => ['createdBy 4 is no account the set creates or adopts', 'createdBy 4 is no account the set creates or adopts'],
			'label number' => ['label is not text', 'label is not text'],
			'label null' => ['', ''],
			'label text' => ['', ''],
			'capabilities text' => ['capabilities is not a list', 'capabilities is not a list'],
			'capability unknown' => ['capability "own" is not one of read, comment, upload', 'capability "own" is not one of read, comment, upload'],
			'capability number' => ['capability 1 is not one of read, comment, upload', 'capability 1 is not one of read, comment, upload'],
			'capabilities empty' => ['', ''],
			'object not of set' => ['', 'object "obj-9" is not an object the set writes'],
			'object missing' => ['', 'object null is not an object the set writes'],
			'no expiry' => ['', 'an access link needs an expiry (expiresAt)'],
			'expiry null' => ['', 'an access link needs an expiry (expiresAt)'],
			'expiry bad' => ['', 'expiresAt "never" is not a date'],
			'expiry blank' => ['', 'expiresAt is not a date'],
			'expiry past' => ['', 'expiresAt "2026-03-09T10:00:00Z" lies before the install day'],
			'expiry today' => ['', ''],
			'fine' => ['', ''],
		];
		foreach ($cases as $name => $case) {
			$cases[$name] = [...$case, ...$expected[$name]];
		}

		return $cases;
	}//end cases()

	/**
	 * A link gets exactly its refusal, and the check reads nothing from the container.
	 *
	 * @param array<string, mixed> $data      The filled link fields.
	 * @param array<int, string>   $users     The account ids the set creates or adopts.
	 * @param array<string, mixed> $context   The context: objects and install day.
	 * @param string               $refusal   What refusal() answers.
	 * @param string               $refusalIn What refusalIn() answers.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testRefusal(array $data, array $users, array $context, string $refusal, string $refusalIn): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$links = new ExampleSetAccessLinks(container: $container);
		$this->assertSame($refusal, $links->refusal(data: $data, users: $users));
		$this->assertSame($refusalIn, (new ReflectionMethod($links, 'refusalIn'))->invoke($links, new FilledRow(data: $data, users: $users), $context));
	}//end testRefusal()
}//end class

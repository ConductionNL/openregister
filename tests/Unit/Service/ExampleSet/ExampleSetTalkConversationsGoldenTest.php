<?php

/**
 * Golden test for ExampleSetTalkConversations: what refusal(), write(),
 * lost() and revert() answer, and every call they make on Talk, the link
 * mapper and the object finder, for a fixed table of rows that reaches every
 * branch. Re-record with UPDATE_GOLDEN=1.
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
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\TalkLink;
use OCA\OpenRegister\Db\TalkLinkMapper;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkConversations;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkStore;
use OCA\OpenRegister\Service\Party\PartyService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkConversations
 */
class ExampleSetTalkConversationsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__.'/fixtures/example-set-talk-conversations-golden.json';

	/**
	 * @var array<int, mixed> Every call made on the fakes, in order.
	 */
	private array $calls = [];

	/**
	 * @var array<string, mixed> How the fakes behave in the running case.
	 */
	private array $world = [];

	public function testTalkConversationsMatchGolden(): void {
		$actual = [];
		foreach ($this->refusals() as $name => $data) {
			$actual['refusal '.$name] = $this->subject()->refusal($data, ['anna', 'bob', 'cleo']);
		}

		foreach ($this->writes() as $name => $case) {
			$this->calls   = [];
			$this->world   = $case['world'];
			$answer        = $this->subject()->write('zaak', 'k1', $case['data']);
			$actual['write '.$name] = ['answer' => $answer, 'calls' => $this->calls];
		}

		foreach ($this->entries() as $name => $case) {
			$this->calls = [];
			$this->world = $case['world'];
			$subject     = $this->subject();
			$actual['entry '.$name] = ['lost' => $subject->lost($case['entry']), 'revert' => $subject->revert($case['entry']), 'calls' => $this->calls];
		}

		$this->assertGolden($actual);
	}

	private function assertGolden(array $actual): void {
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $name => $outcome) {
			$this->assertSame(json_encode($outcome), json_encode($actual[$name]), $name);
		}
	}

	private function subject(): ExampleSetTalkConversations {
		$services  = [
			ExampleSetTalkStore::class => $this->talkStore(),
			TalkLinkMapper::class      => $this->linkMapper(),
			PartyService::class        => $this->parties(),
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]);

		return new ExampleSetTalkConversations($container);
	}

	private function talkStore(): ExampleSetTalkStore {
		$store = $this->createMock(ExampleSetTalkStore::class);
		$store->method('available')->willReturnCallback(fn (): bool => ($this->world['talk'] ?? true));
		$store->method('room')->willReturnCallback(function (string $owner, string $name, array $participants): array {
			$this->calls[] = ['room', $owner, $name, $participants];
			if (($this->world['roomFails'] ?? false) === true) {
				throw new RuntimeException('Talk refused the room');
			}

			return ['token' => 'tok-'.$name, 'id' => 41, 'created' => ($this->world['created'] ?? true)];
		});
		$store->method('heldCall')->willReturnCallback(function (string $token, string $owner, DateTime $start, DateTime $end, array $joined): void {
			$this->calls[] = ['heldCall', $token, $owner, $start->format('c'), $end->format('c'), $joined];
		});
		$store->method('roomExists')->willReturnCallback(function (string $token): bool {
			$this->calls[] = ['roomExists', $token];
			if (($this->world['existsFails'] ?? false) === true) {
				throw new RuntimeException('Talk is down');
			}

			return ($this->world['exists'] ?? true);
		});
		$store->method('deleteRoom')->willReturnCallback(function (string $token): void {
			$this->calls[] = ['deleteRoom', $token];
		});

		return $store;
	}

	private function linkMapper(): TalkLinkMapper {
		$mapper = $this->createMock(TalkLinkMapper::class);
		$mapper->method('findByObjectAndRoom')->willReturnCallback(function (string $objectUuid, string $roomToken): ?TalkLink {
			$this->calls[] = ['findByObjectAndRoom', $objectUuid, $roomToken];
			if (($this->world['linked'] ?? false) === true) {
				$link = new TalkLink();
				$link->setId(7);
				return $link;
			}

			return null;
		});
		$mapper->method('insert')->willReturnCallback(function (TalkLink $link): TalkLink {
			$this->calls[] = ['insert', $this->linkFields($link)];
			$link->setId(12);
			return $link;
		});
		$mapper->method('deleteByObjectAndRoom')->willReturnCallback(function (string $objectUuid, string $roomToken): int {
			$this->calls[] = ['deleteByObjectAndRoom', $objectUuid, $roomToken];
			if (($this->world['deleteFails'] ?? false) === true) {
				throw new RuntimeException('database is gone');
			}

			return 1;
		});

		return $mapper;
	}

	/**
	 * A link's fields, without the moment it was made; its last activity only when it is a held call's end, not now.
	 *
	 * @param TalkLink $link The link.
	 *
	 * @return array<string, mixed>
	 */
	private function linkFields(TalkLink $link): array {
		$activity = 'now';
		if ($link->getLastActivity()->format('Y-m-d') === '2026-01-01') {
			$activity = $link->getLastActivity()->format('c');
		}

		return [
			'objectUuid'       => $link->getObjectUuid(),
			'registerId'       => $link->getRegisterId(),
			'schemaId'         => $link->getSchemaId(),
			'roomToken'        => $link->getRoomToken(),
			'roomId'           => $link->getRoomId(),
			'roomName'         => $link->getRoomName(),
			'roomType'         => $link->getRoomType(),
			'participantCount' => $link->getParticipantCount(),
			'lastActivity'     => $activity,
			'linkedBy'         => $link->getLinkedBy(),
			'linkedAtIsSet'    => ($link->getLinkedAt() instanceof DateTime),
		];
	}

	private function parties(): PartyService {
		$parties = $this->createMock(PartyService::class);
		$parties->method('findUnscoped')->willReturnCallback(function (string $uuid): ?ObjectEntity {
			$this->calls[] = ['findUnscoped', $uuid];
			if ($uuid !== 'obj-1') {
				return null;
			}

			$object = new ObjectEntity();
			$object->setRegister('3');
			$object->setSchema('5');
			return $object;
		});

		return $parties;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function refusals(): array {
		$ok = ['object' => 'obj-1', 'name' => 'Overleg', 'owner' => 'anna'];
		return [
			'ok'                  => $ok,
			'unknown fields'      => $ok + ['colour' => 'red', 'size' => 2],
			'no object'           => ['name' => 'Overleg', 'owner' => 'anna'],
			'blank name'          => ['object' => 'obj-1', 'name' => '  ', 'owner' => 'anna'],
			'owner not text'      => ['object' => 'obj-1', 'name' => 'Overleg', 'owner' => 5],
			'stranger owner'      => ['object' => 'obj-1', 'name' => 'Overleg', 'owner' => 'zed'],
			'stranger participant' => $ok + ['participants' => ['bob', 'yan', 'xi']],
			'participant scalar'  => $ok + ['participants' => 'bob'],
			'call null'           => $ok + ['call' => null],
			'call text'           => $ok + ['call' => 'today'],
			'call no end'         => $ok + ['call' => ['start' => '2026-01-01T10:00:00Z']],
			'call bad date'       => $ok + ['call' => ['start' => 'not a date', 'end' => '2026-01-01T10:00:00Z']],
			'call backwards'      => $ok + ['call' => ['start' => '2026-01-01T11:00:00Z', 'end' => '2026-01-01T10:00:00Z']],
			'call same moment'    => $ok + ['call' => ['start' => '2026-01-01T10:00:00Z', 'end' => '2026-01-01T10:00:00Z']],
			'call ok'             => $ok + ['call' => ['start' => '2026-01-01T10:00:00Z', 'end' => '2026-01-01T11:00:00Z'], 'participants' => ['cleo']],
		];
	}

	/**
	 * @return array<string, array{world: array<string, mixed>, data: array<string, mixed>}>
	 */
	private function writes(): array {
		$row  = ['object' => 'obj-1', 'name' => 'Overleg', 'owner' => 'anna', 'participants' => ['bob', 'anna', 'bob', 7]];
		$call = ['start' => '2026-01-01T10:00:00+01:00', 'end' => '2026-01-01T11:30:00+01:00'];
		return [
			'no talk'          => ['world' => ['talk' => false], 'data' => $row],
			'no object'        => ['world' => [], 'data' => ['object' => 'obj-9'] + $row],
			'plain'            => ['world' => [], 'data' => $row],
			'with call'        => ['world' => [], 'data' => $row + ['call' => $call]],
			'call room kept'   => ['world' => ['created' => false], 'data' => $row + ['call' => $call]],
			'already linked'   => ['world' => ['linked' => true], 'data' => $row + ['call' => $call]],
			'room fails'       => ['world' => ['roomFails' => true], 'data' => $row],
			'call date fails'  => ['world' => [], 'data' => $row + ['call' => ['start' => 'never', 'end' => 'never']]],
			'no participants'  => ['world' => [], 'data' => ['object' => 'obj-1', 'name' => 'Solo', 'owner' => 'cleo']],
		];
	}

	/**
	 * @return array<string, array{world: array<string, mixed>, entry: array<string, mixed>}>
	 */
	private function entries(): array {
		$entry = ['token' => 'tok-1', 'room' => 41, 'createdRoom' => true, 'object' => 'obj-1', 'link' => 12];
		return [
			'there created'   => ['world' => [], 'entry' => $entry],
			'there kept'      => ['world' => [], 'entry' => ['createdRoom' => false] + $entry],
			'there no flag'   => ['world' => [], 'entry' => array_diff_key($entry, ['createdRoom' => 1])],
			'gone'            => ['world' => ['exists' => false], 'entry' => $entry],
			'talk down'       => ['world' => ['existsFails' => true], 'entry' => $entry],
			'delete fails'    => ['world' => ['deleteFails' => true], 'entry' => $entry],
			'created as text' => ['world' => [], 'entry' => ['createdRoom' => 'yes'] + $entry],
		];
	}
}

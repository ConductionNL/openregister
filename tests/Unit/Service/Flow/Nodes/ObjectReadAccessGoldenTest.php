<?php

/**
 * Golden test: who an object-read step reads as, and when it refuses.
 *
 * Recorded on the code before ObjectReadNode's collaborators and read
 * arguments were bundled. Pins the allowed outcome (the read runs inside
 * runAs() as the run's acting identity, with the step's register, schema,
 * filters and limit) and every refused outcome (no acting identity, an
 * unknown account, a disabled account, a read that fails), including that a
 * refused run never reaches findAll().
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectNodeSources;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectReadNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;

/**
 * Pins the access outcomes of ObjectReadNode::execute().
 */
final class ObjectReadAccessGoldenTest extends TestCase {

	/**
	 * Every scenario's recorded outcome.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool, 2: array<string, mixed>}>
	 */
	public static function scenarios(): array {
		$allowedRead = [
			'outcome' => 'allowed',
			'reads' => [['actingAs' => 'alice', 'register' => 1, 'schema' => 2, 'filters' => ['status' => 'open'], 'limit' => 5]],
			'items' => [['status' => 'open', 'objects' => [['id' => 'u-1', 'title' => 'a thing', 'uuid' => 'u-1']]]],
		];

		return [
			'enabled runAs reads as that user' => [['runAs' => 'alice'], false, $allowedRead],
			'runAs is trimmed' => [['runAs' => '  alice  '], false, $allowedRead],
			'triggeredBy alone is no acting identity' => [
				['triggeredBy' => 'alice'],
				false,
				['outcome' => 'refused', 'reads' => [], 'error' => 'This flow run has no acting identity (runAs); an object read must be attributable.'],
			],
			'empty runAs is refused' => [
				['runAs' => '   '],
				false,
				['outcome' => 'refused', 'reads' => [], 'error' => 'This flow run has no acting identity (runAs); an object read must be attributable.'],
			],
			'unknown account is refused' => [
				['runAs' => 'mallory'],
				false,
				[
					'outcome' => 'refused',
					'reads' => [],
					'error' => 'This flow run\'s acting identity "mallory" (runAs) '
						. 'is not a user account; an object read must be attributable.',
				],
			],
			'disabled account is refused' => [
				['runAs' => 'dormant'],
				false,
				[
					'outcome' => 'refused',
					'reads' => [],
					'error' => 'This flow run\'s acting identity "dormant" (runAs) '
						. 'is a disabled account; an object read is refused rather than performed on their behalf.',
				],
			],
			'a failing read throws, never answers empty' => [
				['runAs' => 'alice'],
				true,
				[
					'outcome' => 'refused',
					'reads' => [['actingAs' => 'alice', 'register' => 1, 'schema' => 2, 'filters' => ['status' => 'open'], 'limit' => 5]],
					'error' => 'The object-read step could not read from the register: store down',
				],
			],
		];
	}//end scenarios()

	/**
	 * The node's outcome for each run context matches the recording.
	 *
	 * @param array<string, mixed> $context   The run context.
	 * @param bool                 $readFails Whether findAll() throws.
	 * @param array<string, mixed> $expected  The recorded outcome.
	 *
	 * @return void
	 *
	 * @dataProvider scenarios
	 */
	public function testAccessOutcomeMatchesTheRecording(array $context, bool $readFails, array $expected): void {
		$reads = [];
		$node = $this->node(reads: $reads, readFails: $readFails);

		$items = [FlowItems::item(json: ['status' => 'open'])];
		$config = ['register' => 'r', 'schema' => 's', 'filters' => ['status' => '{{status}}'], 'limit' => 5];

		try {
			$out = $node->execute(new FlowNodeCall(items: $items, config: $config, context: $context));
			$actual = [
				'outcome' => 'allowed',
				'reads' => $reads,
				'items' => array_map(static fn (array $item): array => (array)$item[FlowItems::JSON], $out),
			];
		} catch (RuntimeException $e) {
			$actual = ['outcome' => 'refused', 'reads' => $reads, 'error' => $e->getMessage()];
		}

		$this->assertSame($expected, $actual);
	}//end testAccessOutcomeMatchesTheRecording()

	/**
	 * Build the node over doubles that record every read and who it ran as.
	 *
	 * @param array<int, array<string, mixed>> $reads     Receives one entry per findAll() call.
	 * @param bool                             $readFails Whether findAll() throws.
	 *
	 * @return ObjectReadNode The node.
	 */
	private function node(array &$reads, bool $readFails): ObjectReadNode {
		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(2);

		$acting = null;
		$target = ['register' => null, 'schema' => null];
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('setRegister')->willReturnCallback(
			static function ($value) use (&$target, &$objects) {
				$target['register'] = $value->getId();
				return $objects;
			}
		);
		$objects->method('setSchema')->willReturnCallback(
			static function ($value) use (&$target, &$objects) {
				$target['schema'] = $value->getId();
				return $objects;
			}
		);
		$objects->method('runAs')->willReturnCallback(
			static function (IUser $user, callable $operation) use (&$acting) {
				$previous = $acting;
				$acting = $user->getUID();
				try {
					return $operation();
				} finally {
					$acting = $previous;
				}
			}
		);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config = []) use (&$acting, &$target, &$reads, $readFails): array {
				$reads[] = [
					'actingAs' => $acting,
					'register' => $target['register'],
					'schema' => $target['schema'],
					'filters' => $config['filters'] ?? null,
					'limit' => $config['limit'] ?? null,
				];
				if ($readFails === true) {
					throw new RuntimeException('store down');
				}

				$entity = new ObjectEntity();
				$entity->setUuid('u-1');
				$entity->setObject(['title' => 'a thing']);

				return [$entity];
			}
		);

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturn($register);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('findBySlugInIds')->willReturn($schema);
		$schemas->method('find')->willReturn($schema);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if (in_array($uid, ['alice', 'dormant'], true) === false) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				$user->method('isEnabled')->willReturn($uid === 'alice');

				return $user;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $parameters = []): string {
				if ($parameters === []) {
					return $text;
				}

				return vsprintf($text, $parameters);
			}
		);

		return new ObjectReadNode(
			sources: new ObjectNodeSources(objects: $objects, registers: $registers, schemas: $schemas),
			userManager: $users,
			presentation: new FlowNodePresentation(l10n: $l10n, urls: $this->createMock(IURLGenerator::class))
		);
	}//end node()
}//end class

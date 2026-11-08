<?php

/**
 * Characterisation of three provider bodies: the task a Tasks leaf creates
 * from a payload, the rows the Tags leaf lists, and how the BRP leaf reads
 * the persons out of each response shape.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarTasksInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\TagsProvider;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\TasksProvider;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\Providers\BrpPersonProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Provider bodies, pinned.
 */
class BuiltinProviderBodiesGoldenTest extends TestCase {

	/**
	 * A Tasks leaf whose task service records what it was asked.
	 *
	 * @param ObjectEntity|null $object  What the object lookup answers.
	 * @param array|null        $created What createTask() answers.
	 * @param array|null        $asked   Filled with createTask()'s arguments.
	 *
	 * @return TasksProvider The provider.
	 */
	private function tasks(?ObjectEntity $object, ?array $created, ?array &$asked): TasksProvider {
		$register = new Register();
		$register->setId(7);
		$schema = new Schema();
		$schema->setId(9);

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturn($register);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($schema);
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willReturn($object);

		$service = $this->createMock(ObjectCalendarTasksInterface::class);
		$service->method('createTask')->willReturnCallback(
			static function (int $registerId, int $schemaId, string $objectUuid, string $objectTitle, array $data) use ($created, &$asked): ?array {
				$asked = [$registerId, $schemaId, $objectUuid, $objectTitle, $data];

				return $created;
			}
		);

		return new TasksProvider($service, $registers, $schemas, $objects, $this->createMock(IL10N::class));
	}//end tasks()

	public function testATaskIsCreatedWithDefaultsAndTheObjectName(): void {
		$object = new ObjectEntity();
		$object->setName('Dossier A');
		$asked = null;

		$task = $this->tasks($object, ['id' => 't1'], $asked)->create('reg', 'case', 'obj-1', ['summary' => 5]);

		$this->assertSame(['id' => 't1'], $task);
		$this->assertSame(
			[7, 9, 'obj-1', 'Dossier A', ['summary' => '5', 'description' => '', 'priority' => 0, 'status' => 'NEEDS-ACTION']],
			$asked
		);
	}//end testATaskIsCreatedWithDefaultsAndTheObjectName()

	public function testADueDateIsPassedOnlyWhenGivenAndAMissingObjectFallsBackToItsUuid(): void {
		$asked = null;

		$this->tasks(null, ['id' => 't2'], $asked)->create(
			'reg',
			'case',
			'obj-2',
			['summary' => 'Bel', 'description' => 'terug', 'priority' => '3', 'status' => 'COMPLETED', 'due' => 20261108]
		);

		$this->assertSame(
			[7, 9, 'obj-2', 'obj-2', ['summary' => 'Bel', 'description' => 'terug', 'priority' => 3, 'status' => 'COMPLETED', 'due' => '20261108']],
			$asked
		);
	}//end testADueDateIsPassedOnlyWhenGivenAndAMissingObjectFallsBackToItsUuid()

	public function testANullTaskIsRefused(): void {
		$asked = null;

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('TaskService::createTask returned null — calendar invalid or auth failure.');

		$this->tasks(null, null, $asked)->create('reg', 'case', 'obj-3', ['due' => null]);
	}//end testANullTaskIsRefused()

	/**
	 * A Tags leaf over tag ids and tags we dictate.
	 *
	 * @param array      $idsByObject What getTagIdsForObjects() answers.
	 * @param array|null $tags        The tags, or null for a manager that throws.
	 *
	 * @return TagsProvider The provider.
	 */
	private function tags(array $idsByObject, ?array $tags): TagsProvider {
		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		$mapper->method('getTagIdsForObjects')->willReturn($idsByObject);

		$manager = $this->createMock(ISystemTagManager::class);
		if ($tags === null) {
			$manager->method('getTagsByIds')->willThrowException(new RuntimeException('gone'));
		} else {
			$manager->method('getTagsByIds')->willReturn($tags);
		}

		return new TagsProvider($manager, $mapper, $this->createMock(IL10N::class));
	}//end tags()

	/**
	 * One system tag.
	 *
	 * @param string $id         Its id.
	 * @param string $name       Its name.
	 * @param bool   $visible    Whether users see it.
	 * @param bool   $assignable Whether users may assign it.
	 *
	 * @return ISystemTag The tag.
	 */
	private function tag(string $id, string $name, bool $visible, bool $assignable): ISystemTag {
		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getId')->willReturn($id);
		$tag->method('getName')->willReturn($name);
		$tag->method('isUserVisible')->willReturn($visible);
		$tag->method('isUserAssignable')->willReturn($assignable);

		return $tag;
	}//end tag()

	public function testTagRowsKeepTheManagersOrder(): void {
		$rows = $this->tags(
			['obj-1' => ['2', '1']],
			['2' => $this->tag('2', 'urgent', true, false), '1' => $this->tag('1', 'intern', false, true)]
		)->list('reg', 'case', 'obj-1');

		$this->assertSame(
			[
				['id' => '2', 'name' => 'urgent', 'visibility' => true, 'assignable' => false],
				['id' => '1', 'name' => 'intern', 'visibility' => false, 'assignable' => true],
			],
			$rows
		);
	}//end testTagRowsKeepTheManagersOrder()

	public function testNoTagsOrAFailingManagerListNothing(): void {
		$this->assertSame([], $this->tags(['other' => ['1']], [])->list('reg', 'case', 'obj-1'));
		$this->assertSame([], $this->tags(['obj-1' => []], [])->list('reg', 'case', 'obj-1'));
		$this->assertSame([], $this->tags(['obj-1' => ['1']], null)->list('reg', 'case', 'obj-1'));
	}//end testNoTagsOrAFailingManagerListNothing()

	/**
	 * @return array<string, array{0: array<mixed>, 1: array<int, mixed>}>
	 */
	public static function brpResponses(): array {
		$a = ['burgerservicenummer' => '1'];
		$b = ['burgerservicenummer' => '2'];

		return [
			'personen'                          => [['personen' => [$a, 'x', $b]], [$a, $b]],
			'embedded'                          => [['_embedded' => ['personen' => [$b, null]]], [$b]],
			'personen wins over embedded'       => [['personen' => [$a], '_embedded' => ['personen' => [$b]]], [$a]],
			'non-list personen falls through'   => [['personen' => 'x', '_embedded' => ['personen' => [$b]]], [$b]],
			'null personen falls through'       => [['personen' => null, '_embedded' => ['personen' => [$a]]], [$a]],
			'embedded that is no document'      => [['_embedded' => 'x'], []],
			'a bare list'                       => [[$a, 7, $b], [$a, $b]],
			'an unknown document'               => [['type' => 'RaadpleegMetBurgerservicenummer'], []],
			'an empty response'                 => [[], []],
			'personen keyed by name'            => [['personen' => ['p' => $a]], [$a]],
			'embedded personen that is no list' => [['_embedded' => ['personen' => 3]], []],
		];
	}//end brpResponses()

	#[DataProvider('brpResponses')]
	public function testBrpPersonsAreReadFromEachShape(array $response, array $persons): void {
		$router = $this->createMock(ExternalIntegrationRouter::class);
		$router->method('call')->willReturn($response);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$provider = new BrpPersonProvider(
			router: $router,
			appManager: $this->createMock(IAppManager::class),
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertSame($persons, $provider->list('', '', '', ['_search' => '999993653']));
	}//end testBrpPersonsAreReadFromEachShape()
}//end class

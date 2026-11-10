<?php

/**
 * Behaviour tests for NotesController: the visibility a list request asks for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed fixture properties are self-describing.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Notifications\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\NoteServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Controller\NotesController;
use OCA\OpenRegister\Notifications\Controller\Support\NotesControllerServices;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineWriteService;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NotesController::class)]
#[UsesClass(NotesControllerServices::class)]
#[UsesClass(ObjectEntity::class)]
class NotesControllerBehaviourTest extends TestCase {

	/**
	 * Requested visibility values and what reaches the filter.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: ?string}>
	 */
	public static function requests(): array {
		return [
			'named'      => [['visibility' => 'internal'], 'internal'],
			'absent'     => [[], null],
			'not string' => [['visibility' => ['internal']], null],
		];
	}

	#[DataProvider('requests')]
	public function testIndexHandsTheRequestedVisibilityToTheFilter(array $params, ?string $requested): void {
		$object = new ObjectEntity();
		$object->setUuid('uuid-1');
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('setObject')->willReturnSelf();
		$objects->method('getObject')->willReturn($object);
		$visibility = $this->createMock(TimelineVisibilityService::class);
		$visibility->method('mayManage')->willReturn(true);
		$visibility->expects($this->once())->method('effectiveFilter')->with($object, $requested)->willReturn('public');
		$notes = $this->createMock(NoteServiceInterface::class);
		$notes->expects($this->once())->method('getNotesForObject')->with('uuid-1', 50, 0, 'public')->willReturn([['id' => 1]]);

		$controller = new NotesController(
			$request,
			new NotesControllerServices($notes, $objects, $visibility, $this->createMock(TimelineWriteService::class))
		);
		$response = $controller->index('reg', 'sch', 'id');

		$this->assertSame(
			['results' => [['id' => 1]], 'total' => 1, 'visibility' => 'public', 'canSetVisibility' => true],
			$response->getData()
		);
	}
}

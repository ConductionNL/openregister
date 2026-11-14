<?php

/**
 * Services and helpers consuming apps call by name keep their published arguments.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Contract;

use OCA\OpenRegister\AppHost\Bootstrap;
use OCA\OpenRegister\AppHost\LeafAdoption;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\File\CreateFileHandler;
use OCA\OpenRegister\Service\File\DocumentAnonymization;
use OCA\OpenRegister\Kernel\Contracts\DocumentProcessingHandlerInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\DocumentAnonymisationRequest;
use OCA\OpenRegister\Service\File\FileTarget;
use OCA\OpenRegister\Service\File\FileTransfer;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Flow\FlowItemOptions;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\WebhookEvent;
use OCA\OpenRegister\Service\WebhookService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\Event;
use OCP\Files\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Fleet apps call these with the argument names of v2.1.47 (`appId:`, `controllerNs:`,
 * `objectEntity:`, `fileName:`, `_event:`, `binary:`, `fromItemIndex:`, `limit:`). Each
 * test pins the names, and checks that the release's form and main's value-object form
 * give the same call.
 */
class LegacyServiceArgumentsTest extends TestCase {

	/**
	 * Main's parameters in main's positions, then the release's names, per method.
	 *
	 * @return array<string, array{0: class-string, 1: string, 2: list<string>, 3: list<string>}>
	 */
	public static function publishedParameters(): array {
		return [
			'Bootstrap::register'             => [Bootstrap::class, 'register', ['context', 'appId'], ['options', 'adoption']],
			'Bootstrap::aliasStoreController' => [Bootstrap::class, 'aliasStoreController', ['context', 'appId'], ['controllerNs', 'adoption']],
			'FileService::addFile'            => [
				FileService::class,
				'addFile',
				['target', 'upload'],
				['content', 'share', 'tags', '_schema', '_register', 'registerId', 'objectEntity', 'fileName'],
			],
			'FileService::saveFile'           => [FileService::class, 'saveFile', ['objectEntity', 'upload'], ['content', 'share', 'tags', 'fileName']],
			'FileService::createShareLink'    => [FileService::class, 'createShareLink', ['request'], ['shareType', 'permissions', 'path']],
			'FileService::copyFile'           => [FileService::class, 'copyFile', ['sourceObject', 'fileId', 'targetObject'], []],
			'FileService::moveFile'           => [FileService::class, 'moveFile', ['sourceObject', 'fileId', 'targetObject'], []],
			'FileService::anonymizeDocument'  => [FileService::class, 'anonymizeDocument', ['node', 'anonymization'], ['scope', 'fileKey', 'preserveStructure', 'entities']],
			'WebhookService::dispatchEvent'   => [WebhookService::class, 'dispatchEvent', ['eventName', 'payload'], ['_event']],
			'FlowItems::item'                 => [FlowItems::class, 'item', ['json', 'binary'], ['fromItemIndex', 'output', 'options']],
			'SchemaMapper::findBySlug'        => [SchemaMapper::class, 'findBySlug', ['slug', 'query', '_rbac', '_multitenancy'], ['limit', 'offset']],
			'SchemaMapperInterface::findBySlug' => [SchemaMapperInterface::class, 'findBySlug', ['slug', 'query', '_rbac', '_multitenancy'], ['limit', 'offset']],
		];
	}//end publishedParameters()

	/**
	 * The leading parameters keep their positions; the other published names are still there.
	 *
	 * @param class-string $class   The class.
	 * @param string       $method  The method.
	 * @param list<string> $leading The parameters, in order, the method starts with.
	 * @param list<string> $named   The other published parameter names.
	 *
	 * @return void
	 */
	#[DataProvider('publishedParameters')]
	public function testThePublishedParametersAreStillThere(string $class, string $method, array $leading, array $named): void {
		$names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), (new ReflectionMethod($class, $method))->getParameters());

		$this->assertSame($leading, array_slice($names, 0, count($leading)), "{$class}::{$method}() must keep its leading parameters.");
		foreach ($named as $name) {
			$this->assertContains($name, $names, "{$class}::{$method}() must keep \${$name}.");
		}
	}//end testThePublishedParametersAreStillThere()

	/**
	 * A FileService whose create handler records what it is asked.
	 *
	 * @param array<int, array<int, mixed>> $calls The recorded calls.
	 *
	 * @return FileService
	 */
	private function fileService(array &$calls): FileService {
		$handler = $this->createMock(CreateFileHandler::class);
		$handler->method('addFile')->willReturnCallback(
			function (FileTarget $target, FileUpload $upload) use (&$calls): File {
				$calls[] = ['add', $target, $upload];
				return $this->createMock(File::class);
			}
		);
		$handler->method('saveFile')->willReturnCallback(
			function (ObjectEntity $objectEntity, FileUpload $upload) use (&$calls): File {
				$calls[] = ['save', $objectEntity, $upload];
				return $this->createMock(File::class);
			}
		);
		$service = $this->getMockBuilder(FileService::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
		(new ReflectionProperty(FileService::class, 'createFileHandler'))->setValue($service, $handler);

		return $service;
	}//end fileService()

	public function testFileWritesGiveTheSameCallInEveryForm(): void {
		$object  = new ObjectEntity();
		$release = [];
		$service = $this->fileService($release);
		$service->addFile(objectEntity: $object, fileName: 'a.txt', content: 'x', share: true, tags: ['t'], registerId: 5);
		$service->addFile($object, 'a.txt', 'x', true, ['t'], null, null, 5);
		$service->saveFile(objectEntity: $object, fileName: 'a.txt', content: 'x', share: true, tags: ['t']);

		$main    = [];
		$service = $this->fileService($main);
		$target  = new FileTarget(object: $object, registerId: 5);
		$upload  = new FileUpload(fileName: 'a.txt', content: 'x', share: true, tags: ['t']);
		$service->addFile(target: $target, upload: $upload);
		$service->addFile($target, $upload);
		$service->saveFile(objectEntity: $object, upload: $upload);

		$this->assertEquals($main, $release);
	}

	public function testCopyFileIsOneFileTransfer(): void {
		$source  = new ObjectEntity();
		$target  = new ObjectEntity();
		$service = $this->getMockBuilder(FileService::class)->disableOriginalConstructor()->onlyMethods(['copyAttachedFile'])->getMock();
		$service->expects($this->once())->method('copyAttachedFile')
			->with(new FileTransfer(source: $source, fileId: 7, target: $target))
			->willReturn($this->createMock(File::class));

		$service->copyFile(sourceObject: $source, fileId: 7, targetObject: $target);
	}

	public function testMoveFileIsOneFileTransfer(): void {
		$source  = new ObjectEntity();
		$target  = new ObjectEntity();
		$service = $this->getMockBuilder(FileService::class)->disableOriginalConstructor()->onlyMethods(['moveAttachedFile'])->getMock();
		$service->expects($this->once())->method('moveAttachedFile')
			->with(new FileTransfer(source: $source, fileId: 7, target: $target))
			->willReturn($this->createMock(File::class));

		$service->moveFile($source, 7, $target);
	}

	public function testAnonymizeDocumentGivesTheSameRequestInEveryForm(): void {
		$node     = $this->createMock(File::class);
		$requests = [];
		$handler  = $this->createMock(DocumentProcessingHandlerInterface::class);
		$handler->method('anonymize')->willReturnCallback(
			static function (DocumentAnonymisationRequest $request) use (&$requests, $node): File {
				$requests[] = $request;
				return $node;
			}
		);
		$service = $this->getMockBuilder(FileService::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
		(new ReflectionProperty(FileService::class, 'documentProcessingHandler'))->setValue($service, $handler);

		$entities = [['text' => 'Jan', 'type' => 'person']];
		$service->anonymizeDocument(node: $node, entities: $entities, scope: 'case', fileKey: 'k', preserveStructure: true);
		$service->anonymizeDocument($node, $entities, 'case', 'k', true);
		$service->anonymizeDocument($node, new DocumentAnonymization(entities: $entities, scope: 'case', fileKey: 'k', preserveStructure: true));

		$this->assertCount(3, $requests);
		$this->assertEquals($requests[2], $requests[0]);
		$this->assertEquals($requests[2], $requests[1]);
	}

	public function testDispatchEventGivesTheSameEventInEveryForm(): void {
		$events  = [];
		$service = $this->getMockBuilder(WebhookService::class)->disableOriginalConstructor()->onlyMethods(['dispatch'])->getMock();
		$service->method('dispatch')->willReturnCallback(
			static function (WebhookEvent $event) use (&$events): void {
				$events[] = $event;
			}
		);

		$service->dispatchEvent(_event: new Event(), eventName: 'Created', payload: ['a' => 1]);
		$service->dispatchEvent(new Event(), 'Created', ['a' => 1]);
		$service->dispatchEvent(eventName: 'Created', payload: ['a' => 1]);
		$service->dispatchEvent('Created', ['a' => 1]);

		$this->assertCount(4, $events);
		foreach ($events as $event) {
			$this->assertEquals(new WebhookEvent(name: 'Created', payload: ['a' => 1]), $event);
		}
	}

	public function testDispatchEventWithoutAPayloadIsRefused(): void {
		$service = $this->getMockBuilder(WebhookService::class)->disableOriginalConstructor()->onlyMethods(['dispatch'])->getMock();

		$this->expectException(\InvalidArgumentException::class);
		$service->dispatchEvent(eventName: 'Created');
	}

	public function testFlowItemGivesTheSameItemInEveryForm(): void {
		$expected = FlowItems::item(json: ['a' => 1], options: new FlowItemOptions(binary: ['f' => 'x'], fromItemIndex: 2, output: 'yes'));

		$this->assertSame($expected, FlowItems::item(json: ['a' => 1], binary: ['f' => 'x'], fromItemIndex: 2, output: 'yes'));
		$this->assertSame($expected, FlowItems::item(['a' => 1], ['f' => 'x'], 2, 'yes'));
		$this->assertSame($expected, FlowItems::item(['a' => 1], new FlowItemOptions(binary: ['f' => 'x'], fromItemIndex: 2, output: 'yes')));
		$this->assertSame(FlowItems::item(json: ['a' => 1]), FlowItems::item(['a' => 1], []));
	}

	/**
	 * What a registration context is asked, by method and service name.
	 *
	 * @param array<int, string> $log The log.
	 *
	 * @return IRegistrationContext
	 */
	private function context(array &$log): IRegistrationContext {
		$context = $this->createMock(IRegistrationContext::class);
		foreach ((new \ReflectionClass(IRegistrationContext::class))->getMethods() as $method) {
			$name = $method->getName();
			$context->method($name)->willReturnCallback(
				static function (mixed ...$args) use (&$log, $name): void {
					$first = ($args[0] ?? null);
					$log[] = $name . ':' . (is_string($first) === true ? $first : get_debug_type($first));
				}
			);
		}

		return $context;
	}//end context()

	public function testBootstrapRegistersTheSameInEveryForm(): void {
		$options = ['namespace' => 'OCA\\PetStore', 'deepLinks' => false];

		$release = [];
		Bootstrap::register($this->context($release), 'petstore', $options);
		Bootstrap::aliasStoreController(context: $this->context($release), appId: 'petstore', controllerNs: 'OCA\\PetStore\\Controller');

		$main = [];
		Bootstrap::register(context: $this->context($main), adoption: new LeafAdoption(appId: 'petstore', options: $options));
		Bootstrap::aliasStoreController($this->context($main), new LeafAdoption(appId: 'petstore', options: ['namespace' => 'OCA\\PetStore']));

		$this->assertNotSame([], $main);
		$this->assertSame($main, $release);
	}
}//end class

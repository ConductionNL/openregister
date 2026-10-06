<?php

declare(strict_types=1);

/**
 * The FolderManagementHandler registration hands the handler its folder recorder.
 *
 * `Application` builds FolderManagementHandler by hand (it breaks a circular
 * dependency with FileService), so autowiring does not cover it: a constructor
 * argument the closure forgets is an ArgumentCountError on the first file
 * operation of every request, which no handler unit test can see. This runs the
 * closure itself against a container double and checks what it built.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\AppInfo
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://www.OpenRegister.nl
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-registers-folder-is-created-on-its-first-upload-by-whoever-uploads-req-rffu-001
 */

namespace OCA\OpenRegister\Tests\Unit\AppInfo;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Db\RegisterFolderRecorder;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Wiring of the file handlers.
 */
class FolderManagementHandlerRegistrationTest extends TestCase {

	/**
	 * The registered factory builds a handler holding the container's recorder.
	 *
	 * @return void
	 */
	public function testTheRegistrationPassesTheFolderRecorder(): void {
		$factories = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);

		// Application::__construct() boots the app container, which a unit test has not got.
		$app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		(new ReflectionMethod(Application::class, 'registerCacheAndFileHandlers'))->invoke($app, $context);

		$this->assertArrayHasKey(FolderManagementHandler::class, $factories);

		$recorder = $this->createMock(RegisterFolderRecorder::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($recorder): object {
				if ($id === RegisterFolderRecorder::class) {
					return $recorder;
				}

				return $this->createMock($id);
			}
		);

		$handler = $factories[FolderManagementHandler::class]($container);

		$this->assertInstanceOf(FolderManagementHandler::class, $handler);
		$this->assertSame($recorder, (new ReflectionProperty(FolderManagementHandler::class, 'folderRecorder'))->getValue($handler));
	}//end testTheRegistrationPassesTheFolderRecorder()
}//end class

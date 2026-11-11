<?php

/**
 * Characterisation tests for how ObjectShareReader finds an object's folder
 * without creating it.
 *
 * Pins every branch of the private `existingFolder()`: no or a non-numeric
 * folder id means no folder and no lookup; the app's own lookup answering a
 * folder wins; a miss is confirmed against the root (a folder among the
 * nodes wins, a file does not count, none means no folder); a missing root
 * and a failing root lookup are refused with their exact messages.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\Rbac\ObjectShareReader;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Share\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden tests for existingFolder().
 */
class ObjectShareReaderExistingFolderGoldenTest extends TestCase {

	/**
	 * Run the private lookup.
	 *
	 * @param FolderManagementHandler $folders The app's folder lookup.
	 * @param IRootFolder|null        $root    The root, or null when none is wired.
	 * @param mixed                   $folder  The object's folder id.
	 *
	 * @return Folder|null
	 */
	private function lookUp(FolderManagementHandler $folders, ?IRootFolder $root, mixed $folder): ?Folder {
		$object = new ObjectEntity();
		$object->setFolder($folder);
		$reader = new ObjectShareReader($folders, $this->createMock(IManager::class), new NullLogger(), $root);

		return (new ReflectionMethod(ObjectShareReader::class, 'existingFolder'))->invoke($reader, $object);
	}//end lookUp()

	/**
	 * No usable folder id means no folder and no lookup.
	 *
	 * @return void
	 */
	public function testNoFolderIdMeansNoLookup(): void {
		$folders = $this->createMock(FolderManagementHandler::class);
		$folders->expects($this->never())->method('getNodeById');
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->never())->method('getById');

		$this->assertNull($this->lookUp($folders, $root, null));
		$this->assertNull($this->lookUp($folders, $root, 'abc'));
	}//end testNoFolderIdMeansNoLookup()

	/**
	 * The app's own lookup wins; a miss is confirmed against the root.
	 *
	 * @return void
	 */
	public function testAppLookupThenRoot(): void {
		$own = $this->createMock(Folder::class);
		$folders = $this->createMock(FolderManagementHandler::class);
		$folders->expects($this->once())->method('getNodeById')->with(42)->willReturn($own);
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->never())->method('getById');
		$this->assertSame($own, $this->lookUp($folders, $root, '42'));

		$found = $this->createMock(Folder::class);
		$folders = $this->createMock(FolderManagementHandler::class);
		$folders->method('getNodeById')->willReturn($this->createMock(File::class));
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->once())->method('getById')->with(42)->willReturn([$this->createMock(File::class), $found]);
		$this->assertSame($found, $this->lookUp($folders, $root, 42));

		$folders = $this->createMock(FolderManagementHandler::class);
		$folders->method('getNodeById')->willReturn(null);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getById')->willReturn([$this->createMock(File::class)]);
		$this->assertNull($this->lookUp($folders, $root, 42));
	}//end testAppLookupThenRoot()

	/**
	 * A missing root and a failing root lookup are refused.
	 *
	 * @return void
	 */
	public function testRootFailuresAreRefused(): void {
		$folders = $this->createMock(FolderManagementHandler::class);
		$folders->method('getNodeById')->willReturn(null);

		try {
			$this->lookUp($folders, null, 42);
			$this->fail('a lookup without a root answered');
		} catch (RuntimeException $e) {
			$this->assertSame('No read-only folder lookup is wired', $e->getMessage());
		}

		$root  = $this->createMock(IRootFolder::class);
		$cause = new \Exception('storage down');
		$root->method('getById')->willThrowException($cause);
		try {
			$this->lookUp($folders, $root, 42);
			$this->fail('a failing root lookup answered');
		} catch (RuntimeException $e) {
			$this->assertSame('The folder of an object could not be looked up', $e->getMessage());
			$this->assertSame($cause, $e->getPrevious());
		}
	}//end testRootFailuresAreRefused()
}//end class

<?php

/**
 * Tests for RegisterConfigurationLocator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\SharedSchema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\SharedSchema;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Service\SharedSchema\RegisterConfigurationLocator;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Exercises discovery, fragment merging and the register/schema slug matching
 * against real configuration documents written to a temporary app directory.
 *
 * @covers \OCA\OpenRegister\Service\SharedSchema\RegisterConfigurationLocator
 * @uses   \OCA\OpenRegister\Db\Register
 */
class RegisterConfigurationLocatorTest extends TestCase {

	/**
	 * Temporary app root.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * @var IAppManager&MockObject
	 */
	private IAppManager $appManager;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Create the temporary app tree.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/or-rcl-' . bin2hex(random_bytes(6));
		mkdir($this->root . '/lib/Settings/register.d', 0777, true);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getAppPath')->willReturnCallback(
			fn (string $appId): string => $appId === 'myapp'
				? $this->root
				: throw new AppPathNotFoundException($appId)
		);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Remove the temporary app tree.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach (['/lib/Settings/register.d', '/lib/Settings'] as $dir) {
			foreach ((glob($this->root . $dir . '/*') ?: []) as $file) {
				if (is_file($file) === true) {
					unlink($file);
				}
			}
		}

		rmdir($this->root . '/lib/Settings/register.d');
		rmdir($this->root . '/lib/Settings');
		rmdir($this->root . '/lib');
		rmdir($this->root);
	}//end tearDown()

	/**
	 * Write a settings document.
	 *
	 * @param string $relative Path below lib/Settings.
	 * @param mixed  $content  JSON-encodable content, or a raw string.
	 *
	 * @return void
	 */
	private function write(string $relative, mixed $content): void {
		$raw = is_string($content) === true ? $content : json_encode($content);
		file_put_contents($this->root . '/lib/Settings/' . $relative, $raw);
	}//end write()

	/**
	 * A register for an application and slug.
	 *
	 * @param string|null $app  The application id.
	 * @param string      $slug The register slug.
	 *
	 * @return Register
	 */
	private function register(?string $app, string $slug): Register {
		$register = new Register();
		$register->setApplication($app);
		$register->setSlug($slug);
		return $register;
	}//end register()

	/**
	 * The locator under test.
	 *
	 * @return RegisterConfigurationLocator
	 */
	private function locator(): RegisterConfigurationLocator {
		return new RegisterConfigurationLocator(appManager: $this->appManager, logger: $this->logger);
	}//end locator()

	/**
	 * A register with no application has no configuration and the app manager is not asked.
	 *
	 * @return void
	 */
	public function testRegisterWithoutApplicationResolvesNothing(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->never())->method('getAppPath');
		$locator = new RegisterConfigurationLocator(appManager: $appManager, logger: $this->logger);

		$this->assertSame([], $locator->schemasFor($this->register(app: null, slug: 'r')));
	}//end testRegisterWithoutApplicationResolvesNothing()

	/**
	 * An app that is not installed resolves nothing.
	 *
	 * @return void
	 */
	public function testUnknownAppResolvesNothing(): void {
		$this->assertSame([], $this->locator()->schemasFor($this->register(app: 'ghost', slug: 'r')));
	}//end testUnknownAppResolvesNothing()

	/**
	 * Only the schemas the register declares are returned, keyed by lowercased slug,
	 * and the register is matched case-insensitively by its slug field.
	 *
	 * @return void
	 */
	public function testReturnsOnlyDeclaredSchemasOfTheMatchingRegister(): void {
		$this->write(
			relative: 'myapp_register.json',
			content: [
				'components' => [
					'registers' => [
						'other' => ['slug' => 'other', 'schemas' => ['shared']],
						'mine'  => ['slug' => 'Mine', 'schemas' => ['Person', ['nested'], 'Address']],
						'junk'  => 'not-an-array',
					],
					'schemas'   => [
						'person'  => ['slug' => 'PERSON', 'title' => 'Person'],
						'address' => ['title' => 'Address'],
						'shared'  => ['slug' => 'shared'],
						'broken'  => 'not-an-array',
					],
				],
			]
		);

		$result = $this->locator()->schemasFor($this->register(app: 'myapp', slug: 'MINE'));

		$this->assertSame(
			[
				'person'  => ['slug' => 'PERSON', 'title' => 'Person'],
				'address' => ['title' => 'Address'],
			],
			$result
		);
	}//end testReturnsOnlyDeclaredSchemasOfTheMatchingRegister()

	/**
	 * Fragments in register.d are merged over the app's base document in filename order:
	 * maps merge, list entries append and later scalars win.
	 *
	 * @return void
	 */
	public function testFragmentsAreDeepMergedOverTheBaseDocumentInSortedOrder(): void {
		$this->write(
			relative: 'myapp_register.json',
			content: ['components' => ['registers' => ['mine' => ['schemas' => ['a']]], 'schemas' => ['a' => ['title' => 'base']]]]
		);
		$this->write(
			relative: 'register.d/20-late.json',
			content: ['components' => ['schemas' => ['a' => ['title' => 'late'], 'b' => ['title' => 'B']]]]
		);
		$this->write(
			relative: 'register.d/10-early.json',
			content: ['components' => ['registers' => ['mine' => ['schemas' => ['b']]], 'schemas' => ['a' => ['title' => 'early']]]]
		);
		$this->write(relative: 'register.d/30-broken.json', content: '{not json');
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('30-broken.json'));

		$result = $this->locator()->schemasFor($this->register(app: 'myapp', slug: 'mine'));

		$this->assertSame(['a' => ['title' => 'late'], 'b' => ['title' => 'B']], $result);
	}//end testFragmentsAreDeepMergedOverTheBaseDocumentInSortedOrder()

	/**
	 * Fragments are only merged into the app's own base document, never into the
	 * other `*_register.json` documents an app may ship.
	 *
	 * @return void
	 */
	public function testFragmentsAreNotMergedIntoForeignDocuments(): void {
		$this->write(
			relative: 'extra_register.json',
			content: ['components' => ['registers' => ['mine' => ['schemas' => ['a']]], 'schemas' => ['a' => ['title' => 'extra']]]]
		);
		$this->write(
			relative: 'register.d/10.json',
			content: ['components' => ['schemas' => ['a' => ['title' => 'fragment']]]]
		);

		$result = $this->locator()->schemasFor($this->register(app: 'myapp', slug: 'mine'));

		$this->assertSame(['a' => ['title' => 'extra']], $result);
	}//end testFragmentsAreNotMergedIntoForeignDocuments()

	/**
	 * Documents that do not describe the register are skipped until one does; an
	 * undecodable document is logged and skipped.
	 *
	 * @return void
	 */
	public function testSkipsDocumentsUntilOneDescribesTheRegister(): void {
		$this->write(relative: 'a_register.json', content: '"just a string"');
		$this->write(relative: 'b_register.json', content: ['components' => 'not-a-map']);
		$this->write(relative: 'c_register.json', content: ['components' => ['registers' => 'x', 'schemas' => []]]);
		$this->write(relative: 'd_register.json', content: ['components' => ['registers' => ['other' => []], 'schemas' => []]]);
		$this->write(
			relative: 'e_register.json',
			content: ['components' => ['registers' => ['mine' => ['schemas' => ['gone']]], 'schemas' => ['kept' => []]]]
		);
		$this->write(
			relative: 'f_register.json',
			content: ['components' => ['registers' => ['mine' => ['schemas' => ['kept']]], 'schemas' => ['kept' => ['title' => 'F']]]]
		);
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('a_register.json'));

		$result = $this->locator()->schemasFor($this->register(app: 'myapp', slug: 'mine'));

		$this->assertSame(['kept' => ['title' => 'F']], $result);
	}//end testSkipsDocumentsUntilOneDescribesTheRegister()

	/**
	 * When no document names the register, nothing is attributed.
	 *
	 * @return void
	 */
	public function testNoMatchingRegisterResolvesNothing(): void {
		$this->write(
			relative: 'myapp_register.json',
			content: ['components' => ['registers' => ['other' => ['schemas' => ['a']]], 'schemas' => ['a' => []]]]
		);

		$this->assertSame([], $this->locator()->schemasFor($this->register(app: 'myapp', slug: 'mine')));
	}//end testNoMatchingRegisterResolvesNothing()
}//end class

<?php

/**
 * DocumentStyleReader test.
 *
 * Pins that the reader of thematiq's document house style never fails the
 * export: it returns the profile when thematiq answers, and null when thematiq
 * is disabled, cannot be resolved, or throws (export-pdf-house-style, task 1.1).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/export-pdf-house-style/specs/data-import-export/spec.md
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Export;

use OCA\OpenRegister\Service\Export\DocumentStyleReader;
use OCA\OpenRegister\Tests\Unit\Service\Export\Odt\OdtFontTest;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

require_once __DIR__ . '/Stubs/ThematiqDocumentStyleService.php';
require_once __DIR__ . '/Stubs/ThematiqDocumentAssetService.php';
require_once __DIR__ . '/Stubs/ThematiqFontService.php';
require_once __DIR__ . '/Odt/OdtFontTest.php';
require_once __DIR__ . '/Odt/OdtLogoTest.php';

/**
 * @covers \OCA\OpenRegister\Service\Export\DocumentStyleReader
 * @uses   \OCA\OpenRegister\Service\Export\Odt\OdtLogo
 * @uses   \OCA\OpenRegister\Service\Export\Odt\OdtFont
 */
class DocumentStyleReaderTest extends TestCase {

	/**
	 * Build the reader over an app-enabled flag and a container that answers
	 * `get()` with the given value (an object to return, or a Throwable to
	 * throw).
	 *
	 * @param bool  $enabled      Whether thematiq is enabled.
	 * @param mixed $containerGet What the container's get() does.
	 *
	 * @return DocumentStyleReader The reader.
	 */
	private function reader(bool $enabled, mixed $containerGet = null): DocumentStyleReader {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn($enabled);

		$container = $this->createMock(ContainerInterface::class);
		if ($containerGet instanceof \Throwable) {
			$container->method('get')->willThrowException($containerGet);
		} else {
			$container->method('get')->willReturn($containerGet);
		}

		return new DocumentStyleReader($appManager, $container, new NullLogger());
	}//end reader()

	/**
	 * A thematiq service double whose forUser answers the given value or throws.
	 *
	 * @param mixed $answer The profile to return, or a Throwable to throw.
	 *
	 * @return object The double.
	 */
	private function styleService(mixed $answer): object {
		return new class($answer) {

			public function __construct(private mixed $answer) {
			}

			public function forUser(?string $uid): mixed {
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};
	}//end styleService()

	public function testThematiqDisabledGivesNullWithoutTouchingTheContainer(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(false);

		// A container that would answer with a real profile if it were asked —
		// the enabled check must short-circuit before any resolution.
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$reader = new DocumentStyleReader($appManager, $container, new NullLogger());

		$this->assertNull($reader->forUser(uid: 'alice'));
	}//end testThematiqDisabledGivesNullWithoutTouchingTheContainer()

	public function testAProfileIsReturnedWhenThematiqAnswers(): void {
		$profile = ['organisation' => 'Gemeente', 'colours' => ['primary' => '#123456']];
		$reader = $this->reader(enabled: true, containerGet: $this->styleService(answer: $profile));

		$this->assertSame($profile, $reader->forUser(uid: 'alice'));
	}//end testAProfileIsReturnedWhenThematiqAnswers()

	public function testTheInstanceDefaultIsReadForNoUser(): void {
		$profile = ['organisation' => 'Default'];
		$reader = $this->reader(enabled: true, containerGet: $this->styleService(answer: $profile));

		$this->assertSame($profile, $reader->forUser(uid: null));
	}//end testTheInstanceDefaultIsReadForNoUser()

	public function testAThrowingServiceGivesNull(): void {
		$reader = $this->reader(enabled: true, containerGet: $this->styleService(answer: new RuntimeException('boom')));

		$this->assertNull($reader->forUser(uid: 'alice'));
	}//end testAThrowingServiceGivesNull()

	public function testAnUnresolvableServiceGivesNull(): void {
		$reader = $this->reader(enabled: true, containerGet: new RuntimeException('not in container'));

		$this->assertNull($reader->forUser(uid: 'alice'));
	}//end testAnUnresolvableServiceGivesNull()

	public function testANonArrayAnswerGivesNull(): void {
		$reader = $this->reader(enabled: true, containerGet: $this->styleService(answer: 'not an array'));

		$this->assertNull($reader->forUser(uid: 'alice'));
	}//end testANonArrayAnswerGivesNull()

	// --- the logo (export-pdf-house-style, task 2.1) ---

	/**
	 * A reader whose container answers thematiq's asset store with the given uploaded logo.
	 *
	 * @param array|null|\Throwable $uploaded The asset store's answer to read('logo'), or a Throwable to throw.
	 * @param string|null           $appPath  Thematiq's install folder.
	 *
	 * @return DocumentStyleReader
	 */
	private function logoReader(array|null|\Throwable $uploaded, ?string $appPath = null): DocumentStyleReader {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('thematiq')->willReturn((string) $appPath);

		$assets = new class($uploaded) {

			public array $asked = [];

			public function __construct(private mixed $uploaded) {
			}

			public function read(string $kind): ?array {
				$this->asked[] = $kind;
				if ($this->uploaded instanceof \Throwable) {
					throw $this->uploaded;
				}

				return $this->uploaded;
			}
		};
		$this->assets = $assets;

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(DocumentStyleReader::ASSET_SERVICE)->willReturn($assets);

		return new DocumentStyleReader($appManager, $container, new NullLogger());
	}//end logoReader()

	/**
	 * The asset store double of the last logoReader().
	 *
	 * @var object|null
	 */
	private ?object $assets = null;

	public function testTheUploadedLogoIsReadFromThematiqsAssetStore(): void {
		$png = \OCA\OpenRegister\Tests\Unit\Service\Export\Odt\OdtLogoTest::png(width: 200, height: 100);
		$reader = $this->logoReader(uploaded: ['bytes' => $png, 'mime' => 'image/png']);

		$logo = $reader->logo(profile: ['logo' => ['url' => '/apps/thematiq/document-style/asset/logo?v=3', 'mime' => 'image/png']]);

		$this->assertSame($png, $logo?->bytes);
		$this->assertSame('image/png', $logo->mime);
		$this->assertSame(['logo'], $this->assets->asked);
	}

	public function testAProfileWithoutALogoReadsNothing(): void {
		$reader = $this->logoReader(uploaded: ['bytes' => 'x', 'mime' => 'image/png']);

		$this->assertNull($reader->logo(profile: null));
		$this->assertNull($reader->logo(profile: ['logo' => null]));
		$this->assertNull($reader->logo(profile: ['logo' => ['mime' => 'image/png']]));
		$this->assertSame([], $this->assets->asked);
	}

	public function testAnUploadedLogoThatFailsTheCheckGivesNoLogo(): void {
		$reader = $this->logoReader(uploaded: ['bytes' => '<svg xmlns="http://www.w3.org/2000/svg"><script/></svg>', 'mime' => 'image/svg+xml']);

		$this->assertNull($reader->logo(profile: ['logo' => ['url' => '/x', 'mime' => 'image/svg+xml']]));
	}

	public function testAThrowingAssetStoreGivesNoLogo(): void {
		$reader = $this->logoReader(uploaded: new RuntimeException('storage down'));

		$this->assertNull($reader->logo(profile: ['logo' => ['url' => '/apps/thematiq/img/logo.svg', 'mime' => 'image/svg+xml']]));
	}

	public function testATokenSetLogoIsReadFromThematiqsImageFolderOnly(): void {
		$root = sys_get_temp_dir() . '/or-thematiq-' . bin2hex(random_bytes(4));
		mkdir($root . '/img/sets', 0700, true);
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="10"/>';
		file_put_contents($root . '/img/sets/hilversum.svg', $svg);
		file_put_contents($root . '/secret.svg', $svg);

		try {
			$reader = $this->logoReader(uploaded: null, appPath: $root);
			$logo = $reader->logo(profile: ['logo' => ['url' => '/custom_apps/thematiq/img/sets/hilversum.svg?v=1', 'mime' => 'image/svg+xml']]);
			$this->assertSame($svg, $logo?->bytes);
			$this->assertSame(3.0, $logo->widthCm);

			$this->assertNull($reader->logo(profile: ['logo' => ['url' => '/custom_apps/thematiq/img/../secret.svg']]));
			$this->assertNull($reader->logo(profile: ['logo' => ['url' => '/custom_apps/thematiq/img/missing.svg']]));
			$this->assertNull($reader->logo(profile: ['logo' => ['url' => 'https://elders.example/logo.svg']]));
		} finally {
			unlink($root . '/img/sets/hilversum.svg');
			unlink($root . '/secret.svg');
			rmdir($root . '/img/sets');
			rmdir($root . '/img');
			rmdir($root);
		}
	}

	// --- the fonts (export-pdf-house-style, task 2.2) ---

	/**
	 * A reader whose container answers thematiq's font store with bytes per font id.
	 *
	 * @param array<string, string|null>|\Throwable $stored Font id => bytes, or a Throwable to throw.
	 *
	 * @return DocumentStyleReader
	 */
	private function fontReader(array|\Throwable $stored): DocumentStyleReader {
		$fonts = new class($stored) {

			public array $asked = [];

			public function __construct(private mixed $stored) {
			}

			public function readFontBytes(string $id): ?string {
				$this->asked[] = $id;
				if ($this->stored instanceof \Throwable) {
					throw $this->stored;
				}

				return ($this->stored[$id] ?? null);
			}
		};
		$this->fontStore = $fonts;

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(DocumentStyleReader::FONT_SERVICE)->willReturn($fonts);

		return new DocumentStyleReader($this->createMock(IAppManager::class), $container, new NullLogger());
	}//end fontReader()

	/**
	 * The font store double of the last fontReader().
	 *
	 * @var object|null
	 */
	private ?object $fontStore = null;

	/**
	 * A profile's fonts section as thematiq builds it.
	 *
	 * @param string|null $headingUrl The heading font URL.
	 * @param string|null $bodyUrl    The body font URL.
	 *
	 * @return array<string, mixed>
	 */
	private function fontProfile(?string $headingUrl, ?string $bodyUrl): array {
		return ['fonts' => [
			'heading' => ['family' => 'Gemeente Kop', 'url' => $headingUrl],
			'body' => ['family' => 'Gemeente Tekst', 'url' => $bodyUrl],
		]];
	}//end fontProfile()

	public function testUploadedFontsAreReadFromThematiqsFontStoreByTheirId(): void {
		$ttf = OdtFontTest::sfnt();
		$reader = $this->fontReader(stored: ['custom-gemeente-kop' => OdtFontTest::woff(), 'custom-gemeente-tekst' => $ttf]);

		$fonts = $reader->fonts(profile: $this->fontProfile(
			headingUrl: '/index.php/apps/thematiq/fonts/custom-gemeente-kop.woff2',
			bodyUrl: '/apps/thematiq/fonts/custom-gemeente-tekst.woff2?v=2'
		));

		$this->assertCount(2, $fonts);
		$this->assertSame(['heading', 'Gemeente Kop', $ttf], [$fonts[0]->role, $fonts[0]->family, $fonts[0]->bytes]);
		$this->assertSame(['body', 'Gemeente Tekst', $ttf], [$fonts[1]->role, $fonts[1]->family, $fonts[1]->bytes]);
		$this->assertSame(['custom-gemeente-kop', 'custom-gemeente-tekst'], $this->fontStore->asked);
	}

	public function testAFontThatFailsToLoadLeavesOnlyThatRoleOnTheDefault(): void {
		$reader = $this->fontReader(stored: ['custom-kop' => 'wOF2' . substr(OdtFontTest::sfnt(), 4), 'custom-tekst' => OdtFontTest::sfnt()]);

		$fonts = $reader->fonts(profile: $this->fontProfile(headingUrl: '/apps/thematiq/fonts/custom-kop.woff2', bodyUrl: '/apps/thematiq/fonts/custom-tekst.woff2'));

		$this->assertCount(1, $fonts);
		$this->assertSame('body', $fonts[0]->role);
	}

	public function testAMissingStoredFontGivesNoFont(): void {
		$reader = $this->fontReader(stored: []);

		$this->assertSame([], $reader->fonts(profile: $this->fontProfile(headingUrl: null, bodyUrl: '/apps/thematiq/fonts/custom-weg.woff2')));
		$this->assertSame(['custom-weg'], $this->fontStore->asked);
	}

	public function testAThrowingFontStoreGivesNoFonts(): void {
		$reader = $this->fontReader(stored: new RuntimeException('storage down'));

		$this->assertSame([], $reader->fonts(profile: $this->fontProfile(headingUrl: '/apps/thematiq/fonts/custom-kop.woff2', bodyUrl: '/apps/thematiq/fonts/custom-tekst.woff2')));
	}

	public function testOnlyThematiqsFontRouteIsRead(): void {
		$reader = $this->fontReader(stored: ['custom-kop' => OdtFontTest::sfnt()]);

		$this->assertSame([], $reader->fonts(profile: null));
		$this->assertSame([], $reader->fonts(profile: ['fonts' => 'Fira Sans']));
		// A library font carries a family and no URL; an outside URL and an id outside thematiq's pattern are not read.
		$this->assertSame([], $reader->fonts(profile: $this->fontProfile(headingUrl: null, bodyUrl: null)));
		$this->assertSame([], $reader->fonts(profile: $this->fontProfile(headingUrl: 'https://elders.example/thematiq/fonts/custom-kop.woff2x', bodyUrl: '/apps/thematiq/fonts/../custom-kop.woff2')));
		$this->assertSame([], $reader->fonts(profile: $this->fontProfile(headingUrl: '/apps/thematiq/fonts/Custom-Kop.woff2', bodyUrl: '/apps/thematiq/img/custom-kop.woff2')));
		$this->assertSame([], $this->fontStore->asked);
	}
}//end class

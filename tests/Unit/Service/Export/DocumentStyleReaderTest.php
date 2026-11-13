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
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

require_once __DIR__ . '/Stubs/ThematiqDocumentStyleService.php';

/**
 * @covers \OCA\OpenRegister\Service\Export\DocumentStyleReader
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
}//end class

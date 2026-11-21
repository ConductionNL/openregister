<?php

/**
 * Unit tests for resolving a form that cannot be read, and the default audience.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Form\FormDefinitionResolver;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A form that throws on read, or reads as nothing, is a 404 like any unknown form; an empty audience means public.
 *
 * @covers \OCA\OpenRegister\Service\Form\FormDefinitionResolver
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Exception\FormSubmitRefusedException
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 */
class FormDefinitionResolverMissingTest extends TestCase {

	/**
	 * A resolver whose object store behaves as given.
	 *
	 * @param callable $find The find behaviour.
	 *
	 * @return FormDefinitionResolver
	 */
	private function resolver(callable $find): FormDefinitionResolver {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willReturnCallback($find);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new FormDefinitionResolver($objects, $l10n);

	}//end resolver()

	/**
	 * Resolutions that end in a 404.
	 *
	 * @return array<string, array{0: callable}>
	 */
	public static function unreadable(): array {
		return [
			'the read throws'       => [static function (): never {
				throw new RuntimeException('db down');
			}],
			'the read finds nothing' => [static fn (): ?ObjectEntity => null],
		];

	}//end unreadable()

	/**
	 * An unreadable form is refused as not existing.
	 *
	 * @param callable $find The find behaviour.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('unreadable')]
	public function testAnUnreadableFormDoesNotExist(callable $find): void {
		try {
			$this->resolver($find)->resolve('form-1');
			$this->fail('an unreadable form resolved');
		} catch (FormSubmitRefusedException $e) {
			$this->assertSame('This form does not exist.', $e->getMessage());
			$this->assertSame(404, $e->getStatus());
		}

	}//end testAnUnreadableFormDoesNotExist()

	/**
	 * A published form with an empty audience is public.
	 *
	 * @return void
	 */
	public function testAnEmptyAudienceIsPublic(): void {
		$form = new ObjectEntity();
		$form->setObject(['status' => 'published', 'audience' => '', 'writes' => [['register' => 'r', 'schema' => 's']]]);

		$resolved = $this->resolver(static fn (): ObjectEntity => $form)->resolve('form-1');

		$this->assertSame('public', $resolved['audience']);
		$this->assertSame([['register' => 'r', 'schema' => 's']], $resolved['writes']);

	}//end testAnEmptyAudienceIsPublic()
}//end class

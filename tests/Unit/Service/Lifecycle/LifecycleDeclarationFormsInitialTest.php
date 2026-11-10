<?php

/**
 * Unit tests for validating the `initial` declaration of a lifecycle annotation.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

use OCA\OpenRegister\Service\Lifecycle\LifecycleDeclarationForms;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `initial` is a state name, or an object naming both `from` and `field`; anything else is malformed.
 *
 * @covers \OCA\OpenRegister\Service\Lifecycle\LifecycleDeclarationForms
 */
class LifecycleDeclarationFormsInitialTest extends TestCase {

	/**
	 * Initial declarations and the error code they get (null when valid).
	 *
	 * @return array<string, array{0: mixed, 1: string|null, 2: string|null}>
	 */
	public static function initials(): array {
		$scalar = 'x-openregister-lifecycle.initial must be a string or an object with "from" and "field".';
		$object = 'x-openregister-lifecycle.initial object form must declare non-empty "from" and "field" strings.';

		return [
			'a state name'          => ['new', null, null],
			'from and field'        => [['from' => 'intake', 'field' => 'status'], null, null],
			'a number'              => [42, 'lifecycle-initial-malformed', $scalar],
			'null'                  => [null, 'lifecycle-initial-malformed', $scalar],
			'field missing'         => [['from' => 'intake'], 'lifecycle-initial-malformed', $object],
			'empty from'            => [['from' => '', 'field' => 'status'], 'lifecycle-initial-malformed', $object],
			'field not a string'    => [['from' => 'intake', 'field' => ['status']], 'lifecycle-initial-malformed', $object],
		];

	}//end initials()

	/**
	 * Each declaration is accepted or refused with the matching message.
	 *
	 * @param mixed       $initial The declaration.
	 * @param string|null $code    The expected code.
	 * @param string|null $message The expected message.
	 *
	 * @return void
	 */
	#[DataProvider('initials')]
	public function testTheInitialDeclarationIsValidated(mixed $initial, ?string $code, ?string $message): void {
		$error = (new LifecycleDeclarationForms())->validateInitialForm($initial);

		if ($code === null) {
			$this->assertNull($error);
			return;
		}

		$this->assertSame(['code' => $code, 'message' => $message], $error);

	}//end testTheInitialDeclarationIsValidated()
}//end class

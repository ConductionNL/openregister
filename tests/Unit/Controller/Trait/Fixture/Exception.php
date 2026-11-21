<?php

/**
 * A domain exception whose class is named just `Exception`.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Trait\Fixture
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Trait\Fixture;

/**
 * Argument-shaped, so the trait maps it to 400.
 */
class Exception extends \InvalidArgumentException {
}//end class

<?php

/**
 * Why an encrypted instance set could not be opened.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Exchange
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
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-a-load-checks-the-whole-set-before-writing-anything
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Exchange;

use RuntimeException;

/**
 * An encrypted set that cannot be opened.
 *
 * One message for every cause — a changed byte, a changed header, a missing
 * end, a wrong key — so the answer tells nobody which part to work on.
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-a-load-checks-the-whole-set-before-writing-anything
 */
class EncryptedSetException extends RuntimeException {

	/**
	 * The one message every refusal carries.
	 *
	 * @var string
	 */
	public const MESSAGE = 'This file was changed or the key is wrong.';

	/**
	 * Constructor.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-a-load-checks-the-whole-set-before-writing-anything
	 */
	public function __construct() {
		parent::__construct(message: self::MESSAGE);
	}//end __construct()
}//end class

<?php

/**
 * OpenRegister SIP Package Run
 *
 * The state of building one SIP package: which part, its objects, its contents.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

/**
 * One package being built by {@see SipPackageBuilder}: the part of the
 * transfer, the objects it holds and the contents collected for it.
 *
 * A run object (ADR-011 Rule 3): it never leaves SipPackageBuilder.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
 */
final class SipPackageRun {

	/**
	 * The package contents, collected as the objects are added.
	 *
	 * @var SipContents
	 */
	public readonly SipContents $contents;

	/**
	 * Constructor.
	 *
	 * @param SipPart                          $part             Which package of the transfer this is.
	 * @param array<int, array<string, mixed>> $objectsWithFiles The objects (with their files) of this package.
	 */
	public function __construct(
		public readonly SipPart $part,
		public readonly array $objectsWithFiles,
	) {
		$this->contents = new SipContents();
	}//end __construct()
}//end class

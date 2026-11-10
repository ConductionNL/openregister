<?php

/**
 * OpenRegister ReadCaptureRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One capture of ProcessingLogService: the read, the object it is anchored on, the objects read and the effective configuration.
 *
 * Never leaves ProcessingLogService.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
final class ReadCaptureRun {

	/**
	 * Constructor.
	 *
	 * @param ProcessingRead           $read    The read being recorded.
	 * @param ObjectEntity             $anchor  The object whose schema and register decide.
	 * @param array<int|string, mixed> $objects The objects read (the anchor alone for a single read).
	 * @param array<string, mixed>     $config  The effective processing configuration.
	 */
	public function __construct(
		public readonly ProcessingRead $read,
		public readonly ObjectEntity $anchor,
		public readonly array $objects,
		public readonly array $config,
	) {

	}//end __construct()
}//end class

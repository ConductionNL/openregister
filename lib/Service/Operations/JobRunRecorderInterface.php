<?php

/**
 * JobRunRecorderInterface: what callers outside the Service component ask of JobRunRecorder.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

use OCA\OpenRegister\Kernel\Contracts\Core\JobRunRecordingInterface;

/**
 * Recording a job run around its execution, as the recorded job bases and
 * their subclasses use it.
 *
 * The method is the Core-owned JobRunRecordingInterface's, which the recorded
 * job base asks; this name stays for the callers that already type against it.
 *
 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
 */
interface JobRunRecorderInterface extends JobRunRecordingInterface {
}//end interface

<?php

/**
 * FlowVersionServiceInterface: what callers outside the Service component ask of FlowVersionService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
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

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowVersion;
use Throwable;

/**
 * Flow definition versioning, as the flow controller and the scheduled-flow
 * store use it. Only the methods those callers call.
 */
interface FlowVersionServiceInterface {

	/**
	 * Every version of a flow, newest first.
	 *
	 * @param string $flowUuid The flow.
	 *
	 * @return FlowVersion[] The versions.
	 */
	public function versionsOf(string $flowUuid): array;

	/**
	 * One version of a flow by its number.
	 *
	 * @param string  $flowUuid The flow.
	 * @param integer $number   The version number.
	 *
	 * @return FlowVersion|null The version, or null.
	 */
	public function versionOf(string $flowUuid, int $number): ?FlowVersion;

	/**
	 * The graph a version holds.
	 *
	 * @param FlowVersion $version The version.
	 *
	 * @return array<string, mixed>|null The graph, or null when unresolvable.
	 */
	public function graphOfVersion(FlowVersion $version): ?array;

	/**
	 * Publish the flow's head, deprecating whatever was published before it.
	 *
	 * @param Flow        $flow        The flow whose head is being published.
	 * @param string|null $publishedBy The acting user.
	 * @param string|null $bump        `major` to raise the derived verdict,
	 *                                 `minor` to assert there was no removal.
	 *                                 Null lets the diff decide.
	 *
	 * @return FlowVersion The now-published version.
	 *
	 * @throws FlowLifecycleRefused When the head is not a draft, or has a dead end.
	 * @throws Throwable            When the transaction could not be committed.
	 */
	public function publish(Flow $flow, ?string $publishedBy = null, ?string $bump = null): FlowVersion;

	/**
	 * Copy the published graph into a new draft at version N+1.
	 *
	 * @param Flow $flow The flow to draft from.
	 *
	 * @return FlowVersion The new draft version row.
	 *
	 * @throws Throwable When the transaction could not be committed.
	 */
	public function createDraft(Flow $flow): FlowVersion;

	/**
	 * Retire the published version, leaving the flow backing no new runs.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return FlowVersion The deprecated version.
	 *
	 * @throws FlowLifecycleRefused When there is no published version.
	 * @throws Throwable            When the transaction could not be committed.
	 */
	public function deprecate(Flow $flow): FlowVersion;

	/**
	 * What publishing this flow's head would be called, without publishing it.
	 *
	 * @param Flow $flow The flow whose head would be published.
	 *
	 * @return array{
	 *     verdict: string,
	 *     next: string,
	 *     removed: string,
	 *     removedNodes: array<int, string>,
	 *     removedEdges: array<int, string>,
	 *     removedKeys: array<int, string>,
	 *     first: bool,
	 *     current: string|null
	 * } What the publish would be called, and what it takes away.
	 */
	public function previewPublish(Flow $flow): array;
}//end interface

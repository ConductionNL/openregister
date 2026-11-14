/**
 * Publish a local configuration to GitLab: the request body the
 * `POST /api/configurations/{id}/publish/gitlab` endpoint takes.
 *
 * GitLab names a project by its full path, so the form asks for one
 * "group/project" value and this splits it at the last slash: everything
 * before it is the namespace, which may itself hold subgroups.
 *
 * @spec openspec/changes/capability-rows-verified-b/tasks.md#task-2.1
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * The namespace and project of a GitLab project path.
 *
 * @param {string} path The project path, such as `group/sub/project`.
 * @return {{namespace: string, project: string}|null} The parts, or null when the path names no project.
 */
export function gitlabProject(path) {
	const trimmed = String(path || '').trim().replace(/^\/+|\/+$/g, '')
	const slash = trimmed.lastIndexOf('/')
	if (slash <= 0 || slash === trimmed.length - 1) {
		return null
	}
	return { namespace: trimmed.slice(0, slash), project: trimmed.slice(slash + 1) }
}

/**
 * The body of a GitLab publish request.
 *
 * @param {{repository: string, branch: string, filePath: string, commitMessage: string}} form The form values.
 * @return {object} The request body, without empty optional values so the server's defaults apply.
 * @throws {Error} When the repository names no project.
 */
export function gitlabPublishBody(form) {
	const project = gitlabProject(form.repository)
	if (project === null) {
		throw new Error('Namespace and project parameters are required')
	}
	const body = { namespace: project.namespace, project: project.project }
	for (const [key, value] of [['branch', form.branch], ['path', form.filePath], ['commitMessage', form.commitMessage]]) {
		const trimmed = String(value || '').trim()
		if (trimmed !== '') {
			body[key] = trimmed
		}
	}
	return body
}

/**
 * The GitLab publish request: a "group/project" path splits at its last slash,
 * subgroups stay in the namespace, empty optional values are left to the
 * server's defaults, and a path that names no project is refused.
 *
 * @spec openspec/changes/capability-rows-verified-b/tasks.md#task-2.1
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { gitlabProject, gitlabPublishBody } from './gitlabPublish.js'

describe('gitlabProject', () => {
	it('splits a path at its last slash, keeping subgroups in the namespace', () => {
		expect(gitlabProject('conduction/config')).toEqual({ namespace: 'conduction', project: 'config' })
		expect(gitlabProject(' /gemeente/team/registers/ ')).toEqual({ namespace: 'gemeente/team', project: 'registers' })
	})

	it('names no project without both parts', () => {
		for (const path of ['', 'config', '/config', 'group/', undefined, null]) {
			expect(gitlabProject(path)).toBeNull()
		}
	})
})

describe('gitlabPublishBody', () => {
	it('sends the namespace, project and the filled-in options', () => {
		expect(gitlabPublishBody({ repository: 'gemeente/team/registers', branch: ' develop ', filePath: 'config/zaken.json', commitMessage: 'Add zaken' }))
			.toEqual({ namespace: 'gemeente/team', project: 'registers', branch: 'develop', path: 'config/zaken.json', commitMessage: 'Add zaken' })
	})

	it('leaves empty options to the server defaults', () => {
		expect(gitlabPublishBody({ repository: 'conduction/config', branch: '', filePath: '  ', commitMessage: '' }))
			.toEqual({ namespace: 'conduction', project: 'config' })
	})

	it('refuses a path that names no project', () => {
		expect(() => gitlabPublishBody({ repository: 'config' })).toThrow('Namespace and project parameters are required')
	})
})

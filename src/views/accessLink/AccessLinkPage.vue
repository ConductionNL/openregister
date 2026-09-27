<template>
	<main class="access-link-page">
		<NcLoadingIcon v-if="state === 'loading'" :size="44" />

		<NcEmptyContent
			v-else-if="state === 'gone'"
			:name="t('openregister', 'This link does not open anything')"
			:description="
				t(
					'openregister',
					'It may have expired, been switched off or been revoked. The person who sent it can make a new one.',
				)
			" />

		<form
			v-else-if="state === 'password'"
			class="access-link-page__password"
			@submit.prevent="open">
			<h2>{{ t('openregister', 'This link is closed with a password') }}</h2>
			<NcPasswordField
				v-model="password"
				:label="t('openregister', 'Password')"
				autocomplete="current-password" />
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcButton type="submit" variant="primary" :disabled="password === ''">
				{{ t('openregister', 'Open') }}
			</NcButton>
		</form>

		<template v-else-if="state === 'open'">
			<header class="access-link-page__header">
				<h2>
					{{ body.link.label || t('openregister', 'Shared with you') }}
				</h2>
				<p v-if="body.link.expiresAt" class="access-link-page__expiry">
					{{
						t('openregister', 'This link is open until {date}.', {
							date: formatDate(body.link.expiresAt),
						})
					}}
				</p>
			</header>

			<section v-if="body.subject" class="access-link-page__record">
				<dl>
					<template v-for="field in fields" :key="field.key">
						<dt>{{ field.key }}</dt>
						<dd>{{ field.value }}</dd>
					</template>
				</dl>
				<p v-if="fields.length === 0">
					{{ t('openregister', 'This record has no visible fields.') }}
				</p>
			</section>

			<section v-if="body.results" class="access-link-page__records">
				<dl
					v-for="row in body.results"
					:key="row.id"
					class="access-link-page__row">
					<template v-for="field in fieldsOf(row)" :key="field.key">
						<dt>{{ field.key }}</dt>
						<dd>{{ field.value }}</dd>
					</template>
				</dl>
			</section>

			<section v-if="body.file" class="access-link-page__file">
				<h3>{{ t('openregister', 'File') }}</h3>
				<a
					v-if="body.file.downloadUrl || body.file.accessUrl"
					:href="body.file.downloadUrl || body.file.accessUrl">
					{{
						body.file.title
						|| body.file.name
						|| t('openregister', 'Download')
					}}
				</a>
				<span v-else>{{ body.file.title || body.file.name }}</span>
			</section>

			<section v-if="body.timeline" class="access-link-page__timeline">
				<h3>{{ t('openregister', 'Comments') }}</h3>
				<p v-if="body.timeline.length === 0">
					{{ t('openregister', 'No comments yet.') }}
				</p>
				<ul v-else>
					<li v-for="entry in body.timeline" :key="entry.id">
						<span class="access-link-page__moment">{{
							formatDate(entry.occurredAt)
						}}</span>
						{{ entry.message }}
					</li>
				</ul>
			</section>

			<form
				v-if="may('comment')"
				class="access-link-page__comment"
				@submit.prevent="comment">
				<NcTextArea
					v-model="message"
					:label="t('openregister', 'Comment')" />
				<NcButton type="submit" :disabled="message.trim() === '' || busy">
					{{ t('openregister', 'Comment') }}
				</NcButton>
			</form>

			<form
				v-if="may('upload')"
				class="access-link-page__upload"
				@submit.prevent="upload">
				<label for="access-link-file">{{ t('openregister', 'File') }}</label>
				<input
					id="access-link-file"
					ref="file"
					type="file"
					@change="picked = $event.target.files[0] || null" />
				<NcButton type="submit" :disabled="!picked || busy">
					{{ t('openregister', 'Upload') }}
				</NcButton>
			</form>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="notice" type="success">
				{{ notice }}
			</NcNoteCard>
		</template>
	</main>
</template>

<script>
import axios from '@nextcloud/axios'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcPasswordField,
	NcTextArea,
} from '@nextcloud/vue'
import {
	fileAsBase64,
	passwordOptions,
	publicLinkUrl,
	readableFields,
} from '../../services/accessLinks.js'

/**
 * What the holder of an access link sees: the record, view or file the link
 * opens, its public comments, and a comment box and upload field when the
 * link allows them. Replaces the raw JSON the link used to answer with.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */
export default {
	name: 'AccessLinkPage',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcPasswordField,
		NcTextArea,
	},

	props: {
		/** The anchor from the page URL. */
		anchor: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			state: 'loading',
			body: null,
			password: '',
			message: '',
			picked: null,
			busy: false,
			error: '',
			notice: '',
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
		 * @return {Array<{key: string, value: string}>} The record's readable fields.
		 */
		fields() {
			return readableFields(this.body?.subject)
		},
	},

	mounted() {
		this.open()
	},

	methods: {
		t,
		n,
		/**
		 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
		 * @return {Promise<void>}
		 */
		async open() {
			this.error = ''
			try {
				const response = await axios.get(
					publicLinkUrl(this.anchor),
					passwordOptions(this.password),
				)
				this.body = response.data
				this.state = 'open'
			} catch (e) {
				const status = e?.response?.status
				if (status === 401) {
					if (this.state === 'password') {
						this.error = t('openregister', 'That password is not right.')
					}
					this.state = 'password'
					return
				}
				this.state = 'gone'
			}
		},

		/**
		 * @spec exclude UI display helper: readable fields of one view row.
		 * @param {object} row A record.
		 * @return {Array<{key: string, value: string}>} The fields.
		 */
		fieldsOf(row) {
			return readableFields(row)
		},

		/**
		 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
		 * @param {string} capability The capability.
		 * @return {boolean} Whether the link declares it.
		 */
		may(capability) {
			return (this.body?.link?.capabilities || []).includes(capability)
		},

		/**
		 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
		 * @return {Promise<void>}
		 */
		async comment() {
			await this.act(async () => {
				await axios.post(
					publicLinkUrl(this.anchor, '/comments'),
					{ message: this.message },
					passwordOptions(this.password),
				)
				this.message = ''
				this.notice = t('openregister', 'Thank you, it was added.')
			})
		},

		/**
		 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
		 * @return {Promise<void>}
		 */
		async upload() {
			const file = this.picked
			if (!file) {
				return
			}
			await this.act(async () => {
				const content = await fileAsBase64(file)
				await axios.post(
					publicLinkUrl(this.anchor, '/files'),
					{ name: file.name, content, encoding: 'base64' },
					passwordOptions(this.password),
				)
				this.picked = null
				if (this.$refs.file) {
					this.$refs.file.value = ''
				}
				this.notice = t('openregister', 'Thank you, it was added.')
			})
		},

		/**
		 * @spec exclude UI display helper: runs one holder act and reloads.
		 * @param {() => Promise<void>} work The act.
		 * @return {Promise<void>}
		 */
		async act(work) {
			this.busy = true
			this.error = ''
			this.notice = ''
			try {
				await work()
				await this.open()
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| t('openregister', 'That did not work. Try again later.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * @spec exclude UI display helper: a readable date.
		 * @param {string} value An ISO date.
		 * @return {string} The date.
		 */
		formatDate(value) {
			return value ? new Date(value).toLocaleString() : ''
		},
	},
}
</script>

<style scoped>
.access-link-page {
	max-width: 760px;
	margin: 0 auto;
	padding: 24px 16px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.access-link-page dl {
	display: grid;
	grid-template-columns: minmax(120px, max-content) 1fr;
	gap: 4px 16px;
}

.access-link-page dt {
	font-weight: bold;
}

.access-link-page dd {
	margin: 0;
	overflow-wrap: anywhere;
}

.access-link-page__row {
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.access-link-page__expiry,
.access-link-page__moment {
	color: var(--color-text-maxcontrast);
}

.access-link-page__password,
.access-link-page__comment,
.access-link-page__upload {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 480px;
}

.access-link-page__timeline ul {
	list-style: none;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}
</style>

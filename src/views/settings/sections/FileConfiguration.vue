<template>
	<SettingsSection
		id="section-text-extraction"
		:name="t('openregister', 'Text Extraction')"
		description="Configure extraction pipelines for files, objects, and detected entities"
		:loading="settingsStore.loadingFileSettings"
		:loadingMessage="t('openregister', 'Loading text extraction settings…')">
		<template #actions>
			<!-- File Actions Menu -->
			<NcActions
				:aria-label="t('openregister', 'File actions menu')"
				:menuName="t('openregister', 'Actions')">
				<template #icon>
					<DotsVertical :size="20" />
				</template>

				<!-- Discover Files -->
				<NcActionButton :disabled="isProcessing" @click="discoverFiles">
					<template #icon>
						<span class="action-icon-wrapper">
							<NcLoadingIcon v-if="discoveringFiles" :size="20" />
							<MagnifyIcon v-else :size="20" />
						</span>
					</template>
					{{ t('openregister', 'Discover Files') }}
				</NcActionButton>

				<!-- Extract Pending Files -->
				<NcActionButton
					:disabled="isProcessing"
					@click="extractAllPendingFiles">
					<template #icon>
						<span class="action-icon-wrapper">
							<NcLoadingIcon v-if="extractingFiles" :size="20" />
							<FileDocumentIcon v-else :size="20" />
						</span>
					</template>
					{{ t('openregister', 'Extract Pending Files') }}
				</NcActionButton>

				<!-- Retry Failed Extractions -->
				<NcActionButton
					:disabled="isProcessing"
					@click="reprocessFailedFiles">
					<template #icon>
						<span class="action-icon-wrapper">
							<NcLoadingIcon v-if="retryingFiles" :size="20" />
							<RefreshIcon v-else :size="20" />
						</span>
					</template>
					{{ t('openregister', 'Retry Failed Extractions') }}
				</NcActionButton>
			</NcActions>
		</template>

		<!-- Section Description -->
		<div class="section-description-full">
			<p class="main-description">
				Text extraction converts uploaded files, object attachments, and
				captured communications into searchable and AI-processable content.
				Choose from <strong>LLPhant</strong> (local PHP processing, best for
				simple files) or <strong>Dolphin AI</strong> (ByteDance API, best for
				complex documents, OCR, tables, and formulas). Extracted text is
				split into chunks for embeddings and semantic search.
			</p>
			<p class="main-description info-note">
				<strong>📝 Note:</strong> Text extraction is
				<strong>required</strong> before LLM vectorization. The process flow
				is: File/Object Upload → Text Extraction → Chunking → Embedding
				Creation. Without text extraction enabled, content cannot be
				vectorized for semantic search.
			</p>
		</div>

		<!-- Text Extraction Settings -->
		<SettingsCard
			:title="t('openregister', 'Text Extraction')"
			icon="📄"
			:collapsible="true"
			:defaultCollapsed="true">
			<div class="settings-group compact">
				<div class="setting-item">
					<label for="extraction-scope">Extract Text From</label>
					<LabeledSelect
						v-model="fileSettings.extractionScope"
						inputId="extraction-scope"
						:inputLabel="t('openregister', 'Extraction Scope')"
						:options="extractionScopes"
						@update:modelValue="saveSettings" />
					<p class="setting-description">
						Choose which files should have text extracted for search and
						AI features.
					</p>
				</div>

				<div class="setting-item">
					<label for="text-extractor">Text Extractor</label>
					<LabeledSelect
						v-model="fileSettings.textExtractor"
						inputId="text-extractor"
						:inputLabel="t('openregister', 'Text Extraction Engine')"
						:disabled="fileSettings.extractionScope.id === 'none'"
						:options="textExtractors"
						withIcon
						@update:modelValue="saveSettings" />
					<p class="setting-description">
						Choose the text extraction engine for processing documents.
					</p>
				</div>

				<!-- Dolphin API Configuration (only shown when Dolphin is selected) -->
				<div
					v-if="fileSettings.textExtractor.id === 'dolphin'"
					class="setting-item api-config">
					<div class="api-fields">
						<div class="field-group">
							<label for="dolphin-endpoint"
								>Dolphin API Endpoint</label
							>
							<NcTextField
								id="dolphin-endpoint"
								v-model="fileSettings.dolphinApiEndpoint"
								placeholder="https://api.your-dolphin-instance.com"
								@update:modelValue="saveSettings">
								<template #trailing-button-icon>
									<InformationIcon :size="20" />
								</template>
							</NcTextField>
							<p class="field-hint">
								URL to your Dolphin API instance
							</p>
						</div>

						<div class="field-group">
							<label for="dolphin-key">Dolphin API Key</label>
							<NcTextField
								id="dolphin-key"
								v-model="fileSettings.dolphinApiKey"
								type="password"
								:placeholder="
									t('openregister', 'Enter your API key')
								"
								@update:modelValue="saveSettings">
								<template #trailing-button-icon>
									<KeyIcon :size="20" />
								</template>
							</NcTextField>
							<p class="field-hint">
								Your Dolphin API authentication key
							</p>
						</div>

						<NcButton variant="secondary" @click="testDolphinConnection">
							<template #icon>
								<ConnectionTestIcon :result="dolphinConnectionTested" />
							</template>
							Test Connection
						</NcButton>
					</div>
				</div>

				<div class="setting-item">
					<label for="extraction-mode">Extraction Mode</label>
					<LabeledSelect
						v-model="fileSettings.extractionMode"
						inputId="extraction-mode"
						:inputLabel="t('openregister', 'Extraction Mode')"
						:disabled="fileSettings.extractionScope.id === 'none'"
						:options="extractionModes"
						@update:modelValue="saveSettings" />
					<p class="setting-description">
						Control when extraction happens relative to file upload.
					</p>
				</div>
			</div>

			<!-- Processing Limits -->
			<div class="processing-limits-section">
				<h5>⚙️ Processing Limits</h5>

				<!-- First row: File size, chunking strategy, batch size -->
				<div class="settings-group">
					<div class="setting-item">
						<label for="max-file-size">Maximum File Size (MB)</label>
						<input
							id="max-file-size"
							v-model.number="fileSettings.maxFileSize"
							type="number"
							min="1"
							max="500"
							@change="saveSettings" />
						<p class="setting-description">
							Maximum file size for text extraction (1-500 MB)
						</p>
					</div>

					<div class="setting-item">
						<label for="chunking-strategy">Chunking Strategy</label>
						<select
							id="chunking-strategy"
							v-model="fileSettings.chunkingStrategy"
							@change="saveSettings">
							<option value="RECURSIVE_CHARACTER">
								Recursive Character Split
							</option>
							<option value="CHARACTER">Character Split</option>
							<option value="TOKEN">Token Split</option>
							<option value="SENTENCE">Sentence Split</option>
						</select>
						<p class="setting-description">
							How to split text into chunks for processing
						</p>
					</div>

					<div class="setting-item">
						<label for="batch-size">Batch Processing Size</label>
						<input
							id="batch-size"
							v-model.number="fileSettings.batchSize"
							type="number"
							min="1"
							max="100"
							@change="saveSettings" />
						<p class="setting-description">
							Number of files to process in parallel background jobs
						</p>
					</div>
				</div>

				<!-- Second row: Chunk size and overlap -->
				<div class="settings-group" style="margin-top: 16px">
					<div class="setting-item">
						<label for="chunk-size">Chunk Size (characters)</label>
						<input
							id="chunk-size"
							v-model.number="fileSettings.chunkSize"
							type="number"
							min="100"
							max="10000"
							@change="saveSettings" />
						<p class="setting-description">
							Size of text chunks for processing and embeddings
							(100-10000 characters)
						</p>
					</div>

					<div class="setting-item">
						<label for="chunk-overlap">Chunk Overlap (characters)</label>
						<input
							id="chunk-overlap"
							v-model.number="fileSettings.chunkOverlap"
							type="number"
							min="0"
							max="1000"
							@change="saveSettings" />
						<p class="setting-description">
							Overlap between consecutive chunks to maintain context
							(0-1000 characters)
						</p>
					</div>

					<div class="setting-item">
						<label>Search Integration</label>
						<NcCheckboxRadioSwitch
							v-model="fileSettings.includeInSearch"
							type="switch"
							@update:modelValue="saveSettings">
							Include in Search Results
						</NcCheckboxRadioSwitch>
						<p class="setting-description">
							Files appear in Nextcloud's global search
						</p>
					</div>
				</div>
			</div>
		</SettingsCard>

		<!-- Object Text Extraction Settings -->
		<SettingsCard
			:title="t('openregister', 'Object Text Extraction')"
			icon="📦"
			:collapsible="true"
			:defaultCollapsed="true">
			<div class="settings-group compact">
				<div class="setting-item">
					<label for="object-extraction-mode">Extraction Mode</label>
					<LabeledSelect
						v-model="objectSettings.extractionMode"
						inputId="object-extraction-mode"
						:inputLabel="t('openregister', 'Object Extraction Mode')"
						:options="extractionModes"
						@update:modelValue="saveObjectSettings" />
					<p class="setting-description">
						Control when text extraction happens for OpenRegister
						objects. Objects contain structured data that is converted to
						searchable text chunks.
					</p>
				</div>
			</div>
		</SettingsCard>

		<!-- Entity Recognition Settings -->
		<SettingsCard
			:title="t('openregister', 'Entity Recognition')"
			icon="🔍"
			:collapsible="true"
			:defaultCollapsed="true">
			<div class="settings-group compact">
				<!-- Enable/Disable Toggle -->
				<div class="setting-item">
					<NcCheckboxRadioSwitch
						v-model="fileSettings.entityRecognitionEnabled"
						type="switch"
						@update:modelValue="saveSettings">
						Enable Entity Recognition
					</NcCheckboxRadioSwitch>
					<p class="setting-description">
						Detect personal data (names, emails, phone numbers, IBAN,
						BSN) in extracted text for GDPR compliance.
					</p>
				</div>

				<!-- Method Selector -->
				<div
					v-if="fileSettings.entityRecognitionEnabled"
					class="setting-item">
					<label for="entity-recognition-method">Detection Method</label>
					<LabeledSelect
						v-model="fileSettings.entityRecognitionMethod"
						inputId="entity-recognition-method"
						:inputLabel="t('openregister', 'Entity Recognition Method')"
						:options="entityRecognitionMethods"
						withIcon
						@update:modelValue="saveSettings" />
					<p class="setting-description">
						Choose how entities are detected. Regex is fast and local;
						external services provide better accuracy for names and
						context.
					</p>
				</div>

				<!-- Backend availability (single source of truth: GET backend-state) -->
				<div
					v-if="fileSettings.entityRecognitionEnabled && backendState"
					class="setting-item">
					<label>{{ t('openregister', 'Detection backends') }}</label>
					<p class="setting-description backend-summary" role="status">
						{{
							t('openregister', 'Active method: {active}', {
								active: activeMethodLabel,
							})
						}}
						<span class="backend-summary-sep">·</span>
						{{
							t('openregister', 'Effective: {effective}', {
								effective: effectiveMethodLabel,
							})
						}}
						<span
							v-if="effectiveDiffersFromActive"
							class="backend-badge is-unavailable">
							{{ t('openregister', 'falling back') }}
						</span>
					</p>
					<ul class="backend-status-list">
						<li
							v-for="backend in backendStatusItems"
							:key="backend.name"
							class="backend-status-item"
							:class="{
								'is-active':
									backend.name === backendState.activeMethod,
							}">
							<span class="backend-name">{{ backend.label }}</span>
							<span
								v-if="backend.name === backendState.activeMethod"
								class="backend-badge active">
								{{ t('openregister', 'Active') }}
							</span>
							<span
								v-if="backend.recommended"
								class="backend-badge recommended">
								{{ t('openregister', 'Recommended') }}
							</span>
							<span
								class="backend-badge"
								:class="
									backend.available
										? 'is-available'
										: 'is-unavailable'
								">
								{{ backendStateLabel(backend) }}
							</span>
							<span
								v-if="
									backend.available && backend.latencyMs !== null
								"
								class="backend-latency">
								{{ backend.latencyMs }} ms
							</span>
							<span
								v-if="backendTestResults[backend.name]"
								class="backend-test-result"
								:class="backendTestResults[backend.name]"
								role="status">
								<CheckIcon
									v-if="
										backendTestResults[backend.name]
										=== 'success'
									"
									:size="18" />
								<AlertCircleIcon v-else :size="18" />
								<span class="backend-test-text">
									{{
										backendTestResults[backend.name]
										=== 'success'
											? t('openregister', 'Reachable')
											: t('openregister', 'Unreachable')
									}}
								</span>
							</span>
							<NcButton
								variant="tertiary"
								:disabled="testingBackendMethod === backend.name"
								:aria-label="
									t(
										'openregister',
										'Test connection for {backend}',
										{ backend: backend.label },
									)
								"
								@click="testBackend(backend.name)">
								<template #icon>
									<ConnectionTestIcon
										:result="backendTestResults[backend.name]"
										:testing="testingBackendMethod === backend.name"
										testingFirst />
								</template>
								{{ t('openregister', 'Test connection') }}
							</NcButton>
						</li>
					</ul>
					<OpenAnonymiserHint
						v-if="openAnonymiserHint"
						:hint="openAnonymiserHint"
						:installLink="openAnonymiserInstallLink" />
				</div>

				<!-- Presidio API Configuration -->
				<div
					v-if="
						fileSettings.entityRecognitionEnabled && showPresidioConfig
					"
					class="setting-item api-config">
					<div class="api-fields">
						<div class="field-group">
							<label for="presidio-endpoint"
								>Presidio API Endpoint</label
							>
							<NcTextField
								id="presidio-endpoint"
								v-model="fileSettings.presidioApiEndpoint"
								placeholder="http://openregister-presidio-analyzer:3000"
								@update:modelValue="saveSettings">
								<template #trailing-button-icon>
									<InformationIcon :size="20" />
								</template>
							</NcTextField>
							<p class="field-hint">
								URL to your Presidio Analyzer instance
							</p>
						</div>

						<NcButton
							variant="secondary"
							@click="testPresidioConnection">
							<template #icon>
								<ConnectionTestIcon
									:result="presidioConnectionTested"
									:testing="testingPresidio" />
							</template>
							Test Connection
						</NcButton>
					</div>
				</div>

				<!-- OpenAnonymiser source: built-in ExApp (AppAPI) vs external endpoint -->
				<div
					v-if="
						fileSettings.entityRecognitionEnabled
						&& showOpenAnonymiserConfig
					"
					class="setting-item api-config">
					<fieldset class="openanonymiser-source">
						<legend>
							{{ t('openregister', 'OpenAnonymiser source') }}
						</legend>

						<!-- Internal: detected ExApp via AppAPI -->
						<NcCheckboxRadioSwitch
							:modelValue="fileSettings.openAnonymiserSource"
							type="radio"
							value="internal"
							name="openanonymiser-source"
							@update:modelValue="setOpenAnonymiserSource">
							{{
								t(
									'openregister',
									'Use the built-in OpenAnonymiser (recommended)',
								)
							}}
						</NcCheckboxRadioSwitch>

						<div
							v-if="fileSettings.openAnonymiserSource === 'internal'"
							class="source-detail">
							<p class="field-hint">
								{{
									t(
										'openregister',
										'Calls the installed OpenAnonymiser ExApp directly through AppAPI — no endpoint needed.',
									)
								}}
							</p>
							<OpenAnonymiserHint
								v-if="openAnonymiserHint"
								:hint="openAnonymiserHint"
								:installLink="openAnonymiserInstallLink" />
							<NcButton
								variant="secondary"
								:disabled="testingBackendMethod === 'openanonymiser'"
								@click="testBackend('openanonymiser')">
								<template #icon>
									<ConnectionTestIcon
										:result="backendTestResults.openanonymiser"
										:testing="testingBackendMethod === 'openanonymiser'"
										testingFirst />
								</template>
								{{ t('openregister', 'Test connection') }}
							</NcButton>
						</div>

						<!-- External: operator-entered endpoint -->
						<NcCheckboxRadioSwitch
							:modelValue="fileSettings.openAnonymiserSource"
							type="radio"
							value="external"
							name="openanonymiser-source"
							@update:modelValue="setOpenAnonymiserSource">
							{{
								t(
									'openregister',
									'Use an external OpenAnonymiser endpoint',
								)
							}}
						</NcCheckboxRadioSwitch>

						<div
							v-if="fileSettings.openAnonymiserSource === 'external'"
							class="source-detail api-fields">
							<div class="field-group">
								<label for="openanonymiser-endpoint">{{
									t('openregister', 'OpenAnonymiser API endpoint')
								}}</label>
								<NcTextField
									id="openanonymiser-endpoint"
									v-model="fileSettings.openAnonymiserApiEndpoint"
									placeholder="http://openregister-openanonymiser:8080"
									@update:modelValue="saveSettings">
									<template #trailing-button-icon>
										<InformationIcon :size="20" />
									</template>
								</NcTextField>
								<p class="field-hint">
									{{
										t(
											'openregister',
											'URL to an external OpenAnonymiser instance (Dutch-focused PII detection)',
										)
									}}
								</p>
							</div>

							<div class="field-group">
								<label for="openanonymiser-username">{{
									t('openregister', 'OpenAnonymiser user name')
								}}</label>
								<NcTextField
									id="openanonymiser-username"
									v-model="fileSettings.openAnonymiserUsername"
									autocomplete="off"
									@update:modelValue="saveSettings" />
								<p class="field-hint">
									{{
										t(
											'openregister',
											'Leave empty when the endpoint does not ask for a login.',
										)
									}}
								</p>
							</div>

							<div class="field-group">
								<label for="openanonymiser-password">{{
									t('openregister', 'OpenAnonymiser password')
								}}</label>
								<NcTextField
									id="openanonymiser-password"
									v-model="openAnonymiserPassword"
									type="password"
									autocomplete="new-password"
									:placeholder="
										fileSettings.openAnonymiserPasswordSet
											? t(
													'openregister',
													'A password is stored',
												)
											: ''
									"
									@update:modelValue="
										openAnonymiserPasswordChanged = true
									"
									@blur="saveOpenAnonymiserPassword">
									<template #trailing-button-icon>
										<KeyIcon :size="20" />
									</template>
								</NcTextField>
								<p class="field-hint">
									{{
										t(
											'openregister',
											'Stored as a secret and never shown again. Type a new one to replace it.',
										)
									}}
								</p>
							</div>

							<NcButton
								variant="secondary"
								@click="testOpenAnonymiserConnection">
								<template #icon>
									<ConnectionTestIcon
										:result="openAnonymiserConnectionTested"
										:testing="testingOpenAnonymiser" />
								</template>
								{{ t('openregister', 'Test connection') }}
							</NcButton>
						</div>
					</fieldset>
				</div>
			</div>
		</SettingsCard>

		<!-- Supported File Types -->
		<SettingsCard
			:title="t('openregister', 'Supported File Types')"
			icon="📎"
			:collapsible="true"
			:defaultCollapsed="true">
			<!-- Compatibility info based on selected extractor -->
			<div
				v-if="fileSettings.textExtractor.id === 'llphant'"
				class="compatibility-note info-note">
				<InformationIcon :size="20" />
				<div>
					<strong>LLPhant Extraction:</strong> Supports most document
					formats including PDF, DOCX, XLSX, TXT, MD, HTML, JSON, XML, and
					CSV. <br /><strong>Note:</strong> Image files (JPG, PNG, GIF,
					WebP) require Dolphin AI for OCR text extraction.
				</div>
			</div>
			<div
				v-else-if="fileSettings.textExtractor.id === 'dolphin'"
				class="compatibility-note success-note">
				<CheckIcon :size="20" />
				<div>
					<strong>Dolphin AI:</strong> Supports all file types with
					advanced parsing for tables, formulas, and complex layouts.
					<strong>Includes OCR for scanned documents and images</strong>
					(JPG, PNG, GIF, WebP).
				</div>
			</div>

			<div class="settings-group">
				<div class="file-types-grid">
					<NcCheckboxRadioSwitch
						v-for="fileType in fileTypes"
						:key="fileType.extension"
						v-model="fileType.enabled"
						type="checkbox"
						@update:modelValue="saveSettings">
						<span class="file-type-label">
							{{ fileType.icon }} {{ fileType.label }}
							<span class="file-type-extension"
								>(.{{ fileType.extension }})</span
							>
							<span
								v-if="fileType.llphantSupport === 'none'"
								class="support-indicator dolphin-required"
								:title="
									t(
										'openregister',
										'Requires Dolphin AI for OCR text extraction',
									)
								">
								{{ t('openregister', '(Dolphin required)') }}
							</span>
							<span
								v-else-if="
									fileType.dolphinOcr
									&& fileSettings.textExtractor.id === 'dolphin'
								"
								class="support-indicator ocr"
								:title="t('openregister', 'Dolphin OCR enabled')">
								📷 OCR
							</span>
						</span>
					</NcCheckboxRadioSwitch>
				</div>
			</div>
		</SettingsCard>

		<!-- Extraction Statistics -->
		<div class="stats-section">
			<h3 class="stats-title">📊 Extraction Statistics</h3>
			<div class="stats-grid stats-grid-8">
				<div class="stat-card">
					<div class="stat-value">
						{{ extractionStats.totalFiles || 0 }}
					</div>
					<div class="stat-label">Total Files</div>
				</div>
				<div class="stat-card">
					<div class="stat-value">
						{{ extractionStats.untrackedFiles || 0 }}
					</div>
					<div class="stat-label">Untracked</div>
				</div>
				<div class="stat-card">
					<div class="stat-value">
						{{ extractionStats.pendingFiles || 0 }}
					</div>
					<div class="stat-label">Pending</div>
				</div>
				<div class="stat-card highlight success">
					<div class="stat-value">
						{{ extractionStats.processedFiles || 0 }}
					</div>
					<div class="stat-label">Processed</div>
				</div>
				<div class="stat-card highlight error">
					<div class="stat-value">
						{{ extractionStats.failedFiles || 0 }}
					</div>
					<div class="stat-label">Failed</div>
				</div>
				<div class="stat-card highlight">
					<div class="stat-value">
						{{ extractionStats.totalChunks || 0 }}
					</div>
					<div class="stat-label">Chunks</div>
				</div>
				<div class="stat-card info">
					<div class="stat-value">
						{{ extractionStats.totalObjects || 0 }}
					</div>
					<div class="stat-label">Objects</div>
				</div>
				<div class="stat-card info">
					<div class="stat-value">
						{{ extractionStats.totalEntities || 0 }}
					</div>
					<div class="stat-label">Entities</div>
				</div>
			</div>
		</div>

		<!-- Save Status -->
		<div v-if="saveMessage" class="save-message" :class="saveMessageType">
			{{ saveMessage }}
		</div>
	</SettingsSection>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcActionButton,
	NcActions,
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcTextField,
} from '@nextcloud/vue'
import { mapStores } from 'pinia'
import AlertCircleIcon from 'vue-material-design-icons/AlertCircle.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import DotsVertical from 'vue-material-design-icons/DotsVertical.vue'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import InformationIcon from 'vue-material-design-icons/Information.vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import SettingsCard from '../../../components/shared/SettingsCard.vue'
import SettingsSection from '../../../components/shared/SettingsSection.vue'
import ConnectionTestIcon from './ConnectionTestIcon.vue'
import LabeledSelect from './LabeledSelect.vue'
import OpenAnonymiserHint from './OpenAnonymiserHint.vue'
import { freshState } from '../../../services/initialState.js'
import { useSettingsStore } from '../../../store/settings.js'
import { saveMessageMethods } from './saveMessage.js'

/**
 * File configuration settings component for managing file upload and text extraction.
 * Allows users to control when and how text extraction occurs.
 */
// The hint shown for each OpenAnonymiser probe error code.
const OPEN_ANONYMISER_HINTS = new Map([
	[
		'appapi_missing',
		() =>
			t(
				'openregister',
				'AppAPI is not installed. Install AppAPI and the OpenAnonymiser ExApp to enable Dutch-focused PII detection.',
			),
	],
	[
		'exapp_not_installed',
		() =>
			t(
				'openregister',
				'The OpenAnonymiser ExApp is not installed. Install it to enable Dutch-focused PII detection.',
			),
	],
	[
		'exapp_disabled',
		() =>
			t(
				'openregister',
				'The OpenAnonymiser ExApp is installed but disabled. Enable it to use it for entity recognition.',
			),
	],
])

// The display name of each entity recognition backend, by its id.
const BACKEND_LABELS = {
	regex: 'Regex',
	presidio: 'Presidio',
	openanonymiser: 'OpenAnonymiser',
	llm: 'LLM',
	hybrid: 'Hybrid',
}

// The select fields of the file settings: the option list each picks from,
// and the option it falls back to when the stored id is not in the list.
const SELECT_SETTINGS = [
	['extractionScope', 'extractionScopes', 3],
	['textExtractor', 'textExtractors', 0],
	['extractionMode', 'extractionModes', 0],
	['entityRecognitionMethod', 'entityRecognitionMethods', 0],
]

// The plain file settings and the value each takes when the stored one is empty.
const PLAIN_SETTING_DEFAULTS = [
	['maxFileSize', 100],
	['chunkSize', 1000],
	['chunkOverlap', 200],
	['chunkingStrategy', 'RECURSIVE_CHARACTER'],
	['batchSize', 10],
	['dolphinApiEndpoint', ''],
	['dolphinApiKey', ''],
	['entityRecognitionEnabled', false],
	['presidioApiEndpoint', ''],
	['openAnonymiserApiEndpoint', ''],
	['openAnonymiserSource', 'internal'],
	['openAnonymiserUsername', ''],
]

/**
 * The option a stored select value (an id, or an option object) stands for.
 *
 * @param {Array<object>} options The options.
 * @param {string|object} value The stored value.
 * @return {object|undefined} The option, if the list has it.
 */
function optionFor(options, value) {
	const id = typeof value === 'string' ? value : value.id
	return options.find((option) => option.id === id)
}

/**
 * Turn the stored select values into the options the selects show.
 *
 * @param {object} vm The file configuration section.
 * @param {object} settings The stored file settings.
 * @return {void}
 */
function applySelectedOptions(vm, settings) {
	for (const [field, list, fallback] of SELECT_SETTINGS) {
		if (settings[field]) {
			vm.fileSettings[field] =
				optionFor(vm[list], settings[field]) || vm[list][fallback]
		}
	}
}

/**
 * Copy the plain stored settings into the form, with their defaults.
 *
 * @param {object} fileSettings The form's settings.
 * @param {object} settings The stored file settings.
 * @return {void}
 */
function applyPlainSettings(fileSettings, settings) {
	fileSettings.includeInSearch =
		settings.includeInSearch !== undefined ? settings.includeInSearch : true
	for (const [field, fallback] of PLAIN_SETTING_DEFAULTS) {
		fileSettings[field] = settings[field] || fallback
	}
	fileSettings.openAnonymiserPasswordSet =
		settings.openAnonymiserPasswordSet === true
}

/**
 * Mark the file types the stored settings enable.
 *
 * @param {Array<object>} fileTypes The file types.
 * @param {object} settings The stored file settings.
 * @return {void}
 */
function applyEnabledFileTypes(fileTypes, settings) {
	if (!settings.enabledFileTypes) {
		return
	}
	fileTypes.forEach((ft) => {
		ft.enabled = settings.enabledFileTypes.includes(ft.extension)
	})
}

/**
 * The extraction settings to save.
 *
 * @param {object} settings The form state
 * @return {object} The settings
 */
function extractionFields(settings) {
	return {
		extractionScope: settings.extractionScope?.id || 'objects',
		textExtractor: settings.textExtractor?.id || 'llphant',
		extractionMode: settings.extractionMode?.id || 'background',
		includeInSearch: settings.includeInSearch,
		maxFileSize: settings.maxFileSize,
		chunkSize: settings.chunkSize,
		chunkOverlap: settings.chunkOverlap,
		chunkingStrategy: settings.chunkingStrategy,
		batchSize: settings.batchSize,
	}
}

/**
 * The text recognition settings to save.
 *
 * @param {object} settings The form state
 * @return {object} The settings
 */
function recognitionFields(settings) {
	return {
		dolphinApiEndpoint: settings.dolphinApiEndpoint || '',
		dolphinApiKey: settings.dolphinApiKey || '',
		entityRecognitionEnabled: settings.entityRecognitionEnabled,
		entityRecognitionMethod: settings.entityRecognitionMethod?.id || 'regex',
		presidioApiEndpoint: settings.presidioApiEndpoint || '',
	}
}

/**
 * The OpenAnonymiser settings to save, without the password.
 *
 * @param {object} settings The form state
 * @return {object} The settings
 */
function openAnonymiserFields(settings) {
	return {
		openAnonymiserApiEndpoint: settings.openAnonymiserApiEndpoint || '',
		openAnonymiserSource: settings.openAnonymiserSource || 'internal',
		openAnonymiserUsername: settings.openAnonymiserUsername || '',
	}
}

const FILE_COUNTS = [
	'totalFiles',
	'untrackedFiles',
	'pendingFiles',
	'processedFiles',
	'failedFiles',
	'totalChunks',
]

/**
 * The extraction statistics to show, zero where the server gave none.
 *
 * @param {object} stats The server's statistics
 * @return {object} The statistics
 */
function extractionStatsOf(stats) {
	return {
		...Object.fromEntries(FILE_COUNTS.map((key) => [key, stats[key] || 0])),
		totalObjects: stats.totalObjects || stats.total_objects || 0,
		totalEntities: stats.totalEntities || stats.total_entities || 0,
	}
}

/**
 * The file types text can be extracted from, one row each: extension, label,
 * icon, whether it is enabled by default, LLPhant support and whether Dolphin
 * reads it with OCR.
 */
const FILE_TYPE_ROWS = [
	// Text formats (LLPhant supported)
	['txt', 'Text Files', '📝', true, 'yes', false],
	['md', 'Markdown', '📋', true, 'yes', false],
	['html', 'HTML Files', '🌐', true, 'yes', false],
	['json', 'JSON Files', '📦', true, 'yes', false],
	['xml', 'XML Files', '📰', true, 'yes', false],
	['csv', 'CSV Files', '📊', true, 'yes', false],
	// Document formats (LLPhant supported)
	['pdf', 'PDF Documents', '📄', true, 'yes', true],
	['docx', 'Word Documents', '📘', true, 'yes', false],
	['doc', 'Word (Legacy)', '📘', true, 'yes', false],
	['xlsx', 'Excel Spreadsheets', '📊', true, 'yes', false],
	['xls', 'Excel (Legacy)', '📊', true, 'yes', false],
	['pptx', 'PowerPoint', '📽️', false, 'yes', false],
	// Image formats (Dolphin required for OCR)
	['jpg', 'JPEG Images', '🖼️', false, 'none', true],
	['jpeg', 'JPEG Images', '🖼️', false, 'none', true],
	['png', 'PNG Images', '🖼️', false, 'none', true],
	['gif', 'GIF Images', '🖼️', false, 'none', true],
	['webp', 'WebP Images', '🖼️', false, 'none', true],
	// Other formats
	['odt', 'OpenDocument Text', '📄', false, 'yes', false],
	['rtf', 'Rich Text Format', '📝', false, 'yes', false],
]

/**
 * One row of FILE_TYPE_ROWS as the form edits it.
 *
 * @param {Array} row The row
 * @return {object} The file type
 */
function toFileType(row) {
	return {
		extension: row[0],
		label: row[1],
		icon: row[2],
		enabled: row[3],
		llphantSupport: row[4],
		dolphinOcr: row[5],
	}
}

/**
 * The state every instance of this component starts from.
 */
const INITIAL_STATE = {
	fileSettings: {
		extractionScope: {
			id: 'objects',
			label: 'Files Attached to Objects',
		},

		textExtractor: { id: 'llphant', label: 'LLPhant' },
		extractionMode: { id: 'background', label: 'Background Job' },
		includeInSearch: true,
		maxFileSize: 100,
		chunkSize: 1000,
		chunkOverlap: 200,
		chunkingStrategy: 'RECURSIVE_CHARACTER',
		batchSize: 10,
		dolphinApiEndpoint: '',
		dolphinApiKey: '',
		entityRecognitionEnabled: false,
		entityRecognitionMethod: { id: 'regex', label: 'Regex' },
		presidioApiEndpoint: '',
		openAnonymiserApiEndpoint: '',
		// OpenAnonymiser source: 'internal' (AppAPI ExApp) or 'external' (URL).
		openAnonymiserSource: 'internal',
		// HTTP Basic user for the external endpoint; the password is write-only.
		openAnonymiserUsername: '',
		openAnonymiserPasswordSet: false,
	},

	// A newly typed OpenAnonymiser password, sent once and then cleared.
	openAnonymiserPassword: '',
	openAnonymiserPasswordChanged: false,

	// Resolved backend state from GET /api/admin/anonymisation/backend-state.
	backendState: null,
	// Last probe error code for the openanonymiser backend (drives the install hint).
	openAnonymiserError: null,
	// Method name currently being probed via the test-connection endpoint.
	testingBackendMethod: '',
	// Persistent per-backend test outcomes: { [method]: 'success' | 'error' }.
	backendTestResults: {},
	objectSettings: {
		extractionMode: { id: 'background', label: 'Background Job' },
	},

	fileTypes: FILE_TYPE_ROWS.map(toFileType),

	textExtractors: [
		{
			id: 'llphant',
			label: 'LLPhant',
			icon: '🐘',
			description:
				'Local PHP library - Best for: TXT, MD, HTML, JSON, XML, CSV, simple PDFs',
		},
		{
			id: 'dolphin',
			label: 'Dolphin',
			icon: '🐬',
			description:
				'ByteDance AI - Best for: Complex PDFs, tables, formulas, DOCX, XLSX, PPT',
		},
	],

	extractionScopes: [
		{
			id: 'none',
			label: 'None (Disabled)',
			description: 'Do not extract text automatically',
		},
		{
			id: 'all',
			label: 'All Files',
			description: 'Extract text from all uploaded files in Nextcloud',
		},
		{
			id: 'folders',
			label: 'Files in Specific Folders',
			description: 'Extract text from files in designated folders only',
		},
		{
			id: 'objects',
			label: 'Files Attached to Objects',
			description:
				'Extract text only from files attached to OpenRegister objects (recommended)',
		},
	],

	extractionModes: [
		{
			id: 'immediate',
			label: 'Immediate',
			description:
				'Process during upload - direct link between file upload and parsing logic',
		},
		{
			id: 'background',
			label: 'Background Job',
			description:
				'Delayed extraction action on the job stack - process files asynchronously after upload',
		},
		{
			id: 'cron',
			label: 'Cron Job',
			description:
				'Repeating action handling files - periodic batch processing via scheduled jobs',
		},
		{
			id: 'manual',
			label: 'Manual Only',
			description: 'Only extract when manually triggered via Actions menu',
		},
	],

	extractionStats: {
		totalFiles: 0,
		untrackedFiles: 0,
		pendingFiles: 0,
		processedFiles: 0,
		failedFiles: 0,
		totalChunks: 0,
		totalObjects: 0,
		totalEntities: 0,
	},

	discoveringFiles: false,
	extractingFiles: false,
	retryingFiles: false,
	saveMessage: '',
	saveMessageType: 'success',
	dolphinConnectionTested: null, // null, 'success', 'error'
	presidioConnectionTested: null,
	openAnonymiserConnectionTested: null,
	testingPresidio: false,
	testingOpenAnonymiser: false,
	entityRecognitionMethods: [
		{
			id: 'regex',
			label: 'Regex',
			icon: '🔤',
			description:
				'Local pattern matching - Fast, privacy-friendly, detects emails, phones, IBANs',
		},
		{
			id: 'presidio',
			label: 'Presidio',
			icon: '🛡️',
			description:
				'Microsoft Presidio - Accurate NER for names, organizations, locations (English)',
		},
		{
			id: 'openanonymiser',
			label: 'OpenAnonymiser',
			icon: '🇳🇱',
			description:
				'Dutch-focused PII detection - Best for Dutch names, BSN, addresses',
		},
		{
			id: 'hybrid',
			label: 'Hybrid',
			icon: '⚡',
			description:
				'Combines regex with external services for maximum coverage',
		},
	],
}

export default {
	name: 'FileConfiguration',

	components: {
		SettingsSection,
		SettingsCard,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		NcButton,
		NcTextField,
		NcActions,
		NcActionButton,
		FileDocumentIcon,
		RefreshIcon,
		MagnifyIcon,
		InformationIcon,
		KeyIcon,
		CheckIcon,
		AlertCircleIcon,
		ConnectionTestIcon,
		DotsVertical,
		LabeledSelect,
		OpenAnonymiserHint,
	},

	data() {
		return freshState(INITIAL_STATE)
	},

	computed: {
		...mapStores(useSettingsStore),

		/**
		 * Check if any file operation is currently running
		 *
		 * @spec exclude UI plumbing — derived busy-state for disabling buttons.
		 */
		isProcessing() {
			return (
				this.discoveringFiles || this.extractingFiles || this.retryingFiles
			)
		},

		/**
		 * Show Presidio config when presidio or hybrid is selected
		 *
		 * @spec exclude UI plumbing — conditional-render predicate for a settings block.
		 */
		showPresidioConfig() {
			const methodId = this.fileSettings.entityRecognitionMethod?.id || 'regex'
			return methodId === 'presidio' || methodId === 'hybrid'
		},

		/**
		 * Show OpenAnonymiser config when openanonymiser is selected
		 *
		 * @spec exclude UI plumbing — conditional-render predicate for a settings block.
		 */
		showOpenAnonymiserConfig() {
			const methodId = this.fileSettings.entityRecognitionMethod?.id || 'regex'
			return methodId === 'openanonymiser'
		},

		/**
		 * Per-backend status rows derived from the resolved backend state.
		 *
		 * The openanonymiser backend is flagged "recommended" when it is available,
		 * reflecting ADR-017's auto-select preference even when another method is active.
		 *
		 * @spec exclude UI plumbing — derives display rows from backendState.
		 * @return {Array<object>} Backend status rows.
		 */
		backendStatusItems() {
			if (!this.backendState || !this.backendState.backends) {
				return []
			}
			const labels = BACKEND_LABELS
			return Object.values(this.backendState.backends).map((backend) => ({
				name: backend.name,
				label: labels[backend.name] || backend.name,
				available: backend.available,
				configured: backend.configured,
				latencyMs: backend.latencyMs,
				recommended: backend.name === 'openanonymiser' && backend.available,
			}))
		},

		/**
		 * Hint shown when the OpenAnonymiser ExApp is not usable.
		 *
		 * @spec exclude UI plumbing — maps the probe error code to a message.
		 * @return {string|null} The hint text, or null when no hint applies.
		 */
		openAnonymiserHint() {
			const hint = OPEN_ANONYMISER_HINTS.get(this.openAnonymiserError)
			return hint ? hint() : null
		},

		/**
		 * Deep link to the OpenAnonymiser ExApp in the App Store discover view.
		 *
		 * @spec exclude UI plumbing — static deep link.
		 * @return {string} The settings deep link.
		 */
		openAnonymiserInstallLink() {
			return generateUrl('/settings/apps/discover/openanonymiser_light')
		},

		/**
		 * Map a backend method id to its human-readable label.
		 *
		 * @spec exclude UI plumbing — label lookup.
		 * @return {object} Map of method id to label.
		 */
		methodLabels() {
			return { auto: 'Auto', ...BACKEND_LABELS }
		},

		/**
		 * Human-readable label of the resolved active method.
		 *
		 * @spec exclude UI plumbing — derived display label.
		 * @return {string} The active method label.
		 */
		activeMethodLabel() {
			const active = this.backendState?.activeMethod
			return this.methodLabels[active] || active || '—'
		},

		/**
		 * Human-readable label of the effective method.
		 *
		 * @spec exclude UI plumbing — derived display label.
		 * @return {string} The effective method label.
		 */
		effectiveMethodLabel() {
			const effective = this.backendState?.effectiveMethod
			return this.methodLabels[effective] || effective || '—'
		},

		/**
		 * Whether the effective method differs from the active method (i.e. a fallback occurred).
		 *
		 * @spec exclude UI plumbing — derived predicate.
		 * @return {boolean} True when the effective method falls back.
		 */
		effectiveDiffersFromActive() {
			return (
				Boolean(this.backendState)
				&& this.backendState.activeMethod
					!== this.backendState.effectiveMethod
			)
		},
	},

	/**
	 * Load settings and extraction stats on mount.
	 *
	 * @spec exclude UI plumbing — lifecycle hook delegating to loaders.
	 * @return {Promise<void>}
	 */
	async mounted() {
		await this.loadSettings()
		await this.loadExtractionStats()
		await this.loadBackendState()
	},

	methods: {
		...saveMessageMethods,

		/**
		 * Load file configuration settings
		 *
		 * @spec exclude UI plumbing — admin-settings load hydrating local form state.
		 */
		async loadSettings() {
			try {
				const settings = await this.settingsStore.getFileSettings()
				if (settings) {
					applySelectedOptions(this, settings)
					applyPlainSettings(this.fileSettings, settings)
					applyEnabledFileTypes(this.fileTypes, settings)
				}
				// Load object settings
				await this.loadObjectSettings()
			} catch (error) {
				console.error('Failed to load file settings:', error)
			}
		},

		/**
		 * Load object text extraction settings
		 *
		 * @spec exclude UI plumbing — admin-settings load hydrating local form state.
		 */
		async loadObjectSettings() {
			try {
				const settings = await this.settingsStore.getObjectSettings()
				if (settings && settings.objectExtractionMode) {
					const modeId =
						typeof settings.objectExtractionMode === 'string'
							? settings.objectExtractionMode
							: settings.objectExtractionMode.id
					this.objectSettings.extractionMode =
						this.extractionModes.find((m) => m.id === modeId)
						|| this.extractionModes[1] // default to 'background'
				}
			} catch (error) {
				console.error('Failed to load object settings:', error)
			}
		},

		/**
		 * Load the resolved anonymisation backend state and capture any ExApp hint.
		 *
		 * @spec exclude UI plumbing — admin-settings load hydrating backend status.
		 * @return {Promise<void>}
		 */
		async loadBackendState() {
			try {
				const state = await this.settingsStore.getAnonymisationBackendState()
				this.backendState = state
				this.openAnonymiserError = null
				if (state) {
					this.resolveRecognitionMethod(state)
					await this.probeUnavailableOpenAnonymiser(state)
				}
			} catch (error) {
				console.error('Failed to load anonymisation backend state:', error)
			}
		},

		/**
		 * Resolve the invisible 'auto' (not-yet-configured) marker, or a method
		 * that is no longer offered, to the backend's active method so the
		 * dropdown shows a real choice; persisted on save.
		 *
		 * @param {object} state The resolved backend state
		 * @spec exclude UI plumbing — picks the recognition method shown in the dropdown.
		 */
		resolveRecognitionMethod(state) {
			const currentId = this.fileSettings.entityRecognitionMethod?.id
			const methods = this.entityRecognitionMethods
			if (currentId !== 'auto' && methods.some((m) => m.id === currentId)) {
				return
			}
			const resolved = state.activeMethod || 'regex'
			this.fileSettings.entityRecognitionMethod =
				methods.find((m) => m.id === resolved) || methods[0]
		},

		/**
		 * When the ExApp is not available, probe once to capture the precise reason
		 * (appapi_missing / exapp_not_installed / exapp_disabled) for the install hint.
		 *
		 * @param {object} state The resolved backend state
		 * @spec exclude UI plumbing — store-delegated probe for the install hint.
		 * @return {Promise<void>}
		 */
		async probeUnavailableOpenAnonymiser(state) {
			if (state.backends?.openanonymiser?.available !== false) {
				return
			}
			const probe =
				await this.settingsStore.testAnonymisationBackend('openanonymiser')
			this.openAnonymiserError = probe?.error || null
		},

		/**
		 * Probe a single backend via the test-connection endpoint and refresh state.
		 *
		 * @param {string} method - The backend method to probe.
		 * @spec exclude UI plumbing — thin store-delegated probe + message.
		 * @return {Promise<void>}
		 */
		async testBackend(method) {
			try {
				this.testingBackendMethod = method
				const probe =
					await this.settingsStore.testAnonymisationBackend(method)
				if (method === 'openanonymiser') {
					this.openAnonymiserError = probe?.error || null
				}
				this.reportProbe(method, probe)
				// Refresh the resolved state so availability indicators reflect the probe.
				const state = await this.settingsStore.getAnonymisationBackendState()
				if (state) {
					this.backendState = state
				}
			} catch (error) {
				console.error('Failed to probe backend:', error)
				this.recordBackendTest(method, 'error')
				this.showSaveMessage(
					t('openregister', 'Connection test failed'),
					'error',
				)
			} finally {
				this.testingBackendMethod = ''
			}
		},

		/**
		 * Record and announce the outcome of a backend probe.
		 *
		 * @param {string} method The probed backend method
		 * @param {object|null} probe The probe result
		 * @spec exclude UI plumbing — formats a probe outcome message.
		 */
		reportProbe(method, probe) {
			const reachable = Boolean(probe && probe.reachable)
			this.recordBackendTest(method, reachable ? 'success' : 'error')
			if (reachable) {
				this.showSaveMessage(
					t('openregister', 'Connection successful'),
					'success',
				)
				return
			}
			this.showSaveMessage(
				t('openregister', 'Connection failed: {error}', {
					error: probe?.error || 'unknown',
				}),
				'error',
			)
		},

		/**
		 * Persist a per-backend result so the row/button keep showing the outcome.
		 *
		 * @param {string} method The backend method
		 * @param {string} outcome 'success' or 'error'
		 * @spec exclude UI plumbing — per-backend test outcome state.
		 */
		recordBackendTest(method, outcome) {
			this.backendTestResults = {
				...this.backendTestResults,
				[method]: outcome,
			}
		},

		/**
		 * Set the OpenAnonymiser source (internal ExApp vs external endpoint) and persist.
		 *
		 * @param {string} source - 'internal' or 'external'.
		 * @spec exclude UI plumbing — radio handler delegating to save.
		 * @return {Promise<void>}
		 */
		async setOpenAnonymiserSource(source) {
			this.fileSettings.openAnonymiserSource = source
			await this.saveSettings()
		},

		/**
		 * Human-readable availability label for a backend status row.
		 *
		 * @param {object} backend - A backend status row.
		 * @spec exclude UI plumbing — formats an availability label.
		 * @return {string} The label.
		 */
		backendStateLabel(backend) {
			if (backend.available) {
				return t('openregister', 'Available')
			}
			if (backend.configured) {
				return t('openregister', 'Unavailable')
			}
			return t('openregister', 'Not configured')
		},

		/**
		 * Save file configuration settings
		 *
		 * @spec exclude UI plumbing — admin-settings save delegating to the store.
		 */
		async saveSettings() {
			try {
				const saved = await this.settingsStore.saveFileSettings(
					this.fileSettingsPayload(),
				)
				this.applySavedSettings(saved)
				// Refresh resolved backend state so the active/recommended indicators stay in sync.
				await this.loadBackendState()
			} catch (error) {
				console.error('Failed to save file settings:', error)
				this.showSaveMessage('Failed to save settings', 'error')
			}
		},

		/**
		 * The file settings to save. The password is only sent when it was typed:
		 * a save without the key keeps the stored one, an empty string clears it.
		 *
		 * @return {object} The settings
		 * @spec exclude UI plumbing — builds the file settings payload.
		 */
		fileSettingsPayload() {
			const passwordField = this.openAnonymiserPasswordChanged
				? { openAnonymiserPassword: this.openAnonymiserPassword }
				: {}
			return {
				...passwordField,
				...extractionFields(this.fileSettings),
				...recognitionFields(this.fileSettings),
				...openAnonymiserFields(this.fileSettings),
				enabledFileTypes: this.fileTypes
					.filter((ft) => ft.enabled)
					.map((ft) => ft.extension),
			}
		},

		/**
		 * Clear a sent password and take over whether the server now holds one.
		 *
		 * @param {object|null} saved The saved settings
		 * @spec exclude UI plumbing — write-only credential field state after a save.
		 */
		applySavedSettings(saved) {
			if (this.openAnonymiserPasswordChanged) {
				this.openAnonymiserPassword = ''
				this.openAnonymiserPasswordChanged = false
			}
			if (saved && typeof saved.openAnonymiserPasswordSet === 'boolean') {
				this.fileSettings.openAnonymiserPasswordSet =
					saved.openAnonymiserPasswordSet
			}
		},

		/**
		 * Save a newly typed OpenAnonymiser password when the field loses focus.
		 *
		 * @spec exclude UI plumbing: write-only credential field delegating to saveSettings.
		 */
		async saveOpenAnonymiserPassword() {
			if (!this.openAnonymiserPasswordChanged) {
				return
			}
			await this.saveSettings()
		},

		/**
		 * Save object text extraction settings
		 *
		 * @spec exclude UI plumbing — admin-settings save delegating to the store.
		 */
		async saveObjectSettings() {
			try {
				await this.settingsStore.saveObjectSettings({
					objectExtractionMode:
						this.objectSettings.extractionMode?.id || 'background',
				})

				// Settings saved silently - no success message
			} catch (error) {
				console.error('Failed to save object settings:', error)
				this.showSaveMessage('Failed to save object settings', 'error')
			}
		},

		/**
		 * Test Dolphin API connection
		 *
		 * @spec exclude UI plumbing — thin store-delegated connection test + message.
		 */
		async testDolphinConnection() {
			try {
				this.dolphinConnectionTested = null

				if (
					!this.fileSettings.dolphinApiEndpoint
					|| !this.fileSettings.dolphinApiKey
				) {
					this.showSaveMessage(
						'Please provide both API endpoint and API key',
						'error',
					)
					this.dolphinConnectionTested = 'error'
					return
				}

				// Test connection via backend
				const response = await this.settingsStore.testDolphinConnection({
					apiEndpoint: this.fileSettings.dolphinApiEndpoint,
					apiKey: this.fileSettings.dolphinApiKey,
				})

				if (response.success) {
					this.showSaveMessage('Dolphin connection successful!', 'success')
					this.dolphinConnectionTested = 'success'
				} else {
					this.showSaveMessage(
						`Connection failed: ${response.error}`,
						'error',
					)
					this.dolphinConnectionTested = 'error'
				}
			} catch (error) {
				console.error('Failed to test Dolphin connection:', error)
				this.showSaveMessage('Connection test failed', 'error')
				this.dolphinConnectionTested = 'error'
			}
		},

		/**
		 * Test Presidio API connection
		 *
		 * @spec exclude UI plumbing — thin store-delegated connection test + message.
		 */
		async testPresidioConnection() {
			try {
				this.presidioConnectionTested = null
				this.testingPresidio = true

				if (!this.fileSettings.presidioApiEndpoint) {
					this.showSaveMessage(
						'Please provide the Presidio API endpoint',
						'error',
					)
					this.presidioConnectionTested = 'error'
					return
				}

				const response = await this.settingsStore.testPresidioConnection({
					apiEndpoint: this.fileSettings.presidioApiEndpoint,
				})

				if (response.success) {
					this.showSaveMessage(
						'Presidio connection successful!',
						'success',
					)
					this.presidioConnectionTested = 'success'
				} else {
					this.showSaveMessage(
						`Connection failed: ${response.error}`,
						'error',
					)
					this.presidioConnectionTested = 'error'
				}
			} catch (error) {
				console.error('Failed to test Presidio connection:', error)
				this.showSaveMessage('Connection test failed', 'error')
				this.presidioConnectionTested = 'error'
			} finally {
				this.testingPresidio = false
			}
		},

		/**
		 * Test OpenAnonymiser API connection
		 *
		 * @spec exclude UI plumbing — thin store-delegated connection test + message.
		 */
		async testOpenAnonymiserConnection() {
			try {
				this.openAnonymiserConnectionTested = null
				this.testingOpenAnonymiser = true

				if (!this.fileSettings.openAnonymiserApiEndpoint) {
					this.showSaveMessage(
						'Please provide the OpenAnonymiser API endpoint',
						'error',
					)
					this.openAnonymiserConnectionTested = 'error'
					return
				}

				const response =
					await this.settingsStore.testOpenAnonymiserConnection({
						apiEndpoint: this.fileSettings.openAnonymiserApiEndpoint,
					})

				if (response.success) {
					this.showSaveMessage(
						'OpenAnonymiser connection successful!',
						'success',
					)
					this.openAnonymiserConnectionTested = 'success'
				} else {
					this.showSaveMessage(
						`Connection failed: ${response.error}`,
						'error',
					)
					this.openAnonymiserConnectionTested = 'error'
				}
			} catch (error) {
				console.error('Failed to test OpenAnonymiser connection:', error)
				this.showSaveMessage('Connection test failed', 'error')
				this.openAnonymiserConnectionTested = 'error'
			} finally {
				this.testingOpenAnonymiser = false
			}
		},

		/**
		 * Load extraction statistics
		 *
		 * @spec exclude UI plumbing — admin-settings stats load hydrating local display state.
		 */
		async loadExtractionStats() {
			try {
				const stats = await this.settingsStore.getExtractionStats()
				if (stats) {
					this.extractionStats = extractionStatsOf(stats)
				}
			} catch (error) {
				console.error('Failed to load extraction stats:', error)
			}
		},

		/**
		 * Discover files in Nextcloud that aren't tracked yet
		 *
		 * @spec exclude UI plumbing — store-delegated action trigger + feedback message.
		 */
		async discoverFiles() {
			this.discoveringFiles = true
			try {
				const result = await this.settingsStore.discoverFiles()

				// Show detailed feedback about what happened
				const data = result?.data || {}
				const discovered = data.discovered || 0
				const failed = data.failed || 0

				let message = `Discovered ${discovered} new files`
				if (failed > 0) {
					message += `, ${failed} failed to stage`
				}

				this.showSaveMessage(message, failed > 0 ? 'error' : 'success')
				await this.loadExtractionStats()
			} catch (error) {
				console.error('Failed to discover files:', error)
				this.showSaveMessage('Failed to discover files', 'error')
			} finally {
				this.discoveringFiles = false
			}
		},

		/**
		 * Extract pending files (files already staged with status='pending')
		 *
		 * @spec exclude UI plumbing — store-delegated action trigger + feedback message.
		 */
		async extractAllPendingFiles() {
			this.extractingFiles = true
			try {
				const result =
					await this.settingsStore.triggerFileExtraction('pending')

				// Show detailed feedback about what happened
				const data = result?.data || {}
				const processed = data.processed || 0
				const failed = data.failed || 0

				let message = `Processed ${processed} pending files`
				if (failed > 0) {
					message += `, ${failed} failed`
				}

				this.showSaveMessage(message, failed > 0 ? 'error' : 'success')
				await this.loadExtractionStats()
			} catch (error) {
				console.error('Failed to process files:', error)
				this.showSaveMessage('Failed to extract files', 'error')
			} finally {
				this.extractingFiles = false
			}
		},

		/**
		 * Retry failed file extractions
		 *
		 * @spec exclude UI plumbing — store-delegated action trigger + feedback message.
		 */
		async reprocessFailedFiles() {
			this.retryingFiles = true
			try {
				const result =
					await this.settingsStore.triggerFileExtraction('failed')

				// Show detailed feedback about what happened
				const data = result?.data || {}
				const retried = data.retried || 0
				const failed = data.failed || 0

				let message = `Retried ${retried} failed extractions`
				if (failed > 0) {
					message += `, ${failed} failed again`
				}

				this.showSaveMessage(message, failed > 0 ? 'error' : 'success')
				await this.loadExtractionStats()
			} catch (error) {
				console.error('Failed to reprocess files:', error)
				this.showSaveMessage('Failed to retry extractions', 'error')
			} finally {
				this.retryingFiles = false
			}
		},

		/**
		 * Format number with thousands separator
		 *
		 * @spec exclude UI plumbing — pure display formatter, no observable contract.
		 * @param {number} num - The number to format
		 */
		formatNumber(num) {
			return new Intl.NumberFormat().format(num || 0)
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../styles/common.scss' as *;
@use '../../../styles/views.scss' as *;

/* SettingsSection handles all action button positioning and spacing */

/* Fix for action menu icon wrapper to prevent layout shifts when showing loading icons */
.action-icon-wrapper {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 20px;
	height: 20px;
}

@include section-description-full-rules-2;

.main-description.info-note strong {
	color: var(--color-primary-element);
}

/* Compact Layout */
.settings-group.compact {
	gap: 12px;
}

.settings-group.compact .setting-item {
	gap: 6px;
}

.settings-group.compact .setting-description {
	font-size: 12px;
	margin-top: 4px;
}

.processing-limits-section {
	margin-top: 24px;
	padding-top: 24px;
	border-top: 1px solid var(--color-border);
}

.processing-limits-section h5 {
	color: var(--color-text-light);
	margin: 0 0 16px 0;
	font-size: 15px;
	font-weight: 500;
}

.processing-limits-section .settings-group {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 16px;
}

@media (max-width: 768px) {
	.processing-limits-section .settings-group {
		grid-template-columns: 1fr;
	}
}

.settings-group {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.setting-item {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.setting-item label {
	color: var(--color-text-maxcontrast);
	font-weight: 500;
	font-size: 14px;
}

.setting-item input[type='number'],
.setting-item select {
	max-width: 200px;
	padding: 8px 12px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-text-light);
}

.setting-item select {
	min-width: 200px;
	cursor: pointer;
}

.setting-description {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 0;
	line-height: 1.4;
}

.file-types-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
	gap: 12px;
}

.file-type-label {
	display: flex;
	align-items: center;
	gap: 8px;
}

.file-type-extension {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.support-indicator {
	margin-left: 8px;
	font-size: 11px;
	cursor: help;
}

.support-indicator.dolphin-required {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.support-indicator.ocr {
	color: var(--color-primary);
	font-weight: 600;
	background: var(--color-primary-element-light);
	padding: 2px 6px;
	border-radius: 3px;
}

.compatibility-note {
	display: flex;
	gap: 12px;
	padding: 12px 16px;
	border-radius: var(--border-radius);
	margin-bottom: 16px;
	font-size: 13px;
	line-height: 1.5;
}

.compatibility-note.info-note {
	background: var(--color-primary-element-light);
	border-left: 3px solid var(--color-primary-element);
}

.compatibility-note.success-note {
	background: rgba(70, 180, 130, 0.1);
	border-left: 3px solid var(--color-success);
}

.compatibility-note ul {
	margin: 8px 0 0 0;
	padding-left: 20px;
}

.compatibility-note li {
	margin: 4px 0;
}

.api-config {
	background: var(--color-background-dark);
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	padding: 16px;
	margin-top: 8px;
}

.api-fields {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

@include field-group;

.field-hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

@include save-message-rules;

@include loading-icon;

@media (max-width: 768px) {
	.file-types-grid {
		grid-template-columns: 1fr;
	}

	.stats-grid {
		grid-template-columns: repeat(2, 1fr);
	}
}

/* Statistics Section */
.stats-section {
	margin-bottom: 20px;
}

.stats-title {
	margin: 0 0 16px 0;
	color: var(--color-main-text);
	font-size: 16px;
	font-weight: 600;
}

/* Vectorization Statistics */
.stats-grid {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 16px;
}

.stats-grid-5 {
	grid-template-columns: repeat(5, 1fr);
}

.stats-grid-6 {
	grid-template-columns: repeat(6, 1fr);
}

.stats-grid-8 {
	grid-template-columns: repeat(8, 1fr);
}

.stat-card {
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	text-align: center;
	transition: all 0.2s ease;
}

.stat-card:hover {
	border-color: var(--color-primary-element);
	transform: translateY(-2px);
	box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

.stat-card.highlight {
	background: var(--color-primary-light);
	border: 2px solid var(--color-primary-element);
	box-shadow: 0 2px 8px rgba(0, 123, 255, 0.15);
}

.stat-card.success {
	background: var(--color-success-light);
	border-color: var(--color-success);
}

.stat-card.error {
	background: var(--color-error-light);
	border-color: var(--color-error);
}

.stat-card.info {
	background: var(--color-primary-element-light);
	border-color: var(--color-primary-element);
}

/* Highlight with success keeps green background but adds prominent border */
.stat-card.highlight.success {
	background: var(--color-success-light);
	border: 2px solid var(--color-primary-element);
	box-shadow: 0 2px 8px rgba(0, 123, 255, 0.15);
}

/* Highlight with error keeps red background but adds prominent border */
.stat-card.highlight.error {
	background: var(--color-error-light);
	border: 2px solid var(--color-primary-element);
	box-shadow: 0 2px 8px rgba(0, 123, 255, 0.15);
}

.stat-value {
	font-size: 32px;
	font-weight: bold;
	color: var(--color-primary-element);
	margin-bottom: 8px;
}

.stat-card.highlight .stat-value {
	color: var(--color-primary-element);
}

.stat-card.success .stat-value {
	color: var(--color-success);
}

.stat-card.error .stat-value {
	color: var(--color-error);
}

.stat-card.info .stat-value {
	color: var(--color-primary-element);
}

.stat-label {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

/* Anonymisation backend availability list */
.backend-status-list {
	list-style: none;
	padding: 0;
	margin: 8px 0 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.backend-status-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 8px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.backend-status-item.is-active {
	border-left: 3px solid var(--color-primary-element);
}

.backend-status-item .backend-name {
	font-weight: 500;
	min-width: 130px;
}

.backend-summary {
	margin: 4px 0 0;
	font-weight: 500;
}

.backend-summary-sep {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.openanonymiser-source {
	border: none;
	margin: 0;
	padding: 0;
}

.openanonymiser-source legend {
	font-weight: 600;
	margin-bottom: 4px;
}

.openanonymiser-source .source-detail {
	margin: 4px 0 12px 28px;
}

.backend-test-result {
	display: inline-flex;
	align-items: center;
	gap: 2px;
	font-size: 12px;
}

.backend-test-result.success {
	color: var(--color-success-text, var(--color-success));
}

.backend-test-result.error {
	color: var(--color-error-text, var(--color-error));
}

.backend-badge {
	font-size: 12px;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 16px);
	border: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
}

.backend-badge.is-available {
	color: var(--color-success-text, var(--color-success));
	border-color: var(--color-success);
}

.backend-badge.is-unavailable {
	color: var(--color-error-text, var(--color-error));
	border-color: var(--color-error);
}

.backend-badge.recommended {
	color: var(--color-primary-element-text);
	background-color: var(--color-primary-element);
	border-color: var(--color-primary-element);
}

.backend-latency {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.backend-hint {
	margin-top: 8px;
}

@media (prefers-reduced-motion: reduce) {
	.stat-card {
		transition: none;
	}
}
</style>

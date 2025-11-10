<script setup>
import { schemaStore, navigationStore, registerStore } from '../../store/store.js'
</script>

<template>
	<NcDialog :name="schemaStore.schemaItem?.id && !createAnother ? 'Edit Schema' : 'Add Schema'"
		size="large"
		:can-close="true"
		@update:open="handleDialogClose">
		<NcNoteCard v-if="success" type="success">
			<p>Schema successfully {{ schemaStore.schemaItem?.id && !createAnother ? 'updated' : 'created' }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>
		<div v-if="createAnother || !success">
			<!-- Metadata Display -->
			<div class="detail-grid">
				<div v-if="schemaItem.id" class="detail-item id-card">
					<div class="id-card-header">
						<span class="detail-label">ID / UUID:</span>
						<NcButton class="copy-button" @click="copyToClipboard(schemaItem.uuid || schemaItem.id)">
							<template #icon>
								<Check v-if="isCopied" :size="20" />
								<ContentCopy v-else :size="20" />
							</template>
							{{ isCopied ? 'Copied' : 'Copy' }}
						</NcButton>
					</div>
					<span class="detail-value">{{ schemaItem.id }}</span>
					<span v-if="schemaItem.uuid && schemaItem.uuid !== schemaItem.id" class="detail-value uuid-value">{{ schemaItem.uuid }}</span>
				</div>
				<div class="detail-item title-with-badge">
					<NcTextField :disabled="loading"
						label="Title *"
						:value.sync="schemaItem.title" />
					<span v-if="schemaItem.extend" class="statusPill statusPill--alert">
						{{ t('openregister', 'Extended') }}
					</span>
				</div>
				<div v-if="schemaItem.created" class="detail-item">
					<span class="detail-label">Created:</span>
					<span class="detail-value">{{ new Date(schemaItem.created).toLocaleString() }}</span>
				</div>
				<div v-if="schemaItem.updated" class="detail-item">
					<span class="detail-label">Updated:</span>
					<span class="detail-value">{{ new Date(schemaItem.updated).toLocaleString() }}</span>
				</div>
				<div class="detail-item">
					<span class="detail-label">Version:</span>
					<span class="detail-value">{{ schemaItem.version || 'Not set' }}</span>
				</div>
				<div class="detail-item">
					<span class="detail-label">Owner:</span>
					<span class="detail-value">{{ schemaItem.owner || 'Not set' }}</span>
				</div>
			</div>

			<div class="tabContainer">
				<BTabs v-model="activeTab" content-class="mt-3" justified>
					<BTab title="Properties" active>
						<div class="viewTableContainer scrollable">
							<table class="viewTable">
								<thead>
									<tr>
										<th>{{ t('openregister', 'Name') }}</th>
										<th>{{ t('openregister', 'Type') }}</th>
										<th>
											<NcButton
												type="primary"
												:disabled="loading"
												@click="addProperty">
												<template #icon>
													<Plus :size="20" />
												</template>
												{{ t('openregister', 'Add property') }}
											</NcButton>
										</th>
									</tr>
								</thead>
								<tbody>
									<tr v-for="(property, key) in sortedProperties(schemaItem)"
										:key="`property-${getStablePropertyId(key)}`"
										:class="{ 'selected-row': selectedProperty === key, 'modified-row': isPropertyModified(key) }"
										@click="handleRowClick(key, $event)">
										<td>
											<div v-if="selectedProperty === key" class="name-input-container" @click.stop>
												<AlertOutline v-if="isPropertyModified(key)"
													:size="16"
													class="warning-icon"
													:title="'Property has been modified. Changes will only take effect after the schema is saved.'" />
												<NcTextField
													ref="propertyNameInput"
													:value="key"
													label="(technical) Property Name"
													@update:value="updatePropertyKey(key, $event)"
													@click.stop />
											</div>
											<div v-else class="name-display-container">
												<AlertOutline v-if="isPropertyModified(key)"
													:size="16"
													class="warning-icon"
													:title="'Property has been modified. Changes will only take effect after the schema is saved.'" />
												<div class="name-with-chips">
													<span class="property-name">{{ key }}</span>
													<div class="inline-chips">
														<span v-if="isPropertyRequired(schemaItem, key)"
															class="property-chip chip-primary">Required</span>
														<span v-if="property.immutable"
															class="property-chip chip-secondary">Immutable</span>
														<span v-if="property.deprecated"
															class="property-chip chip-warning">Deprecated</span>
														<span v-if="property.visible === false"
															class="property-chip chip-secondary">Hidden in view</span>
														<span v-if="property.hideOnCollection"
															class="property-chip chip-secondary">Hidden in Collection</span>
														<span v-if="property.hideOnForm"
															class="property-chip chip-secondary">Hidden in Form</span>
														<span v-if="property.const !== undefined"
															class="property-chip chip-success">Constant</span>
														<span v-if="property.enum && property.enum.length > 0"
															class="property-chip chip-success">Enumeration ({{ property.enum.length }})</span>
														<span v-if="property.facetable === true"
															class="property-chip chip-info">Facetable</span>
														<span v-if="hasCustomTableSettings(key)"
															class="property-chip chip-table">Table</span>
													</div>
												</div>
											</div>
										</td>
										<td>
											<NcSelect
												v-if="selectedProperty === key"
												v-model="schemaItem.properties[key].type"
												:options="typeOptionsForSelect"
												input-label="Property Type"
												@click.stop />
											<span v-else>{{ property.type }}</span>
										</td>
										<td class="tableColumnActions">
											<NcActions>
												<NcActionCaption name="Actions" />
												<NcActionButton :aria-label="'Copy ' + key" @click="copyProperty(key)">
													<template #icon>
														<ContentCopy :size="16" />
													</template>
													Copy Property
												</NcActionButton>
												<NcActionButton :aria-label="'Delete ' + key" @click="deleteProperty(key)">
													<template #icon>
														<TrashCanOutline :size="16" />
													</template>
													Delete Property
												</NcActionButton>

												<NcActionSeparator />
												<NcActionCaption name="General" />
												<NcActionCheckbox
													:checked="isPropertyRequired(schemaItem, key)"
													@update:checked="updatePropertyRequired(key, $event)">
													Required
												</NcActionCheckbox>
												<NcActionCheckbox
													:checked="property.immutable || false"
													@update:checked="updatePropertySetting(key, 'immutable', $event)">
													Immutable
												</NcActionCheckbox>
												<NcActionCheckbox
													:checked="property.deprecated || false"
													@update:checked="updatePropertySetting(key, 'deprecated', $event)">
													Deprecated
												</NcActionCheckbox>
												<NcActionCheckbox
													:checked="property.visible !== false"
													@update:checked="updatePropertySetting(key, 'visible', $event)">
													Visible to end users
												</NcActionCheckbox>
												<NcActionCheckbox
													:checked="property.hideOnCollection || false"
													@update:checked="updatePropertySetting(key, 'hideOnCollection', $event)">
													Hide in collection view
												</NcActionCheckbox>
												<NcActionCheckbox
													:checked="property.hideOnForm || false"
													@update:checked="updatePropertySetting(key, 'hideOnForm', $event)">
													Hide in form view
												</NcActionCheckbox>
												<NcActionCheckbox
													:checked="property.facetable === true"
													@update:checked="updatePropertySetting(key, 'facetable', $event)">
													Facetable
												</NcActionCheckbox>

												<NcActionSeparator />
												<NcActionCaption name="Properties" />
												<NcActionInput
													:value="property.title || ''"
													label="Title"
													@update:value="updatePropertySetting(key, 'title', $event)" />
												<NcActionInput
													v-if="getFormatOptionsForType(property.type).length > 0"
													v-model="schemaItem.properties[key].format"
													type="multiselect"
													:options="getFormatOptionsForType(property.type)"
													input-label="Format"
													label="Format" />
												<NcActionInput
													:value="property.description || ''"
													label="Description"
													@update:value="updatePropertySetting(key, 'description', $event)" />
												<NcActionInput
													:value="property.example || ''"
													label="Example"
													@update:value="updatePropertySetting(key, 'example', $event)" />
												<NcActionInput
													:value="property.order || 0"
													type="number"
													label="Order"
													@update:value="updatePropertySetting(key, 'order', Number($event))" />

												<!-- Const and Enum Configuration -->
												<NcActionSeparator />
												<NcActionCaption name="Value Constraints" />
												<NcActionInput
													:value="property.const || ''"
													label="Constant"
													@update:value="updatePropertySetting(key, 'const', $event === '' ? undefined : $event)" />
												<template v-if="property.enum && property.enum.length > 0">
													<NcActionCaption :name="'Current Enum Values (' + property.enum.length + ')'" />
													<NcActionButton
														v-for="(enumValue, index) in property.enum"
														:key="`enum-chip-${index}-${enumValue}`"
														:aria-label="'Remove ' + enumValue"
														class="enum-action-chip"
														@click="removeEnumValue(key, index)">
														<template #icon>
															<Close :size="16" />
														</template>
														{{ String(enumValue) }}
													</NcActionButton>
												</template>
												<NcActionInput
													:value="enumInputValue"
													label="Add Enum Value"
													placeholder="Type value and press Enter"
													@update:value="enumInputValue = $event"
													@keydown.enter.prevent="addEnumValueAndClear(key)" />

												<!-- Default Value Configuration -->
												<NcActionSeparator />
												<NcActionCaption name="Default Value Configuration" />
												<template v-if="property.type === 'string'">
													<NcActionInput
														:value="property.default || ''"
														label="Default Value"
														@update:value="updatePropertySetting(key, 'default', $event === '' ? undefined : $event)" />
												</template>
												<template v-else-if="property.type === 'number' || property.type === 'integer'">
													<NcActionInput
														:value="property.default || 0"
														type="number"
														label="Default Value"
														@update:value="updatePropertySetting(key, 'default', Number($event))" />
												</template>
												<template v-else-if="property.type === 'boolean'">
													<NcActionCheckbox
														:checked="property.default === true"
														@update:checked="updatePropertySetting(key, 'default', $event)">
														Default Value
													</NcActionCheckbox>
												</template>
												<template v-else-if="property.type === 'array' && property.items && property.items.type === 'string'">
													<NcActionInput
														:value="getArrayDefaultAsString(property.default)"
														label="Default Values (comma separated)"
														placeholder="value1, value2, value3"
														@update:value="updateArrayDefault(key, $event)" />
												</template>
												<template v-else-if="property.type === 'object'">
													<NcActionInput
														:value="typeof property.default === 'object' ? JSON.stringify(property.default, null, 2) : (property.default || '{}')"
														label="Default Value (JSON)"
														@update:value="updateObjectDefault(key, $event)" />
												</template>

												<!-- Default Behavior Toggle -->
												<template v-if="property.default !== undefined && property.default !== null && property.default !== ''">
													<NcActionCheckbox
														:checked="property.defaultBehavior === 'falsy'"
														@update:checked="updatePropertySetting(key, 'defaultBehavior', $event ? 'falsy' : 'false')">
														Apply default for empty values
													</NcActionCheckbox>
													<NcActionCaption
														v-if="property.defaultBehavior === 'falsy'"
														name="ℹ️ Default will be applied when value is missing, null, or empty string"
														style="color: var(--color-text-lighter); font-size: 11px;" />
													<NcActionCaption
														v-else
														name="ℹ️ Default will only be applied when value is missing or null"
														style="color: var(--color-text-lighter); font-size: 11px;" />
												</template>

												<!-- Type-specific configurations -->
												<template v-if="property.type === 'string'">
													<NcActionSeparator />
													<NcActionCaption name="String Configuration" />
													<NcActionInput
														:value="property.minLength || 0"
														type="number"
														label="Minimum Length"
														@update:value="updatePropertySetting(key, 'minLength', Number($event))" />
													<NcActionInput
														:value="property.maxLength || 0"
														type="number"
														label="Maximum Length"
														@update:value="updatePropertySetting(key, 'maxLength', Number($event))" />
													<NcActionInput
														:value="property.pattern || ''"
														label="Pattern (regex)"
														@update:value="updatePropertySetting(key, 'pattern', $event)" />
												</template>

												<template v-if="property.type === 'number' || property.type === 'integer'">
													<NcActionSeparator />
													<NcActionCaption name="Number Configuration" />
													<NcActionInput
														:value="property.minimum || 0"
														type="number"
														label="Minimum Value"
														@update:value="updatePropertySetting(key, 'minimum', Number($event))" />
													<NcActionInput
														:value="property.maximum || 0"
														type="number"
														label="Maximum Value"
														@update:value="updatePropertySetting(key, 'maximum', Number($event))" />
													<NcActionInput
														:value="property.multipleOf || 0"
														type="number"
														label="Multiple Of"
														@update:value="updatePropertySetting(key, 'multipleOf', Number($event))" />
													<NcActionCheckbox
														:checked="property.exclusiveMin || false"
														@update:checked="updatePropertySetting(key, 'exclusiveMin', $event)">
														Exclusive Minimum
													</NcActionCheckbox>
													<NcActionCheckbox
														:checked="property.exclusiveMax || false"
														@update:checked="updatePropertySetting(key, 'exclusiveMax', $event)">
														Exclusive Maximum
													</NcActionCheckbox>
												</template>

												<template v-if="property.type === 'array'">
													<NcActionSeparator />
													<NcActionCaption name="Array Configuration" />
													<NcActionInput
														v-model="schemaItem.properties[key].items.type"
														type="multiselect"
														:options="[
															{ id: 'string', label: 'String' },
															{ id: 'number', label: 'Number' },
															{ id: 'integer', label: 'Integer' },
															{ id: 'object', label: 'Object' },
															{ id: 'boolean', label: 'Boolean' },
															{ id: 'file', label: 'File' }
														]"
														input-label="Array Item Type"
														label="Array Item Type" />
													<NcActionInput
														:value="property.minItems || 0"
														type="number"
														label="Minimum Items"
														@update:value="updatePropertySetting(key, 'minItems', Number($event))" />
													<NcActionInput
														:value="property.maxItems || 0"
														type="number"
														label="Maximum Items"
														@update:value="updatePropertySetting(key, 'maxItems', Number($event))" />

													<!-- Show object configuration for array items when item type is object -->
													<template v-if="property.items && property.items.type === 'object'">
														<NcActionSeparator />
														<NcActionCaption name="Array Item Object Configuration" />
														<NcActionInput
															v-model="schemaItem.properties[key].items.objectConfiguration.handling"
															type="multiselect"
															:options="[
																{ id: 'nested-object', label: 'Nested Object' },
																{ id: 'related-object', label: 'Related Object' },
																{ id: 'nested-schema', label: 'Nested Schema' },
																{ id: 'related-schema', label: 'Related Schema' },
																{ id: 'uri', label: 'URI' }
															]"
															input-label="Object Handling"
															label="Object Handling" />
														<NcActionInput
															:value="schemaItem.properties[key].items.$ref"
															type="multiselect"
															:options="availableSchemas"
															input-label="Schema Reference"
															label="Schema Reference"
															@update:value="updateArrayItemSchemaReference(key, $event)" />
														<NcActionCaption
															v-if="isArrayItemRefInvalid(key)"
															:name="`⚠️ Invalid Schema Reference: Expected string, got number (${schemaItem.properties[key].items.$ref}). This will be sent to backend as-is.`"
															style="color: var(--color-error); font-weight: bold;" />
														<NcActionInput
															:value="getArrayItemRegisterValue(key)"
															type="multiselect"
															:options="availableRegisters"
															input-label="Register"
															label="Register (Required when schema is selected)"
															:required="!!schemaItem.properties[key].items.$ref"
															:disabled="!schemaItem.properties[key].items.$ref"
															@update:value="updateArrayItemRegisterReference(key, $event)" />
														<NcActionInput
															v-model="schemaItem.properties[key].items.inversedBy"
															type="multiselect"
															:options="getInversedByOptionsForArrayItems(key)"
															input-label="Inversed By Property"
															label="Inversed By"
															:disabled="!schemaItem.properties[key].items.$ref"
															@update:value="updateInversedByForArrayItems(key, $event)" />
														<NcActionInput
															:value="getArrayItemQueryParams(key)"
															label="Query Parameters"
															placeholder="e.g. gemmaType=referentiecomponent&_extend=aanbevolenStandaarden"
															@update:value="updateArrayItemQueryParams(key, $event)" />
														<NcActionCheckbox
															:checked="property.items.writeBack || false"
															@update:checked="updateArrayItemObjectConfigurationSetting(key, 'writeBack', $event)">
															Write Back
														</NcActionCheckbox>
														<NcActionCheckbox
															:checked="property.items.removeAfterWriteBack || false"
															@update:checked="updateArrayItemObjectConfigurationSetting(key, 'removeAfterWriteBack', $event)">
															Remove After Write Back
														</NcActionCheckbox>
														<NcActionCheckbox
															:checked="property.items.cascadeDelete || false"
															@update:checked="updateArrayItemObjectConfigurationSetting(key, 'cascadeDelete', $event)">
															Cascade Delete
														</NcActionCheckbox>
													</template>
												</template>

												<template v-if="property.type === 'object'">
													<NcActionSeparator />
													<NcActionCaption name="Object Configuration" />
													<NcActionInput
														v-model="schemaItem.properties[key].objectConfiguration.handling"
														type="multiselect"
														:options="[
															{ id: 'nested-object', label: 'Nested Object' },
															{ id: 'related-object', label: 'Related Object' },
															{ id: 'nested-schema', label: 'Nested Schema' },
															{ id: 'related-schema', label: 'Related Schema' },
															{ id: 'uri', label: 'URI' }
														]"
														input-label="Object Handling"
														label="Object Handling" />
													<NcActionInput
														:value="schemaItem.properties[key].$ref"
														type="multiselect"
														:options="availableSchemas"
														input-label="Schema Reference"
														label="Schema Reference"
														@update:value="updateSchemaReference(key, $event)" />
													<NcActionCaption
														v-if="isRefInvalid(key)"
														:name="`⚠️ Invalid Schema Reference: Expected string, got number (${schemaItem.properties[key].$ref}). This will be sent to backend as-is.`"
														style="color: var(--color-error); font-weight: bold;" />
													<NcActionInput
														:value="getRegisterValue(key)"
														type="multiselect"
														:options="availableRegisters"
														input-label="Register"
														label="Register (Required when schema is selected)"
														:required="!!schemaItem.properties[key].$ref"
														:disabled="!schemaItem.properties[key].$ref"
														@update:value="updateRegisterReference(key, $event)" />
													<NcActionInput
														v-model="schemaItem.properties[key].inversedBy"
														type="multiselect"
														:options="getInversedByOptions(key)"
														input-label="Inversed By Property"
														label="Inversed By"
														:disabled="!schemaItem.properties[key].$ref"
														@update:value="updateInversedBy(key, $event)" />
													<NcActionInput
														:value="getObjectQueryParams(key)"
														label="Query Parameters"
														placeholder="e.g. gemmaType=referentiecomponent&_extend=aanbevolenStandaarden"
														@update:value="updateObjectQueryParams(key, $event)" />
													<NcActionCheckbox
														:checked="property.writeBack || false"
														@update:checked="updatePropertySetting(key, 'writeBack', $event)">
														Write Back
													</NcActionCheckbox>
													<NcActionCheckbox
														:checked="property.removeAfterWriteBack || false"
														@update:checked="updatePropertySetting(key, 'removeAfterWriteBack', $event)">
														Remove After Write Back
													</NcActionCheckbox>
													<NcActionCheckbox
														:checked="property.cascadeDelete || false"
														@update:checked="updatePropertySetting(key, 'cascadeDelete', $event)">
														Cascade Delete
													</NcActionCheckbox>
												</template>

												<!-- File Configuration -->
												<template v-if="property.type === 'file' || (property.type === 'array' && property.items && property.items.type === 'file')">
													<NcActionSeparator />
													<NcActionCaption name="File Configuration" />
													<NcActionCheckbox
														:checked="getFilePropertySetting(key, 'autoPublish')"
														@update:checked="updateFilePropertySetting(key, 'autoPublish', $event)">
														Auto-Publish Files
													</NcActionCheckbox>
													<NcActionCaption
														v-if="getFilePropertySetting(key, 'autoPublish')"
														name="ℹ️ Files uploaded to this property will be automatically publicly shared"
														style="color: var(--color-text-lighter); font-size: 11px;" />
													<NcActionInput
														:value="(property.allowedTypes || []).join(', ')"
														label="Allowed MIME Types (comma separated)"
														placeholder="image/png, image/jpeg, application/pdf"
														@update:value="updateFileProperty(key, 'allowedTypes', $event)" />
													<NcActionInput
														:value="property.maxSize || ''"
														type="number"
														label="Maximum File Size (bytes)"
														placeholder="5242880"
														@update:value="updateFileProperty(key, 'maxSize', $event)" />
													<NcActionInput
														:value="getFilePropertyTags(key, 'allowedTags')"
														type="multiselect"
														:options="availableTagsOptions"
														input-label="Allowed Tags"
														label="Allowed Tags (select from available tags)"
														multiple
														@update:value="updateFilePropertyTags(key, 'allowedTags', $event)" />
													<NcActionInput
														:value="getFilePropertyTags(key, 'autoTags')"
														type="multiselect"
														:options="availableTagsOptions"
														input-label="Auto Tags"
														label="Auto Tags (automatically applied to uploaded files)"
														multiple
														@update:value="updateFilePropertyTags(key, 'autoTags', $event)" />
												</template>

												<!-- Property-level Table Configuration -->
												<NcActionSeparator />
												<NcActionCaption name="Table" />
												<NcActionCheckbox
													:checked="getPropertyTableSetting(key, 'default')"
													@update:checked="updatePropertyTableSetting(key, 'default', $event)">
													Default
												</NcActionCheckbox>

												<!-- Property-level Security Configuration -->
												<NcActionSeparator />
												<NcActionCaption name="Property Security" />

												<template v-if="!loadingGroups">
													<!-- Current Property Permissions List -->
													<div v-for="permission in getPropertyPermissionsList(key)" :key="`${key}-perm-text-${permission.group}`">
														<NcActionText
															class="property-permission-text">
															{{ permission.group }} ({{ permission.rights }})
														</NcActionText>
														<NcActionButton
															v-if="permission.groupId !== 'admin'"
															:key="`${key}-perm-remove-${permission.group}`"
															:aria-label="`Remove ${permission.group} permissions`"
															class="property-permission-remove-btn"
															@click="removePropertyGroupPermissions(key, permission.group)">
															<template #icon>
																<Close :size="16" />
															</template>
															Remove {{ permission.group }}
														</NcActionButton>
													</div>

													<!-- Show inheritance status if no specific permissions -->
													<NcActionCaption
														v-if="!hasPropertyAnyPermissions(key)"
														name="📄 Inherits schema permissions"
														style="color: var(--color-success); font-size: 11px;" />

													<!-- Add Permission Interface -->
													<NcActionSeparator />
													<NcActionInput
														v-model="propertyNewPermissionGroup"
														type="multiselect"
														:options="getAvailableGroupsForProperty()"
														input-label="Group"
														label="Add Group Permission"
														placeholder="Select group..." />

													<template v-if="propertyNewPermissionGroup">
														<NcActionCaption name="Select Permissions:" />
														<NcActionCheckbox
															:checked="propertyNewPermissionCreate"
															@update:checked="propertyNewPermissionCreate = $event">
															Create (C)
														</NcActionCheckbox>
														<NcActionCheckbox
															:checked="propertyNewPermissionRead"
															@update:checked="propertyNewPermissionRead = $event">
															Read (R)
														</NcActionCheckbox>
														<NcActionCheckbox
															:checked="propertyNewPermissionUpdate"
															@update:checked="propertyNewPermissionUpdate = $event">
															Update (U)
														</NcActionCheckbox>
														<NcActionCheckbox
															:checked="propertyNewPermissionDelete"
															@update:checked="propertyNewPermissionDelete = $event">
															Delete (D)
														</NcActionCheckbox>

														<NcActionButton
															v-if="hasAnyPropertyNewPermissionSelected()"
															@click="addPropertyGroupPermissions(key)">
															<template #icon>
																<Plus :size="16" />
															</template>
															Add Permission
														</NcActionButton>
													</template>
												</template>
												<template v-else>
													<NcActionCaption name="Loading groups..." />
												</template>
											</NcActions>
										</td>
									</tr>
									<tr v-if="!Object.keys(schemaItem.properties || {}).length">
										<td colspan="3">
											No properties found. Click "Add property" to create one.
										</td>
									</tr>
								</tbody>
							</table>
						</div>
						<NcNoteCard v-if="propertiesModified && !loading" type="warning" class="properties-warning">
							<p>Properties have been modified. Changes will only take effect after the schema is saved.</p>
						</NcNoteCard>
					</BTab>
					<BTab title="Configuration">
						<div class="form-editor">
							<NcTextArea :disabled="loading"
								label="Description"
								:value.sync="schemaItem.description" />
							<NcTextArea :disabled="loading"
								label="Summary"
								:value.sync="schemaItem.summary" />
							<NcTextField :disabled="loading"
								label="Slug"
								:value.sync="schemaItem.slug" />
							<NcSelect
								v-model="schemaItem.extend"
								:disabled="loading"
								:options="availableSchemas"
								:clearable="true"
								label="title"
								track-by="id"
								input-label="Extends Schema"
								placeholder="Select a schema to extend (optional)">
								<template #option="{ title, description }">
									<div class="schema-option">
										<span class="schema-title">{{ title }}</span>
										<span v-if="description" class="schema-description">{{ description }}</span>
									</div>
								</template>
							</NcSelect>
							<NcNoteCard v-if="schemaItem.extend" type="info" class="extend-info">
								<p><strong>{{ t('openregister', 'Schema Extension') }}</strong></p>
								<p v-if="parentSchemaName">
									{{ t('openregister', 'This schema extends "{parent}" and inherits its properties. Only the differences (delta) are stored. When retrieved, properties from the parent schema will be merged with this schema\'s properties.', { parent: parentSchemaName }) }}
								</p>
								<p v-else>
									{{ t('openregister', 'This schema extends another schema and inherits its properties. Only the differences (delta) are stored. When retrieved, properties from the parent schema will be merged with this schema\'s properties.') }}
								</p>
								<div v-if="parentSchemaName" class="parent-schema-link">
									<strong>{{ t('openregister', 'Parent Schema:') }}</strong> {{ parentSchemaName }}
								</div>
							</NcNoteCard>
							<NcSelect
								v-model="schemaItem.configuration.objectNameField"
								:disabled="loading"
								:options="propertyOptions"
								input-label="Object Name Field"
								placeholder="Select a property to use as object name" />
							<NcSelect
								v-model="schemaItem.configuration.objectDescriptionField"
								:disabled="loading"
								:options="propertyOptions"
								input-label="Object Description Field"
								placeholder="Select a property to use as object description" />
							<NcSelect
								v-model="schemaItem.configuration.objectImageField"
								:disabled="loading"
								:options="propertyOptions"
								input-label="Object Image Field"
								placeholder="Select a property to use as object image representing the object. e.g. logo (should contain base64 encoded image)" />
							<NcSelect
								v-model="schemaItem.configuration.objectSummaryField"
								:disabled="loading"
								:options="propertyOptions"
								input-label="Object Summary Field"
								placeholder="Select a property to use as object summary. e.g. summary, abstract, or excerpt" />
							<NcCheckboxRadioSwitch
								:disabled="loading"
								:checked.sync="schemaItem.configuration.allowFiles">
								Allow Files
							</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
								:disabled="loading"
								:checked.sync="schemaItem.configuration.autoPublish">
								Auto-Publish Objects
							</NcCheckboxRadioSwitch>
							<NcTextField
								v-model="allowedTagsInput"
								:disabled="loading"
								label="Allowed Tags (comma-separated)"
								placeholder="image, document, audio, video"
								@update:value="updateAllowedTags" />
							<NcCheckboxRadioSwitch
								:disabled="loading"
								:checked.sync="schemaItem.hardValidation">
								Hard Validation
							</NcCheckboxRadioSwitch>
							<NcTextField :disabled="loading"
								label="Max Depth"
								type="number"
								:value.sync="schemaItem.maxDepth" />
							<NcCheckboxRadioSwitch
								:disabled="loading"
								:checked.sync="schemaItem.immutable">
								Immutable
							</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
								:disabled="loading"
								:checked.sync="schemaItem.searchable">
								Searchable in SOLR
							</NcCheckboxRadioSwitch>
						</div>
					</BTab>
					<BTab title="Security">
						<div class="security-section">
							<NcNoteCard type="info">
								<p><strong>Role-Based Access Control (RBAC)</strong></p>
								<p>Configure which Nextcloud user groups can perform CRUD operations on objects of this schema.</p>
								<ul>
									<li>If no groups are specified for an operation, all users can perform it</li>
									<li>The 'admin' group always has full access (cannot be changed)</li>
									<li>The object owner always has full access</li>
									<li>'public' represents unauthenticated access</li>
								</ul>
							</NcNoteCard>

							<div v-if="loadingGroups" class="loading-groups">
								<NcLoadingIcon :size="20" />
								<span>Loading user groups...</span>
							</div>

							<div v-else class="rbac-table-container">
								<h3>Group Permissions</h3>
								<table class="rbac-table">
									<thead>
										<tr>
											<th>Group</th>
											<th>Create</th>
											<th>Read</th>
											<th>Update</th>
											<th>Delete</th>
										</tr>
									</thead>
									<tbody>
										<!-- Public group at top -->
										<tr class="public-row">
											<td class="group-name">
												<span class="group-badge public">public</span>
												<small>Unauthenticated users</small>
											</td>
											<td>
												<NcCheckboxRadioSwitch
													:checked="hasGroupPermission('public', 'create')"
													@update:checked="updateGroupPermission('public', 'create', $event)" />
											</td>
											<td>
												<NcCheckboxRadioSwitch
													:checked="hasGroupPermission('public', 'read')"
													@update:checked="updateGroupPermission('public', 'read', $event)" />
											</td>
											<td>
												<NcCheckboxRadioSwitch
													:checked="hasGroupPermission('public', 'update')"
													@update:checked="updateGroupPermission('public', 'update', $event)" />
											</td>
											<td>
												<NcCheckboxRadioSwitch
													:checked="hasGroupPermission('public', 'delete')"
													@update:checked="updateGroupPermission('public', 'delete', $event)" />
											</td>
										</tr>

										<!-- User group (authenticated users) -->
										<tr class="user-row">
											<td class="group-name">
												<span class="group-badge user">user</span>
												<small>Authenticated users</small>
											</td>
											<td>
												<NcCheckboxRadioSwitch
													:checked="hasGroupPermission('user', 'create')"
													@update:checked="updateGroupPermission('user', 'create', $event)" />
											</td>
											<td>
												<NcCheckboxRadioSwitch
													:checked="hasGroupPermission('user', 'read')"
													@update:checked="updateGroupPermission('user', 'read', $event)" />
											</td>
											<td>
												<NcCheckboxRadioSwitch
													:checked="hasGroupPermission('user', 'update')"
													@update:checked="updateGroupPermission('user', 'update', $event)" />
											</td>
											<td>
												<NcCheckboxRadioSwitch
													:checked="hasGroupPermission('user', 'delete')"
													@update:checked="updateGroupPermission('user', 'delete', $event)" />
											</td>
										</tr>

										<!-- Regular user groups -->
										<tr v-for="group in sortedUserGroups" :key="group.id" class="group-row">
											<td class="group-name">
												<span class="group-badge">{{ group.displayname || group.id }}</span>
												<small v-if="group.displayname && group.displayname !== group.id">{{ group.id }}</small>
											</td>
											<td>
												<NcCheckboxRadioSwitch
													:checked="hasGroupPermission(group.id, 'create')"
													@update:checked="updateGroupPermission(group.id, 'create', $event)" />
											</td>
											<td>
												<NcCheckboxRadioSwitch
													:checked="hasGroupPermission(group.id, 'read')"
													@update:checked="updateGroupPermission(group.id, 'read', $event)" />
											</td>
											<td>
												<NcCheckboxRadioSwitch
													:checked="hasGroupPermission(group.id, 'update')"
													@update:checked="updateGroupPermission(group.id, 'update', $event)" />
											</td>
											<td>
												<NcCheckboxRadioSwitch
													:checked="hasGroupPermission(group.id, 'delete')"
													@update:checked="updateGroupPermission(group.id, 'delete', $event)" />
											</td>
										</tr>

										<!-- Admin group at bottom (disabled) -->
										<tr class="admin-row">
											<td class="group-name">
												<span class="group-badge admin">admin</span>
												<small>Always has full access</small>
											</td>
											<td>
												<NcCheckboxRadioSwitch
													:checked="true"
													:disabled="true" />
											</td>
											<td>
												<NcCheckboxRadioSwitch
													:checked="true"
													:disabled="true" />
											</td>
											<td>
												<NcCheckboxRadioSwitch
													:checked="true"
													:disabled="true" />
											</td>
											<td>
												<NcCheckboxRadioSwitch
													:checked="true"
													:disabled="true" />
											</td>
										</tr>
									</tbody>
								</table>

								<div class="rbac-summary">
									<NcNoteCard v-if="!hasAnyPermissions" type="success">
										<p><strong>Open Access:</strong> No specific permissions set - all users can perform all operations.</p>
									</NcNoteCard>
									<NcNoteCard v-else-if="isRestrictiveSchema" type="warning">
										<p><strong>Restrictive Schema:</strong> Access is limited to specified groups only.</p>
									</NcNoteCard>
								</div>
							</div>
						</div>
					</BTab>
				</BTabs>
			</div>
		</div>

		<template #actions>
			<NcCheckboxRadioSwitch
				v-if="!schemaStore.schemaItem?.id"
				class="create-another-checkbox"
				:disabled="loading"
				:checked.sync="createAnother">
				Create another
			</NcCheckboxRadioSwitch>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? 'Close' : 'Cancel' }}
			</NcButton>
			<NcButton v-if="createAnother ||!success"
				:disabled="loading || !schemaItem.title"
				type="primary"
				@click="editSchema()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentSaveOutline v-if="!loading && schemaStore.schemaItem?.id" :size="20" />
					<Plus v-if="!loading && !schemaStore.schemaItem?.id" :size="20" />
				</template>
				{{ schemaStore.schemaItem?.id && !createAnother ? 'Save' : 'Create' }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcTextField,
	NcTextArea,
	NcLoadingIcon,
	NcNoteCard,
	NcCheckboxRadioSwitch,
	NcSelect,
	NcActions,
	NcActionButton,
	NcActionCheckbox,
	NcActionInput,
	NcActionCaption,
	NcActionSeparator,
	NcActionText,
} from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'

import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Check from 'vue-material-design-icons/Check.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import Close from 'vue-material-design-icons/Close.vue'

export default {
	name: 'EditSchema',
	components: {
		NcDialog,
		NcTextField,
		NcTextArea,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcActions,
		NcActionButton,
		NcActionCheckbox,
		NcActionInput,
		NcActionCaption,
		NcActionSeparator,
		NcActionText,
		BTabs,
		BTab,
		// Icons
		ContentSaveOutline,
		Cancel,
		Plus,
		ContentCopy,
		Check,
		TrashCanOutline,
		AlertOutline,
		Close,
	},
	data() {
		return {
			activeTab: 0,
			isCopied: false,
			selectedProperty: null,
			propertiesModified: false,
			originalProperties: null,
			propertyStableIds: {}, // Map property names to stable IDs
			nextPropertyId: 1, // Counter for generating unique IDs
			enumInputValue: '', // For entering new enum values
			allowedTagsInput: '', // For entering allowed tags as comma-separated string
			availableTags: [], // Available tags from the API
			// Property-level permission interface
			propertyNewPermissionGroup: null,
			propertyNewPermissionCreate: false,
			propertyNewPermissionRead: false,
			propertyNewPermissionUpdate: false,
			propertyNewPermissionDelete: false,
			schemaItem: {
				title: '',
				version: '0.0.0',
				description: '',
				summary: '',
				slug: '',
				properties: {},
				configuration: {
					objectNameField: '',
					objectDescriptionField: '',
					objectImageField: '',
					objectSummaryField: '',
					allowFiles: false,
					allowedTags: [],
					autoPublish: false,
				},
				authorization: {},
				hardValidation: false,
				immutable: false,
				searchable: true,
				maxDepth: 0,
			},
			createAnother: false,
			success: false,
			loading: false,
			error: false,
			closeModalTimeout: null,
			loadingGroups: false,
			userGroups: [], // List of Nextcloud user groups
			typeOptions: [
				{ label: 'String', value: 'string' },
				{ label: 'Number', value: 'number' },
				{ label: 'Integer', value: 'integer' },
				{ label: 'Boolean', value: 'boolean' },
				{ label: 'Array', value: 'array' },
				{ label: 'Object', value: 'object' },
			],
		}
	},
	computed: {
		sortedProperties() {
			return (schema) => {
				const properties = schema.properties || {}
				return Object.entries(properties)
					.sort(([keyA, propA], [keyB, propB]) => {
						const orderA = propA.order || 0
						const orderB = propB.order || 0
						if (orderA > 0 && orderB > 0) {
							return orderA - orderB
						}
						if (orderA > 0) return -1
						if (orderB > 0) return 1
						const createdA = propA.created || ''
						const createdB = propB.created || ''
						return createdA.localeCompare(createdB)
					})
					.reduce((acc, [key, value]) => {
						acc[key] = value
						return acc
					}, {})
			}
		},
		sortedUserGroups() {
			// Filter out admin and public groups, sort alphabetically
			return this.userGroups
				.filter(group => group.id !== 'admin' && group.id !== 'public')
				.sort((a, b) => {
					const nameA = a.displayname || a.id
					const nameB = b.displayname || b.id
					return nameA.localeCompare(nameB)
				})
		},
		hasAnyPermissions() {
			const auth = this.schemaItem.authorization || {}
			return Object.keys(auth).some(action =>
				Array.isArray(auth[action]) && auth[action].length > 0,
			)
		},
		isRestrictiveSchema() {
			const auth = this.schemaItem.authorization || {}
			const actions = ['create', 'read', 'update', 'delete']
			return actions.some(action =>
				Array.isArray(auth[action]) && auth[action].length > 0
					&& !auth[action].includes('public'),
			)
		},
		typeOptionsForSelect() {
			return [
				{ id: 'string', label: 'String' },
				{ id: 'number', label: 'Number' },
				{ id: 'integer', label: 'Integer' },
				{ id: 'boolean', label: 'Boolean' },
				{ id: 'array', label: 'Array' },
				{ id: 'object', label: 'Object' },
				{ id: 'dictionary', label: 'Dictionary' },
				{ id: 'file', label: 'File' },
				{ id: 'oneOf', label: 'One Of' },
			]
		},
		propertyOptions() {
			const properties = this.schemaItem.properties || {}
			const options = Object.keys(properties)
			// Add empty option at the beginning
			return ['', ...options]
		},
		availableRegisters() {
			return registerStore.registerList.map(register => ({
				id: register.id,
				label: register.title || register.name || register.id,
			}))
		},
		availableSchemas() {
			// Return all schemas regardless of register selection
			// The register selection is optional and used for explicit register specification
			// Exclude the current schema to prevent self-extension
			return schemaStore.schemaList
				.filter(schema => {
					// Exclude current schema by checking id, uuid, and slug
					const currentId = this.schemaItem.id
					const currentUuid = this.schemaItem.uuid
					const currentSlug = this.schemaItem.slug

					return schema.id !== currentId
						&& schema.uuid !== currentUuid
						&& schema.slug !== currentSlug
				})
				.map(schema => ({
					// Use schema ID as the value (what gets stored in extend property)
					id: schema.id || schema.uuid || schema.slug,
					// Display title for user-friendly selection
					title: schema.title || schema.name || `Schema ${schema.id}`,
					// Add description for additional context
					description: schema.description || schema.summary || '',
					// Keep reference format for other uses (like property references)
					reference: `#/components/schemas/${schema.slug || schema.title || schema.id}`,
				}))
		},
		availableTagsOptions() {
			// Return available tags for multiselect
			return this.availableTags.map(tag => ({
				id: tag,
				label: tag,
			}))
		},
		/**
		 * Get the parent schema name if this schema extends another
		 *
		 * @return {string|null} Parent schema title or null
		 */
		parentSchemaName() {
			if (!this.schemaItem.extend) {
				return null
			}

			// Find the parent schema by ID, UUID, or slug
			const parentSchema = schemaStore.schemaList.find(schema =>
				schema.id === this.schemaItem.extend
				|| schema.uuid === this.schemaItem.extend
				|| schema.slug === this.schemaItem.extend
			)

			return parentSchema ? (parentSchema.title || parentSchema.name || `Schema ${parentSchema.id}`) : null
		},
	},
	watch: {
		'schemaItem.properties': {
			handler(newProperties) {
				// Convert any object values back to strings for multiselect fields
				if (newProperties) {
					Object.keys(newProperties).forEach(key => {
						const property = newProperties[key]
						if (property) {
							// Initialize nested objects if they don't exist
							if (property.type === 'array' && !property.items) {
								this.$set(this.schemaItem.properties[key], 'items', { type: 'string' })
							}
							if (property.type === 'object' && !property.objectConfiguration) {
								this.$set(this.schemaItem.properties[key], 'objectConfiguration', { handling: 'nested-object' })
							}
							// Initialize array item object configuration if items type is object
							if (property.type === 'array' && property.items && property.items.type === 'object' && !property.items.objectConfiguration) {
								this.$set(this.schemaItem.properties[key].items, 'objectConfiguration', { handling: 'nested-object' })
							}

							// Convert property type from object to string
							if (property.type && typeof property.type === 'object' && property.type.id) {
								this.$set(this.schemaItem.properties[key], 'type', property.type.id)
							}

							// Convert property format from object to string
							if (property.format && typeof property.format === 'object' && property.format.id) {
								this.$set(this.schemaItem.properties[key], 'format', property.format.id)
							}

							// Convert array item type from object to string
							if (property.items && property.items.type && typeof property.items.type === 'object' && property.items.type.id) {
								this.$set(this.schemaItem.properties[key].items, 'type', property.items.type.id)
							}

							// Convert object handling from object to string
							if (property.objectConfiguration && property.objectConfiguration.handling
								&& typeof property.objectConfiguration.handling === 'object' && property.objectConfiguration.handling.id) {
								this.$set(this.schemaItem.properties[key].objectConfiguration, 'handling', property.objectConfiguration.handling.id)
							}

							// Convert register from object to ID
							if (property.objectConfiguration && property.objectConfiguration.register
								&& typeof property.objectConfiguration.register === 'object' && property.objectConfiguration.register.id) {
								this.$set(this.schemaItem.properties[key].objectConfiguration, 'register', property.objectConfiguration.register.id)
							}

							// Convert array item object handling from object to string
							if (property.items && property.items.objectConfiguration && property.items.objectConfiguration.handling
								&& typeof property.items.objectConfiguration.handling === 'object' && property.items.objectConfiguration.handling.id) {
								this.$set(this.schemaItem.properties[key].items.objectConfiguration, 'handling', property.items.objectConfiguration.handling.id)
							}

							// Convert array item register from object to ID
							if (property.items && property.items.objectConfiguration && property.items.objectConfiguration.register
								&& typeof property.items.objectConfiguration.register === 'object' && property.items.objectConfiguration.register.id) {
								this.$set(this.schemaItem.properties[key].items.objectConfiguration, 'register', property.items.objectConfiguration.register.id)
							}

							// Ensure $ref is always a string
							this.ensureRefIsString(this.schemaItem.properties, key)

							// Ensure inversedBy is always a string for regular properties
							if (property.inversedBy && typeof property.inversedBy === 'object' && property.inversedBy.id) {
								this.$set(this.schemaItem.properties[key], 'inversedBy', property.inversedBy.id)
							}

							// Ensure inversedBy is always a string for array items
							if (property.items && property.items.inversedBy && typeof property.items.inversedBy === 'object' && property.items.inversedBy.id) {
								this.$set(this.schemaItem.properties[key].items, 'inversedBy', property.items.inversedBy.id)
							}
						}
					})
				}
				this.checkPropertiesModified()
			},
			deep: true,
		},
	},
	mounted() {
		this.initializeSchemaItem()
		this.loadRegistersAndSchemas()
		this.loadUserGroups()
		this.fetchAvailableTags()
	},
	methods: {
		async fetchAvailableTags() {
			try {
				const response = await fetch('/index.php/apps/openregister/api/tags')
				if (response.ok) {
					const tags = await response.json()
					this.availableTags = Array.isArray(tags) ? tags : []
				} else {
					console.warn('Failed to fetch available tags:', response.statusText)
					this.availableTags = []
				}
			} catch (error) {
				console.error('Error fetching available tags:', error)
				this.availableTags = []
			}
		},
		async loadRegistersAndSchemas() {
			try {
				// Load registers if not already loaded
				if (!registerStore.registerList.length) {
					await registerStore.refreshRegisterList()
				}

				// Load schemas if not already loaded
				if (!schemaStore.schemaList.length) {
					await schemaStore.refreshSchemaList()
				}
			} catch (error) {
				console.error('Error loading registers and schemas:', error)
			}
		},

		// Generate or get stable ID for a property
		getStablePropertyId(propertyName) {
			if (!this.propertyStableIds[propertyName]) {
				this.propertyStableIds[propertyName] = this.nextPropertyId++
			}
			return this.propertyStableIds[propertyName]
		},

		isPropertyRequired(schema, key) {
			// Check both the schema-level required array and the property-level required field
			const isInSchemaRequired = schema.required && schema.required.includes(key)
			const hasPropertyRequired = schema.properties && schema.properties[key] && schema.properties[key].required === true
			return isInSchemaRequired || hasPropertyRequired
		},
		initializeSchemaItem() {
			if (schemaStore.schemaItem?.id) {
				this.schemaItem = {
					...this.schemaItem, // Keep default structure
					...schemaStore.schemaItem,
				}

				// Ensure configuration object exists and has the required structure
				if (!this.schemaItem.configuration) {
					this.schemaItem.configuration = {
						objectNameField: '',
						objectDescriptionField: '',
						objectImageField: '',
						objectSummaryField: '',
						allowFiles: false,
						allowedTags: [],
					}
				} else {
				// Ensure all configuration fields exist
					if (!this.schemaItem.configuration.objectNameField) {
						this.schemaItem.configuration.objectNameField = ''
					}
					if (!this.schemaItem.configuration.objectDescriptionField) {
						this.schemaItem.configuration.objectDescriptionField = ''
					}
					if (!this.schemaItem.configuration.objectImageField) {
						this.schemaItem.configuration.objectImageField = ''
					}
					if (!this.schemaItem.configuration.objectSummaryField) {
						this.schemaItem.configuration.objectSummaryField = ''
					}
					if (this.schemaItem.configuration.allowFiles === undefined) {
						this.schemaItem.configuration.allowFiles = false
					}
					if (!this.schemaItem.configuration.allowedTags) {
						this.schemaItem.configuration.allowedTags = []
					}
					if (this.schemaItem.configuration.autoPublish === undefined) {
						this.schemaItem.configuration.autoPublish = false
					}
				}

				// Initialize allowedTagsInput from existing allowedTags array
				this.allowedTagsInput = (this.schemaItem.configuration.allowedTags || []).join(', ')

				// Ensure authorization object exists
				if (!this.schemaItem.authorization) {
					this.schemaItem.authorization = {}
				}

				// Ensure existing properties have facetable set to false by default if not specified
				// and ensure enum arrays are properly reactive
				Object.keys(this.schemaItem.properties || {}).forEach(key => {
					if (this.schemaItem.properties[key].facetable === undefined) {
						this.$set(this.schemaItem.properties[key], 'facetable', false)
					}

					// Ensure enum arrays are reactive
					if (this.schemaItem.properties[key].enum && Array.isArray(this.schemaItem.properties[key].enum)) {
						this.$set(this.schemaItem.properties[key], 'enum', [...this.schemaItem.properties[key].enum])
					}

					// Initialize array item object configuration if needed
					const property = this.schemaItem.properties[key]
					if (property.type === 'array' && property.items && property.items.type === 'object' && !property.items.objectConfiguration) {
						this.$set(this.schemaItem.properties[key].items, 'objectConfiguration', { handling: 'nested-object' })
					}
				})

				// Ensure all $ref values are strings and migrate old structure to new
				Object.keys(this.schemaItem.properties || {}).forEach(key => {
					this.ensureRefIsString(this.schemaItem.properties, key)
					this.migratePropertyToNewStructure(key)
				})

				// Store original properties for comparison AFTER setting defaults
				this.originalProperties = JSON.parse(JSON.stringify(this.schemaItem.properties || {}))
			} else {
				// Initialize configuration for new schemas
				this.schemaItem.configuration = {
					objectNameField: '',
					objectDescriptionField: '',
					objectImageField: '',
					objectSummaryField: '',
					allowFiles: false,
					allowedTags: [],
					autoPublish: false,
				}
				this.allowedTagsInput = ''
				this.originalProperties = {}
			}
			this.propertiesModified = false
		},
		checkPropertiesModified() {
			if (!this.originalProperties) return false

			const currentProperties = JSON.stringify(this.schemaItem.properties || {})
			const originalProperties = JSON.stringify(this.originalProperties)

			this.propertiesModified = currentProperties !== originalProperties
		},
		isPropertyModified(key) {
			if (!this.originalProperties) return false

			const currentProperty = JSON.stringify(this.schemaItem.properties[key] || {})
			const originalProperty = JSON.stringify(this.originalProperties[key] || {})

			return currentProperty !== originalProperty
		},
		async copyToClipboard(text) {
			try {
				await navigator.clipboard.writeText(text)
				this.isCopied = true
				setTimeout(() => { this.isCopied = false }, 2000)
			} catch (err) {
				console.error('Failed to copy text:', err)
			}
		},
		addProperty() {
			// Generate a unique property name
			let newPropertyName = 'new'
			let counter = 1

			while (this.schemaItem.properties[newPropertyName]) {
				counter++
				newPropertyName = `new_${counter}`
			}

			// Add the new property with default values
			this.$set(this.schemaItem.properties, newPropertyName, {
				type: 'string',
				format: '',
				title: newPropertyName,
				description: '',
				facetable: false, // Default to false for new properties
			})

			// Ensure stable ID is created for the new property
			this.getStablePropertyId(newPropertyName)

			// Check if properties have been modified
			this.checkPropertiesModified()

			// Select the new property for editing
			this.selectedProperty = newPropertyName

			// Focus the input field after Vue updates the DOM
			this.$nextTick(() => {
				if (this.$refs.propertyNameInput && this.$refs.propertyNameInput[0]) {
					this.$refs.propertyNameInput[0].$el.querySelector('input').focus()
					this.$refs.propertyNameInput[0].$el.querySelector('input').select()
				}
			})
		},
		handleRowClick(key, event) {
			// Don't select if clicking on an input or button
			if (event.target.tagName === 'INPUT' || event.target.tagName === 'BUTTON' || event.target.closest('.name-input-container')) {
				return
			}

			// Don't deselect if already selected
			if (this.selectedProperty === key) {
				return
			}

			this.selectProperty(key)
		},
		selectProperty(key) {
			this.selectedProperty = key

			// Focus the input field if selecting a property for editing
			if (key) {
				this.$nextTick(() => {
					if (this.$refs.propertyNameInput && this.$refs.propertyNameInput[0]) {
						this.$refs.propertyNameInput[0].$el.querySelector('input').focus()
						this.$refs.propertyNameInput[0].$el.querySelector('input').select()
					}
				})
			}
		},
		updatePropertyKey(oldKey, newKey) {
			// Don't update if the key hasn't changed or is empty
			if (!newKey || newKey === oldKey) {
				return
			}

			// Don't allow duplicate keys
			if (this.schemaItem.properties[newKey] && newKey !== oldKey) {
				return
			}

			// Get the property data first
			const propertyData = {
				...this.schemaItem.properties[oldKey],
				// Keep the existing title - don't update it to match the technical key
			}

			// Transfer the stable ID from old key to new key
			if (this.propertyStableIds[oldKey]) {
				this.propertyStableIds[newKey] = this.propertyStableIds[oldKey]
				delete this.propertyStableIds[oldKey]
			}

			// Use Vue.set to add the new property and Vue.delete to remove the old one
			// This maintains reactivity without recreating the entire object
			this.$set(this.schemaItem.properties, newKey, propertyData)
			this.$delete(this.schemaItem.properties, oldKey)

			this.selectedProperty = newKey // Update selected property to new key

			// Check if properties have been modified
			this.checkPropertiesModified()

			// Ensure the input field stays focused after the update
			this.$nextTick(() => {
				if (this.$refs.propertyNameInput && this.$refs.propertyNameInput[0]) {
					const input = this.$refs.propertyNameInput[0].$el.querySelector('input')
					if (input) {
						input.focus()
						// Set cursor to end of text
						input.setSelectionRange(input.value.length, input.value.length)
					}
				}
			})
		},
		updatePropertyType(key, newType) {
			if (this.schemaItem.properties[key]) {
				// Handle both string values and objects with id property
				const typeValue = typeof newType === 'object' && newType?.id ? newType.id : newType

				this.$set(this.schemaItem.properties[key], 'type', typeValue)
				this.checkPropertiesModified()
			}
		},
		updatePropertyFormat(key, newFormat) {
			if (this.schemaItem.properties[key]) {
				this.$set(this.schemaItem.properties[key], 'format', newFormat)
				this.checkPropertiesModified()
			}
		},
		deleteProperty(key) {
			// Remove the property from the schema
			this.$delete(this.schemaItem.properties, key)

			// Clear selection if deleted property was selected
			if (this.selectedProperty === key) {
				this.selectedProperty = null
			}

			// Check if properties have been modified
			this.checkPropertiesModified()
		},
		closeModal() {
			this.success = false
			this.error = null
			this.createAnother = false
			navigationStore.setModal(false)
			navigationStore.setDialog(false)
			clearTimeout(this.closeModalTimeout)
		},
		async editSchema() {
			this.loading = true

			// Clean up schema properties before saving
			const cleanedSchemaItem = { ...this.schemaItem }
			Object.keys(cleanedSchemaItem.properties || {}).forEach(key => {
				// Ensure all $ref values are strings
				this.ensureRefIsString(cleanedSchemaItem.properties, key)

				// Remove the old register property at root level if it exists
				if (cleanedSchemaItem.properties[key].register
					&& cleanedSchemaItem.properties[key].objectConfiguration
					&& cleanedSchemaItem.properties[key].objectConfiguration.register) {
					delete cleanedSchemaItem.properties[key].register
				}

				// Remove old register property from array items if it exists
				if (cleanedSchemaItem.properties[key].items
					&& cleanedSchemaItem.properties[key].items.register
					&& cleanedSchemaItem.properties[key].items.objectConfiguration
					&& cleanedSchemaItem.properties[key].items.objectConfiguration.register) {
					delete cleanedSchemaItem.properties[key].items.register
				}
			})

			schemaStore.saveSchema(cleanedSchemaItem).then(({ response }) => {

				if (this.createAnother) {
					// since saveSchema populates the schema item, we need to clear it
					schemaStore.setSchemaItem(null)

					// clear the form after 0.5s
					setTimeout(() => {
						this.schemaItem = {
							title: '',
							version: '0.0.0',
							description: '',
							summary: '',
							slug: '',
							properties: {},
							configuration: {
								objectNameField: '',
								objectDescriptionField: '',
								objectImageField: '',
								objectSummaryField: '',
								allowFiles: false,
								allowedTags: [],
								autoPublish: false,
							},
							hardValidation: false,
							immutable: false,
							searchable: true,
							maxDepth: 0,
						}
						this.allowedTagsInput = ''
						this.originalProperties = {}
						this.propertiesModified = false
					}, 500)

					this.success = response.ok
					this.error = false

					// clear the success message after 2s
					setTimeout(() => {
						this.success = null
					}, 2000)
				} else {
					this.success = response.ok
					this.error = false

					if (response.ok) {
						// Reset properties tracking after successful save
						this.originalProperties = JSON.parse(JSON.stringify(this.schemaItem.properties || {}))
						this.propertiesModified = false
						this.closeModalTimeout = setTimeout(this.closeModal, 2000)
					}
				}

			}).catch((error) => {
				this.success = false
				this.error = error.message || 'An error occurred while saving the schema'
			}).finally(() => {
				this.loading = false
			})
		},
		handleDialogClose() {
			this.closeModal()
		},
		// New methods for the action menu functionality
		getFormatOptionsForType(type) {
			const formatMap = {
				string: [
					{ id: 'text', label: 'Text' },
					{ id: 'markdown', label: 'Markdown' },
					{ id: 'html', label: 'HTML' },
					{ id: 'date-time', label: 'Date Time' },
					{ id: 'date', label: 'Date' },
					{ id: 'time', label: 'Time' },
					{ id: 'duration', label: 'Duration' },
					{ id: 'email', label: 'Email' },
					{ id: 'idn-email', label: 'IDN Email' },
					{ id: 'hostname', label: 'Hostname' },
					{ id: 'idn-hostname', label: 'IDN Hostname' },
					{ id: 'ipv4', label: 'IPv4' },
					{ id: 'ipv6', label: 'IPv6' },
					{ id: 'uri', label: 'URI' },
					{ id: 'uri-reference', label: 'URI Reference' },
					{ id: 'iri', label: 'IRI' },
					{ id: 'iri-reference', label: 'IRI Reference' },
					{ id: 'uuid', label: 'UUID' },
					{ id: 'uri-template', label: 'URI Template' },
					{ id: 'json-pointer', label: 'JSON Pointer' },
					{ id: 'relative-json-pointer', label: 'Relative JSON Pointer' },
					{ id: 'regex', label: 'Regex' },
					{ id: 'binary', label: 'Binary' },
					{ id: 'byte', label: 'Byte' },
					{ id: 'password', label: 'Password' },
					{ id: 'rsin', label: 'RSIN' },
					{ id: 'kvk', label: 'KVK' },
					{ id: 'bsn', label: 'BSN' },
					{ id: 'oidn', label: 'OIDN' },
					{ id: 'telephone', label: 'Telephone' },
					{ id: 'accessUrl', label: 'Access URL' },
					{ id: 'shareUrl', label: 'Share URL' },
					{ id: 'downloadUrl', label: 'Download URL' },
					{ id: 'extension', label: 'Extension' },
					{ id: 'filename', label: 'Filename' },
					{ id: 'semver', label: 'Semantic Version' },
					{ id: 'url', label: 'URL' },
					{ id: 'color', label: 'Color' },
					{ id: 'color-hex', label: 'Color Hex' },
					{ id: 'color-hex-alpha', label: 'Color Hex Alpha' },
					{ id: 'color-rgb', label: 'Color RGB' },
					{ id: 'color-rgba', label: 'Color RGBA' },
					{ id: 'color-hsl', label: 'Color HSL' },
					{ id: 'color-hsla', label: 'Color HSLA' },
				],
				number: [],
				integer: [],
				boolean: [],
				array: [],
				object: [],
			}
			return formatMap[type] || []
		},
		updatePropertySetting(key, setting, value) {
			if (this.schemaItem.properties[key]) {
				// Handle both string values and objects with id property
				const settingValue = typeof value === 'object' && value?.id ? value.id : value
				this.$set(this.schemaItem.properties[key], setting, settingValue)
				// Enforce $ref is always a string after any update
				this.ensureRefIsString(this.schemaItem.properties, key)
				this.checkPropertiesModified()
			}
		},
		updateFileProperty(key, setting, value) {
			if (this.schemaItem.properties[key]) {
				// Handle array properties (allowedTypes, allowedTags, autoTags)
				if (['allowedTypes', 'allowedTags', 'autoTags'].includes(setting)) {
					const arrayValue = value ? value.split(',').map(item => item.trim()).filter(item => item !== '') : []
					// Apply to both direct file properties and array[file] properties
					if (this.schemaItem.properties[key].type === 'file') {
						this.$set(this.schemaItem.properties[key], setting, arrayValue)
					} else if (this.schemaItem.properties[key].type === 'array' && this.schemaItem.properties[key].items) {
						if (!this.schemaItem.properties[key].items) {
							this.$set(this.schemaItem.properties[key], 'items', {})
						}
						this.$set(this.schemaItem.properties[key].items, setting, arrayValue)
					}
				} else if (setting === 'maxSize') {
					// Handle maxSize as number
					const numValue = value ? Number(value) : undefined
					if (this.schemaItem.properties[key].type === 'file') {
						this.$set(this.schemaItem.properties[key], setting, numValue)
					} else if (this.schemaItem.properties[key].type === 'array' && this.schemaItem.properties[key].items) {
						if (!this.schemaItem.properties[key].items) {
							this.$set(this.schemaItem.properties[key], 'items', {})
						}
						this.$set(this.schemaItem.properties[key].items, setting, numValue)
					}
				}
				this.checkPropertiesModified()
			}
		},
		getFilePropertySetting(key, setting) {
			// Get boolean/value settings for file properties
			const property = this.schemaItem.properties[key]
			if (!property) return false

			if (property.type === 'file') {
				return property[setting] || false
			} else if (property.type === 'array' && property.items) {
				return property.items[setting] || false
			}

			return false
		},
		updateFilePropertySetting(key, setting, value) {
			// Handle boolean settings like autoPublish
			if (this.schemaItem.properties[key]) {
				// Apply to both direct file properties and array[file] properties
				if (this.schemaItem.properties[key].type === 'file') {
					this.$set(this.schemaItem.properties[key], setting, value)
				} else if (this.schemaItem.properties[key].type === 'array' && this.schemaItem.properties[key].items) {
					if (!this.schemaItem.properties[key].items) {
						this.$set(this.schemaItem.properties[key], 'items', {})
					}
					this.$set(this.schemaItem.properties[key].items, setting, value)
				}
				this.checkPropertiesModified()
			}
		},
		getFilePropertyTags(key, setting) {
			// Get tags for multiselect display
			const property = this.schemaItem.properties[key]
			if (!property) return []

			let tags = []
			if (property.type === 'file') {
				tags = property[setting] || []
			} else if (property.type === 'array' && property.items) {
				tags = property.items[setting] || []
			}

			// Convert to multiselect format
			return tags.map(tag => ({
				id: tag,
				label: tag,
			}))
		},
		updateFilePropertyTags(key, setting, selectedOptions) {
			// Handle multiselect tag updates
			if (this.schemaItem.properties[key]) {
				// Extract tag names from selected options
				const tags = selectedOptions ? selectedOptions.map(option => option.id || option) : []

				// Apply to both direct file properties and array[file] properties
				if (this.schemaItem.properties[key].type === 'file') {
					this.$set(this.schemaItem.properties[key], setting, tags)
				} else if (this.schemaItem.properties[key].type === 'array' && this.schemaItem.properties[key].items) {
					if (!this.schemaItem.properties[key].items) {
						this.$set(this.schemaItem.properties[key], 'items', {})
					}
					this.$set(this.schemaItem.properties[key].items, setting, tags)
				}
				this.checkPropertiesModified()
			}
		},
		updatePropertyRequired(key, isRequired) {
			// Update the property-level required field
			if (this.schemaItem.properties[key]) {
				if (isRequired) {
					this.$set(this.schemaItem.properties[key], 'required', true)
				} else {
					this.$delete(this.schemaItem.properties[key], 'required')
				}
			}

			// Also update the schema-level required array for consistency
			if (!this.schemaItem.required) {
				this.$set(this.schemaItem, 'required', [])
			}

			const currentRequired = [...this.schemaItem.required]
			if (isRequired && !currentRequired.includes(key)) {
				currentRequired.push(key)
			} else if (!isRequired && currentRequired.includes(key)) {
				const index = currentRequired.indexOf(key)
				currentRequired.splice(index, 1)
			}

			this.schemaItem.required = currentRequired
			this.checkPropertiesModified()
		},
		updateArrayItemType(key, itemType) {
			if (this.schemaItem.properties[key]) {
				if (!this.schemaItem.properties[key].items) {
					this.$set(this.schemaItem.properties[key], 'items', {})
				}
				// Handle both string values and objects with id property
				const typeValue = typeof itemType === 'object' && itemType?.id ? itemType.id : itemType
				this.$set(this.schemaItem.properties[key].items, 'type', typeValue)
				this.checkPropertiesModified()
			}
		},
		updateArrayItemObjectConfigurationSetting(key, setting, value) {
			if (this.schemaItem.properties[key]) {
				if (!this.schemaItem.properties[key].items) {
					this.$set(this.schemaItem.properties[key], 'items', {})
				}
				if (!this.schemaItem.properties[key].items.objectConfiguration) {
					this.$set(this.schemaItem.properties[key].items, 'objectConfiguration', {})
				}
				// Handle both string values and objects with id property
				const settingValue = typeof value === 'object' && value?.id ? value.id : value
				this.$set(this.schemaItem.properties[key].items, setting, settingValue)
				this.checkPropertiesModified()
			}
		},
		updateObjectConfiguration(key, setting, value) {
			if (this.schemaItem.properties[key]) {
				if (!this.schemaItem.properties[key].objectConfiguration) {
					this.$set(this.schemaItem.properties[key], 'objectConfiguration', {})
				}
				// Handle both string values and objects with id property
				const settingValue = typeof value === 'object' && value?.id ? value.id : value
				this.$set(this.schemaItem.properties[key].objectConfiguration, setting, settingValue)
				this.checkPropertiesModified()
			}
		},
		copyProperty(key) {
			if (this.schemaItem.properties[key]) {
				// Create a deep copy of the property
				const originalProperty = JSON.parse(JSON.stringify(this.schemaItem.properties[key]))

				// Generate a unique property name for the copy
				let newPropertyName = `${key}_copy`
				let counter = 1

				while (this.schemaItem.properties[newPropertyName]) {
					counter++
					newPropertyName = `${key}_copy_${counter}`
				}

				// Add the copied property with the new name
				// Keep the original title but add a suffix to indicate it's a copy
				const originalTitle = originalProperty.title || key
				this.$set(this.schemaItem.properties, newPropertyName, {
					...originalProperty,
					title: `${originalTitle} (copy)`,
				})

				// Check if properties have been modified
				this.checkPropertiesModified()

				// Select the new property for editing
				this.selectedProperty = newPropertyName

				// Focus the input field after Vue updates the DOM
				this.$nextTick(() => {
					if (this.$refs.propertyNameInput && this.$refs.propertyNameInput[0]) {
						this.$refs.propertyNameInput[0].$el.querySelector('input').focus()
						this.$refs.propertyNameInput[0].$el.querySelector('input').select()
					}
				})
			}
		},
		addEnumValue(key, value) {
			if (!value || !value.trim()) return

			const trimmedValue = value.trim()

			if (this.schemaItem.properties[key]) {
				if (!this.schemaItem.properties[key].enum) {
					this.$set(this.schemaItem.properties[key], 'enum', [])
				}

				// Don't add duplicate values
				if (!this.schemaItem.properties[key].enum.includes(trimmedValue)) {
					// Create a new array to trigger reactivity
					const newEnum = [...this.schemaItem.properties[key].enum, trimmedValue]
					this.$set(this.schemaItem.properties[key], 'enum', newEnum)
					this.checkPropertiesModified()
				}
			}
		},
		addEnumValueAndClear(key) {
			if (this.enumInputValue && this.enumInputValue.trim()) {
				this.addEnumValue(key, this.enumInputValue)
				this.enumInputValue = ''
			}
		},
		removeEnumValue(key, index) {
			if (this.schemaItem.properties[key] && this.schemaItem.properties[key].enum) {
				// Create a new array without the removed item to trigger reactivity
				const newEnum = this.schemaItem.properties[key].enum.filter((_, i) => i !== index)

				// Remove the enum array if it's empty
				if (newEnum.length === 0) {
					this.$delete(this.schemaItem.properties[key], 'enum')
				} else {
					this.$set(this.schemaItem.properties[key], 'enum', newEnum)
				}

				this.checkPropertiesModified()
			}
		},
		getInversedByOptions(key) {
			const property = this.schemaItem.properties[key]
			if (!property || !property.$ref) {
				return []
			}

			// Extract schema slug from reference format like "#/components/schemas/Contactgegevens"
			const rawRef = typeof property.$ref === 'object' ? property.$ref.id : property.$ref

			// If $ref is a number, it's invalid - return empty options but don't break
			if (typeof rawRef === 'number') {
				console.warn(`Invalid $ref for property '${key}': expected string, got number (${rawRef})`)
				return []
			}

			const schemaRef = String(rawRef) // Ensure it's a string before using string methods
			let schemaSlug = schemaRef

			// Handle JSON Schema path references
			if (schemaRef.includes('/')) {
				schemaSlug = schemaRef.substring(schemaRef.lastIndexOf('/') + 1)
			}

			// Find the referenced schema by slug (case-insensitive), ID, or title
			const referencedSchema = schemaStore.schemaList.find(schema =>
				(schema.slug && schema.slug.toLowerCase() === schemaSlug.toLowerCase())
				|| schema.id === schemaSlug
				|| schema.title === schemaSlug,
			)

			if (!referencedSchema || !referencedSchema.properties) {
				return []
			}

			// Return properties from the referenced schema
			return Object.keys(referencedSchema.properties).map(propKey => ({
				id: propKey,
				label: referencedSchema.properties[propKey].title || propKey,
			}))
		},
		getInversedByOptionsForArrayItems(key) {
			const property = this.schemaItem.properties[key]
			if (!property || !property.items || !property.items.$ref) {
				return []
			}

			// Extract schema slug from reference format like "#/components/schemas/Contactgegevens"
			const rawRef = typeof property.items.$ref === 'object' ? property.items.$ref.id : property.items.$ref

			// If $ref is a number, it's invalid - return empty options but don't break
			if (typeof rawRef === 'number') {
				console.warn(`Invalid $ref for array items property '${key}': expected string, got number (${rawRef})`)
				return []
			}

			const schemaRef = String(rawRef) // Ensure it's a string before using string methods
			let schemaSlug = schemaRef

			// Handle JSON Schema path references
			if (schemaRef.includes('/')) {
				schemaSlug = schemaRef.substring(schemaRef.lastIndexOf('/') + 1)
			}

			// Find the referenced schema by slug (case-insensitive), ID, or title
			const referencedSchema = schemaStore.schemaList.find(schema =>
				(schema.slug && schema.slug.toLowerCase() === schemaSlug.toLowerCase())
				|| schema.id === schemaSlug
				|| schema.title === schemaSlug,
			)

			if (!referencedSchema || !referencedSchema.properties) {
				return []
			}

			// Return properties from the referenced schema
			return Object.keys(referencedSchema.properties).map(propKey => ({
				id: propKey,
				label: referencedSchema.properties[propKey].title || propKey,
			}))
		},
		ensureRefIsString(obj, key) {
			if (!obj || !key) return

			// Check property $ref - only convert objects to strings, preserve numbers
			if (obj[key] && typeof obj[key].$ref === 'object' && obj[key].$ref !== null) {
				if (obj[key].$ref.id) {
					obj[key].$ref = obj[key].$ref.id
				} else {
					// If $ref is not a string, number, or object with id, clear it
					obj[key].$ref = ''
				}
			}
			// Note: Numbers are preserved as-is and will be sent to backend

			// Also check array items - only convert objects to strings, preserve numbers
			if (obj[key] && obj[key].items && typeof obj[key].items.$ref === 'object' && obj[key].items.$ref !== null) {
				if (obj[key].items.$ref.id) {
					obj[key].items.$ref = obj[key].items.$ref.id
				} else {
					obj[key].items.$ref = ''
				}
			}
			// Note: Numbers are preserved as-is and will be sent to backend
		},
		updateInversedBy(key, value) {
			// Ensure inversedBy is always a string, not an object
			if (this.schemaItem.properties[key]) {
				const inversedByValue = typeof value === 'object' && value?.id ? value.id : value
				this.$set(this.schemaItem.properties[key], 'inversedBy', inversedByValue)
				this.checkPropertiesModified()
			}
		},
		updateInversedByForArrayItems(key, value) {
			// Ensure inversedBy is always a string for array items, not an object
			if (this.schemaItem.properties[key] && this.schemaItem.properties[key].items) {
				const inversedByValue = typeof value === 'object' && value?.id ? value.id : value
				this.$set(this.schemaItem.properties[key].items, 'inversedBy', inversedByValue)
				this.checkPropertiesModified()
			}
		},
		/**
		 * Update schema reference and handle register requirement
		 *
		 * @param {string} key Property key
		 * @param {object|string} value Schema reference value
		 */
		updateSchemaReference(key, value) {
			if (!this.schemaItem.properties[key]) {
				return
			}

			// Extract schema reference value
			const schemaRef = typeof value === 'object' && value?.id ? value.id : value

			// Update the $ref
			this.$set(this.schemaItem.properties[key], '$ref', schemaRef)

			// Ensure objectConfiguration exists
			if (!this.schemaItem.properties[key].objectConfiguration) {
				this.$set(this.schemaItem.properties[key], 'objectConfiguration', { handling: 'related-object' })
			}

			// Extract schema ID from reference and save in objectConfiguration
			if (schemaRef) {
				// Extract schema slug/ID from reference format like "#/components/schemas/voorzieningmodule"
				let schemaSlug = schemaRef
				if (schemaRef.includes('/')) {
					schemaSlug = schemaRef.substring(schemaRef.lastIndexOf('/') + 1)
				}

				// Find the schema to get its numeric ID
				const referencedSchema = schemaStore.schemaList.find(schema =>
					(schema.slug && schema.slug.toLowerCase() === schemaSlug.toLowerCase())
					|| schema.id === schemaSlug
					|| schema.title === schemaSlug,
				)

				if (referencedSchema) {
					this.$set(this.schemaItem.properties[key].objectConfiguration, 'schema', referencedSchema.id)
				}

				// Migrate existing register from old structure to new structure
				if (this.schemaItem.properties[key].register && !this.schemaItem.properties[key].objectConfiguration.register) {
					const oldRegister = this.schemaItem.properties[key].register
					const registerId = typeof oldRegister === 'object' && oldRegister.id ? oldRegister.id : oldRegister
					this.$set(this.schemaItem.properties[key].objectConfiguration, 'register', registerId)
				}
			} else {
				// Clear schema and register from objectConfiguration if schema reference is removed
				this.$delete(this.schemaItem.properties[key].objectConfiguration, 'schema')
				this.$delete(this.schemaItem.properties[key].objectConfiguration, 'register')
			}

			this.checkPropertiesModified()
		},
		/**
		 * Update array item schema reference and handle register requirement
		 *
		 * @param {string} key Property key
		 * @param {object|string} value Schema reference value
		 */
		updateArrayItemSchemaReference(key, value) {
			if (!this.schemaItem.properties[key] || !this.schemaItem.properties[key].items) {
				return
			}

			// Extract schema reference value
			const schemaRef = typeof value === 'object' && value?.id ? value.id : value

			// Update the $ref
			this.$set(this.schemaItem.properties[key].items, '$ref', schemaRef)

			// Ensure objectConfiguration exists
			if (!this.schemaItem.properties[key].items.objectConfiguration) {
				this.$set(this.schemaItem.properties[key].items, 'objectConfiguration', { handling: 'related-object' })
			}

			// Extract schema ID from reference and save in objectConfiguration
			if (schemaRef) {
				// Extract schema slug/ID from reference format like "#/components/schemas/voorzieningmodule"
				let schemaSlug = schemaRef
				if (schemaRef.includes('/')) {
					schemaSlug = schemaRef.substring(schemaRef.lastIndexOf('/') + 1)
				}

				// Find the schema to get its numeric ID
				const referencedSchema = schemaStore.schemaList.find(schema =>
					(schema.slug && schema.slug.toLowerCase() === schemaSlug.toLowerCase())
					|| schema.id === schemaSlug
					|| schema.title === schemaSlug,
				)

				if (referencedSchema) {
					this.$set(this.schemaItem.properties[key].items.objectConfiguration, 'schema', referencedSchema.id)
				}
			} else {
				// Clear schema and register from objectConfiguration if schema reference is removed
				this.$delete(this.schemaItem.properties[key].items.objectConfiguration, 'schema')
				this.$delete(this.schemaItem.properties[key].items.objectConfiguration, 'register')
			}

			this.checkPropertiesModified()
		},
		/**
		 * Update register reference in objectConfiguration
		 *
		 * @param {string} key Property key
		 * @param {object|string|number} value Register reference value
		 */
		updateRegisterReference(key, value) {
			if (!this.schemaItem.properties[key]) {
				return
			}

			// Ensure objectConfiguration exists
			if (!this.schemaItem.properties[key].objectConfiguration) {
				this.$set(this.schemaItem.properties[key], 'objectConfiguration', { handling: 'related-object' })
			}

			// Extract register ID from value
			const registerId = typeof value === 'object' && value?.id ? value.id : value

			if (registerId) {
				this.$set(this.schemaItem.properties[key].objectConfiguration, 'register', registerId)

				// Remove old register property if it exists
				if (this.schemaItem.properties[key].register) {
					this.$delete(this.schemaItem.properties[key], 'register')
				}
			} else {
				this.$delete(this.schemaItem.properties[key].objectConfiguration, 'register')
			}

			this.checkPropertiesModified()
		},
		/**
		 * Update register reference in array items objectConfiguration
		 *
		 * @param {string} key Property key
		 * @param {object|string|number} value Register reference value
		 */
		updateArrayItemRegisterReference(key, value) {
			if (!this.schemaItem.properties[key] || !this.schemaItem.properties[key].items) {
				return
			}

			// Ensure objectConfiguration exists
			if (!this.schemaItem.properties[key].items.objectConfiguration) {
				this.$set(this.schemaItem.properties[key].items, 'objectConfiguration', { handling: 'related-object' })
			}

			// Extract register ID from value
			const registerId = typeof value === 'object' && value?.id ? value.id : value

			if (registerId) {
				this.$set(this.schemaItem.properties[key].items.objectConfiguration, 'register', registerId)
			} else {
				this.$delete(this.schemaItem.properties[key].items.objectConfiguration, 'register')
			}

			this.checkPropertiesModified()
		},
		/**
		 * Get register value, handling both old and new structure
		 *
		 * @param {string} key Property key
		 * @return {number|object|null} Register value
		 */
		getRegisterValue(key) {
			if (!this.schemaItem.properties[key]) {
				return null
			}

			const property = this.schemaItem.properties[key]

			// Check new structure first
			if (property.objectConfiguration && property.objectConfiguration.register !== undefined) {
				return property.objectConfiguration.register
			}

			// Check old structure
			if (property.register !== undefined) {
				return property.register
			}

			return null
		},
		/**
		 * Get array item register value, handling both old and new structure
		 *
		 * @param {string} key Property key
		 * @return {number|object|null} Register value
		 */
		getArrayItemRegisterValue(key) {
			if (!this.schemaItem.properties[key] || !this.schemaItem.properties[key].items) {
				return null
			}

			const items = this.schemaItem.properties[key].items

			// Check new structure first
			if (items.objectConfiguration && items.objectConfiguration.register !== undefined) {
				return items.objectConfiguration.register
			}

			// Check old structure
			if (items.register !== undefined) {
				return items.register
			}

			return null
		},
		/**
		 * Migrate property from old structure to new objectConfiguration structure
		 *
		 * @param {string} key Property key
		 */
		migratePropertyToNewStructure(key) {
			if (!this.schemaItem.properties[key]) {
				return
			}

			const property = this.schemaItem.properties[key]

			// Only migrate if we have a schema reference and old register structure
			if (property.$ref && property.register && !property.objectConfiguration?.register) {
				// Ensure objectConfiguration exists
				if (!property.objectConfiguration) {
					this.$set(this.schemaItem.properties[key], 'objectConfiguration', { handling: 'related-object' })
				}

				// Extract register ID from old structure
				const registerId = typeof property.register === 'object' && property.register.id
					? property.register.id
					: property.register

				// Set register in objectConfiguration
				this.$set(this.schemaItem.properties[key].objectConfiguration, 'register', registerId)

				// Find and set schema ID
				if (property.$ref) {
					let schemaSlug = property.$ref
					if (schemaSlug.includes('/')) {
						schemaSlug = schemaSlug.substring(schemaSlug.lastIndexOf('/') + 1)
					}

					const referencedSchema = schemaStore.schemaList.find(schema =>
						(schema.slug && schema.slug.toLowerCase() === schemaSlug.toLowerCase())
						|| schema.id === schemaSlug
						|| schema.title === schemaSlug,
					)

					if (referencedSchema) {
						this.$set(this.schemaItem.properties[key].objectConfiguration, 'schema', referencedSchema.id)
					}
				}

				// Don't remove the old register property yet - let the save process handle cleanup
				// This ensures the UI still works during the transition
			}

			// Handle array items migration
			if (property.items && property.items.$ref && property.items.register && !property.items.objectConfiguration?.register) {
				// Ensure objectConfiguration exists for items
				if (!property.items.objectConfiguration) {
					this.$set(this.schemaItem.properties[key].items, 'objectConfiguration', { handling: 'related-object' })
				}

				// Extract register ID from old structure
				const registerId = typeof property.items.register === 'object' && property.items.register.id
					? property.items.register.id
					: property.items.register

				// Set register in objectConfiguration
				this.$set(this.schemaItem.properties[key].items.objectConfiguration, 'register', registerId)

				// Find and set schema ID
				if (property.items.$ref) {
					let schemaSlug = property.items.$ref
					if (schemaSlug.includes('/')) {
						schemaSlug = schemaSlug.substring(schemaSlug.lastIndexOf('/') + 1)
					}

					const referencedSchema = schemaStore.schemaList.find(schema =>
						(schema.slug && schema.slug.toLowerCase() === schemaSlug.toLowerCase())
						|| schema.id === schemaSlug
						|| schema.title === schemaSlug,
					)

					if (referencedSchema) {
						this.$set(this.schemaItem.properties[key].items.objectConfiguration, 'schema', referencedSchema.id)
					}
				}
			}
		},
		// Check if a property's $ref is invalid (contains a number instead of string)
		isRefInvalid(key) {
			const property = this.schemaItem.properties[key]
			if (!property || !property.$ref) {
				return false
			}
			const rawRef = typeof property.$ref === 'object' ? property.$ref.id : property.$ref
			return typeof rawRef === 'number'
		},
		// Check if an array item's $ref is invalid (contains a number instead of string)
		isArrayItemRefInvalid(key) {
			const property = this.schemaItem.properties[key]
			if (!property || !property.items || !property.items.$ref) {
				return false
			}
			const rawRef = typeof property.items.$ref === 'object' ? property.items.$ref.id : property.items.$ref
			return typeof rawRef === 'number'
		},
		/**
		 * Helper method to convert array default value to comma-separated string for display
		 *
		 * @param {Array|null|undefined} defaultValue The array default value to convert
		 * @return {string} Comma-separated string representation of the array
		 */
		getArrayDefaultAsString(defaultValue) {
			if (!defaultValue || !Array.isArray(defaultValue)) {
				return ''
			}
			return defaultValue.join(', ')
		},

		/**
		 * Update array default value from comma-separated string input
		 *
		 * @param {string} key The property key to update
		 * @param {string} value The comma-separated string of values
		 */
		updateArrayDefault(key, value) {
			if (!this.schemaItem.properties[key]) {
				return
			}

			if (!value || value.trim() === '') {
				// Clear the default value if empty
				this.$set(this.schemaItem.properties[key], 'default', undefined)
			} else {
				// Parse comma-separated values and trim whitespace
				const arrayValues = value.split(',').map(item => item.trim()).filter(item => item !== '')
				this.$set(this.schemaItem.properties[key], 'default', arrayValues)
			}

			this.checkPropertiesModified()
		},

		/**
		 * Update object default value from JSON string input
		 *
		 * @param {string} key The property key to update
		 * @param {string} value The JSON string representation of the object
		 */
		updateObjectDefault(key, value) {
			if (!this.schemaItem.properties[key]) {
				return
			}

			if (!value || value.trim() === '' || value.trim() === '{}') {
				// Clear the default value if empty or empty object
				this.$set(this.schemaItem.properties[key], 'default', undefined)
				this.checkPropertiesModified()
				return
			}

			try {
				// Try to parse as JSON
				const parsedValue = JSON.parse(value)
				this.$set(this.schemaItem.properties[key], 'default', parsedValue)
				this.checkPropertiesModified()
			} catch (e) {
				// Invalid JSON - don't update the value
				console.warn('Invalid JSON for default value:', e.message)
			}
		},

		// RBAC Methods
		async loadUserGroups() {
			this.loadingGroups = true
			try {
				// Use Nextcloud's OCS API to get groups
				const response = await fetch('/ocs/v1.php/cloud/groups?format=json', {
					headers: {
						'OCS-APIRequest': 'true',
					},
				})

				if (response.ok) {
					const data = await response.json()

					if (data.ocs && data.ocs.data && data.ocs.data.groups) {
						// Transform group list to include display names
						this.userGroups = data.ocs.data.groups.map(groupId => ({
							id: groupId,
							displayname: groupId, // In a real implementation, you might want to fetch display names separately
						}))
					} else {
						console.warn('Invalid API response structure:', data)
						this.setFallbackGroups()
					}
				} else {
					console.warn('Failed to load user groups:', response.statusText)
					this.setFallbackGroups()
				}
			} catch (error) {
				console.error('Error loading user groups:', error)
				this.setFallbackGroups()
			} finally {
				this.loadingGroups = false
			}
		},

		setFallbackGroups() {
			// Fallback groups including our test groups
			this.userGroups = [
				{ id: 'users', displayname: 'All Users' },
				{ id: 'editors', displayname: 'Editors' },
				{ id: 'managers', displayname: 'Managers' },
				{ id: 'viewers', displayname: 'Viewers' },
			]
		},

		hasGroupPermission(groupId, action) {
			const auth = this.schemaItem.authorization || {}
			if (!auth[action] || !Array.isArray(auth[action])) {
				return false
			}
			return auth[action].includes(groupId)
		},

		updateGroupPermission(groupId, action, hasPermission) {
			// Initialize authorization object if it doesn't exist
			if (!this.schemaItem.authorization) {
				this.$set(this.schemaItem, 'authorization', {})
			}

			// Initialize action array if it doesn't exist
			if (!this.schemaItem.authorization[action]) {
				this.$set(this.schemaItem.authorization, action, [])
			}

			const currentPermissions = this.schemaItem.authorization[action]
			const groupIndex = currentPermissions.indexOf(groupId)

			if (hasPermission && groupIndex === -1) {
				// Add permission
				currentPermissions.push(groupId)
			} else if (!hasPermission && groupIndex !== -1) {
				// Remove permission
				currentPermissions.splice(groupIndex, 1)
			}

			// Clean up empty arrays to keep the data structure clean
			if (currentPermissions.length === 0) {
				this.$delete(this.schemaItem.authorization, action)
			}

			// If authorization object is empty, remove it entirely
			if (Object.keys(this.schemaItem.authorization).length === 0) {
				this.$set(this.schemaItem, 'authorization', {})
			}
		},

		initializeAuthorizationIfNeeded() {
			// Ensure authorization object exists with proper structure
			if (!this.schemaItem.authorization) {
				this.$set(this.schemaItem, 'authorization', {})
			}
		},

		/**
		 * Update allowed tags from comma-separated input string
		 *
		 * @param {string} value The comma-separated string of tags
		 */
		updateAllowedTags(value) {
			if (!value || value.trim() === '') {
				// Clear the allowed tags if empty
				this.$set(this.schemaItem.configuration, 'allowedTags', [])
			} else {
				// Parse comma-separated values and trim whitespace
				const tags = value.split(',').map(tag => tag.trim()).filter(tag => tag !== '')
				this.$set(this.schemaItem.configuration, 'allowedTags', tags)
			}
		},

		// Property-level RBAC Methods

		/**
		 * Check if a property has any permissions set
		 *
		 * @param {string} key Property key
		 * @return {boolean} True if property has any permissions
		 */
		hasPropertyAnyPermissions(key) {
			if (!this.schemaItem.properties[key] || !this.schemaItem.properties[key].authorization) {
				return false
			}
			const auth = this.schemaItem.properties[key].authorization
			return Object.keys(auth).some(action =>
				Array.isArray(auth[action]) && auth[action].length > 0,
			)
		},

		/**
		 * Check if property has restrictive permissions (excludes public)
		 *
		 * @param {string} key Property key
		 * @return {boolean} True if property has restrictive permissions
		 */
		isRestrictiveProperty(key) {
			if (!this.schemaItem.properties[key] || !this.schemaItem.properties[key].authorization) {
				return false
			}
			const auth = this.schemaItem.properties[key].authorization
			const actions = ['create', 'read', 'update', 'delete']
			return actions.some(action =>
				Array.isArray(auth[action]) && auth[action].length > 0
					&& !auth[action].includes('public'),
			)
		},

		/**
		 * Check if a property has permission for a specific group and action
		 *
		 * @param {string} key Property key
		 * @param {string} groupId Group ID
		 * @param {string} action CRUD action
		 * @return {boolean} True if group has permission
		 */
		hasPropertyGroupPermission(key, groupId, action) {
			if (!this.schemaItem.properties[key] || !this.schemaItem.properties[key].authorization) {
				return false
			}
			const auth = this.schemaItem.properties[key].authorization
			if (!auth[action] || !Array.isArray(auth[action])) {
				return false
			}
			return auth[action].includes(groupId)
		},

		/**
		 * Update property-level group permission
		 *
		 * @param {string} key Property key
		 * @param {string} groupId Group ID
		 * @param {string} action CRUD action
		 * @param {boolean} hasPermission Whether group should have permission
		 */
		updatePropertyGroupPermission(key, groupId, action, hasPermission) {
			if (!this.schemaItem.properties[key]) {
				return
			}

			// Initialize property authorization object if it doesn't exist
			if (!this.schemaItem.properties[key].authorization) {
				this.$set(this.schemaItem.properties[key], 'authorization', {})
			}

			// Initialize action array if it doesn't exist
			if (!this.schemaItem.properties[key].authorization[action]) {
				this.$set(this.schemaItem.properties[key].authorization, action, [])
			}

			const currentPermissions = this.schemaItem.properties[key].authorization[action]
			const groupIndex = currentPermissions.indexOf(groupId)

			if (hasPermission && groupIndex === -1) {
				// Add permission
				currentPermissions.push(groupId)
			} else if (!hasPermission && groupIndex !== -1) {
				// Remove permission
				currentPermissions.splice(groupIndex, 1)
			}

			// Clean up empty arrays to keep the data structure clean
			if (currentPermissions.length === 0) {
				this.$delete(this.schemaItem.properties[key].authorization, action)
			}

			// If authorization object is empty, remove it entirely
			if (Object.keys(this.schemaItem.properties[key].authorization).length === 0) {
				this.$delete(this.schemaItem.properties[key], 'authorization')
			}

			this.checkPropertiesModified()
		},

		/**
		 * Get top user groups for property RBAC display (limit to 8 for action menu)
		 *
		 * @return {Array} Array of top user groups
		 */
		getTopUserGroupsForProperty() {
			return this.sortedUserGroups.slice(0, 8)
		},

		/**
		 * Get a compact list of current property permissions
		 *
		 * @param {string} key Property key
		 * @return {Array} Array of permission objects with group and rights
		 */
		getPropertyPermissionsList(key) {
			if (!this.schemaItem.properties[key] || !this.schemaItem.properties[key].authorization) {
				return []
			}

			const auth = this.schemaItem.properties[key].authorization
			const permissionsList = []
			const processedGroups = new Set()

			// Process each action to build group permissions
			Object.keys(auth).forEach(action => {
				if (Array.isArray(auth[action])) {
					auth[action].forEach(groupId => {
						if (!processedGroups.has(groupId)) {
							const rights = []
							if (auth.create && auth.create.includes(groupId)) rights.push('C')
							if (auth.read && auth.read.includes(groupId)) rights.push('R')
							if (auth.update && auth.update.includes(groupId)) rights.push('U')
							if (auth.delete && auth.delete.includes(groupId)) rights.push('D')

							permissionsList.push({
								group: this.getDisplayGroupName(groupId),
								groupId,
								rights: rights.length > 0 ? rights.join(',') : 'none',
							})
							processedGroups.add(groupId)
						}
					})
				}
			})

			// Always show admin with full rights (even though not stored explicitly)
			permissionsList.push({
				group: 'Admin',
				groupId: 'admin',
				rights: 'C,R,U,D',
			})

			return permissionsList.sort((a, b) => {
				// Sort order: public, user, others alphabetically, admin last
				if (a.groupId === 'public') return -1
				if (b.groupId === 'public') return 1
				if (a.groupId === 'user') return -1
				if (b.groupId === 'user') return 1
				if (a.groupId === 'admin') return 1
				if (b.groupId === 'admin') return -1
				return a.group.localeCompare(b.group)
			})
		},

		/**
		 * Get available groups for property permission selection
		 *
		 * @return {Array} Array of available groups including special groups
		 */
		getAvailableGroupsForProperty() {
			const availableGroups = [
				{ id: 'public', label: 'Public (Unauthenticated)' },
				{ id: 'user', label: 'User (Authenticated)' },
				...this.sortedUserGroups.map(group => ({
					id: group.id,
					label: group.displayname || group.id,
				})),
			]
			return availableGroups
		},

		/**
		 * Get display name for a group ID
		 *
		 * @param {string} groupId Group ID
		 * @return {string} Display name
		 */
		getDisplayGroupName(groupId) {
			if (groupId === 'public') return 'Public'
			if (groupId === 'user') return 'User'
			if (groupId === 'admin') return 'Admin'

			const group = this.userGroups.find(g => g.id === groupId)
			return group ? (group.displayname || group.id) : groupId
		},

		/**
		 * Check if any new permission checkboxes are selected
		 *
		 * @return {boolean} True if any permission is selected
		 */
		hasAnyPropertyNewPermissionSelected() {
			return this.propertyNewPermissionCreate
				   || this.propertyNewPermissionRead
				   || this.propertyNewPermissionUpdate
				   || this.propertyNewPermissionDelete
		},

		/**
		 * Add permissions for a group to a property
		 *
		 * @param {string} key Property key
		 */
		addPropertyGroupPermissions(key) {
			if (!this.propertyNewPermissionGroup) return

			const groupId = typeof this.propertyNewPermissionGroup === 'object'
				? this.propertyNewPermissionGroup.id
				: this.propertyNewPermissionGroup

			// Initialize property authorization if needed
			if (!this.schemaItem.properties[key].authorization) {
				this.$set(this.schemaItem.properties[key], 'authorization', {})
			}

			// Add permissions for selected actions
			if (this.propertyNewPermissionCreate) {
				this.updatePropertyGroupPermission(key, groupId, 'create', true)
			}
			if (this.propertyNewPermissionRead) {
				this.updatePropertyGroupPermission(key, groupId, 'read', true)
			}
			if (this.propertyNewPermissionUpdate) {
				this.updatePropertyGroupPermission(key, groupId, 'update', true)
			}
			if (this.propertyNewPermissionDelete) {
				this.updatePropertyGroupPermission(key, groupId, 'delete', true)
			}

			// Reset form
			this.resetPropertyPermissionForm()
		},

		/**
		 * Remove all permissions for a group from a property
		 *
		 * @param {string} key Property key
		 * @param {string} displayName Group display name to remove
		 */
		removePropertyGroupPermissions(key, displayName) {
			// Find the actual groupId from the display name
			const permission = this.getPropertyPermissionsList(key).find(p => p.group === displayName)
			if (!permission || permission.groupId === 'admin') {
				return // Cannot remove admin permissions or unknown groups
			}

			const groupId = permission.groupId

			if (!this.schemaItem.properties[key] || !this.schemaItem.properties[key].authorization) {
				return
			}

			// Remove group from all actions
			['create', 'read', 'update', 'delete'].forEach(action => {
				this.updatePropertyGroupPermission(key, groupId, action, false)
			})
		},

		/**
		 * Reset the property permission form
		 */
		resetPropertyPermissionForm() {
			this.propertyNewPermissionGroup = null
			this.propertyNewPermissionCreate = false
			this.propertyNewPermissionRead = false
			this.propertyNewPermissionUpdate = false
			this.propertyNewPermissionDelete = false
		},

		// Property-level Table Configuration Methods

		/**
		 * Get a table setting value for a property
		 *
		 * @param {string} key Property key
		 * @param {string} setting Table setting name
		 * @return {boolean|any} Setting value
		 */
		 getPropertyTableSetting(key, setting) {
			if (!this.schemaItem.properties[key] || !this.schemaItem.properties[key].table) {
				return false
			}
			return this.schemaItem.properties[key].table[setting] === true
		},

		/**
		 * Get the original table setting value for a property
		 *
		 * @param {string} key Property key
		 * @param {string} setting Table setting name
		 * @return {boolean|any} Setting value
		 */
		getOriginalPropertyTableSetting(key, setting) {
			return this.originalProperties?.[key]?.table[setting]
		},

		/**
		 * Update a table setting for a property
		 *
		 * @param {string} key Property key
		 * @param {string} setting Table setting name
		 * @param {boolean|any} value Setting value
		 */
		 updatePropertyTableSetting(key, setting, value) {
			if (!this.schemaItem.properties[key]) {
				return
			}

			// Initialize table object if it doesn't exist
			if (!this.schemaItem.properties[key].table) {
				this.$set(this.schemaItem.properties[key], 'table', {})
			}

			// Update the setting
			this.$set(this.schemaItem.properties[key].table, setting, value)

			// Clean up table object if all settings are default,
			// UNLESS this was an explicit change from true -> false (we must send false)
			const wasTrueOriginally = this.getOriginalPropertyTableSetting(key, setting) === true
			const becameExplicitFalse = value === false
			const shouldKeepExplicitFalse = setting === 'default' && becameExplicitFalse && wasTrueOriginally

			if (this.isTableConfigDefault(key) && !shouldKeepExplicitFalse) {
				this.$delete(this.schemaItem.properties[key], 'table')
			}

			this.checkPropertiesModified()
		},

		/**
		 * Check if table configuration is all default values
		 *
		 * @param {string} key Property key
		 * @return {boolean} True if all table settings are default
		 */
		isTableConfigDefault(key) {
			const table = this.schemaItem.properties[key]?.table
			if (!table) return true

			// Check if all known settings are default values
			const defaults = { default: false }
			return Object.keys(table).every(setting =>
				table[setting] === defaults[setting],
			)
		},

		/**
		 * Check if a property has custom table settings (non-default)
		 *
		 * @param {string} key Property key
		 * @return {boolean} True if property has custom table settings
		 */
		hasCustomTableSettings(key) {
			return !this.isTableConfigDefault(key)
		},

		/**
		 * Get query parameters for object property
		 *
		 * @param {string} key Property key
		 * @return {string} Query parameters string
		 */
		getObjectQueryParams(key) {
			if (!this.schemaItem.properties[key] || !this.schemaItem.properties[key].objectConfiguration) {
				return ''
			}
			return this.schemaItem.properties[key].objectConfiguration.queryParams || ''
		},

		/**
		 * Update query parameters for object property
		 *
		 * @param {string} key Property key
		 * @param {string} value Query parameters string
		 */
		updateObjectQueryParams(key, value) {
			if (!this.schemaItem.properties[key]) {
				return
			}

			// Ensure objectConfiguration exists
			if (!this.schemaItem.properties[key].objectConfiguration) {
				this.$set(this.schemaItem.properties[key], 'objectConfiguration', { handling: 'related-object' })
			}

			// Update query parameters (remove if empty)
			if (value && value.trim()) {
				this.$set(this.schemaItem.properties[key].objectConfiguration, 'queryParams', value.trim())
			} else {
				this.$delete(this.schemaItem.properties[key].objectConfiguration, 'queryParams')
			}

			this.checkPropertiesModified()
		},

		/**
		 * Get query parameters for array item objects
		 *
		 * @param {string} key Property key
		 * @return {string} Query parameters string
		 */
		getArrayItemQueryParams(key) {
			if (!this.schemaItem.properties[key] || !this.schemaItem.properties[key].items || !this.schemaItem.properties[key].items.objectConfiguration) {
				return ''
			}
			return this.schemaItem.properties[key].items.objectConfiguration.queryParams || ''
		},

		/**
		 * Update query parameters for array item objects
		 *
		 * @param {string} key Property key
		 * @param {string} value Query parameters string
		 */
		updateArrayItemQueryParams(key, value) {
			if (!this.schemaItem.properties[key] || !this.schemaItem.properties[key].items) {
				return
			}

			// Ensure objectConfiguration exists
			if (!this.schemaItem.properties[key].items.objectConfiguration) {
				this.$set(this.schemaItem.properties[key].items, 'objectConfiguration', { handling: 'related-object' })
			}

			// Update query parameters (remove if empty)
			if (value && value.trim()) {
				this.$set(this.schemaItem.properties[key].items.objectConfiguration, 'queryParams', value.trim())
			} else {
				this.$delete(this.schemaItem.properties[key].items.objectConfiguration, 'queryParams')
			}

			this.checkPropertiesModified()
		},
	},
}
</script>

<style scoped>
/* EditSchema-specific overrides only */
.tableColumnActions {
	width: 150px;
	text-align: right;
}

/* Table actions button */
.table-actions {
	margin-bottom: 15px;
	display: flex;
	justify-content: flex-end;
}

/* Enum preview styling */
.enum-preview {
	margin-top: 4px;
	display: flex;
	align-items: center;
	gap: 4px;
	flex-wrap: wrap;
}

.enum-label {
	color: var(--color-text-lighter);
	font-size: 11px;
	font-weight: 500;
	margin-right: 4px;
}

.enum-value-chip {
	background: var(--color-primary-light);
	color: var(--color-primary-text);
	padding: 2px 6px;
	border-radius: 12px;
	font-size: 10px;
	font-weight: 500;
	border: 1px solid var(--color-primary-element-light);
}

.property-chip.chip-info {
	background: var(--color-info);
	color: var(--color-primary-text);
}

.property-chip.chip-table {
	background: var(--color-primary);
	color: var(--color-primary-text);
}

/* Enum chip styling for action menu using NcActionButton */
.enum-action-chip {
	background: var(--color-primary-element-light) !important;
	border-radius: 16px !important;
	margin: 2px 4px !important;
	padding: 4px 12px !important;
}

.enum-action-chip:hover {
	background: var(--color-primary-element) !important;
}

.enum-action-chip .action-button__text {
	color: var(--color-primary-text) !important;
	font-size: 12px !important;
	font-weight: 500 !important;
}

.enum-action-chip .action-button__icon {
	color: var(--color-primary-text) !important;
}

/* RBAC Security Tab Styling */
.security-section {
	padding: 20px 0;
}

.loading-groups {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 20px;
	justify-content: center;
}

.rbac-table-container {
	margin-top: 20px;
}

.rbac-table-container h3 {
	margin-bottom: 15px;
	color: var(--color-text-dark);
	font-size: 16px;
	font-weight: 600;
}

.rbac-table {
	width: 100%;
	border-collapse: collapse;
	border: 1px solid var(--color-border-dark);
	border-radius: 8px;
	overflow: hidden;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.rbac-table th {
	background: var(--color-background-dark);
	color: var(--color-text-dark);
	font-weight: 600;
	padding: 12px 16px;
	text-align: left;
	border-bottom: 2px solid var(--color-border-dark);
}

.rbac-table th:first-child {
	width: 40%;
}

.rbac-table th:not(:first-child) {
	width: 15%;
	text-align: center;
}

.rbac-table td {
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.rbac-table td:not(.group-name) {
	text-align: center;
}

.rbac-table tr:hover {
	background: var(--color-background-hover);
}

.public-row {
	background: var(--color-primary-light) !important;
}

.user-row {
	background: var(--color-warning-light) !important;
}

.admin-row {
	background: var(--color-success-light) !important;
}

.admin-row:hover {
	background: var(--color-success-light) !important;
}

.group-name {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.group-badge {
	display: inline-block;
	padding: 4px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	background: var(--color-primary-element-light);
	color: var(--color-primary-text);
}

.group-badge.public {
	background: var(--color-info);
	color: white;
}

.group-badge.user {
	background: var(--color-warning);
	color: white;
}

.group-badge.admin {
	background: var(--color-success);
	color: white;
}

.group-name small {
	color: var(--color-text-lighter);
	font-size: 11px;
	font-style: italic;
}

.rbac-summary {
	margin-top: 20px;
}

/* Property-level RBAC Styling - Action Menu Based */
.property-permission-text {
	font-family: monospace;
	font-size: 12px;
	font-weight: 600;
}

.property-permission-remove-btn {
	font-size: 11px;
	color: var(--color-error);
}

/* Schema Extension Status Pill */
.statusPill {
	display: inline-block;
	padding: 4px 12px;
	border-radius: 12px;
	font-size: 0.75em;
	font-weight: 600;
	text-transform: uppercase;
	margin-left: 8px;
	white-space: nowrap;
	vertical-align: middle;
}

.statusPill--alert {
	background-color: var(--color-warning);
	color: var(--color-main-background);
}

/* Title with badge layout */
.title-with-badge {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.title-with-badge > :first-child {
	flex: 1;
	min-width: 0;
}

/* Parent schema link styling */
.parent-schema-link {
	margin-top: 12px;
	padding: 8px 12px;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
	font-size: 0.9em;
}

.parent-schema-link strong {
	color: var(--color-text-dark);
}

/* Extend info card styling */
.extend-info {
	margin-top: 12px;
	margin-bottom: 12px;
}

.extend-info p {
	margin: 8px 0;
}

.extend-info p:first-of-type {
	margin-top: 0;
}

.extend-info p:last-of-type {
	margin-bottom: 0;
}
</style>

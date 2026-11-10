/* eslint-disable */
import { useDropZone, useFileDialog } from '@vueuse/core'
import { ref, computed } from 'vue'
import { objectStore } from '../store/store.js'

/**
 * Copy a file from its binary data, keeping name, type and modification time.
 *
 * @param {File} file The file.
 * @return {File} The copy.
 */
function copyFile(file) {
	return new File([file], file.name, {
		type: file.type,
		lastModified: file.lastModified,
	})
}

/**
 * Copy a file and mark it pending, with tags and status as defined
 * (writable, enumerable, not configurable) properties.
 *
 * @param {File} file The file.
 * @param {Array} tags The tags to attach.
 * @return {File} The pending copy.
 */
function pendingCopy(file, tags) {
	const newFile = copyFile(file)
	Object.defineProperty(newFile, 'tags', {
		value: tags,
		writable: true,
		enumerable: true,
	})
	Object.defineProperty(newFile, 'status', {
		value: 'pending',
		writable: true,
		enumerable: true,
	})
	return newFile
}

/**
 * Copy a file and mark it pending, with tags and status as plain properties.
 *
 * @param {File} file The file.
 * @param {Array} tags The tags to attach.
 * @return {File} The pending copy.
 */
function assignedPendingCopy(file, tags) {
	const newFile = copyFile(file)
	newFile.tags = tags
	newFile.status = 'pending'
	return newFile
}

/**
 * File selection composable
 * @param {Array} options
 *
 * Special thanks to Github user adamreisnz for creating most of this file
 * https://github.com/adamreisnz
 * https://github.com/vueuse/vueuse/issues/4085
 *
 * @spec exclude Upstream-derived VueUse dropzone/file-dialog wrapper; generic file-picker plumbing not tied to a register-data contract.
 */
export function useFileSelection(options) {
	// Extract options
	const { dropzone, allowMultiple, allowedFileTypes, onFileDrop, onFileSelect } =
		options

	// Data types computed ref
	const dataTypes = computed(() => {
		if (allowedFileTypes) {
			if (!Array.isArray(allowedFileTypes)) {
				return [allowedFileTypes]
			}
			return allowedFileTypes
		}
		return null
	})

	let tags = []
	const setTags = (_tags) => {
		tags = _tags
	}

	// Accept string computed ref
	const accept = computed(() => {
		if (Array.isArray(dataTypes.value)) {
			return dataTypes.value.join(',')
		}
		return '*'
	})

	// Append dropped files not yet in the list, or start a new list
	const merge = (files) => {
		const kept = filesList.value
		if (kept?.length > 0) {
			const added = files.filter(
				(file) => !kept.some((f) => f.name === file.name),
			)
			return [...kept, ...added.map((file) => pendingCopy(file, tags))]
		}
		return Array.from(files, (file) => pendingCopy(file, tags))
	}

	// The file list after a drop
	const select = (files) => {
		const list =
			files instanceof FileList
				? Array.from(files, (file) => assignedPendingCopy(file, tags))
				: files
		if (!allowMultiple) {
			return list.length > 1 ? [list[0]] : list
		}
		return merge(list)
	}

	// Handling of files drop
	const onDrop = (files) => {
		if (!files || files.length === 0) {
			return
		}
		filesList.value = select(files)
		onFileDrop && onFileDrop()
		onFileSelect && onFileSelect()
	}

	const reset = (name = null) => {
		if (name) {
			filesList.value =
				filesList.value.filter((file) => file.name !== name).length > 0
					? filesList.value.filter((file) => file.name !== name)
					: null
		} else {
			filesList.value = null
		}
	}
	const setFiles = (files) => {
		filesList.value = files
		objectStore.setAttachmentFile(null)
	}

	// Setup dropzone and file dialog composables
	const { isOverDropZone } = useDropZone(dropzone, { dataTypes: '*', onDrop })
	const { onChange, open } = useFileDialog({
		accept: accept.value,
		multiple: allowMultiple,
	})

	const filesList = ref(null)

	// Use onChange handler
	onChange((fileList) => onDrop(fileList))

	// Expose interface
	return {
		isOverDropZone,
		openFileUpload: open,
		files: filesList,
		reset,
		setFiles,
		setTags,
	}
}

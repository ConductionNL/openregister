/* eslint-disable */
import { useDropZone, useFileDialog } from '@vueuse/core'
import { ref, computed } from 'vue'
import { objectStore } from '../store/store.js'

/**
 * File selection composable
 * @param {Array} options
 *
 * Special thanks to Github user adamreisnz for creating most of this file
 * https://github.com/adamreisnz
 * https://github.com/vueuse/vueuse/issues/4085
 *
 */
export function useFileSelection(options) {

	// Extract options
	const {
		dropzone,
		allowMultiple,
		allowedFileTypes,
		onFileDrop,
		onFileSelect,
	} = options

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

	// Handling of files drop
	const onDrop = files => {
		if (!files || files.length === 0) {
			return
		}
		if (files instanceof FileList) {
			files = Array.from(files, (file) => {
				// Create new File object using the original file's binary data
				const newFile = new File([file], file.name, {
					type: file.type,
					lastModified: file.lastModified,
				})
				newFile.tags = tags
				newFile.status = 'pending'
				return newFile
			})
		}

		if (files.length > 1 && !allowMultiple) {
			files = [files[0]]
		}

		if (filesList.value?.length > 0 && allowMultiple) {
			const filteredFiles = files.filter(file => !filesList.value.some(f => f.name === file.name))

			const filteredFilesWithLabels = filteredFiles.map(file => {
				// Create new File object using the original file's binary data
				const newFile = new File([file], file.name, {
					type: file.type,
					lastModified: file.lastModified,
				})
				// Add tags
				Object.defineProperty(newFile, 'tags', {
					value: tags,
					writable: true,
					enumerable: true,
				})
				// Add status
				Object.defineProperty(newFile, 'status', {
					value: 'pending', // Default status
					writable: true,
					enumerable: true,
				})
				return newFile
			})

			files = [...filesList.value, ...filteredFilesWithLabels]
		}

		if (files.length > 0 && !filesList.value?.length > 0 && allowMultiple) {
			files = Array.from(files, (file) => {
				// Create new File object using the original file's binary data
				const newFile = new File([file], file.name, {
					type: file.type,
					lastModified: file.lastModified,
				})
				// Add tags
				Object.defineProperty(newFile, 'tags', {
					value: tags,
					writable: true,
					enumerable: true,
				})
				// Add status
				Object.defineProperty(newFile, 'status', {
					value: 'pending', // Default status
					writable: true,
					enumerable: true,
				})
				return newFile
			})
		}

		filesList.value = files
		onFileDrop && onFileDrop()
		onFileSelect && onFileSelect()
	}

	const reset = (name = null) => {
		if (name) {
			filesList.value = filesList.value.filter(file => file.name !== name).length > 0 ? filesList.value.filter(file => file.name !== name) : null
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
	onChange(fileList => onDrop(fileList))

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

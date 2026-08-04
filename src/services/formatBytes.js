/**
 * @param bytes
 * @spec exclude Stateless byte-size formatting helper; pure presentation utility with no domain contract.
 */
export default function formatBytes(bytes) {
	if (!bytes || bytes === 0) {
		return '0 KB'
	}
	const k = 1024
	const sizes = ['B', 'KB', 'MB', 'GB']
	const i = Math.floor(Math.log(bytes) / Math.log(k))
	return `${parseFloat((bytes / Math.pow(k, i)).toFixed(2))} ${sizes[i]}`
}

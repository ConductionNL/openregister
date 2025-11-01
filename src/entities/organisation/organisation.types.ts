export type TOrganisation = {
    id?: number
    uuid?: string
    name: string
    slug?: string
    description?: string
    users?: string[]
    userCount?: number
    isDefault?: boolean
    active?: boolean
    owner?: string
    storageQuota?: number | null
    bandwidthQuota?: number | null
    requestQuota?: number | null
    created?: string
    updated?: string
}

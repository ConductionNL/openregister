export type TSource = {
    id?: string | number;
    title: string;
    description: string;
    databaseUrl: string;
    type: 'internal' | 'mongodb';
    updated: string;
    created: string;
}

export type TSource = {
    id?: string | number;
    title: string;
    description: string;
    databaseUrl: string;
    type: string;
    updated: {
        date: string;
        timezone_type: number;
        timezone: string;
    };
    created: {
        date: string;
        timezone_type: number;
        timezone: string;
    };
}

export declare const assign: {
    <T extends {}, U>(target: T, source: U): T & U;
    <T extends {}, U, V>(target: T, source1: U, source2: V): T & U & V;
    <T extends {}, U, V, W>(target: T, source1: U, source2: V, source3: W): T & U & V & W;
    (target: object, ...sources: any[]): any;
};
export declare const getDescriptor: (o: any, p: PropertyKey) => PropertyDescriptor | undefined;
export declare const defineProperty: <T>(o: T, p: PropertyKey, attributes: PropertyDescriptor & ThisType<any>) => T;
export declare const objectPrototype: Object;
export declare const EMPTY_ARRAY: never[];
export declare const EMPTY_OBJECT: {};
export interface Lambda {
    (): void;
    name?: string;
}
export declare function assertProxies(): void;
export declare function warnAboutProxyRequirement(msg: string): void;
export declare function getNextId(): number;
/**
 * Makes sure that the provided function is invoked at most once.
 */
export declare function once(func: Lambda): Lambda;
export declare const noop: () => void;
export declare function isFunction(fn: any): fn is Function;
export declare function isString(value: any): value is string;
export declare function isStringish(value: any): value is string | number | symbol;
export declare function isObject(value: any): value is Object;
export declare function isPlainObject(value: any): boolean;
export declare function isGenerator(obj: any): boolean;
export declare function addHiddenProp(object: any, propName: PropertyKey, value: any): void;
export declare function addHiddenFinalProp(object: any, propName: PropertyKey, value: any): void;
export declare function createInstanceofPredicate<T>(name: string, theClass: new (...args: any[]) => T): (x: any) => x is T;
/**
 * Yields true for both native and observable Map, even across different windows.
 */
export declare function isES6Map(thing: unknown): thing is Map<any, any>;
/**
 * Makes sure a Map is an instance of non-inherited native or observable Map.
 */
export declare function isPlainES6Map(thing: Map<unknown, unknown>): boolean;
/**
 * Yields true for both native and observable Set, even across different windows.
 */
export declare function isES6Set(thing: unknown): thing is Set<any>;
/**
 * Returns the following: own enumerable keys and symbols.
 */
export declare function getPlainObjectKeys(object: any): (string | symbol)[];
export declare const ownKeys: (target: any) => Array<string | symbol>;
export declare function stringifyKey(key: any): string;
export declare function toPrimitive(value: any): any;
export declare function hasProp(target: Object, prop: PropertyKey): boolean;
export declare const getOwnPropertyDescriptors: <T>(o: T) => { [P in keyof T]: TypedPropertyDescriptor<T[P]>; } & {
    [x: string]: PropertyDescriptor;
};
export declare function getFlag(flags: number, mask: number): boolean;
export declare function setFlag(flags: number, mask: number, newValue: boolean): number;

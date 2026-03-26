import type { Config } from '@redocly/openapi-core';
/**
 * Based on loadAndBundleSpec from redoc.
 * Customized to pass custom loaded config
 * @see https://github.com/Redocly/redoc/blob/33be51a7a4068f44fd914314002c058a204ba0c2/src/utils/loadAndBundleSpec.ts
 */
export declare function loadSpecWithConfig(specUrlOrObject: object | string, config: Config): Promise<import("@redocly/openapi-core/lib/bundle").BundleResult>;
//# sourceMappingURL=loadSpec.d.ts.map
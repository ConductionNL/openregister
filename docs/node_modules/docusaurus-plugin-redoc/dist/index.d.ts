import type { LoadContext, Plugin, OptionValidationContext } from '@docusaurus/types';
import type { OpenAPISpec } from 'redoc/typings/types';
import { PluginOptionSchema, PluginOptions, PluginDirectUsageOptions } from './options';
import { loadRedoclyConfig } from './loadRedoclyConfig';
export { PluginOptions, PluginDirectUsageOptions, loadRedoclyConfig };
export default function redocPlugin(context: LoadContext, opts: PluginOptions): Plugin<{
    converted: OpenAPISpec;
    bundle?: Record<string, unknown>;
}>;
export declare function validateOptions({ options, validate, }: OptionValidationContext<typeof PluginOptionSchema, PluginOptions>): PluginOptions;
//# sourceMappingURL=index.d.ts.map
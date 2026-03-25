import type { LoadContext } from '@docusaurus/types';
import type { PluginOptions } from 'docusaurus-plugin-redoc';
import type { ThemeOptions } from 'docusaurus-theme-redoc';
import type { PresetEntry, PresetOptions } from './types';
export type { PresetEntry, PresetOptions };
export default function preset(context: LoadContext, opts?: PresetOptions): Promise<{
    themes: readonly (readonly [string, ThemeOptions])[];
    plugins: readonly (readonly [string, PluginOptions])[];
}>;
//# sourceMappingURL=index.d.ts.map
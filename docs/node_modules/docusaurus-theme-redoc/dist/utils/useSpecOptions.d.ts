import '../global';
import type { RedocRawOptions } from 'redoc';
import type { SpecProps } from '../types/common';
/**
 * Redocusaurus
 * https://redocusaurus.vercel.app/
 * (c) 2024 Rohit Gohri
 * Released under the MIT License
 */
export declare function useSpecOptions(themeId?: SpecProps['themeId'], optionsOverrides?: RedocRawOptions): {
    options: RedocRawOptions;
    darkThemeOptions: RedocRawOptions;
    lightThemeOptions: RedocRawOptions;
};
//# sourceMappingURL=useSpecOptions.d.ts.map
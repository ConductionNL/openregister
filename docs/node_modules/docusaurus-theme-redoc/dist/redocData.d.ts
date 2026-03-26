import type { GlobalData, RedocThemeOverrides, ThemeOptions } from './types/options';
export declare function getRedocThemes(customTheme: RedocThemeOverrides, customDarkTheme?: RedocThemeOverrides): {
    darkTheme: RedocThemeOverrides;
    lightTheme: RedocThemeOverrides;
};
export declare function getGlobalData({ primaryColor, primaryColorDark, theme: customThemeDeprecated, options, }: ThemeOptions): Promise<GlobalData>;
//# sourceMappingURL=redocData.d.ts.map
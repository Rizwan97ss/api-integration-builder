import { definePreset } from '@primeuix/themes';
import Aura from '@primeuix/themes/aura';
import type { App } from 'vue';
import PrimeVue from 'primevue/config';
import Tooltip from 'primevue/tooltip';

/**
 * Aura re-tinted with the app's neutral palette so PrimeVue components
 * match the shadcn-based UI in both light and dark mode.
 */
const AppPreset = definePreset(Aura, {
    semantic: {
        primary: {
            50: '{zinc.50}',
            100: '{zinc.100}',
            200: '{zinc.200}',
            300: '{zinc.300}',
            400: '{zinc.400}',
            500: '{zinc.500}',
            600: '{zinc.600}',
            700: '{zinc.700}',
            800: '{zinc.800}',
            900: '{zinc.900}',
            950: '{zinc.950}',
        },
        colorScheme: {
            light: {
                primary: {
                    color: '{zinc.950}',
                    inverseColor: '#ffffff',
                    hoverColor: '{zinc.800}',
                    activeColor: '{zinc.700}',
                },
                highlight: {
                    background: '{zinc.950}',
                    focusBackground: '{zinc.700}',
                    color: '#ffffff',
                    focusColor: '#ffffff',
                },
            },
            dark: {
                primary: {
                    color: '{zinc.50}',
                    inverseColor: '{zinc.950}',
                    hoverColor: '{zinc.200}',
                    activeColor: '{zinc.300}',
                },
                highlight: {
                    background: 'rgba(250, 250, 250, .16)',
                    focusBackground: 'rgba(250, 250, 250, .24)',
                    color: 'rgba(255,255,255,.87)',
                    focusColor: 'rgba(255,255,255,.87)',
                },
            },
        },
    },
});

/**
 * Register PrimeVue in styled mode inside its own CSS layer, which is
 * ordered in app.css so Tailwind utilities can still override it.
 */
export function installPrimeVue(app: App): void {
    app.use(PrimeVue, {
        theme: {
            preset: AppPreset,
            options: {
                darkModeSelector: '.dark',
                cssLayer: {
                    name: 'primevue',
                    order: 'theme, base, primevue, components, utilities',
                },
            },
        },
    });

    app.directive('tooltip', Tooltip);
}

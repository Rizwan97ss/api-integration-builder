import { createApp, Fragment, h } from 'vue';
import { Toaster } from 'vue-sonner';
import 'vue-sonner/style.css';
import { installPrimeVue } from '@/lib/primevue';
import Builder from '@/pages/integrations/Builder.vue';
import { boot } from './boot';
import './builder.css';

const app = createApp({
    render: () =>
        h(Fragment, [h(Builder, boot.props), h(Toaster, { richColors: true })]),
});

installPrimeVue(app);

app.mount('#app');

import Main from './components/Main.vue';

Statamic.booting(() => {
    Statamic.$components.register('cookie-less-tracking-report', Main);
});

import Main from './components/Main.vue';

Statamic.booting(() => {
    Statamic.$components.register('rc_main', Main);
});

// Functionality to make __('string') in js

import { usePage } from '@inertiajs/inertia-react';

export function __(key, replace = {}) {
    const { language } = usePage().props;
    var translation = language[key] ? language[key] : key;

    Object.keys(replace).forEach(function (key) {
        translation = translation.replace(':' + key, replace[key]);
    });

    return translation;
}

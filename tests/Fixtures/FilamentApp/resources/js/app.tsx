import { createInertiaApp } from '@inertiajs/react';

createInertiaApp({ resolve: (name) => import(`./pages/${name}.tsx`), setup() {} });

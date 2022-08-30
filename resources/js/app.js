import '@css/app.scss';
import baguetteBox from 'baguettebox.js';

// Accept HMR as per: https://vitejs.dev/guide/api-hmr.html
if (import.meta.hot) {
    import.meta.hot.accept(() => {
        console.log("HMR")
    });
}


window.baguetteBox = baguetteBox;

import Cookies from 'js-cookie'

window.Cookies = Cookies

import Consent from './stores/Consent';

import Alpine from 'alpinejs'

window.Alpine = Alpine

import focus from '@alpinejs/focus'
import collapse from '@alpinejs/collapse'
import intersect from '@alpinejs/intersect'

Alpine.plugin(focus)
Alpine.plugin(collapse)
Alpine.plugin(intersect)

Alpine.store('consent', Consent)
Alpine.store('modalOpen', false)

Alpine.start()

// import {getLCP, getFID, getCLS, getTTFB, getFCP} from 'web-vitals';
//
// getCLS(console.log);
// getFID(console.log);
// getLCP(console.log);
// getFCP(console.log);
// getTTFB(console.log);

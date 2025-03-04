/**
 * @copyright Copyright (c) 2019 John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @author John Molakvoæ <skjnldsv@protonmail.com>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Marco Ambrosini <marcoambrosini@pm.me>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

import Vue from 'vue'
import App from './App'

// Store
import Vuex from 'vuex'
import store from './store'

// Router
import VueRouter from 'vue-router'
import router from './router/router'

// Utils
import { generateFilePath } from '@nextcloud/router'
import { getRequestToken } from '@nextcloud/auth'

// Directives
import VueClipboard from 'vue-clipboard2'
import { translate, translatePlural } from '@nextcloud/l10n'
import VueObserveVisibility from 'vue-observe-visibility'
import VueShortKey from 'vue-shortkey'
import vOutsideEvents from 'vue-outside-events'

// Styles
import '@nextcloud/dialogs/styles/toast.scss'

// CSP config for webpack dynamic chunk loading
// eslint-disable-next-line
__webpack_nonce__ = btoa(getRequestToken())

// Correct the root of the app for chunk loading
// OC.linkTo matches the apps folders
// OC.generateUrl ensure the index.php (or not)
// We do not want the index.php since we're loading files
// eslint-disable-next-line
__webpack_public_path__ = generateFilePath('spreed', '', 'js/')

Vue.prototype.t = translate
Vue.prototype.n = translatePlural
Vue.prototype.OC = OC
Vue.prototype.OCA = OCA

Vue.use(Vuex)
Vue.use(VueRouter)
Vue.use(VueClipboard)
Vue.use(VueObserveVisibility)
Vue.use(VueShortKey, { prevent: ['input', 'textarea', 'div'] })
Vue.use(vOutsideEvents)

export default new Vue({
	el: '#content',
	store,
	router,
	propsData: {
		fileInfo: null,
	},
	render: h => h(App),
})

window.store = store

// Setup OCA.Files.Sidebar to be used by the viewer
window.OCA.Files = {}

const Sidebar = function() {
	this.state = {
		file: '',
	}

	store.watch(
		(state, getters) => {
			return getters.getSidebarStatus
		},
		(sidebarShown) => {
			if (!sidebarShown) {
				this.state.file = ''
			}
		}
	)
}

const waitForSidebarToBeOpen = function(sidebarElement, resolve) {
	if ('ontransitionend' in sidebarElement) {
		const resolveOnceSidebarWidthHasChanged = (event) => {
			if (event.propertyName !== 'min-width' && event.propertyName !== 'width' && event.propertyName !== 'max-width') {
				return
			}

			sidebarElement.removeEventListener('transitionend', resolveOnceSidebarWidthHasChanged)

			resolve()
		}

		sidebarElement.addEventListener('transitionend', resolveOnceSidebarWidthHasChanged)
	} else {
		const animationQuickValue = getComputedStyle(document.documentElement).getPropertyValue('--animation-quick')

		// The browser does not support the "ontransitionend" event, so just
		// wait a few milliseconds more than the duration of the transition.
		setTimeout(() => {
			console.debug('ontransitionend is not supported; the sidebar should have been fully shown by now')

			resolve()
		}, Number.parseInt(animationQuickValue) + 200)
	}
}

Sidebar.prototype.open = function(path) {
	// The sidebar is already open, so this can return immediately.
	if (this.state.file) {
		return
	}

	store.commit('showSidebar')
	this.state.file = path

	const sidebarElement = document.getElementById('app-sidebar')

	// The Viewer adjusts its width to the sidebar width once the sidebar has
	// been opened. The sidebar opens with an animation, so a delay is needed
	// before the width can be properly adjusted.
	return new Promise((resolve, reject) => {
		waitForSidebarToBeOpen(sidebarElement, resolve)
	})
}
Sidebar.prototype.close = function() {
	store.dispatch('hideSidebar')
	this.state.file = ''
}

Object.assign(window.OCA.Files, {
	Sidebar: new Sidebar(),
})

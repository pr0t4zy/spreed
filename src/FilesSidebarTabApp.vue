<!--
  - @copyright Copyright (c) 2019 Marco Ambrosini <marcoambrosini@pm.me>
  -
  - @author Marco Ambrosini <marcoambrosini@pm.me>
  -
  - @license GNU AGPL version 3 or any later version
  -
  - This program is free software: you can redistribute it and/or modify
  - it under the terms of the GNU Affero General Public License as
  - published by the Free Software Foundation, either version 3 of the
  - License, or (at your option) any later version.
  -
  - This program is distributed in the hope that it will be useful,
  - but WITHOUT ANY WARRANTY; without even the implied warranty of
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program. If not, see <http://www.gnu.org/licenses/>.
-->
<template>
	<div class="talkChatTab">
		<div v-if="isTalkSidebarSupportedForFile === undefined" class="emptycontent ui-not-ready-placeholder">
			<div class="icon icon-loading" />
		</div>
		<div v-else-if="!isTalkSidebarSupportedForFile" class="emptycontent file-not-shared">
			<div class="icon icon-talk" />
			<h2>{{ t('spreed', 'Discuss this file') }}</h2>
			<p>{{ t('spreed', 'Share this file with others to discuss it') }}</p>
			<button class="primary" @click="openSharingTab">
				{{ t('spreed', 'Share this file') }}
			</button>
		</div>
		<div v-else-if="isTalkSidebarSupportedForFile && !token" class="emptycontent room-not-joined">
			<div class="icon icon-talk" />
			<h2>{{ t('spreed', 'Discuss this file') }}</h2>
			<button class="primary" @click="joinConversation">
				{{ t('spreed', 'Join conversation') }}
			</button>
		</div>
		<template v-else>
			<div class="call-button-wrapper">
				<CallButton class="call-button" />
			</div>
			<ChatView :token="token" />
		</template>
	</div>
</template>

<script>

import { EventBus } from './services/EventBus'
import { getFileConversation } from './services/filesIntegrationServices'
import { fetchConversation } from './services/conversationsService'
import {
	joinConversation,
	leaveConversation,
	leaveConversationSync,
} from './services/participantsService'
import CancelableRequest from './utils/cancelableRequest'
import { signalingKill } from './utils/webrtc/index'
import { getCurrentUser } from '@nextcloud/auth'
import { loadState } from '@nextcloud/initial-state'
import Axios from '@nextcloud/axios'
import CallButton from './components/TopBar/CallButton'
import ChatView from './components/ChatView'
import duplicateSessionHandler from './mixins/duplicateSessionHandler'
import browserCheck from './mixins/browserCheck'
import '@nextcloud/dialogs/styles/toast.scss'

export default {

	name: 'FilesSidebarTabApp',

	components: {
		CallButton,
		ChatView,
	},

	mixins: [
		browserCheck,
		duplicateSessionHandler,
	],

	data() {
		return {
			// needed for reactivity
			Talk: OCA.Talk,
			sidebarState: OCA.Files.Sidebar.state,
			/**
			 * Stores the cancel function returned by `cancelableLookForNewMessages`,
			 */
			cancelGetFileConversation: () => {},
			isTalkSidebarSupportedForFile: undefined,
		}
	},

	computed: {
		fileInfo() {
			return this.Talk.fileInfo || {}
		},
		fileId() {
			return this.fileInfo.id
		},
		token() {
			return this.$store.getters.getToken()
		},
		fileIdForToken() {
			return this.$store.getters.getFileIdForToken()
		},
		isChatTheActiveTab() {
			// FIXME check for empty active tab is currently needed because the
			// activeTab is not set when opening the sidebar from the "Details"
			// action (which opens the first tab, which is the Chat tab).
			return !this.sidebarState.activeTab || this.sidebarState.activeTab === 'chat'
		},
	},

	watch: {
		fileInfo: {
			immediate: true,
			handler(fileInfo) {
				if (this.token && (!fileInfo || fileInfo.id !== this.fileIdForToken)) {
					this.leaveConversation()
				}

				this.setTalkSidebarSupportedForFile(fileInfo)
			},
		},

		isChatTheActiveTab: {
			immediate: true,
			handler(isChatTheActiveTab) {
				this.forceTabsContentStyleWhenChatTabIsActive(isChatTheActiveTab)
			},
		},
	},

	created() {
		// The fetchCurrentConversation event handler/callback is started and
		// stopped from different FilesSidebarTabApp instances, so it needs to
		// be stored in a common place. Moreover, as the bound method would be
		// overriden when a new instance is created the one used as handler is
		// a wrapper that calls the latest bound method. This makes possible to
		// register and unregister it from different instances.
		if (!OCA.Talk.fetchCurrentConversationWrapper) {
			OCA.Talk.fetchCurrentConversationWrapper = function() {
				OCA.Talk.fetchCurrentConversationBound()
			}
		}

		OCA.Talk.fetchCurrentConversationBound = this.fetchCurrentConversation.bind(this)
	},

	beforeMount() {
		this.$store.dispatch('setCurrentUser', getCurrentUser())

		window.addEventListener('unload', () => {
			console.info('Navigating away, leaving conversation')
			if (this.token) {
				// We have to do this synchronously, because in unload and beforeunload
				// Promises, async and await are prohibited.
				signalingKill()
				if (!this.isLeavingAfterSessionConflict) {
					leaveConversationSync(this.token)
				}
			}
		})
	},

	methods: {
		async joinConversation() {
			// see browserCheck mixin
			this.checkBrowser()

			try {
				await this.getFileConversation()
			} catch (error) {
				console.debug('Could not get file conversation. Is it a file and shared?')
				return
			}

			await joinConversation(this.token)

			// The current participant (which is automatically set when fetching
			// the current conversation) is needed for the MessagesList to start
			// getting the messages, and both the current conversation and the
			// current participant are needed for CallButton. No need to wait
			// for it, but fetching the conversation needs to be done once the
			// user has joined the conversation (otherwise only limited data
			// would be received if the user was not a participant of the
			// conversation yet).
			this.fetchCurrentConversation()

			// FIXME The participant will not be updated with the server data
			// when the conversation is got again (as "addParticipantOnce" is
			// used), although that should not be a problem given that only the
			// "inCall" flag (which is locally updated when joining and leaving
			// a call) is currently used.
			if (loadState('talk', 'signaling_mode') !== 'internal') {
				EventBus.$on('shouldRefreshConversations', OCA.Talk.fetchCurrentConversationWrapper)
				EventBus.$on('Signaling::participantListChanged', OCA.Talk.fetchCurrentConversationWrapper)
			} else {
				// The "shouldRefreshConversations" event is triggered only when
				// the external signaling server is used; when the internal
				// signaling server is used periodic polling has to be used
				// instead.
				OCA.Talk.fetchCurrentConversationIntervalId = window.setInterval(OCA.Talk.fetchCurrentConversationWrapper, 30000)
			}
		},

		leaveConversation() {
			EventBus.$off('shouldRefreshConversations', OCA.Talk.fetchCurrentConversationWrapper)
			EventBus.$off('Signaling::participantListChanged', OCA.Talk.fetchCurrentConversationWrapper)
			window.clearInterval(OCA.Talk.fetchCurrentConversationIntervalId)

			// Remove the conversation to ensure that the old data is not used
			// before fetching it again if this conversation is joined again.
			this.$store.dispatch('deleteConversation', this.token)
			// Remove the participant to ensure that it will be set again fresh
			// if this conversation is joined again.
			this.$store.dispatch('purgeParticipantsStore', this.token)

			leaveConversation(this.token)

			this.$store.dispatch('updateTokenAndFileIdForToken', {
				newToken: null,
				newFileId: null,
			})
		},

		async getFileConversation() {
			// Clear previous requests if there's one pending
			this.cancelGetFileConversation('canceled')
			// Get a new cancelable request function and cancel function pair
			const { request, cancel } = CancelableRequest(getFileConversation)
			// Assign the new cancel function to our data value
			this.cancelGetFileConversation = cancel
			// Make the request
			try {
				const response = await request({ fileId: this.fileId })
				this.$store.dispatch('updateTokenAndFileIdForToken', {
					newToken: response.data.ocs.data.token,
					newFileId: this.fileId,
				})
			} catch (exception) {
				if (Axios.isCancel(exception)) {
					console.debug('The request has been canceled', exception)
				} else {
					throw exception
				}
			}
		},

		async fetchCurrentConversation() {
			if (!this.token) {
				return
			}

			const response = await fetchConversation(this.token)
			this.$store.dispatch('addConversation', response.data.ocs.data)
			this.$store.dispatch('markConversationRead', this.token)
		},

		/**
		 * Sets whether the Talk sidebar is supported for the file or not.
		 *
		 * In some cases it is not possible to know if the Talk sidebar is
		 * supported for the file or not just from the data in the FileInfo (for
		 * example, for files in a folder shared by the current user). Due to
		 * that this function is asynchronous; isTalkSidebarSupportedForFile
		 * will be set as soon as possible (in some cases, immediately) with
		 * either true or false, depending on whether the Talk sidebar is
		 * supported for the file or not.
		 *
		 * The Talk sidebar is supported for a file if the file is shared with
		 * the current user or by the current user to another user (as a user,
		 * group...), or if the file is a descendant of a folder that meets
		 * those conditions.
		 *
		 * @param {OCA.Files.FileInfo} fileInfo the FileInfo to check
		 */
		async setTalkSidebarSupportedForFile(fileInfo) {
			this.isTalkSidebarSupportedForFile = undefined

			if (!fileInfo) {
				this.isTalkSidebarSupportedForFile = false

				return
			}

			if (fileInfo.get('type') === 'dir') {
				this.isTalkSidebarSupportedForFile = false

				return
			}

			if (fileInfo.get('shareOwnerId')) {
				// Shared with me
				// TODO How to check that it is not a remote share? At least for
				// local shares "shareTypes" is not defined when shared with me.
				this.isTalkSidebarSupportedForFile = true

				return
			}

			if (!fileInfo.get('shareTypes')) {
				// When it is not possible to know whether the Talk sidebar is
				// supported for a file or not only from the data in the
				// FileInfo it is necessary to query the server.
				// FIXME If the file is shared this will create the conversation
				// if it does not exist yet.
				try {
					this.isTalkSidebarSupportedForFile = (await getFileConversation({ fileId: fileInfo.id })) || false
				} catch (error) {
					this.isTalkSidebarSupportedForFile = false
				}

				return
			}

			const shareTypes = fileInfo.get('shareTypes').filter(function(shareType) {
				// Ensure that shareType is an integer (as in the past shareType
				// could be an integer or a string depending on whether the
				// Sharing tab was opened or not).
				shareType = parseInt(shareType)
				return shareType === OC.Share.SHARE_TYPE_USER
						|| shareType === OC.Share.SHARE_TYPE_GROUP
						|| shareType === OC.Share.SHARE_TYPE_CIRCLE
						|| shareType === OC.Share.SHARE_TYPE_ROOM
						|| shareType === OC.Share.SHARE_TYPE_LINK
						|| shareType === OC.Share.SHARE_TYPE_EMAIL
			})

			if (shareTypes.length === 0) {
				// When it is not possible to know whether the Talk sidebar is
				// supported for a file or not only from the data in the
				// FileInfo it is necessary to query the server.
				// FIXME If the file is shared this will create the conversation
				// if it does not exist yet.
				try {
					this.isTalkSidebarSupportedForFile = (await getFileConversation({ fileId: fileInfo.id })) || false
				} catch (error) {
					this.isTalkSidebarSupportedForFile = false
				}

				return
			}

			this.isTalkSidebarSupportedForFile = true
		},

		openSharingTab() {
			OCA.Files.Sidebar.setActiveTab('sharing')
		},

		/**
		 * Dirty hack to set the style in the tabs container.
		 *
		 * This is needed to force the scroll bars on the tabs container instead
		 * of on the whole sidebar.
		 *
		 * Additionally a minimum height is forced to ensure that the height of
		 * the chat view will be at least 300px, even if the info view is large
		 * and the screen short; in that case a scroll bar will be shown for the
		 * sidebar, but even if that looks really bad it is better than an
		 * unusable chat view.
		 *
		 * @param {boolean} isChatTheActiveTab whether the active tab is the
		 *        chat tab or not.
		 */
		forceTabsContentStyleWhenChatTabIsActive(isChatTheActiveTab) {
			const tabs = document.querySelector('.app-sidebar-tabs')
			const tabsContent = document.querySelector('.app-sidebar-tabs__content')

			if (isChatTheActiveTab) {
				this.savedTabsMinHeight = tabs.style.minHeight
				this.savedTabsOverflow = tabs.style.overflow
				this.savedTabsContentOverflow = tabsContent.style.overflow
				this.savedTabsContentStyle = true

				tabs.style.minHeight = '300px'
				tabs.style.overflow = 'hidden'
				tabsContent.style.overflow = 'hidden'
			} else if (this.savedTabsContentStyle) {
				tabs.style.minHeight = this.savedTabsMinHeight
				tabs.style.overflow = this.savedTabsOverflow
				tabsContent.style.overflow = this.savedTabsContentOverflow

				delete this.savedTabsMinHeight
				delete this.savedTabsOverflow
				delete this.savedTabsContentOverflow
				this.savedTabsContentStyle = false
			}
		},
	},
}
</script>

<style scoped>
.talkChatTab {
	height: 100%;

	display: flex;
	flex-grow: 1;
	flex-direction: column;
}

.emptycontent {
	/* Override default top margin set in server and center vertically
	 * instead. */
	margin-top: unset;

	height: 100%;

	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
}

.call-button-wrapper {
	width: 100%;
	background-color: var(--color-main-background);
	z-index: 1;
}

.call-button {
	display: block;

	/* Center button horizontally. */
	margin-left: auto;
	margin-right: auto;

	margin-top: 10px;
	margin-bottom: 10px;
}

::v-deep .scroller {
	margin-top: 64px;
}

.chatView {
	overflow: hidden;
}
</style>

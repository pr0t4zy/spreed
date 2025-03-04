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
	<div class="wrapper">
		<button slot="trigger"
			v-tooltip.bottom="t('spreed','Create a new group conversation')"
			class="toggle"
			icon=""
			:aria-label="t('spreed','Create a new group conversation')"
			@click="showModal">
			<Plus
				decorative
				title=""
				:size="24" />
		</button>
		<!-- New group form -->
		<Modal
			v-if="modal"
			@close="closeModal">
			<!-- Wrapper for content & navigation -->
			<div
				class="new-group-conversation talk-modal">
				<!-- Content -->
				<div
					class="new-group-conversation__content">
					<!-- First page -->
					<template
						v-if="page === 0">
						<SetConversationName
							v-model="conversationNameInput"
							@clickEnter="handleEnter" />
						<SetConversationType
							v-model="isPublic"
							:conversation-name="conversationName" />
						<!-- Password protection -->
						<template v-if="isPublic">
							<input
								id="password-checkbox"
								type="checkbox"
								class="checkbox"
								:checked="passwordProtect"
								@input="handleCheckboxInput">
							<label for="password-checkbox">{{ t('spreed', 'Password protect') }}</label>
							<PasswordProtect
								v-if="passwordProtect"
								v-model="password" />
						</template>
						<ListableSettings v-model="listable" />
					</template>
					<!-- Second page -->
					<template v-if="page === 1">
						<SetContacts
							:conversation-name="conversationName" />
					</template>
					<!-- Third page -->
					<template v-if="page === 2">
						<Confirmation
							:conversation-name="conversationName"
							:error="error"
							:is-loading="isLoading"
							:success="success"
							:is-public="isPublic"
							:link-to-conversation="linkToConversation" />
					</template>
				</div>
				<!-- Navigation: different buttons with different actions and
				placement are rendered depending on the current page -->
				<div
					class="navigation">
					<!-- First page -->
					<button
						v-if="page===0"
						class="navigation__button navigation__button-right primary"
						:disabled="disabled"
						@click="handleSetConversationName">
						{{ t('spreed', 'Add participants') }}
					</button>
					<!-- Second page -->
					<button
						v-if="page===1"
						class="navigation__button navigation__button-left"
						@click="handleClickBack">
						{{ t('spreed', 'Back') }}
					</button>
					<button
						v-if="page===1"
						class="navigation__button navigation__button-right primary"
						@click="handleCreateConversation">
						{{ t('spreed', 'Create conversation') }}
					</button>
					<!-- Third page -->
					<button
						v-if="page===2 && (error || isPublic)"
						class="navigation__button navigation__button-right primary"
						@click="closeModal">
						{{ t('spreed', 'Close') }}
					</button>
				</div>
			</div>
		</modal>
	</div>
</template>

<script>

import { CONVERSATION } from '../../../constants'
import Modal from '@nextcloud/vue/dist/Components/Modal'
import Plus from 'vue-material-design-icons/Plus'
import SetContacts from './SetContacts/SetContacts'
import SetConversationName from './SetConversationName/SetConversationName'
import SetConversationType from './SetConversationType/SetConversationType'
import Confirmation from './Confirmation/Confirmation'
import { addParticipant } from '../../../services/participantsService'
import {
	createPublicConversation,
	createPrivateConversation,
	setConversationPassword,
} from '../../../services/conversationsService'
import { generateUrl } from '@nextcloud/router'
import PasswordProtect from './PasswordProtect/PasswordProtect'
import ListableSettings from '../../ConversationSettings/ListableSettings'
import isInCall from '../../../mixins/isInCall'
import participant from '../../../mixins/participant'
import Tooltip from '@nextcloud/vue/dist/Directives/Tooltip'

export default {

	name: 'NewGroupConversation',

	directives: {
		tooltip: Tooltip,
	},

	components: {
		Modal,
		SetContacts,
		SetConversationName,
		SetConversationType,
		Confirmation,
		PasswordProtect,
		ListableSettings,
		Plus,
	},

	mixins: [
		isInCall,
		participant,
	],

	data() {
		return {
			modal: false,
			page: 0,
			conversationNameInput: '',
			isPublic: false,
			isLoading: true,
			token: '',
			success: false,
			error: false,
			password: '',
			passwordProtect: false,
			listable: CONVERSATION.LISTABLE.NONE,
		}
	},

	computed: {
		// Trims whitespaces from the input string
		conversationName() {
			return this.conversationNameInput.trim()
		},
		// Generates the link to the current conversation
		linkToConversation() {
			if (this.token !== '') {
				return window.location.protocol + '//' + window.location.host + generateUrl('/call/' + this.token)
			} else return ''
		},
		// Controls the disabled/enabled state of the first page's button.
		disabled() {
			return this.conversationName === '' || (this.passwordProtect && this.password === '')
		},
		selectedParticipants() {
			return this.$store.getters.selectedParticipants
		},
	},

	methods: {
		showModal() {
			this.modal = true
		},
		/** Reinitialise the component to it's initial state. This is necessary
		 * because once the component is mounted it's data would persist even if
		 * the modal closes */
		closeModal() {
			this.modal = false
			this.page = 0
			this.conversationNameInput = ''
			this.isPublic = false
			this.isLoading = true
			this.token = ''
			this.success = false
			this.error = false
			this.passwordProtect = false
			this.password = ''
			this.listable = CONVERSATION.LISTABLE.NONE
			this.$store.dispatch('purgeNewGroupConversationStore')
		},
		/** Switch to page 2 */
		handleSetConversationName() {
			this.page = 1
		},
		/** Switch to page 1 from page 2 */
		handleClickBack() {
			this.page = 0
		},
		/** Handles the creation of the group conversation, adds the seleced
		 * participants to it and routes to it */
		async handleCreateConversation() {
			this.page = 2

			// TODO: move all operations to a single store action
			// and commit + addConversation only once at the very end
			if (this.isPublic) {
				try {
					await this.createPublicConversation()
					if (this.password && this.passwordProtect) {
						await setConversationPassword(this.token, this.password)
					}
				} catch (exception) {
					console.debug(exception)
					this.isLoading = false
					this.error = true
					// Stop the execution of the method on exceptions.
					return
				}
			} else {
				try {
					await this.createPrivateConversation()
				} catch (exception) {
					console.debug(exception)
					this.isLoading = false
					this.error = true
					// Stop the execution of the method on exceptions.
					return
				}
			}

			try {
				await this.$store.dispatch('setListable', {
					token: this.token,
					listable: this.listable,
				})
			} catch (exception) {
				console.debug(exception)
				this.isLoading = false
				this.error = true
				// Stop the execution of the method on exceptions.
				return
			}

			for (const participant of this.selectedParticipants) {
				try {
					await addParticipant(this.token, participant.id, participant.source)
				} catch (exception) {
					console.debug(exception)
					this.isLoading = false
					this.error = true
					// Stop the execution of the method on exceptions.
					return
				}
			}

			this.success = true

			if (!this.isInCall) {
				// Push the newly created conversation's route.
				this.pushNewRoute()
			}

			// Close the modal right away if the conversation is public.
			if (!this.isPublic) {
				this.closeModal()
			}
		},
		/** Creates a new private conversation, adds it to the store and sets
		 * the local token value to the newly created conversation's token */
		async createPrivateConversation() {
			const response = await createPrivateConversation(this.conversationName)
			const conversation = response.data.ocs.data
			this.$store.dispatch('addConversation', conversation)
			this.token = conversation.token
		},
		/** Creates a new public conversation, adds it to the store and sets
		 * the local token value to the newly created conversation's token */
		async createPublicConversation() {
			const response = await createPublicConversation(this.conversationName)
			const conversation = response.data.ocs.data
			this.$store.dispatch('addConversation', conversation)
			this.token = conversation.token
		},
		pushNewRoute() {
			this.$router.push({ name: 'conversation', params: { token: this.token } })
				.catch(err => console.debug(`Error while pushing the new conversation's route: ${err}`))
		},
		handleCheckboxInput(event) {
			this.passwordProtect = event.target.checked
			// Reinitialise the password value when unchecking the password-protect option.
			if (this.passwordProtect === false) {
				this.password = ''
			}
		},
		/** Handles the press of the enter key */
		handleEnter() {
			if (!this.disabled) {
				this.handleSetConversationName()
				this.page = 1
			}
		},
	},

}

</script>

<style lang="scss" scoped>

// Dialog variables
$dialog-margin: 20px;
$dialog-width: 400px;
$dialog-height: 480px;

.toggle {
	height: 44px;
	width: 44px;
	padding: 0;
	margin: 0 0 0 4px;
}

.new-group-conversation {
	/** This next 2 rules are pretty hacky, with the modal component somehow
	the margin applied to the content is added to the total modal width,
	so here we subtract it to the width and height of the content.
	*/
	width: $dialog-width - $dialog-margin * 2;
	height: $dialog-height - $dialog-margin * 2;
	margin: $dialog-margin;
	display: flex;
	flex-direction: column;
	justify-content: space-between;
	position: relative;
	&__content {
		height: calc(100% - 50px);
	}
}

/** Size full in the modal component doesn't have border radius, this adds
it back */
::v-deep .modal-container {
	border-radius: var(--border-radius-large) !important;
}

.navigation {
	display: flex;
	flex: 0 0 40px;
	height: 50px;
	box-shadow: 0 -10px 5px var(--color-main-background);
	z-index: 1;
	// Same as above
	width: $dialog-width - $dialog-margin * 2;
	&__button {
		height: 44px;
		padding: 0 16px;
	}
	&__button-right {
		margin-left:auto;
	}
}

.wrapper {
	margin: auto;
}

::v-deep .app-settings-section__hint {
	color: var(--color-text-lighter);
	padding: 8px 0;
}

::v-deep .app-settings-subsection {
	margin-top: 25px;

	&:first-child {
		margin-top: 0;
	}
}

</style>

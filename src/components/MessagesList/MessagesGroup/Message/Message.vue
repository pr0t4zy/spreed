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

<docs>
This component displays the text inside the message component and can be used for
the main body of the message as well as a quote.
</docs>

<template>
	<li
		:id="`message_${id}`"
		ref="message"
		class="message"
		:class="{'hover': showActions && !isSystemMessage, 'system' : isSystemMessage}"
		@mouseover="showActions=true"
		@mouseleave="showActions=false">
		<div v-if="isFirstMessage && showAuthor"
			class="message__author"
			role="heading"
			aria-level="4">
			{{ actorDisplayName }}
		</div>
		<div
			ref="messageMain"
			class="message__main">
			<div v-if="isSingleEmoji"
				class="message__main__text">
				<Quote v-if="parent" :parent-id="parent" v-bind="quote" />
				<div class="single-emoji">
					{{ message }}
				</div>
			</div>
			<div v-else-if="showJoinCallButton" class="message__main__text call-started">
				<RichText :text="message" :arguments="richParameters" :autolink="true" />
				<CallButton />
			</div>
			<div v-else class="message__main__text" :class="{'system-message': isSystemMessage}">
				<Quote v-if="parent" :parent-id="parent" v-bind="quote" />
				<RichText :text="message" :arguments="richParameters" :autolink="true" />
			</div>
			<div class="message__main__right">
				<span
					v-tooltip.auto="messageDate"
					class="date"
					:style="{'visibility': hasDate ? 'visible' : 'hidden'}"
					:class="{'date--self': showSentIcon}">{{ messageTime }}</span>
				<!-- Message delivery status indicators -->
				<div v-if="isTemporary && !isTemporaryUpload"
					v-tooltip.auto="loadingIconTooltip"
					class="icon-loading-small message-status"
					:aria-label="loadingIconTooltip" />
				<div v-if="showCommonReadIcon"
					v-tooltip.auto="commonReadIconTooltip"
					class="message-status"
					:aria-label="commonReadIconTooltip">
					<CheckAll decorative
						title=""
						:size="16" />
				</div>
				<div v-else-if="showSentIcon"
					v-tooltip.auto="sentIconTooltip"
					class="message-status"
					:aria-label="sentIconTooltip">
					<Check decorative
						title=""
						:size="16" />
				</div>
				<!-- Message Actions -->
				<Actions
					v-show="showActions && hasActions"
					class="message__main__right__actions"
					:class="{ 'tall' : isTallEnough }">
					<ActionButton
						v-if="isReplyable"
						icon="icon-reply"
						:close-after-click="true"
						@click.stop="handleReply">
						{{ t('spreed', 'Reply') }}
					</ActionButton>
				</Actions>
			</div>
		</div>
	</li>
</template>

<script>
import ActionButton from '@nextcloud/vue/dist/Components/ActionButton'
import Actions from '@nextcloud/vue/dist/Components/Actions'
import Tooltip from '@nextcloud/vue/dist/Directives/Tooltip'
import CallButton from '../../../TopBar/CallButton'
import DeckCard from './MessagePart/DeckCard'
import DefaultParameter from './MessagePart/DefaultParameter'
import FilePreview from './MessagePart/FilePreview'
import Mention from './MessagePart/Mention'
import RichText from '@juliushaertl/vue-richtext'
import Check from 'vue-material-design-icons/Check'
import CheckAll from 'vue-material-design-icons/CheckAll'
import Quote from '../../../Quote'
import participant from '../../../../mixins/participant'
import { EventBus } from '../../../../services/EventBus'
import emojiRegex from 'emoji-regex'
import { PARTICIPANT, CONVERSATION } from '../../../../constants'
import moment from '@nextcloud/moment'

export default {
	name: 'Message',

	directives: {
		tooltip: Tooltip,
	},

	components: {
		Actions,
		ActionButton,
		CallButton,
		Quote,
		RichText,
		Check,
		CheckAll,
	},

	mixins: [
		participant,
	],

	inheritAttrs: false,

	props: {
		/**
		 * The actor type of the sender of the message.
		 */
		actorType: {
			type: String,
			required: true,
		},
		/**
		 * The actor id of the sender of the message.
		 */
		actorId: {
			type: String,
			required: true,
		},
		/**
		 * The display name of the sender of the message.
		 */
		actorDisplayName: {
			type: String,
			required: true,
		},
		/**
		 * The message or quote text.
		 */
		message: {
			type: String,
			required: true,
		},
		/**
		 * The parameters of the rich object message
		 */
		messageParameters: {
			type: [Array, Object],
			required: true,
		},
		/**
		 * The message timestamp.
		 */
		timestamp: {
			type: Number,
			default: 0,
		},
		/**
		 * The message id.
		 */
		id: {
			type: [String, Number],
			required: true,
		},
		/**
		 * If true, it displays the message author on top of the message.
		 */
		showAuthor: {
			type: Boolean,
			default: true,
		},
		/**
		 * Specifies if the message is temporary in order to display the spinner instead
		 * of the message time.
		 */
		isTemporary: {
			type: Boolean,
			required: true,
		},
		/**
		 * Specifies if the message is the first of a group of same-author messages.
		 */
		isFirstMessage: {
			type: Boolean,
			required: true,
		},
		/**
		 * Specifies if the message can be replied to.
		 */
		isReplyable: {
			type: Boolean,
			required: true,
		},
		/**
		 * The conversation token.
		 */
		token: {
			type: String,
			required: true,
		},
		/**
		 * The conversation token.
		 */
		systemMessage: {
			type: String,
			required: true,
		},
		/**
		 * The parent message's id.
		 */
		parent: {
			type: Number,
			default: 0,
		},
	},

	data() {
		return {
			showActions: false,
			// Is tall enough for both actions and date upon hovering
			isTallEnough: false,
		}
	},

	computed: {
		hasActions() {
			return this.isReplyable && !this.isConversationReadOnly
		},

		isConversationReadOnly() {
			return this.conversation.readOnly === CONVERSATION.STATE.READ_ONLY
		},

		isSystemMessage() {
			return this.systemMessage !== ''
		},

		messageTime() {
			return moment(this.timestamp * 1000).format('LT')
		},

		messageDate() {
			return moment(this.timestamp * 1000).format('LL')
		},

		quote() {
			return this.parent && this.$store.getters.message(this.token, this.parent)
		},

		conversation() {
			return this.$store.getters.conversation(this.token)
		},

		showCommonReadIcon() {
			return this.conversation.lastCommonReadMessage >= this.id
				&& this.showSentIcon
		},

		showSentIcon() {
			return !this.isSystemMessage
				&& !this.isTemporary
				&& this.actorType === this.participant.actorType
				&& this.actorId === this.participant.actorId
		},

		messagesList() {
			return this.$store.getters.messagesList(this.token)
		},

		isLastCallStartedMessage() {
			const messages = this.messagesList
			const lastCallStartedMessage = messages.reverse().find((message) => message.systemMessage === 'call_started')
			return lastCallStartedMessage ? (this.id === lastCallStartedMessage.id) : false
		},

		showJoinCallButton() {
			return this.systemMessage === 'call_started'
				&& this.conversation.hasCall
				&& this.participant.inCall === PARTICIPANT.CALL_FLAG.DISCONNECTED
				&& this.isLastCallStartedMessage
		},

		isSingleEmoji() {
			const regex = emojiRegex()
			let match
			let emojiStrings = ''
			let emojiCount = 0

			// eslint-disable-next-line no-cond-assign
			while (match = regex.exec(this.message)) {
				if (emojiCount > 2) {
					return false
				}

				emojiStrings += match[0]
				emojiCount++
			}

			return emojiStrings === this.message
		},

		richParameters() {
			const richParameters = {}
			Object.keys(this.messageParameters).forEach(function(p) {
				const type = this.messageParameters[p].type
				if (type === 'user' || type === 'call' || type === 'guest') {
					richParameters[p] = {
						component: Mention,
						props: this.messageParameters[p],
					}
				} else if (type === 'file') {
					richParameters[p] = {
						component: FilePreview,
						props: this.messageParameters[p],
					}
				} else if (type === 'deck-card') {
					richParameters[p] = {
						component: DeckCard,
						props: this.messageParameters[p],
					}
				} else {
					richParameters[p] = {
						component: DefaultParameter,
						props: this.messageParameters[p],
					}
				}
			}.bind(this))
			return richParameters
		},

		// Determines whether the date has to be displayed or not
		hasDate() {
			return this.isSystemMessage || (!this.isTemporary && !this.showActions) || this.isTallEnough
		},

		isTemporaryUpload() {
			return this.isTemporary && this.messageParameters.file
		},

		loadingIconTooltip() {
			return t('spreed', 'Sending message')
		},

		sentIconTooltip() {
			return t('spreed', 'Message sent')
		},

		commonReadIconTooltip() {
			return t('spreed', 'Message read by everyone who shares their reading status')
		},

	},

	watch: {
		showJoinCallButton() {
			EventBus.$emit('scrollChatToBottom')
		},
	},

	mounted() {
		if (this.$refs.messageMain.clientHeight > 56) {
			this.isTallEnough = true
		}

		// define a function so it can be triggered directly on the DOM element
		// which can be found with document.getElementById()
		this.$refs.message.highlightAnimation = () => {
			this.highlightAnimation()
		}

		this.$refs.message.addEventListener('animationend', this.highlightAnimationStop)
	},

	beforeDestroy() {
		this.$refs.message.removeEventListener('animationend', this.highlightAnimationStop)
	},

	methods: {
		highlightAnimation() {
			// trigger CSS highlight animation by setting a class
			this.$refs.message.classList.add('highlight-animation')
		},
		highlightAnimationStop() {
			// when the animation ended, remove the class so we can trigger it
			// again another time
			this.$refs.message.classList.remove('highlight-animation')
		},
		handleReply() {
			this.$store.dispatch('addMessageToBeReplied', {
				id: this.id,
				actorId: this.actorId,
				actorType: this.actorType,
				actorDisplayName: this.actorDisplayName,
				timestamp: this.timestamp,
				systemMessage: this.systemMessage,
				messageType: this.messageType,
				message: this.message,
				messageParameters: this.messageParameters,
				token: this.token,
			})
			EventBus.$emit('focusChatInput')
		},
		handleDelete() {
			this.$store.dispatch('deleteMessage', this.message)
		},
	},
}
</script>

<style lang="scss" scoped>
@import '../../../../assets/variables';

.message {
	padding: 4px;
	font-size: $chat-font-size;
	line-height: $chat-line-height;
	&__author {
		color: var(--color-text-maxcontrast);
	}
	&__main {
		display: flex;
		justify-content: space-between;
		min-width: 100%;
		&__text {
			flex: 0 1 600px;
			color: var(--color-text-light);
			.single-emoji {
				font-size: 250%;
				line-height: 100%;
			}

			&.call-started {
				background-color: var(--color-primary-light);
				padding: 10px;
				border-radius: var(--border-radius-large);
				text-align: center;
			}

			&.system-message {
				color: var(--color-text-maxcontrast);
				text-align: center;
				padding: 0 20px;
				width: 100%;
			}

			::v-deep .rich-text--wrapper {
				white-space: pre-wrap;
				word-break: break-word;
			}

			&--quote {
				border-left: 4px solid var(--color-primary);
				padding: 4px 0 0 8px;
			}
		}
		&__right {
			justify-self: flex-start;
			justify-content: flex-end;
			position: relative;
			user-select: none;
			display: flex;
			color: var(--color-text-maxcontrast);
			font-size: $chat-font-size;
			flex: 1 0 auto;
			padding: 0 8px 0 8px;
			&__actions.action-item {
				position: absolute;
				top: -8px;
				right: 50px;
				&.tall {
					top: unset;
					right: 8px;
					bottom: -8px;
				}
			}
			& h6 {
				margin-left: auto;
			}
		}
	}
}

.date {
	margin-right: $clickable-area;
	&--self {
		margin-right: 0;
	}
}

// Increase the padding for regular messages to improve readability and
// allow some space for the reply button
.message:not(.system) {
	padding: 12px 4px 12px 8px;
	margin: -6px 0;
}

.hover, .highlight-animation {
	border-radius: 8px;
}

.hover {
	background-color: var(--color-background-hover);
}

.highlight-animation {
	animation: highlight-animation 5s 1;
}

@keyframes highlight-animation {
	0% { background-color: var(--color-background-hover); }
	50% { background-color: var(--color-background-hover); }
	100% { background-color: rgba(var(--color-background-hover), 0); }
}

.message-status {
	margin: -8px 0;
	width: $clickable-area;
	height: $clickable-area;
	display: flex;
	justify-content: center;
	align-items: center;
}
</style>

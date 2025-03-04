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
	<div
		class="wrapper"
		:class="{'wrapper--chatScrolledToBottom': isChatScrolledToBottom}">
		<!--native file picker, hidden -->
		<input id="file-upload"
			ref="fileUploadInput"
			multiple
			type="file"
			tabindex="-1"
			aria-hidden="true"
			class="hidden-visually"
			@change="handleFileInput">
		<div
			class="new-message">
			<form
				class="new-message-form"
				@submit.prevent>
				<div
					class="new-message-form__button">
					<Actions
						default-icon="icon-clip-add-file"
						class="new-message-form__button"
						:aria-label="t('spreed', 'Share files to the conversation')"
						:aria-haspopup="true">
						<ActionButton
							v-if="canShareAndUploadFiles"
							:close-after-click="true"
							icon="icon-upload"
							@click.prevent="clickImportInput">
							{{ t('spreed', 'Upload new files') }}
						</ActionButton>
						<ActionButton
							v-if="canShareAndUploadFiles"
							:close-after-click="true"
							icon="icon-folder"
							@click.prevent="handleFileShare">
							{{ t('spreed', 'Share from Files') }}
						</ActionButton>
					</Actions>
				</div>
				<div
					v-if="!isReadOnly"
					class="new-message-form__button">
					<EmojiPicker @select="addEmoji">
						<button
							type="button"
							class="new-message-form__icon new-message-form__button"
							:aria-label="t('spreed', 'Add emoji')"
							:aria-haspopup="true">
							<EmoticonOutline
								:size="20"
								decorative
								title="" />
						</button>
					</EmojiPicker>
				</div>
				<div class="new-message-form__input">
					<div v-if="messageToBeReplied" class="new-message-form__quote">
						<Quote
							:is-new-message-form-quote="true"
							:parent-id="messageToBeReplied.id"
							v-bind="messageToBeReplied" />
					</div>

					<AdvancedInput
						ref="advancedInput"
						v-model="text"
						:token="token"
						:active-input="!isReadOnly"
						:placeholder-text="placeholderText"
						:aria-label="placeholderText"
						@update:contentEditable="contentEditableToParsed"
						@submit="handleSubmit"
						@files-pasted="handlePastedFiles" />
				</div>
				<button
					:disabled="isReadOnly"
					type="submit"
					:aria-label="t('spreed', 'Send message')"
					class="new-message-form__button submit"
					@click.prevent="handleSubmit">
					<Send
						:size="20"
						decorative />
				</button>
			</form>
		</div>
	</div>
</template>

<script>
import AdvancedInput from './AdvancedInput/AdvancedInput'
import { getFilePickerBuilder, showError } from '@nextcloud/dialogs'
import { postNewMessage } from '../../services/messagesService'
import Quote from '../Quote'
import Actions from '@nextcloud/vue/dist/Components/Actions'
import ActionButton from '@nextcloud/vue/dist/Components/ActionButton'
import EmojiPicker from '@nextcloud/vue/dist/Components/EmojiPicker'
import { EventBus } from '../../services/EventBus'
import { shareFile } from '../../services/filesSharingServices'
import { processFiles } from '../../utils/fileUpload'
import { CONVERSATION } from '../../constants'
import createTemporaryMessage from '../../utils/temporaryMessage'
import EmoticonOutline from 'vue-material-design-icons/EmoticonOutline'
import Send from 'vue-material-design-icons/Send'

const picker = getFilePickerBuilder(t('spreed', 'File to share'))
	.setMultiSelect(false)
	.setModal(true)
	.setType(1)
	.allowDirectories()
	.build()

export default {
	name: 'NewMessageForm',
	components: {
		AdvancedInput,
		Quote,
		Actions,
		ActionButton,
		EmojiPicker,
		EmoticonOutline,
		Send,
	},

	props: {
		isChatScrolledToBottom: {
			type: Boolean,
			required: true,
		},
	},
	data: function() {
		return {
			text: '',
			parsedText: '',
			conversationIsFirstInList: false,
		}
	},
	computed: {
		/**
		 * The current conversation token
		 *
		 * @returns {String}
		 */
		token() {
			return this.$store.getters.getToken()
		},

		conversation() {
			return this.$store.getters.conversation(this.token) || {
				readOnly: CONVERSATION.STATE.READ_WRITE,
			}
		},

		isReadOnly() {
			return this.conversation.readOnly === CONVERSATION.STATE.READ_ONLY
		},

		placeholderText() {
			return this.isReadOnly
				? t('spreed', 'This conversation has been locked')
				: t('spreed', 'Write message, @ to mention someone …')
		},

		messageToBeReplied() {
			return this.$store.getters.getMessageToBeReplied(this.token)
		},

		currentUserIsGuest() {
			return this.$store.getters.getUserId() === null
		},

		canShareAndUploadFiles() {
			return !this.currentUserIsGuest && !this.isReadOnly
		},

		attachmentFolder() {
			return this.$store.getters.getAttachmentFolder()
		},
	},

	mounted() {
		EventBus.$on('uploadStart', this.handleUploadStart)
	},

	beforeDestroy() {
		EventBus.$off('uploadStart', this.handleUploadStart)
	},

	methods: {
		handleUploadStart() {
			// refocus on upload start as the user might want to type again
			// while the upload is running
			this.$refs.advancedInput.focusInput()
		},

		contentEditableToParsed(contentEditable) {
			const mentions = contentEditable.querySelectorAll('span[data-at-embedded]')
			mentions.forEach(mention => {
				// FIXME Adding a space after the mention should be improved to
				// do it or not based on the next element instead of always
				// adding it.
				mention.replaceWith('@' + mention.firstElementChild.attributes['data-mention-id'].value + ' ')
			})

			this.parsedText = this.rawToParsed(contentEditable.innerHTML)
		},
		/**
		 * Returns a parsed version of the given raw text of the content
		 * editable div.
		 *
		 * The given raw text contains a plain text representation of HTML
		 * content (like "first&nbsp;line<br>second&nbsp;line"). The returned
		 * parsed text replaces the (known) HTML content with the format
		 * expected by the server (like "first line\nsecond line").
		 *
		 * The parsed text is also trimmed.
		 *
		 * @param {String} text the raw text
		 * @returns {String} the parsed text
		 */
		rawToParsed(text) {
			text = text.replace(/<br>/g, '\n')
			text = text.replace(/&nbsp;/g, ' ')

			// Since we used innerHTML to get the content of the div.contenteditable
			// it is escaped. With this little trick from https://stackoverflow.com/a/7394787
			// We unescape the code again, so if you write `<strong>` we can display
			// it again instead of `&lt;strong&gt;`
			const temp = document.createElement('textarea')
			temp.innerHTML = text
			text = temp.value

			// Although the text is fully trimmed, at the very least the last
			// "\n" occurrence should be always removed, as browsers add a
			// "<br>" element as soon as some rich text is written in a content
			// editable div (for example, if a new line is added the div content
			// will be "<br><br>").
			return text.trim()
		},

		/**
		 * Sends the new message
		 */
		async handleSubmit() {
			if (this.parsedText !== '') {
				const oldMessage = this.parsedText
				const temporaryMessage = createTemporaryMessage(this.parsedText, this.token)
				this.$store.dispatch('addTemporaryMessage', temporaryMessage)
				this.text = ''
				this.parsedText = ''
				// Scrolls the message list to the last added message
				EventBus.$emit('smoothScrollChatToBottom')
				// Also remove the message to be replied for this conversation
				this.$store.dispatch('removeMessageToBeReplied', this.token)
				try {
					// Posts the message to the server
					const response = await postNewMessage(temporaryMessage)
					// If successful, deletes the temporary message from the store
					this.$store.dispatch('deleteMessage', temporaryMessage)
					// And adds the complete version of the message received
					// by the server
					this.$store.dispatch('processMessage', response.data.ocs.data)
				} catch (error) {
					let statusCode = null
					console.debug(`error while submitting message ${error}`, error)
					if (error.isAxiosError) {
						statusCode = error.response.status
					}
					// 403 when room is read-only, 412 when switched to lobby mode
					if (statusCode === 403 || statusCode === 412) {
						showError(t('spreed', 'No permission to post messages in this conversation'))
					} else {
						showError(t('spreed', 'Could not post message: {errorMessage}', { errorMessage: error.message || error }))
					}

					// restore message to allow re-sending
					this.$store.dispatch('deleteMessage', temporaryMessage)
					this.text = oldMessage
					this.parsedText = oldMessage
				}
			}
		},

		async handleFileShare() {
			picker.pick()
				.then(async(path) => {
					console.debug(`path ${path} selected for sharing`)
					if (!path.startsWith('/')) {
						throw new Error(t('files', 'Invalid path selected'))
					}
					shareFile(path, this.token)
					this.$refs.advancedInput.focusInput()
				})
		},

		/**
		 * Clicks the hidden file input when clicking the correspondent ActionButton,
		 * thus opening the file-picker
		 */
		clickImportInput() {
			this.$refs.fileUploadInput.click()
		},

		handleFileInput(event) {
			const files = Object.values(event.target.files)

			this.handleFiles(files)

			// Clear input to ensure that the change event will be emitted if
			// the same file is picked again.
			event.target.value = ''
		},

		/**
		 * Handles files pasting event
		 *
		 * @param {File[] | FileList} files pasted files list
		 */
		async handlePastedFiles(files) {
			this.handleFiles(files, true)
		},

		/**
		 * Handles file upload
		 *
		 * @param {File[] | FileList} files pasted files list
		 * @param {bool} rename whether to rename the files
		 */
		async handleFiles(files, rename = false) {
			// Create a unique id for the upload operation
			const uploadId = new Date().getTime()
			// Uploads and shares the files
			await processFiles(files, this.token, uploadId, rename)
		},

		/**
		 * Add selected emoji to text input area
		 *
		 * The emoji will be added at the current caret position, and any text
		 * currently selected will be replaced by the emoji. If the input area
		 * does not have the focus there will be no caret or selection; in that
		 * case the emoji will be added at the end.
		 *
		 * @param {Emoji} emoji Emoji object
		 */
		addEmoji(emoji) {
			const selection = document.getSelection()

			const contentEditable = this.$refs.advancedInput.$refs.contentEditable

			// There is no select, or current selection does not start in the
			// content editable element, so just append the emoji at the end.
			if (!contentEditable.isSameNode(selection.anchorNode) && !contentEditable.contains(selection.anchorNode)) {
				// Browsers add a "<br>" element as soon as some rich text is
				// written in a content editable div (for example, if a new line
				// is added the div content will be "<br><br>"), so the emoji
				// has to be added before the last "<br>" (if any).
				if (this.text.endsWith('<br>')) {
					this.text = this.text.substr(0, this.text.lastIndexOf('<br>')) + emoji + '<br>'
				} else {
					this.text += emoji
				}

				return
			}

			// Although due to legacy reasons the API allows several ranges the
			// specification requires the selection to always have a single
			// range.
			// https://developer.mozilla.org/en-US/docs/Web/API/Selection#Multiple_ranges_in_a_selection
			const range = selection.getRangeAt(0)

			// Deleting the contents also collapses the range to the start.
			range.deleteContents()

			const emojiTextNode = document.createTextNode(emoji)
			range.insertNode(emojiTextNode)

			this.text = contentEditable.innerHTML

			range.setStartAfter(emojiTextNode)
		},
	},
}
</script>

<style lang="scss" scoped>
@import '../../assets/variables';

.wrapper {
	position: sticky;
	bottom: 0;
	display: flex;
	justify-content: center;
	padding: 12px 0;
	border-top: 1px solid var(--color-border);
	&--chatScrolledToBottom {
		border-top: none;
	}
}

.new-message {
	max-width: $messages-list-max-width + 145px;
	flex: 1 1 100%;
	&-form {
		display: flex;
		align-items: flex-end;
		&__input {
			flex-grow: 1;
			max-height: $message-form-max-height;
			overflow-y: auto;
			overflow-x: hidden;
			max-width: 638px;
		}
		&__button {
			width: 44px;
			height: 44px;
			background-color: transparent;
			border: none;
			margin: 0 4px;
			color: var(--color-main-text);
			opacity: .9;
			display: flex;
			justify-content: center;
			align-items: center;
		}
		&__quote {
			margin: 0 16px 12px 24px;
			background-color: var(--color-background-hover);
			padding: 8px;
			border-radius: var(--border-radius-large);
		}

		// put a grey round background when popover is opened
		// or hover-focused
		&__icon:hover,
		&__icon:focus,
		&__icon:active {
			opacity: $opacity_full;
			// good looking on dark AND white bg
			background-color: $icon-focus-bg;
		}

	}
}

</style>

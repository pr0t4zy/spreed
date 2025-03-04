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
	<ul class="conversations">
		<Conversation
			v-for="item of conversationsList"
			:key="item.id"
			:item="item"
			@click.native="handleConversationClick(item)" />
		<template
			v-if="!initialisedConversations">
			<LoadingPlaceholder
				type="conversations" />
		</template>
		<Hint v-else-if="searchText && !conversationsList.length"
			:hint="t('spreed', 'No matches')" />
	</ul>
</template>

<script>
import Conversation from './Conversation'
import Hint from '../../Hint'
import LoadingPlaceholder from '../../LoadingPlaceholder'
import { joinConversation, leaveConversation } from '../../../services/participantsService'
import { EventBus } from '../../../services/EventBus'

export default {
	name: 'ConversationsList',
	components: {
		Conversation,
		Hint,
		LoadingPlaceholder,
	},
	props: {
		searchText: {
			type: String,
			default: '',
		},

		conversationsList: {
			type: Array,
			required: true,
		},

		initialisedConversations: {
			type: Boolean,
			default: true,
		},
	},

	data() {
		return {
			isFetchingConversations: false,
		}
	},

	mounted() {
		EventBus.$on('routeChange', this.onRouteChange)
		EventBus.$once('joinedConversation', ({ token }) => {
			this.scrollToConversation(token)
		})
	},

	beforeDestroy() {
		EventBus.$off('routeChange', this.onRouteChange)
	},

	methods: {
		scrollToConversation(token) {
			// FIXME: not sure why we can't scroll earlier even when the element exists already
			// when too early, Firefox only scrolls a few pixels towards the element but
			// not enough to make it visible
			setTimeout(() => {
				const conversation = document.getElementById(`conversation_${token}`)
				if (!conversation) {
					return
				}
				this.$nextTick(() => {
					conversation.scrollIntoView({
						behavior: 'smooth',
						block: 'start',
						inline: 'nearest',
					})
				})
			}, 500)
		},
		onRouteChange({ from, to }) {
			if (from.name === 'conversation'
				&& to.name === 'conversation'
				&& from.params.token === to.params.token) {
				// this is triggered when the hash in the URL changes
				return
			}
			if (from.name === 'conversation') {
				leaveConversation(from.params.token)
			}
			if (to.name === 'conversation') {
				joinConversation(to.params.token)
				this.$store.dispatch('markConversationRead', to.params.token)
			}
		},

		// Emit the click event so the search text in the leftsidebar can be reset.
		handleConversationClick(item) {
			this.$emit('click-search-result', item.token)
		},
	},
}
</script>

<style lang="scss" scoped>
// Override vue overflow rules for <ul> elements within app-navigation
.conversations {
	overflow: visible !important;
}
</style>

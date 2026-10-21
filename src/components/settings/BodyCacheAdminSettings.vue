<!--
  - SPDX-FileCopyrightText: 2026 YouCha
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<form class="body-cache-admin" @submit.prevent="onSubmit">
		<label for="mail-body-cache-max-days">{{ t('mail', 'Maximum days per user (-1 unlimited, 0 turns prefetching off)') }}</label>
		<input
			id="mail-body-cache-max-days"
			v-model.number="maxDays"
			:disabled="loading"
			type="number"
			min="-1"
			required>
		<label for="mail-body-cache-max-mb">{{ t('mail', 'Maximum storage per user in MB (-1 unlimited)') }}</label>
		<input
			id="mail-body-cache-max-mb"
			v-model.number="maxMb"
			:disabled="loading"
			type="number"
			min="-1"
			required>
		<button type="submit" :disabled="loading" class="primary">
			{{ t('mail', 'Save') }}
		</button>
		<p v-if="usage" class="body-cache-admin__usage">
			{{ t('mail', 'Stored now: {size} in {count} messages for all users', { size: formatBytes(usage.bytes), count: usage.count }) }}
		</p>
	</form>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import logger from '../../logger.js'
import { formatBytes, getAdminBodyCache, setAdminBodyCache } from '../../service/BodyCacheService.js'

export default {
	name: 'BodyCacheAdminSettings',

	data() {
		return {
			loading: true,
			maxDays: 0,
			maxMb: 200,
			usage: null,
		}
	},

	async mounted() {
		try {
			this.apply(await getAdminBodyCache())
		} catch (error) {
			logger.error('Could not load the message prefetch settings', { error })
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,
		formatBytes,

		apply(state) {
			this.maxDays = state.maxDays
			this.maxMb = state.maxMb
			this.usage = state.usage
		},

		async onSubmit() {
			this.loading = true
			try {
				this.apply(await setAdminBodyCache(this.maxDays, this.maxMb))
				showSuccess(t('mail', 'Message prefetch settings saved'))
			} catch (error) {
				logger.error('Could not save the message prefetch settings', { error })
				showError(t('mail', 'Could not save the message prefetch settings'))
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.body-cache-admin {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 4px;

	input {
		width: 160px;
	}

	&__usage {
		margin-top: 8px;
		color: var(--color-text-maxcontrast);
	}
}
</style>

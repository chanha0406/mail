<!--
  - SPDX-FileCopyrightText: 2026 YouCha
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="body-cache">
		<p v-if="!loading && maxDays === 0" class="body-cache__hint">
			{{ t('mail', 'The administrator has turned message prefetching off.') }}
		</p>
		<form v-else class="body-cache__form" @submit.prevent="onSubmit">
			<p class="body-cache__hint">
				{{ t('mail', 'Bodies of recent messages are fetched in the background so they open without waiting for the mail server. Messages deleted on the server are removed here too.') }}
			</p>
			<label for="mail-body-cache-days">{{ t('mail', 'Days to keep (-1 unlimited, 0 turns it off). Limit: {max}', { max: limitText(maxDays) }) }}</label>
			<input
				id="mail-body-cache-days"
				v-model.number="days"
				:disabled="loading"
				type="number"
				min="-1"
				:max="maxDays === -1 ? null : maxDays"
				required>
			<label for="mail-body-cache-mb">{{ t('mail', 'Storage limit in MB (-1 unlimited). Limit: {max}', { max: limitText(maxMb) }) }}</label>
			<input
				id="mail-body-cache-mb"
				v-model.number="mb"
				:disabled="loading"
				type="number"
				min="-1"
				:max="maxMb === -1 ? null : maxMb"
				required>
			<button type="submit" :disabled="loading" class="primary">
				{{ t('mail', 'Save') }}
			</button>
			<p v-if="usage" class="body-cache__usage">
				{{ t('mail', 'Stored now: {size} in {count} messages', { size: formatBytes(usage.bytes), count: usage.count }) }}
			</p>
			<p v-if="pinned" class="body-cache__usage">
				{{ t('mail', 'Archived, starred and important messages are kept regardless of days and storage limit: {size} in {count} messages', { size: formatBytes(pinned.bytes), count: pinned.count }) }}
			</p>
		</form>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import logger from '../logger.js'
import { formatBytes, getUserBodyCache, setUserBodyCache } from '../service/BodyCacheService.js'

export default {
	name: 'BodyCacheSettings',

	data() {
		return {
			loading: true,
			maxDays: 0,
			maxMb: 0,
			days: 0,
			mb: 0,
			usage: null,
			pinned: null,
		}
	},

	async mounted() {
		try {
			this.apply(await getUserBodyCache())
		} catch (error) {
			logger.error('Could not load the message prefetch settings', { error })
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,
		formatBytes,

		limitText(value) {
			return value === -1 ? t('mail', 'unlimited') : String(value)
		},

		apply(state) {
			this.maxDays = state.maxDays
			this.maxMb = state.maxMb
			this.days = state.days
			this.mb = state.mb
			this.usage = state.usage
			this.pinned = state.pinned ?? null
		},

		async onSubmit() {
			this.loading = true
			try {
				this.apply(await setUserBodyCache(this.days, this.mb))
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
.body-cache__form {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 4px;

	input {
		width: 160px;
	}
}

.body-cache__hint,
.body-cache__usage {
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
}
</style>

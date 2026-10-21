/**
 * SPDX-FileCopyrightText: 2026 YouCha
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Prefetched message bodies (self-hosted fork). */

export async function getUserBodyCache() {
	const response = await axios.get(generateUrl('/apps/mail/api/body-cache'))
	return response.data
}

export async function setUserBodyCache(days, mb) {
	const response = await axios.put(generateUrl('/apps/mail/api/body-cache'), { days, mb })
	return response.data
}

export async function getAdminBodyCache() {
	const response = await axios.get(generateUrl('/apps/mail/api/settings/body-cache'))
	return response.data
}

export async function setAdminBodyCache(maxDays, maxMb) {
	const response = await axios.put(generateUrl('/apps/mail/api/settings/body-cache'), { maxDays, maxMb })
	return response.data
}

export function formatBytes(bytes) {
	if (bytes < 1024 * 1024) {
		return `${(bytes / 1024).toFixed(0)} KB`
	}
	return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

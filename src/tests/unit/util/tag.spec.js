/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { tagTextColor } from '../../../util/tag.js'

describe('tag', () => {
	describe('tagTextColor', () => {
		it('keeps the color in the light theme', () => {
			const actual = tagTextColor('#0082c9', false)

			expect(actual).toBe('#0082c9')
		})

		it('raises the lightness in the dark theme', () => {
			const actual = tagTextColor('#0082c9', true)

			expect(actual).toBe('oklch(from #0082c9 max(l, 0.7) c h)')
		})
	})
})

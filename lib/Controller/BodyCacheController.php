<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 YouCha
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Controller;

use OCA\Mail\Service\BodyCacheService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Settings of the message body prefetch (self-hosted fork).
 */
#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class BodyCacheController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private BodyCacheService $cache,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function show(): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}
		return new JSONResponse($this->userState($this->userId));
	}

	#[NoAdminRequired]
	public function update(int $days, int $mb): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}
		$this->cache->setUserLimits($this->userId, $days, $mb);
		return new JSONResponse($this->userState($this->userId));
	}

	public function showAdmin(): JSONResponse {
		return new JSONResponse($this->adminState());
	}

	public function updateAdmin(int $maxDays, int $maxMb): JSONResponse {
		$this->cache->setAdminLimits($maxDays, $maxMb);
		if ($this->cache->adminLimits()['maxDays'] === 0) {
			$this->cache->deleteAll();
		} else {
			foreach ($this->cache->users() as $userId) {
				$this->cache->prune($userId);
			}
		}
		return new JSONResponse($this->adminState());
	}

	/**
	 * @return array<string, mixed>
	 */
	private function userState(string $userId): array {
		return $this->cache->adminLimits() + $this->cache->userLimits($userId) + [
			'usage' => $this->cache->usage($userId),
			'pinned' => $this->cache->pinnedUsage($userId),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function adminState(): array {
		return $this->cache->adminLimits() + ['usage' => $this->cache->usage()];
	}
}

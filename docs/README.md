# chanha0406/mail 포크 문서

공식 Nextcloud Mail(`v5.12.4`)을 자체 서버용으로 고친 포크다. 브랜치는 `naver-uidvalidity`이고, 설치본은 `/ssd1/nextcloud/custom_apps/mail`이다. 앱 ID는 `mail` 그대로이며 버전을 공식보다 높게(15.x) 두어 앱스토어가 덮지 않게 한다.

## 공식과 다른 점

| 커밋 | 내용 |
|---|---|
| `fix(imap): keep UIDVALIDITY 0 and reconnect for the sync token` | 네이버 받은편지함의 UIDVALIDITY 0을 동기화 토큰에 유지하고, 긴 동기화 뒤 끊긴 연결로 토큰을 다시 읽는다. Horde 수정은 `.patches/horde-imap-client-uidvalidity-zero.patch`(`patches.json`/`patches.lock.json`). |
| `fix(imap): retry body fetches without the MIME header` | 카카오가 단일 파트 메일의 `BODY[1.MIME]`에 응답하지 않아 본문이 비던 것을, MIME 헤더 없이 다시 가져온다. |
| `feat: prefetch message bodies and keep them on the server` | 최근 메일 본문을 `oc_mail_body_cache`에 미리 받아 두고 열 때 IMAP 없이 보여 준다. 관리자 한도와 사용자 설정이 있다(-1 무제한, 0일 끔). 같은 계정의 같은 Message-ID(Gmail 라벨로 INBOX·전체보관함·중요에 겹쳐 보이는 메일)는 한 번만 저장하고 어느 폴더에서 열어도 그 보관본을 쓴다. 전체보관함보다 실제 폴더 쪽 사본을 우선 저장한다. 고정 메일(계정의 보관 폴더(`archive_mailbox_id`, 이 서버에서는 네이버 Archive와 다른 계정의 "보관")에 있는 메일, 별표, 중요 표시)은 기간과 상관없이 받고 기간·용량 정리에서 지우지 않는다. |
| `chore(local): bump version …` | 설치용 버전. 항상 맨 위에 둔다. |

## 빌드와 설치

- PHP: `composer install --no-dev`. 호스트 PHP에 dom/intl이 없어서 `composer:2` 컨테이너로 돌린다(`--ignore-platform-reqs`). Horde 패치는 composer-patches가 적용한다.
- 프론트엔드: Node 24가 필요하다. `docker run --rm --user 1000:1000 -e HOME=/tmp -v "$PWD":/app -w /app node:24 sh -c 'npm ci && npm run build'`.
- 설치: 바뀐 `lib`·`appinfo`·`l10n`과 빌드한 `js/`를 설치본에 복사하고 `occ upgrade`. 설치본 파일은 `www-data` 소유라 `docker exec -i --user www-data nextcloud-cron tar xf - -C /var/www/html/custom_apps/mail`로 넣는다.

## 공식 새 버전으로 옮길 때

1. `upstream` 태그로 리베이스한다. 버전 커밋은 빼고 다시 얹는다.
2. `composer install`, `npm ci && npm run build`.
3. 설치 후 네이버 동기화(UID 유효값 0), 카카오 단일 파트 메일, 메일 미리 받기를 확인한다.

## 문서

- [recall-archive.md](recall-archive.md): 발신취소·외부 삭제된 메일을 되살리는 기능 설계(미구현).
- [pinned-restore.md](pinned-restore.md): 고정 메일(보관·별표·중요)이 Nextcloud 밖에서 지워져도 보관 폴더에 되살리는 기능 설계(미구현).

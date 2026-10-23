# Mail 개요

- 작성일: 2026-10-23
- 근거: `appinfo/info.xml` (`<name>Mail</name>`, `<id>mail</id>`, `<version>15.12.5</version>`). 브랜치 `naver-uidvalidity`. `git rev-parse --short HEAD`는 `e17019892`, `git rev-parse HEAD`는 `e1701989285b65500140b72550353be5f4c2761d`. 원격은 `git remote -v`.
- 상태: 초안

## 목적

`appinfo/info.xml`의 `<summary>`는 "A mail app for Nextcloud"이다. `<name>`은 Mail이고 앱 ID는 `mail`이다.

이 체크아웃의 origin은 `git@github.com:chanha0406/mail.git`이다. `docs/README.md`는 이 트리를 공식 Nextcloud Mail을 자체 서버용으로 고친 포크라고 적고, 브랜치 이름을 `naver-uidvalidity`라고 적는다. 그 내용과 현재 브랜치 이름은 같다.

`docs/README.md`는 설치본을 `/ssd1/nextcloud/custom_apps/mail`이라고 적는다. 이번 작업에서는 `/ssd1`을 읽지 않았다. 그 경로에 이 커밋이 설치되어 있는지는 미확인이다.

## 구성

루트가 제품 코드 저장소다. `.gitmodules`는 없다. 서브모듈은 없다.

`appinfo/info.xml`이 버전, 백그라운드 작업, PHP 요구(`8.1`–`8.5`), Nextcloud 요구(`32`–`35`)를 적는다. `<namespace>`는 `Mail`이다. 루트 `AGENTS.md`는 PHP를 `lib/`, 프런트엔드를 `src/`로 적는다. 이번 개요를 쓰면서 `src/`, `js/`, `vendor/`, `node_modules/`, `l10n/`은 열지 않았다.

`git remote -v`로 확인한 원격:

| 이름 | fetch/push URL |
|---|---|
| origin | `git@github.com:chanha0406/mail.git` |
| upstream | `git@github.com:nextcloud/mail.git` |
| backup | `ssh://git@git.chanha.kr:2224/chanha0406/mail.git` |

HEAD는 `e1701989285b65500140b72550353be5f4c2761d`이다.

`appinfo/info.xml`의 `<repository>`는 `https://github.com/nextcloud/mail.git`이다. 이 문자열은 앱 메타데이터이고, 위 origin URL과는 다르다.

## 주요 기능

`appinfo/info.xml`의 `<description>`에 적힌 범위는 IMAP 계정으로 메일을 보내고 받는 것이다. Contacts, Calendar, Files와 연결한다고 적혀 있고, Horde를 쓴다고 적혀 있다. 그 문장의 나머지(Mailvelope, 온프레미스 중요도 학습, 스레드 요약)는 이번 재검토에서 코드를 따라가지 않았다.

이 브랜치에서 upstream 릴리스 `8a00bf1d9` (`chore(release): v5.12.5`) 다음에 있는 로컬 커밋은 `git log`로 확인했다.

- `13ae199fb` `fix(imap): keep UIDVALIDITY 0 and reconnect for the sync token`
- `37fa870aa` `fix(imap): retry body fetches without the MIME header`
- `66f4f0e0f` `feat: prefetch message bodies and keep them on the server`
- `7bff1d621` `docs: describe the fork and the recalled-mail restore design`
- `5fd8e5534` `chore(local): bump version to 15.12.5 for self-hosted install`
- `e17019892` `fix: do not keep transient body-cache failures`

본문 미리 받기는 `BodyCacheJob`이 `oc_mail_body_cache`(`mail_body_cache`)에 gzip JSON을 넣고, 메일을 열 때 `BodyCacheService::find`가 그 행을 쓴다. 관리자 한도의 기본 `maxDays`는 `BodyCacheService::adminLimits`에서 `0`이고, `0`은 미리 받기를 끈다. `-1`은 `BodyCacheService::UNLIMITED`이다. 버전 번호 `15.12.5`는 `appinfo/info.xml`과 같다.

`docs/pinned-restore.md`와 `docs/recall-archive.md`는 각 문서가 미구현 설계라고 적는다. 이번 범위인 `BodyCacheJob`, `BodyCacheService`, `ImapMessageFetcher`, `MessageMapper` 안에는 그 되살리기 구현이 없다. 그 밖 파일에 일부가 있는지는 미확인이다.

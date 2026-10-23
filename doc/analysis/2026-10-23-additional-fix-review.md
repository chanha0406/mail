# 본문 캐시 추가 수정 재검토

- 작성일: 2026-10-23
- 근거: 브랜치 `naver-uidvalidity`. `git rev-parse --short HEAD`는 `e17019892` (`e1701989285b65500140b72550353be5f4c2761d`). 읽은 파일은 `lib/BackgroundJob/BodyCacheJob.php`, `lib/Service/BodyCacheService.php`, `lib/IMAP/ImapMessageFetcher.php`, `lib/IMAP/MessageMapper.php`, `tests/Unit/IMAP/ImapMessageFetcherTest.php`, `tests/Unit/IMAP/MessageMapperTest.php`. 열기가 깨지는지 보려면 `lib/Controller/MessagesController.php`의 `getBody`와 `getHtmlBody`, `lib/Service/MailManager.php`의 `getImapMessage`를 읽었다. 그 파일들은 고치지 않았다.
- 상태: 초안

## 판정

추가 수정 없음.

지금 코드가 사용자 메일이나 이미 저장된 본문 캐시를 지우거나, 메일 열기를 깨는 경우 중에서, 고칠 때 확인되지 않은 가정에 기대지 않고, 유지하기로 한 동작을 바꾸지 않는 항목은 없다.

## 이미 반영된 동작

`e17019892`와 현재 소스가 같다. 다시 고치지 않는다.

- IMAP 인증 실패와 연결 끊김은 `BodyCacheJob::stopsAccount`가 그 계정 실행만 끝내고 `markFailed`를 호출하지 않는다. 코드는 `Horde_Imap_Client_Exception`의 `LOGIN_AUTHENTICATIONFAILED`, `DISCONNECT`, `SERVER_READERROR`, `SERVER_WRITEERROR`이다. `getImapMessage`가 이 예외를 `ServiceException`으로 감싸면 한 단계 풀어서 본다.
- 캐시에 남기는 실패는 두 종류다. `BodyCacheService::put`이 `PUT_EXCLUDED`를 돌리는 경우(암호화, `json_encode` 실패, `gzencode` 실패)와, `messageIsGone`이 참인 경우(`ServiceException`의 이전 예외가 `DoesNotExistException`)이다. 그 외 예외는 실패 행 없이 다음 실행에서 다시 시도한다.
- `put`이 `PUT_FULL`이면 그 실행에서 그 계정의 나머지 저장을 멈추고, 그 메일의 실패 행은 만들지 않는다.
- MIME 헤더 없이 다시 `FETCH`하는 코드는 `ImapMessageFetcher::fetchBodyParts` 한 곳이다. `MessageMapper::getAttachment`와 `getBodyStructureData`는 그 메서드를 호출한다. `getAttachment`의 S/MIME 쿼리는 `withQuery`로 두 시도 모두에 붙는다.
- `appinfo/info.xml`의 `<version>`은 `15.12.5`이다.

## 유지

아래는 코드에 그대로 있고, 이번 판정에서 수정 대상으로 올리지 않는다.

- 캐시 행은 `mail_messages.id`에 묶인다. Message-ID는 `copyOf`가 다른 행을 찾을 때 DB 열을 쓰고, 본문 JSON 안에도 들어 있다. 보관된 쪽 `mail_messages` 행이 없어지면 `deleteOrphans`가 그 캐시 행을 지운다. 다른 폴더 사본은 자기 행이 없으면 본문도 없어진다. 휴지통 사본에 본문을 붙이면 받은편지함에서 지운 메일의 본문이 남으므로 그대로 둔다.
- `BodyCacheService::prune`는 사용자 기간이 `0`이면 고정 본문까지 그 사용자 행을 지운다. `BodyCacheJob::run`은 관리자 `maxDays`가 `0`이면 `deleteAll`을 호출한다. 되살리기용 원본 칸은 이 네 파일에 없다. 삭제 함수를 나누지 않는다.
- 고정 메일 조건은 `pinnedCondition`과 `candidates`의 `CASE`에 각각 있다. `CASE`로 합치지 않는다.
- UIDVALIDITY `0` 패치와 동기화 토큰 재연결은 이 검토에서 열지 않는다.
- `docs/pinned-restore.md`, `docs/recall-archive.md`는 설계 문서다. 미구현 설계 자체를 버그로 올리지 않는다.
- `data`가 `NULL`인 기존 행을 지우는 일회성 정리는 하지 않는다. 새 경로는 끊김·인증 실패로 그 행을 만들지 않는다. `clearFailed`는 한도 변경 때 `NULL` 행을 지우고 다시 시도하게 한다.

## 본 경로에서 확인한 열기

`MessagesController::getBody`는 `find`가 배열을 돌려주면 그 `full`로 응답하고, `null`이면 IMAP으로 연 뒤 `put`한다. `get`은 `data`가 `NULL`이거나 gzip/JSON이 아니면 `null`을 돌려서, 실패 행은 캐시 적중으로 치지 않는다. `getHtmlBody`도 캐시에 HTML이 없으면 IMAP으로 간다. PHP에서 `null`에 `['html']`를 하고 `??`로 받는 식은 예외가 아니라 fallback이다. 이 환경의 `php -r`로 그 식을 실행해 fallback이 되는 것을 확인했다.

첨부 URL은 `enrichAttachment`가 지금 연 메일의 DB id와 파트 id로 만든다. 캐시에 들어 있는 다른 사본의 UID로 첨부를 받지 않는다.

## 탈락한 후보

- `deleteDuplicates`는 `data`가 있는 행을 우선하지 않는다. 같은 Message-ID의 첫 행(`special_use`가 All Mail이 아닌 쪽, 그다음 `c.id`)을 남긴다. 실패 행이 앞에 있고 본문 행이 뒤에 있으면 본문 행이 지워진다. 한 번의 순차 실행에서는 그 쌍이 생기지 않는다. `put`은 `copyOf`가 어떤 행이든 찾으면 `PUT_SKIP`이고, `candidates`의 `NOT EXISTS`와 PHP 쪽 `copyKey` 중복 제거가 같은 Message-ID를 한 통만 고른다. `markFailed`는 `PUT_EXCLUDED` 앞에서 `put`이 이미 `PUT_SKIP`이면 호출되지 않는다. 없는 메일 분기는 `put`을 거치지 않으므로, 다른 사본의 본문이 이미 있고 그 메일이 같은 실행의 후보에 같이 있어야 실패 행이 추가된다. 그 후보는 `candidates`가 돌기 전에 다른 사본이 저장되어 있지 않을 때만 잡힌다. 그 겹침은 이 트리에서 재현하지 않았다. 가정으로 수정하지 않는다.
- 실패 행이 있으면 다른 폴더 사본은 `candidates`와 `put`에서 빠진다. `find`는 그 행의 `data`가 `NULL`이면 `null`을 돌려 IMAP으로 연다. 이미 저장된 본문을 지우지 않고, 열기 응답을 캐시의 빈 본문으로 바꾸지 않는다. 없는 메일을 다음 실행에서 다시 받지 않으려고 행을 남기는 현재 동작이다.
- 한 통의 압축 본문이 사용자 용량보다 크면 `makeRoom`이 거짓을 돌려 `PUT_FULL`이 되고, 그 계정 실행이 멈춘다. 다음 실행에서 그 메일이 후보의 앞에 있으면 다시 멈춘다. 용량이 차면 그 실행의 추가 저장을 멈추는 동작이다.
- `getAttachment`의 두 번째 `FETCH`에는 MIME 헤더 요청이 없다. 테스트 `testGetAttachmentRetriesWithoutMimeAndKeepsSmimeQuery`는 두 번째 결과에 MIME 헤더를 테스트가 직접 넣는다. 서버가 요청하지 않은 헤더를 비우는지는 `vendor`를 읽지 않아 확인하지 않았다. MIME 없는 재시도는 `fetchBodyParts` 한 곳이라는 현재 설계다.
- `getHtmlBody`가 캐시 HTML을 메모리 캐시에 없을 때 `find()['html']`로 읽는다. 위에서 확인한 대로 `??` fallback 뒤 IMAP으로 이어진다. 열기가 멈추지 않는다.
- 고정 메일 SQL이 두 번 있는 것, 기간 `0`이 고정 본문까지 지우는 것, Message-ID가 캐시 행 키가 아니라 본문이 한 행에만 있는 것, 테스트 파일이 `BodyCacheService`에 없는 것은 메일이나 캐시를 잃는 결함으로 보지 않는다. 스타일과 중복 추상화는 대상이 아니다.

## 하지 않은 일

앱 소스를 고치지 않았다. `js/`, `vendor/`, `node_modules/`, `l10n/`, `/ssd1`은 읽지 않았다. upstream 전체는 리뷰하지 않았다. commit은 하지 않았다.

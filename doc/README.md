이 파일이 문서 목록의 단일 출처다.

## 폴더 구성

| 종류 | 경로 | 파일 이름 |
|---|---|---|
| 분석·조사 | `doc/analysis/` | `YYYY-MM-DD-<주제>.md` |
| 계획 | `doc/plans/` | `YYYY-MM-DD-<주제>.md` |
| 설계 | `doc/design/` | `YYYY-MM-DD-<주제>.md` |
| 수정사항 | `doc/changes/` | `YYYY-MM-DD-<주제>.md` |
| 아키텍처 | `doc/architecture/` | `<주제>.md` (갱신형) |
| 백로그 | `doc/backlog/` | `YYYY-MM-DD-<주제>.md` |
| 미구현·보류 | `doc/unimplemented/` | `YYYY-MM-DD-<주제>.md` |
| 작업 로그 | `doc/worklog/` | `YYYY-MM-DD.md` |
| 상시 개요 | `doc/mail-overview.md` | 갱신형 |

하위 폴더는 그 폴더의 첫 문서를 넣을 때 만든다. 빈 폴더는 두지 않는다. 같은 주제의 문서가 있으면 새로 만들지 않고 그 파일을 고친다. 각 문서 상단에는 작성일, 근거, 상태를 적는다. 확인하지 못한 내용은 미확인이라고 적는다.

`docs/` (복수)는 이 표의 폴더가 아니다. 옮기거나 지우지 않는다.

## 문서 목록

| 문서 | 설명 |
|---|---|
| [mail-overview.md](mail-overview.md) | Mail의 목적, 구성, 주요 기능 |
| [worklog/2026-10-23.md](worklog/2026-10-23.md) | 2026-10-23 작업 로그 |
| [analysis/2026-10-23-additional-fix-review.md](analysis/2026-10-23-additional-fix-review.md) | 본문 캐시 경로에 추가 수정이 있는지 재검토 |

제목만 둔 구분:

- 계획
- 설계
- 수정사항
- 아키텍처
- 백로그
- 미구현·보류

이 목록을 만들기 전에 있던 파일:

- [developer.md](developer.md). Nextcloud Mail 개발자 문서.
- `docs/README.md`, `docs/pinned-restore.md`, `docs/recall-archive.md`. 포크 메모와 미구현 설계.

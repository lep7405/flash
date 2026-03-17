import { useEffect, useState } from 'react'
import './App.css'

const API_ROOT = (import.meta.env.VITE_API_URL || '/api').replace(/\/$/, '')

const emptyExample = {
  sentence: '',
  pinyin: '',
  translationVi: '',
}

const emptyDraft = {
  vocabulary: '',
  pinyin: '',
  examples: [{ ...emptyExample }],
  grouping: 'solo',
  existingGroupId: '',
}

const groupingOptions = [
  {
    value: 'solo',
    title: 'Flash lẻ',
    description: 'Tạo một flash độc lập, không gắn vào group nào.',
  },
  {
    value: 'new',
    title: 'Group mới',
    description: 'Backend tự sinh một group ID random rồi gắn flash vào đó.',
  },
  {
    value: 'existing',
    title: 'Group có sẵn',
    description: 'Nhập group ID để thêm flash tiếp theo vào cùng nhóm.',
  },
]

function flashToDraft(flash) {
  return {
    vocabulary: flash.vocabulary || '',
    pinyin: flash.pinyin || '',
    examples:
      Array.isArray(flash.examples) && flash.examples.length > 0
        ? flash.examples.map((example) => ({
            sentence: example.sentence || '',
            pinyin: example.pinyin || '',
            translationVi: example.translation_vi || '',
          }))
        : [{ ...emptyExample }],
    grouping: flash.group_id ? 'existing' : 'solo',
    existingGroupId: flash.group_id || '',
  }
}

function buildFlashPayload(draft) {
  const payload = {
    vocabulary: draft.vocabulary.trim(),
    pinyin: draft.pinyin.trim() || null,
    examples: draft.examples.map((example) => ({
      sentence: example.sentence.trim() || null,
      pinyin: example.pinyin.trim() || null,
      translation_vi: example.translationVi.trim() || null,
    })),
    group_mode: draft.grouping,
  }

  if (draft.grouping === 'existing') {
    payload.group_id = draft.existingGroupId.trim()
  }

  return payload
}

async function fetchRecentFlashes() {
  const response = await fetch(`${API_ROOT}/flashes?limit=24`, {
    headers: {
      Accept: 'application/json',
    },
  })

  if (!response.ok) {
    throw new Error('Không tải được danh sách flash.')
  }

  const payload = await response.json()
  return Array.isArray(payload.data) ? payload.data : []
}

function formatFlashTime(value) {
  if (!value) {
    return 'Vừa tạo'
  }

  return new Intl.DateTimeFormat('vi-VN', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(value))
}

function App() {
  const [draft, setDraft] = useState(emptyDraft)
  const [recentFlashes, setRecentFlashes] = useState([])
  const [activeGroupId, setActiveGroupId] = useState('')
  const [editingFlashId, setEditingFlashId] = useState(null)
  const [viewMode, setViewMode] = useState('builder')
  const [revealedFlashIds, setRevealedFlashIds] = useState([])
  const [notice, setNotice] = useState(null)
  const [isLoadingList, setIsLoadingList] = useState(true)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [deletingFlashId, setDeletingFlashId] = useState(null)
  const [isImporting, setIsImporting] = useState(false)
  const [copyState, setCopyState] = useState('idle')

  async function refreshRecentFlashes(showLoader = true) {
    if (showLoader) {
      setIsLoadingList(true)
    }

    try {
      setRecentFlashes(await fetchRecentFlashes())
    } catch (error) {
      setNotice((current) => {
        if (current?.type === 'success') {
          return current
        }

        return {
          type: 'error',
          text: error.message || 'Không kết nối được backend Laravel.',
        }
      })
    } finally {
      if (showLoader) {
        setIsLoadingList(false)
      }
    }
  }

  useEffect(() => {
    let isMounted = true

    async function bootstrapRecentFlashes() {
      setIsLoadingList(true)

      try {
        const flashes = await fetchRecentFlashes()

        if (!isMounted) {
          return
        }

        setRecentFlashes(flashes)
      } catch (error) {
        if (!isMounted) {
          return
        }

        setNotice({
          type: 'error',
          text: error.message || 'Không kết nối được backend Laravel.',
        })
      } finally {
        if (isMounted) {
          setIsLoadingList(false)
        }
      }
    }

    bootstrapRecentFlashes()

    return () => {
      isMounted = false
    }
  }, [])

  function updateDraft(field, value) {
    setDraft((current) => ({
      ...current,
      [field]: value,
    }))
  }

  function updateExample(index, field, value) {
    setDraft((current) => ({
      ...current,
      examples: current.examples.map((example, exampleIndex) =>
        exampleIndex === index
          ? {
              ...example,
              [field]: value,
            }
          : example,
      ),
    }))
  }

  function addExample() {
    setDraft((current) => ({
      ...current,
      examples: [...current.examples, { ...emptyExample }],
    }))
  }

  function removeExample(index) {
    setDraft((current) => {
      const nextExamples = current.examples.filter(
        (_, exampleIndex) => exampleIndex !== index,
      )

      return {
        ...current,
        examples: nextExamples.length > 0 ? nextExamples : [{ ...emptyExample }],
      }
    })
  }

  function applyExistingGroup(groupId) {
    setDraft((current) => ({
      ...current,
      grouping: 'existing',
      existingGroupId: groupId,
    }))
  }

  function resetComposer(nextGroupId = '') {
    setEditingFlashId(null)
    setDraft(
      nextGroupId
        ? {
            ...emptyDraft,
            grouping: 'existing',
            existingGroupId: nextGroupId,
          }
        : emptyDraft,
    )
  }

  function openBuilderView() {
    setViewMode('builder')
    window.scrollTo({
      top: 0,
      behavior: 'smooth',
    })
  }

  function openStudyView() {
    setViewMode('study')
    refreshRecentFlashes(false)
    window.scrollTo({
      top: 0,
      behavior: 'smooth',
    })
  }

  function startEditingFlash(flash) {
    setViewMode('builder')
    setEditingFlashId(flash.id)
    setDraft(flashToDraft(flash))
    setActiveGroupId(flash.group_id || '')
    setNotice({
      type: 'success',
      text: `Đang sửa flash "${flash.vocabulary}".`,
    })
    window.scrollTo({
      top: 0,
      behavior: 'smooth',
    })
  }

  function toggleFlashReveal(flashId) {
    setRevealedFlashIds((current) =>
      current.includes(flashId)
        ? current.filter((id) => id !== flashId)
        : [...current, flashId],
    )
  }

  const isEditing = editingFlashId !== null

  async function handleSubmit(event) {
    event.preventDefault()
    setNotice(null)

    if (draft.grouping === 'existing' && !draft.existingGroupId.trim()) {
      setNotice({
        type: 'error',
        text: 'Bạn cần nhập group ID trước khi thêm vào group có sẵn.',
      })
      return
    }

    setIsSubmitting(true)

    try {
      const payload = buildFlashPayload(draft)
      const requestPath = isEditing
        ? `${API_ROOT}/flashes/${editingFlashId}`
        : `${API_ROOT}/flashes`

      const response = await fetch(requestPath, {
        method: isEditing ? 'PUT' : 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload),
      })

      const payloadResponse = await response.json().catch(() => ({}))

      if (!response.ok) {
        const validationErrors = Object.values(payloadResponse.errors || {})
          .flat()
          .join(' ')

        throw new Error(validationErrors || payloadResponse.message || 'Không tạo được flash.')
      }

      const nextGroupId =
        payloadResponse.group?.id ||
        (draft.grouping === 'existing' ? draft.existingGroupId.trim() : '')

      setActiveGroupId(nextGroupId)
      setNotice({
        type: 'success',
        text: nextGroupId
          ? `${isEditing ? 'Đã cập nhật' : 'Đã lưu'} flash. Group hiện tại là ${nextGroupId}.`
          : isEditing
            ? 'Đã cập nhật flash thành công.'
            : 'Đã lưu flash lẻ thành công.',
      })

      resetComposer(nextGroupId)

      await refreshRecentFlashes(false)
    } catch (error) {
      setNotice({
        type: 'error',
        text: error.message || `Không ${isEditing ? 'cập nhật' : 'tạo'} được flash.`,
      })
    } finally {
      setIsSubmitting(false)
    }
  }

  async function copyGroupId() {
    if (!activeGroupId || !navigator.clipboard) {
      return
    }

    await navigator.clipboard.writeText(activeGroupId)
    setCopyState('done')

    window.setTimeout(() => {
      setCopyState('idle')
    }, 1800)
  }

  async function handleDeleteFlash(flash) {
    const isConfirmed = window.confirm(
      `Xóa flash "${flash.vocabulary}"? Hành động này không thể hoàn tác.`,
    )

    if (!isConfirmed) {
      return
    }

    setDeletingFlashId(flash.id)
    setNotice(null)

    try {
      const response = await fetch(`${API_ROOT}/flashes/${flash.id}`, {
        method: 'DELETE',
        headers: {
          Accept: 'application/json',
        },
      })

      const payloadResponse = await response.json().catch(() => ({}))

      if (!response.ok) {
        throw new Error(payloadResponse.message || 'Không xóa được flash.')
      }

      if (editingFlashId === flash.id) {
        resetComposer(activeGroupId)
      }

      setRecentFlashes((current) => current.filter((item) => item.id !== flash.id))
      setRevealedFlashIds((current) => current.filter((id) => id !== flash.id))
      setNotice({
        type: 'success',
        text: `Đã xóa flash "${flash.vocabulary}".`,
      })
    } catch (error) {
      setNotice({
        type: 'error',
        text: error.message || 'Không xóa được flash.',
      })
    } finally {
      setDeletingFlashId(null)
    }
  }

  async function handleImportFile(event) {
    const file = event.target.files?.[0]

    if (!file) {
      return
    }

    setIsImporting(true)
    setNotice(null)

    try {
      const formData = new FormData()
      formData.append('file', file)

      const response = await fetch(`${API_ROOT}/flashes/import`, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
        },
        body: formData,
      })

      const payloadResponse = await response.json().catch(() => ({}))

      if (!response.ok) {
        const conflicts = Array.isArray(payloadResponse.conflicts)
          ? payloadResponse.conflicts
          : []

        if (conflicts.length > 0) {
          const details = conflicts
            .map((conflict) => {
              const rows = Array.isArray(conflict.rows)
                ? conflict.rows.join(', ')
                : 'unknown'
              return `${conflict.vocabulary} (rows: ${rows})`
            })
            .join(' | ')

          throw new Error(`Import lỗi ở dòng: ${details}`)
        }

        throw new Error(payloadResponse.message || 'Import thất bại.')
      }

      setNotice({
        type: 'success',
        text: `Import thành công: tạo ${payloadResponse.created || 0} flash, cập nhật ${payloadResponse.merged || 0} flash, thêm ${payloadResponse.examples_created || 0} câu ví dụ, bỏ qua ${payloadResponse.skipped || 0} dòng trống.`,
      })
      await refreshRecentFlashes(false)
    } catch (error) {
      setNotice({
        type: 'error',
        text: error.message || 'Import thất bại.',
      })
    } finally {
      event.target.value = ''
      setIsImporting(false)
    }
  }

  return (
    <main className="page-shell">
      <header className="topbar panel">
        <div>
          <p className="eyebrow">FLASH STUDIO</p>
          <h2 className="topbar__title">
            {viewMode === 'builder'
              ? 'Tạo flash và quản lý dữ liệu'
              : 'Trang xem flash để học nhanh'}
          </h2>
        </div>

        <div className="topbar__actions">
          <button
            type="button"
            className={`nav-button ${viewMode === 'builder' ? 'nav-button--active' : ''}`}
            onClick={openBuilderView}
          >
            Tạo flash
          </button>
          <button
            type="button"
            className={`nav-button ${viewMode === 'study' ? 'nav-button--active' : ''}`}
            onClick={openStudyView}
          >
            Xem flash
          </button>
        </div>
      </header>

      {viewMode === 'builder' ? (
        <>
          <section className="hero-panel">
            <div className="hero-copy">
              <p className="eyebrow">FLASH STUDIO</p>
              <h1>Tạo flash card và gom chúng vào cùng một group random.</h1>
              <p className="hero-text">
                Form này nối trực tiếp vào backend Laravel. Khi bạn chọn
                <strong> Group mới</strong>, backend sẽ sinh ra một `group_id`
                random và flash tiếp theo có thể tiếp tục dùng lại ID đó.
              </p>
            </div>

            <div className="hero-stats">
              <div className="stat-card">
                <span className="stat-label">Flash gần đây</span>
                <strong>{recentFlashes.length}</strong>
              </div>
              <div className="stat-card">
                <span className="stat-label">Group đang nhớ</span>
                <strong>{activeGroupId || 'Chưa có'}</strong>
              </div>
              <div className="stat-card">
                <span className="stat-label">API</span>
                <strong>{API_ROOT}</strong>
              </div>
            </div>
          </section>

          <section className="workspace-grid">
            <form className="panel form-panel" onSubmit={handleSubmit}>
              <div className="section-heading">
                <div>
                  <p className="section-kicker">
                    {isEditing ? 'Chỉnh sửa flash' : 'Tạo flash mới'}
                  </p>
                  <h2>
                    {isEditing ? 'Cập nhật nội dung flash' : 'Điền nội dung flash'}
                  </h2>
                </div>
                <span className="section-chip">
                  {isSubmitting
                    ? isEditing
                      ? 'Đang cập nhật...'
                      : 'Đang lưu...'
                    : isEditing
                      ? 'Đang sửa'
                      : 'Sẵn sàng'}
                </span>
              </div>

              <label className="field">
                <span>Từ vựng</span>
                <input
                  type="text"
                  placeholder="Ví dụ: 你好"
                  value={draft.vocabulary}
                  onChange={(event) => updateDraft('vocabulary', event.target.value)}
                  required
                />
              </label>

              <label className="field">
                <span>Pinyin</span>
                <input
                  type="text"
                  placeholder="Ví dụ: nǐ hǎo"
                  value={draft.pinyin}
                  onChange={(event) => updateDraft('pinyin', event.target.value)}
                />
              </label>

              <div className="examples-block">
                <div className="examples-block__header">
                  <span>Câu ví dụ</span>
                  <button
                    type="button"
                    className="secondary-button examples-block__add"
                    onClick={addExample}
                  >
                    Thêm câu ví dụ
                  </button>
                </div>

                <div className="examples-list">
                  {draft.examples.map((example, index) => (
                    <div className="example-editor" key={`example-${index}`}>
                      <div className="example-editor__head">
                        <strong>Ví dụ {index + 1}</strong>
                        <button
                          type="button"
                          className="text-button"
                          onClick={() => removeExample(index)}
                        >
                          Xóa
                        </button>
                      </div>

                      <label className="field">
                        <span>Câu ví dụ</span>
                        <textarea
                          rows="3"
                          placeholder="Ví dụ: Nǐ hǎo, hěn gāoxìng rènshi nǐ."
                          value={example.sentence}
                          onChange={(event) =>
                            updateExample(index, 'sentence', event.target.value)
                          }
                        />
                      </label>

                      <div className="example-editor__grid">
                        <label className="field">
                          <span>Pinyin câu ví dụ</span>
                          <input
                            type="text"
                            placeholder="Ví dụ: Nǐ hǎo, hěn gāoxìng rènshi nǐ."
                            value={example.pinyin}
                            onChange={(event) =>
                              updateExample(index, 'pinyin', event.target.value)
                            }
                          />
                        </label>

                        <label className="field">
                          <span>Tiếng Việt</span>
                          <input
                            type="text"
                            placeholder="Ví dụ: Xin chào, rất vui được gặp bạn."
                            value={example.translationVi}
                            onChange={(event) =>
                              updateExample(index, 'translationVi', event.target.value)
                            }
                          />
                        </label>
                      </div>
                    </div>
                  ))}
                </div>
              </div>

              <div className="group-options">
                {groupingOptions.map((option) => {
                  const isActive = draft.grouping === option.value

                  return (
                    <label
                      className={`group-card ${isActive ? 'group-card--active' : ''}`}
                      key={option.value}
                    >
                      <input
                        type="radio"
                        name="grouping"
                        value={option.value}
                        checked={isActive}
                        onChange={() => {
                          if (option.value === 'existing' && activeGroupId) {
                            applyExistingGroup(activeGroupId)
                            return
                          }

                          updateDraft('grouping', option.value)
                        }}
                      />
                      <span className="group-card__title">{option.title}</span>
                      <span className="group-card__description">
                        {option.description}
                      </span>
                    </label>
                  )
                })}
              </div>

              {draft.grouping === 'existing' && (
                <label className="field">
                  <span>Group ID</span>
                  <input
                    type="text"
                    placeholder="Dán group ID vào đây"
                    value={draft.existingGroupId}
                    onChange={(event) =>
                      updateDraft('existingGroupId', event.target.value)
                    }
                    required
                  />
                </label>
              )}

              {notice && (
                <div
                  className={`notice notice--${notice.type}`}
                  role="status"
                  aria-live="polite"
                >
                  {notice.text}
                </div>
              )}

              <div className="form-actions">
                <button className="submit-button" type="submit" disabled={isSubmitting}>
                  {isSubmitting
                    ? isEditing
                      ? 'Đang cập nhật flash...'
                      : 'Đang tạo flash...'
                    : isEditing
                      ? 'Cập nhật flash'
                      : 'Lưu flash'}
                </button>

                {isEditing && (
                  <button
                    type="button"
                    className="secondary-button"
                    onClick={() => resetComposer(activeGroupId)}
                    disabled={isSubmitting}
                  >
                    Hủy sửa
                  </button>
                )}

                <button
                  type="button"
                  className="secondary-button"
                  onClick={openStudyView}
                >
                  Sang trang xem flash
                </button>
              </div>
            </form>

            <aside className="panel sidebar-panel">
              <div className="section-heading">
                <div>
                  <p className="section-kicker">Group hiện tại</p>
                  <h2>Làm việc theo nhóm flash</h2>
                </div>
              </div>

              <div className="group-highlight">
                <p className="group-highlight__label">Group ID đang lưu</p>
                <strong>{activeGroupId || 'Chưa có group nào được tạo'}</strong>
                <p className="group-highlight__text">
                  Chọn <strong>Group mới</strong> để backend tự sinh ID random. Sau
                  khi tạo xong, form sẽ tự chuyển sang chế độ thêm tiếp vào group đó.
                </p>

                <div className="group-actions">
                  <button
                    type="button"
                    className="secondary-button"
                    onClick={() => applyExistingGroup(activeGroupId)}
                    disabled={!activeGroupId}
                  >
                    Dùng group này
                  </button>
                  <button
                    type="button"
                    className="secondary-button"
                    onClick={copyGroupId}
                    disabled={!activeGroupId}
                  >
                    {copyState === 'done' ? 'Đã copy' : 'Copy group ID'}
                  </button>
                </div>
              </div>

              <div className="flow-card">
                <p className="flow-card__title">Flow đề xuất</p>
                <ol>
                  <li>Tạo flash đầu tiên với chế độ Group mới.</li>
                  <li>Hệ thống trả về một `group_id` random.</li>
                  <li>Tiếp tục tạo các flash sau với cùng `group_id` đó.</li>
                </ol>
              </div>

              <div className="flow-card">
                <p className="flow-card__title">Import Excel (bulk)</p>
                <p className="import-hint">
                  Header bắt buộc: `vocabulary,pinyin,group_id,example_sentence,example_pinyin,example_translation_vi`
                </p>
                <label className="import-button">
                  <input
                    type="file"
                    accept=".xlsx,.csv"
                    onChange={handleImportFile}
                    disabled={isImporting}
                  />
                  {isImporting ? 'Đang import...' : 'Chọn file .xlsx hoặc .csv'}
                </label>
              </div>
            </aside>
          </section>

          <section className="panel recent-panel">
            <div className="section-heading">
              <div>
                <p className="section-kicker">Kết quả</p>
                <h2>Flash vừa tạo và flash gần đây</h2>
              </div>
              <button
                type="button"
                className="secondary-button"
                onClick={() => refreshRecentFlashes()}
                disabled={isLoadingList}
              >
                {isLoadingList ? 'Đang tải...' : 'Tải lại'}
              </button>
            </div>

            {isLoadingList ? (
              <p className="empty-state">Đang tải danh sách flash...</p>
            ) : recentFlashes.length === 0 ? (
              <p className="empty-state">
                Chưa có flash nào. Tạo flash đầu tiên ở form phía trên.
              </p>
            ) : (
              <div className="flash-grid">
                {recentFlashes.map((flash) => (
                  <article className="flash-card" key={flash.id}>
                    <div className="flash-card__head">
                      <span className="flash-card__badge">
                        {flash.group_id ? 'Có group' : 'Flash lẻ'}
                      </span>
                      <span className="flash-card__time">
                        {formatFlashTime(flash.created_at)}
                      </span>
                    </div>

                    <h3>{flash.vocabulary}</h3>
                    <p className="flash-card__pinyin">{flash.pinyin || 'Chưa có pinyin'}</p>
                    {Array.isArray(flash.examples) && flash.examples.length > 0 ? (
                      <div className="flash-card__examples">
                        {flash.examples.map((example) => (
                          <div className="flash-card__example" key={example.id}>
                            <p className="flash-card__example-sentence">{example.sentence}</p>
                            <p className="flash-card__example-pinyin">
                              {example.pinyin || 'Chưa có pinyin cho câu ví dụ'}
                            </p>
                            <p className="flash-card__example-translation">
                              {example.translation_vi || 'Chưa có nghĩa tiếng Việt'}
                            </p>
                          </div>
                        ))}
                      </div>
                    ) : (
                      <p className="flash-card__example-empty">Chưa có câu ví dụ.</p>
                    )}
                    <p className="flash-card__group">
                      group_id: {flash.group_id || 'null'}
                    </p>
                    <div className="flash-card__actions">
                      <button
                        type="button"
                        className="secondary-button flash-card__button"
                        onClick={() => startEditingFlash(flash)}
                        disabled={deletingFlashId === flash.id}
                      >
                        Edit
                      </button>
                      <button
                        type="button"
                        className="danger-button flash-card__button"
                        onClick={() => handleDeleteFlash(flash)}
                        disabled={deletingFlashId === flash.id}
                      >
                        {deletingFlashId === flash.id ? 'Đang xóa...' : 'Xóa'}
                      </button>
                    </div>
                  </article>
                ))}
              </div>
            )}
          </section>
        </>
      ) : (
        <section className="panel study-panel">
          <div className="section-heading">
            <div>
              <p className="section-kicker">Trang xem flash</p>
              <h2>Chỉ hiện tiếng Trung trước, bấm vào thẻ để mở pinyin và tiếng Việt</h2>
            </div>

            <div className="study-panel__actions">
              <button
                type="button"
                className="secondary-button"
                onClick={() => refreshRecentFlashes()}
                disabled={isLoadingList}
              >
                {isLoadingList ? 'Đang tải...' : 'Tải lại'}
              </button>
              <button
                type="button"
                className="secondary-button"
                onClick={openBuilderView}
              >
                Quay lại tạo flash
              </button>
            </div>
          </div>

          {isLoadingList ? (
            <p className="empty-state">Đang tải danh sách flash...</p>
          ) : recentFlashes.length === 0 ? (
            <p className="empty-state">
              Chưa có flash nào để học. Quay lại trang tạo flash trước.
            </p>
          ) : (
            <div className="study-grid">
              {recentFlashes.map((flash) => {
                const isRevealed = revealedFlashIds.includes(flash.id)

                return (
                  <button
                    type="button"
                    className={`study-card ${isRevealed ? 'study-card--revealed' : ''}`}
                    key={flash.id}
                    onClick={() => toggleFlashReveal(flash.id)}
                  >
                    <div className="study-card__head">
                      <span className="flash-card__badge">
                        {isRevealed ? 'Đang mở nghĩa' : 'Chỉ hiện tiếng Trung'}
                      </span>
                      <span className="flash-card__time">
                        {formatFlashTime(flash.created_at)}
                      </span>
                    </div>

                    <h3>{flash.vocabulary}</h3>
                    {isRevealed && (
                      <p className="study-card__meta">
                        {flash.pinyin || 'Chưa có pinyin cho từ vựng'}
                      </p>
                    )}

                    <div className="study-card__examples">
                      {Array.isArray(flash.examples) && flash.examples.length > 0 ? (
                        flash.examples.map((example) => (
                          <div className="study-card__example" key={example.id}>
                            <p className="study-card__sentence">{example.sentence}</p>
                            {isRevealed && (
                              <>
                                <p className="study-card__meta">
                                  {example.pinyin || 'Chưa có pinyin cho câu ví dụ'}
                                </p>
                                <p className="study-card__translation">
                                  {example.translation_vi || 'Chưa có nghĩa tiếng Việt'}
                                </p>
                              </>
                            )}
                          </div>
                        ))
                      ) : (
                        <p className="study-card__translation">Chưa có câu ví dụ.</p>
                      )}
                    </div>

                    <p className="study-card__hint">
                      {isRevealed
                        ? 'Bấm thêm lần nữa để ẩn pinyin và tiếng Việt'
                        : 'Bấm vào thẻ để hiện pinyin và tiếng Việt'}
                    </p>
                  </button>
                )
              })}
            </div>
          )}
        </section>
      )}
    </main>
  )
}

export default App

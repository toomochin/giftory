<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import HistoryPagination from './HistoryPagination.vue'
import { prepareGiftPhoto } from './giftPhoto.js'

const user = ref(null)
const ready = ref(false)
const screen = ref('home')
const giftFormReturn = ref('home')
const giftRecipientId = ref('')
const registering = ref(false)
const credentials = ref({})
const recipients = ref([])
const giverNames = computed(() => [...new Set(recipients.value.map(record => record.name))]
  .filter(name => name !== user.value?.name))
const recipient = ref(null)
const history = ref(null)
const historyDirection = ref('')
const gift = ref(null)
const draft = ref({})
// 「もらった」では履歴の相手がくれた人になるため、贈った人の選択欄を省く。
const giftPeopleFields = computed(() => {
  const fields = screen.value === 'gift-new' ? ['recipient'] : []
  if (draft.value.direction !== 'received') fields.push('giver')
  return fields
})
const receivedFrom = computed(() => {
  // 既存の記録では、相手の名前を変更した後も記録時の贈り主を保持する。
  if (screen.value === 'gift-edit' && gift.value?.direction === 'received') return gift.value.giver
  const id = screen.value === 'gift-edit' ? gift.value?.recipient_id : giftRecipientId.value
  return recipients.value.find(record => String(record.id) === String(id))?.name ?? ''
})
const newGiver = ref(null)
const newGiverDialog = ref(null)
const photo = ref(null)
const members = ref([])
const invitation = ref(null)
const inviteLink = ref('')
const inviteEmail = ref('')
const giftDate = new Intl.DateTimeFormat('ja-JP', {
  timeZone: 'Asia/Tokyo', year: 'numeric', month: '2-digit', day: '2-digit',
})
const yen = new Intl.NumberFormat('ja-JP', { style: 'currency', currency: 'JPY' })
const directions = { given: '贈った', received: 'もらった' }
const events = ['誕生日', '記念日', 'クリスマス', '母の日', '父の日', 'バレンタイン', 'ホワイトデー', 'お祝い', 'お礼', 'お土産', 'その他']
const preview = ref(null)
// 招待トークンはURLのフラグメントから読み取り、必要なAPIの本文で送る。
const token = ref(new URLSearchParams(location.hash.slice(1)).get('invite'))
const errors = ref([])
const busy = ref(false)
const owner = computed(() => recipient.value?.owner_id === user.value?.id)
const editor = computed(() => gift.value?.created_by === user.value?.id)
const recipientPath = computed(() => '/recipients/' + recipient.value?.id)
const giftPath = computed(() => '/gifts/' + gift.value?.id)

async function api(path, method = 'GET', data) {
  const headers = { Accept: 'application/json' }
  // LaravelのCSRF Cookieをヘッダーに渡す。写真を含むFormDataのContent-Typeはブラウザに任せる。
  const csrf = document.cookie.split('; ').find(item => item.startsWith('XSRF-TOKEN='))
  if (csrf) headers['X-XSRF-TOKEN'] = decodeURIComponent(csrf.slice(11))
  if (data && !(data instanceof FormData)) {
    headers['Content-Type'] = 'application/json'
    data = JSON.stringify(data)
  }
  const response = await fetch('/api' + path, { method, headers, body: data })
  if (response.status === 204) return null
  const body = await response.json()
  if (!response.ok) {
    // 入力エラーはLaravelの項目別メッセージをまとめて表示し、権限エラーなどはステータス別の案内を使う。
    const messages = Object.values(body.errors || {}).flat()
    const fallback = { 401: 'ログインしてください。', 403: 'この操作を行う権限がありません。', 404: '対象が見つかりません。', 419: '画面を再読み込みしてください。', 429: 'しばらく待ってからお試しください。' }
    throw Object.assign(new Error(messages[0] || body.message), {
      status: response.status, messages: messages.length ? messages : [fallback[response.status] || (response.status === 409 ? body.message : '処理に失敗しました。')],
    })
  }
  return body
}

async function run(action) {
  // 通信が終わるまで、同じ操作の重複送信を防ぐ。
  if (busy.value) return
  busy.value = true
  errors.value = []
  try {
    await action()
  } catch (error) {
    errors.value = error.messages || ['通信に失敗しました。もう一度お試しください。']
    // セッション切れなどで401が返ったら、直前の会員・相手・記録の情報を画面の状態から外す。
    if (error.status === 401) {
      user.value = null
      recipient.value = null
      gift.value = null
      newGiver.value = null
    }
  } finally {
    busy.value = false
  }
}

async function home() {
  recipients.value = await api('/recipients')
  recipient.value = null
  gift.value = null
  screen.value = 'home'
}

function openRecipient(id) {
  return openHistory(id)
}

// 相手IDの有無で相手別と全件を切り替え、ページと区分は同じ処理で扱う。
async function openHistory(id = null, page = 1, direction = '') {
  const path = id === null ? '/gifts' : '/recipients/' + id + '/gifts'
  const params = new URLSearchParams({ page })
  if (direction) params.set('direction', direction)
  const [record, listings] = await Promise.all([
    id === null ? null : api('/recipients/' + id), api(path + '?' + params),
  ])
  recipient.value = record
  history.value = listings
  historyDirection.value = direction
  screen.value = id === null ? 'all-history' : 'recipient'
}

// 詳細から履歴へ戻るときはページと区分を維持する。絞り込みボタンからは1ページ目を指定する。
function refreshHistory(page = history.value?.current_page ?? 1, direction = historyDirection.value) {
  return openHistory(recipient.value?.id ?? null, page, direction)
}

function recipientForm(record = null) {
  errors.value = []
  draft.value = record ? { name: record.name, relationship: record.relationship, preferences: record.preferences } : {}
  screen.value = record ? 'recipient-edit' : 'recipient-new'
}

async function saveRecipient() {
  const record = await api(screen.value === 'recipient-edit' ? recipientPath.value : '/recipients',
    screen.value === 'recipient-edit' ? 'PATCH' : 'POST', draft.value)
  await openRecipient(record.id)
}

async function openGift(id) {
  gift.value = await api('/gifts/' + id)
  screen.value = 'gift'
  await nextTick()
  window.scrollTo({ top: 0 })
}

function todayForGift() {
  const parts = Object.fromEntries(giftDate.formatToParts(new Date()).map(({ type, value }) => [type, value]))
  return [parts.year, parts.month, parts.day].join('-')
}

async function giftForm(record = null) {
  recipients.value = await api('/recipients')
  newGiver.value = null
  giftFormReturn.value = screen.value
  // 相手別の画面から開いた場合や候補が1人の場合は、登録先をあらかじめ選ぶ。
  giftRecipientId.value = screen.value === 'recipient' ? recipient.value.id : recipients.value.length === 1 ? recipients.value[0].id : ''
  const fields = ['name', 'gifted_on', 'giver', 'reaction', 'price', 'event', 'direction']
  draft.value = record ? Object.fromEntries(fields.map(field => [field, record[field]])) : { event: null, direction: 'given', giver: user.value.name, gifted_on: todayForGift() }
  photo.value = null
  screen.value = record ? 'gift-edit' : 'gift-new'
}

function selectGiftPerson(event, target) {
  const select = event.target
  const selected = target === 'recipient' ? giftRecipientId : null
  if (select.selectedOptions[0]?.hasAttribute('data-add-recipient')) {
    // 「相手を追加」は登録ダイアログを開く項目なので、現在の選択を保持する。
    select.value = selected ? selected.value : draft.value.giver
    return showGiverForm(target)
  }

  if (selected) selected.value = select.value
  else draft.value.giver = select.value
}

async function showGiverForm(target) {
  errors.value = []
  newGiver.value = { name: '', relationship: '', target }
  await nextTick()
  newGiverDialog.value?.showModal()
}

async function addGiver() {
  const { name, relationship, target } = newGiver.value
  const record = await api('/recipients', 'POST', { name, relationship })
  recipients.value.push(record)
  if (target === 'recipient') giftRecipientId.value = record.id
  else draft.value.giver = record.name
  closeGiverForm()
}

function closeGiverForm() {
  newGiverDialog.value?.close()
  newGiver.value = null
  errors.value = []
}

async function saveGift() {
  const data = new FormData()
  // 縮小に失敗した場合はフォームの保存前にエラーを表示できるよう、先に写真を準備する。
  if (photo.value) data.append('photo', await prepareGiftPhoto(photo.value))
  const dataFields = { ...draft.value }
  if (dataFields.direction === 'received') dataFields.giver = receivedFrom.value
  if (screen.value === 'gift-edit') {
    // 記録の項目と写真は別のAPIで更新する。写真の差し替えが失敗した場合も、先に保存した項目は残る。
    await api(giftPath.value, 'PATCH', dataFields)
    if (photo.value) {
      // PHPが写真付きのmultipartリクエストを読めるよう、POSTで送りLaravel側でPUTとして扱う。
      data.append('_method', 'PUT')
      await api(giftPath.value + '/photo', 'POST', data)
    }
    await openGift(gift.value.id)
    return
  }
  // 新規登録は写真と項目を一度に送る。未入力だけを除外し、価格の0円はそのまま送信する。
  for (const [field, value] of Object.entries(dataFields)) {
    if (value !== null && value !== '') data.append(field, value)
  }
  const record = await api('/recipients/' + giftRecipientId.value + '/gifts', 'POST', data)
  await openGift(record.id)
}

async function sharing() {
  const [people, active] = await Promise.all([
    api(recipientPath.value + '/members'), api(recipientPath.value + '/invitation'),
  ])
  members.value = people
  // 開き直した共有管理からは期限だけを取得でき、平文のリンクは発行時にだけ表示できる。
  invitation.value = active?.expires_at ? active : null
  inviteLink.value = ''
  inviteEmail.value = ''
  screen.value = 'sharing'
}

async function issue() {
  const issued = await api(recipientPath.value + '/invitation', 'POST', { email: inviteEmail.value.trim() || null })
  invitation.value = issued
  // メールの送信結果にかかわらず、発行されたトークンからコピー用リンクを作る。
  inviteLink.value = location.origin + '/#invite=' + encodeURIComponent(issued.token)
}

function clearInvite() {
  token.value = null
  preview.value = null
  window.history.replaceState(null, '', location.pathname + location.search)
}

async function accept() {
  const response = await api('/invitations/accept', 'POST', { token: token.value })
  // 参加が確定してから、画面の状態とURLから使用済みトークンを取り除く。
  clearInvite()
  await openRecipient(response.recipient_id)
}

async function authenticate() {
  user.value = await api(registering.value ? '/register' : '/login', 'POST', credentials.value)
  credentials.value = {}
  // 招待リンク経由なら、ログイン後も参加の確認画面に留まる。
  if (!token.value) await openHistory()
}

function confirmAction(message, action) {
  if (window.confirm(message)) run(action)
}

const formatTime = date => new Intl.DateTimeFormat('ja-JP', {
  dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Tokyo',
}).format(new Date(date.includes('T') ? date : date.replace(' ', 'T') + 'Z'))

onMounted(async () => {
  await run(async () => {
    try {
      user.value = await api('/me')
    } catch (error) {
      if (error.status !== 401) throw error
    }
    if (token.value) preview.value = await api('/invitations/preview', 'POST', { token: token.value })
    else if (user.value) await openHistory()
  })
  ready.value = true
})
</script>

<template>
  <header>
    <strong>Giftory</strong>
    <nav v-if="user" class="header-actions" aria-label="メインメニュー">
      <template v-if="!token">
        <button type="button" class="header-gift-button" aria-label="贈り物を記録" title="贈り物を記録"
          :disabled="busy || ['gift-new', 'gift-edit'].includes(screen)" @click="run(() => giftForm())">
          <span class="header-gift-icon" aria-hidden="true"><span></span></span>
        </button>
        <button type="button" :disabled="busy" @click="run(home)">相手一覧へ</button>
        <button type="button" :disabled="busy" @click="run(() => openHistory())">すべての履歴</button>
      </template>
      <button type="button" :disabled="busy" @click="run(async () => {
        await api('/logout', 'POST')
        user = null
        recipient = null
        gift = null
        screen = 'home'
      })">ログアウト</button>
    </nav>
  </header>
  <main :aria-busy="busy">
    <ul v-if="errors.length && !newGiver" class="error" role="alert"><li v-for="message in errors" :key="message">{{ message }}</li></ul>
    <p v-if="!ready">読み込み中…</p>
    <template v-else>
      <section v-if="token">
        <h1>共有への招待</h1>
        <p v-if="preview">「{{ preview.recipient_name }}」の過去・今後の贈り物を閲覧し、記録を追加できます。</p>
        <p v-if="preview">有効期限 {{ formatTime(preview.expires_at) }}</p>
        <div class="actions">
          <button v-if="preview && user" class="primary" :disabled="busy" @click="run(accept)">参加する</button>
          <button :disabled="busy" @click="run(async () => { clearInvite(); if (user) await openHistory() })">招待を閉じる</button>
        </div>
      </section>

      <section v-if="!user">
        <h1>{{ registering ? '会員登録' : 'ログイン' }}</h1>
        <form @submit.prevent="run(authenticate)">
          <label v-if="registering">名前 <input v-model="credentials.name" autocomplete="name" required maxlength="255"></label>
          <label>メールアドレス <input v-model="credentials.email" type="email" autocomplete="email" required></label>
          <label>パスワード <input v-model="credentials.password" type="password" :autocomplete="registering ? 'new-password' : 'current-password'" required></label>
          <label v-if="registering">パスワード（確認） <input v-model="credentials.password_confirmation" type="password" autocomplete="new-password" required></label>
          <div class="actions">
            <button class="primary" :disabled="busy">{{ registering ? '登録する' : 'ログイン' }}</button>
            <button type="button" :disabled="busy" @click="registering = !registering; errors = []">{{ registering ? 'ログインへ' : '会員登録へ' }}</button>
          </div>
        </form>
      </section>

      <template v-else-if="!token">
        <template v-if="screen === 'home'">
          <h1>贈る相手</h1>
          <div class="actions">
            <button class="primary" :disabled="busy" @click="recipientForm()">相手を登録</button>
          </div>
          <p v-if="!recipients.length">贈り物を記録する相手を登録してください。</p>
          <article v-for="record in recipients" :key="record.id" class="recipient-card">
            <button class="recipient-card-name" :disabled="busy" @click="run(() => openRecipient(record.id))">{{ record.name }}</button>
            <div class="recipient-card-labels">
              <span class="recipient-relationship">{{ record.relationship }}</span>
              <span v-if="record.is_shared || record.owner_id !== user.id" class="recipient-shared">共有中</span>
            </div>
          </article>
        </template>

        <template v-if="screen === 'recipient'">
          <h1>{{ recipient.name }}</h1>
          <p class="note">{{ recipient.relationship }}<template v-if="recipient.preferences"> · {{ recipient.preferences }}</template></p>
          <div class="actions">
            <button v-if="owner" :disabled="busy" @click="recipientForm(recipient)">相手を編集</button>
            <button v-if="owner" :disabled="busy" @click="run(sharing)">共有管理</button>
            <button v-else :disabled="busy" @click="screen = 'leave'">共有から退出</button>
          </div>
        </template>

        <template v-if="screen === 'recipient' || screen === 'all-history'">
          <h1 v-if="screen === 'all-history'">すべての履歴</h1>
          <h2 v-else>贈り物の履歴</h2>
          <div class="actions" role="group" aria-label="履歴の表示">
            <button type="button" :class="{ primary: !historyDirection }" :aria-pressed="!historyDirection"
              :disabled="busy" @click="run(() => refreshHistory(1, ''))">すべて</button>
            <button v-for="(label, value) in directions" :key="value" type="button"
              :class="{ primary: historyDirection === value }" :aria-pressed="historyDirection === value"
              :disabled="busy" @click="run(() => refreshHistory(1, value))">{{ label }}</button>
          </div>
          <HistoryPagination :current="history.current_page" :last="history.last_page" :busy="busy"
            label="履歴ページの選択（上）" @change="page => run(() => refreshHistory(page))" />
          <p v-if="!history.data.length">{{ historyDirection ? 'この区分の記録はありません。' : '贈り物はまだ記録されていません。' }}</p>
          <button v-for="record in history.data" :key="record.id" type="button" class="history-card"
            :disabled="busy" @click="run(() => openGift(record.id))">
            <span class="history-card-media">
              <img v-if="record.has_photo" class="history-card-photo" :src="'/api/gifts/' + record.id + '/photo'"
                alt="" width="80" height="80" loading="lazy" decoding="async">
              <span v-if="record.price != null" class="history-card-price">{{ yen.format(record.price) }}</span>
            </span>
            <span class="history-card-content">
              <strong class="history-card-title">{{ record.name }}</strong>
              <time class="history-card-date" :datetime="record.gifted_on">{{ record.gifted_on }}</time>
              <span class="history-card-meta">
                <span class="history-card-direction" :class="{ received: record.direction === 'received' }">{{ directions[record.direction] }}</span>
                <span v-if="record.event" class="history-card-event">{{ record.event }}</span>
                <span class="history-card-recipient">
                  <template v-if="record.direction === 'given'">{{ record.giver }}{{ screen === 'all-history' && record.giver === user.name ? '（自分）' : '' }} → </template>{{ record.recipient?.name ?? recipient?.name }}
                </span>
              </span>
              <span class="note history-card-reaction" :class="{ empty: !record.reaction }">{{ record.reaction || 'メモは未記入' }}</span>
            </span>
          </button>
          <HistoryPagination :current="history.current_page" :last="history.last_page" :busy="busy"
            label="履歴ページの選択（下）" @change="page => run(() => refreshHistory(page))" />
        </template>

        <section v-if="['recipient-new', 'recipient-edit'].includes(screen)">
          <h1>{{ screen === 'recipient-new' ? '相手を登録' : '相手を編集' }}</h1>
          <form @submit.prevent="run(saveRecipient)">
            <label>名前またはニックネーム <input v-model="draft.name" maxlength="255" required></label>
            <label>関係性 <input v-model="draft.relationship" maxlength="255" required></label>
            <label>好み（任意） <textarea v-model="draft.preferences" maxlength="4000"></textarea></label>
            <div class="actions">
              <button class="primary" :disabled="busy">保存</button>
              <button type="button" :disabled="busy" @click="screen = recipient ? 'recipient' : 'home'">戻る</button>
              <button v-if="screen === 'recipient-edit'" type="button" :disabled="busy" @click="confirmAction('この相手を削除しますか？', async () => { await api(recipientPath, 'DELETE'); await home() })">削除</button>
            </div>
          </form>
        </section>

        <section v-if="screen === 'gift'" class="gift-detail">
          <button :disabled="busy" @click="run(() => refreshHistory())">履歴へ戻る</button>
          <div class="gift-detail-overview" :class="{ 'has-photo': gift.has_photo }">
            <div>
              <h1>{{ gift.name }}</h1>
              <dl class="gift-detail-fields">
                <div><dt>区分</dt><dd>{{ directions[gift.direction] }}</dd></div>
                <div><dt>日付</dt><dd>{{ gift.gifted_on }}</dd></div>
                <div><dt>{{ gift.direction === 'received' ? 'くれた人' : '贈った人' }}</dt><dd>{{ gift.giver }}</dd></div>
                <div><dt>登録者</dt><dd>{{ gift.recorder?.name }}</dd></div>
                <div v-if="gift.event"><dt>イベント</dt><dd>{{ gift.event }}</dd></div>
                <div v-if="gift.price != null"><dt>価格</dt><dd>{{ yen.format(gift.price) }}</dd></div>
              </dl>
            </div>
            <img v-if="gift.has_photo" class="gift-detail-photo" :src="'/api' + giftPath + '/photo?updated=' + encodeURIComponent(gift.updated_at)" :alt="gift.name + 'の写真'">
          </div>
          <div class="gift-detail-memo">
            <h2>メモ</h2>
            <p class="note" :class="{ 'gift-memo-empty': !gift.reaction }">{{ gift.reaction || 'メモは未記入' }}</p>
          </div>
          <div v-if="editor" class="actions">
            <button :disabled="busy" @click="run(() => giftForm(gift))">編集</button>
            <button :disabled="busy" @click="confirmAction('この贈り物の記録を削除しますか？', async () => { await api(giftPath, 'DELETE'); await refreshHistory(1) })">削除</button>
          </div>
        </section>

        <section v-if="['gift-new', 'gift-edit'].includes(screen)">
          <h1>{{ screen === 'gift-new' ? '贈り物を記録' : '贈り物を編集' }}</h1>
          <dialog v-if="newGiver" ref="newGiverDialog" class="giver-dialog"
            aria-labelledby="new-giver-title" @cancel.prevent="!busy && closeGiverForm()">
            <h2 id="new-giver-title">相手を追加</h2>
            <ul v-if="errors.length" class="error" role="alert"><li v-for="message in errors" :key="message">{{ message }}</li></ul>
            <form @submit.prevent="run(addGiver)">
              <label>名前またはニックネーム
                <input v-model="newGiver.name" maxlength="255" required autofocus>
              </label>
              <label>関係性
                <input v-model="newGiver.relationship" maxlength="255" required>
              </label>
              <div class="actions">
                <button type="submit" class="primary" :disabled="busy">登録して選択</button>
                <button type="button" :disabled="busy" @click="closeGiverForm">キャンセル</button>
              </div>
            </form>
          </dialog>
          <form @submit.prevent="!newGiver && run(saveGift)">
            <label>品物名 <input v-model="draft.name" maxlength="255" required></label>
            <fieldset class="event-picker">
              <legend>贈り物の区分</legend>
              <div class="event-options">
                <label v-for="(label, value) in directions" :key="value" class="event-option direction-option" :class="value">
                  <input v-model="draft.direction" type="radio" name="gift-direction" :value="value" required>
                  <span>{{ label }}</span>
                </label>
              </div>
            </fieldset>
            <div v-if="giftPeopleFields.length" class="gift-people" :class="{ paired: giftPeopleFields.length > 1 }">
              <template v-for="(field, index) in giftPeopleFields" :key="field">
                <span v-if="index === 1" class="gift-people-arrow" aria-hidden="true">
                  <span class="gift-arrow-horizontal">⇐</span>
                  <span class="gift-arrow-vertical">⇑</span>
                </span>
                <label v-if="field === 'giver'">贈った人
                  <select :value="draft.giver" :disabled="busy" required @change="selectGiftPerson($event, 'giver')">
                    <option value="" disabled>送り主を選択</option>
                    <option :value="user.name">{{ user.name }}（自分）</option>
                    <option v-if="screen === 'gift-edit' && gift.giver && gift.giver !== user.name && !giverNames.includes(gift.giver)" :value="gift.giver">{{ gift.giver }}（登録済みの値）</option>
                    <option v-for="name in giverNames" :key="name" :value="name">{{ name }}</option>
                    <option data-add-recipient>＋ 相手を追加</option>
                  </select>
                </label>
                <label v-else>記録する相手
                  <select :value="giftRecipientId" :disabled="busy" required aria-describedby="gift-recipient-help" @change="selectGiftPerson($event, 'recipient')">
                    <option value="" disabled>相手を選択</option>
                    <option v-for="record in recipients" :key="record.id" :value="record.id">{{ record.name }}（{{ record.relationship }}）</option>
                    <option data-add-recipient>＋ 相手を追加</option>
                  </select>
                  <small id="gift-recipient-help">選んだ相手の履歴に保存されます。</small>
                </label>
              </template>
            </div>
            <label>日付 <input v-model="draft.gifted_on" type="date" required></label>
            <label>メモ（任意） <textarea placeholder="相手の反応や、次の贈り物選びに役立つことなど" v-model="draft.reaction" maxlength="4000"></textarea></label>
            <label>価格（任意、円） <input v-model="draft.price" type="number" min="0" max="4294967295" step="1"></label>
            <fieldset class="event-picker">
              <legend>イベント（任意）</legend>
              <div class="event-options">
                <label class="event-option">
                  <input v-model="draft.event" type="radio" name="gift-event" :value="null">
                  <span>イベントなし</span>
                </label>
                <label v-if="screen === 'gift-edit' && gift.event && !events.includes(gift.event)" class="event-option">
                  <input v-model="draft.event" type="radio" name="gift-event" :value="gift.event">
                  <span>{{ gift.event }}（登録済み）</span>
                </label>
                <label v-for="event in events" :key="event" class="event-option">
                  <input v-model="draft.event" type="radio" name="gift-event" :value="event">
                  <span>{{ event }}</span>
                </label>
              </div>
            </fieldset>
            <img v-if="screen === 'gift-edit' && gift.has_photo" :src="'/api' + giftPath + '/photo'" alt="編集中の贈り物の写真">
            <label>写真（任意、JPEG／PNG。2 MiBを超える写真は自動で縮小） <input type="file" accept="image/jpeg,image/png" @change="photo = $event.target.files[0] || null"></label>
            <div class="actions">
              <button class="primary" :disabled="busy || !!newGiver">保存</button>
              <button type="button" :disabled="busy" @click="screen = giftFormReturn">戻る</button>
              <button v-if="screen === 'gift-edit' && gift.has_photo" type="button" :disabled="busy" @click="confirmAction('写真を削除しますか？', async () => { await api(giftPath + '/photo', 'DELETE'); gift = await api(giftPath) })">写真を削除</button>
            </div>
          </form>
        </section>

        <section v-if="screen === 'sharing'">
          <button :disabled="busy" @click="screen = 'recipient'; inviteLink = ''">履歴へ戻る</button>
          <h1>{{ recipient.name }}の共有管理</h1>
          <label>招待先メールアドレス（任意） <input v-model="inviteEmail" type="email" maxlength="255" :disabled="busy"></label>
          <p v-if="inviteLink && invitation?.email_sent === true" role="status">招待メールを送信しました。</p>
          <p v-if="inviteLink && invitation?.email_sent === false" class="error" role="alert">メールを送信できませんでした。下の招待リンクをコピーして共有できます。</p>
          <p v-if="invitation">招待の有効期限 {{ formatTime(invitation.expires_at) }}</p>
          <div class="actions">
            <button class="primary" :disabled="busy" @click="invitation ? confirmAction('以前の招待を無効にして再発行しますか？', issue) : run(issue)">{{ invitation ? '招待を再発行' : '招待を発行' }}</button>
            <button v-if="invitation" :disabled="busy" @click="confirmAction('この招待を無効にしますか？', async () => { await api(recipientPath + '/invitation', 'DELETE'); await sharing() })">招待を無効化</button>
          </div>
          <label v-if="inviteLink">招待リンク（この画面でのみ表示） <input readonly :value="inviteLink" @focus="$event.target.select()"></label>
          <h2>参加者</h2>
          <p v-if="!members.length">参加者はいません。</p>
          <ul><li v-for="member in members" :key="member.id">{{ member.name }} <button :disabled="busy" @click="confirmAction('この参加者の共有を解除しますか？', async () => { await api(recipientPath + '/members/' + member.id, 'DELETE'); await sharing() })">解除</button></li></ul>
        </section>

        <section v-if="screen === 'leave'">
          <h1>共有から退出</h1>
          <p>退出すると「{{ recipient.name }}」の履歴を開けなくなります。登録した贈り物は共有履歴に残ります。</p>
          <div class="actions">
            <button class="primary" :disabled="busy" @click="run(async () => { await api(recipientPath + '/membership', 'DELETE'); await home() })">退出する</button>
            <button :disabled="busy" @click="screen = 'recipient'">戻る</button>
          </div>
        </section>
      </template>
    </template>
  </main>
</template>

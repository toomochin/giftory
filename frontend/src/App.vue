<script setup>
import { computed, onMounted, ref } from 'vue'

const user = ref(null)
const ready = ref(false)
const screen = ref('home')
const registering = ref(false)
const credentials = ref({})
const recipients = ref([])
const recipient = ref(null)
const history = ref(null)
const gift = ref(null)
const draft = ref({})
const photo = ref(null)
const members = ref([])
const invitation = ref(null)
const inviteLink = ref('')
const inviteEmail = ref('')
const preview = ref(null)
const token = ref(new URLSearchParams(location.hash.slice(1)).get('invite'))
const errors = ref([])
const busy = ref(false)
const owner = computed(() => recipient.value?.owner_id === user.value?.id)
const editor = computed(() => gift.value?.created_by === user.value?.id)
const recipientPath = computed(() => '/recipients/' + recipient.value?.id)
const giftPath = computed(() => '/gifts/' + gift.value?.id)

async function api(path, method = 'GET', data) {
  const headers = { Accept: 'application/json' }
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
    const messages = Object.values(body.errors || {}).flat()
    const fallback = { 401: 'ログインしてください。', 403: 'この操作を行う権限がありません。', 404: '対象が見つかりません。', 419: '画面を再読み込みしてください。', 429: 'しばらく待ってからお試しください。' }
    throw Object.assign(new Error(messages[0] || body.message), {
      status: response.status, messages: messages.length ? messages : [fallback[response.status] || (response.status === 409 ? body.message : '処理に失敗しました。')],
    })
  }
  return body
}

async function run(action) {
  if (busy.value) return
  busy.value = true
  errors.value = []
  try {
    await action()
  } catch (error) {
    errors.value = error.messages || ['通信に失敗しました。もう一度お試しください。']
    if (error.status === 401) {
      user.value = null
      recipient.value = null
      gift.value = null
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

async function openRecipient(id, page = 1) {
  const [record, listings] = await Promise.all([
    api('/recipients/' + id), api('/recipients/' + id + '/gifts?page=' + page),
  ])
  recipient.value = record
  history.value = listings
  screen.value = 'recipient'
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
}

function giftForm(record = null) {
  errors.value = []
  const fields = ['name', 'gifted_on', 'giver', 'reaction', 'price', 'event']
  draft.value = record ? Object.fromEntries(fields.map(field => [field, record[field]])) : {}
  photo.value = null
  screen.value = record ? 'gift-edit' : 'gift-new'
}

function photoForm() {
  const data = new FormData()
  if (photo.value) data.append('photo', photo.value)
  return data
}

async function saveGift() {
  if (screen.value === 'gift-edit') {
    await api(giftPath.value, 'PATCH', draft.value)
    if (photo.value) {
      const data = photoForm()
      data.append('_method', 'PUT')
      await api(giftPath.value + '/photo', 'POST', data)
    }
    await openGift(gift.value.id)
    return
  }
  const data = photoForm()
  for (const [field, value] of Object.entries(draft.value)) {
    if (value !== null && value !== '') data.append(field, value)
  }
  const record = await api(recipientPath.value + '/gifts', 'POST', data)
  await openGift(record.id)
}

async function sharing() {
  const [people, active] = await Promise.all([
    api(recipientPath.value + '/members'), api(recipientPath.value + '/invitation'),
  ])
  members.value = people
  invitation.value = active
  inviteLink.value = ''
  inviteEmail.value = ''
  screen.value = 'sharing'
}

async function issue() {
  const issued = await api(recipientPath.value + '/invitation', 'POST', { email: inviteEmail.value.trim() || null })
  invitation.value = issued
  inviteLink.value = location.origin + '/#invite=' + encodeURIComponent(issued.token)
}

function clearInvite() {
  token.value = null
  preview.value = null
  window.history.replaceState(null, '', location.pathname + location.search)
}

async function accept() {
  const response = await api('/invitations/accept', 'POST', { token: token.value })
  clearInvite()
  await openRecipient(response.recipient_id)
}

async function authenticate() {
  user.value = await api(registering.value ? '/register' : '/login', 'POST', credentials.value)
  credentials.value = {}
  if (!token.value) await home()
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
    else if (user.value) await home()
  })
  ready.value = true
})
</script>

<template>
  <header>
    <strong>Giftory</strong>
    <button v-if="user" :disabled="busy" @click="run(async () => {
      await api('/logout', 'POST')
      user = null
      recipient = null
      gift = null
      screen = 'home'
    })">ログアウト</button>
  </header>
  <main :aria-busy="busy">
    <ul v-if="errors.length" class="error" role="alert"><li v-for="message in errors" :key="message">{{ message }}</li></ul>
    <p v-if="!ready">読み込み中…</p>
    <template v-else>
      <section v-if="token">
        <h1>共有への招待</h1>
        <p v-if="preview">「{{ preview.recipient_name }}」の過去・今後の贈り物を閲覧し、記録を追加できます。</p>
        <p v-if="preview">有効期限 {{ formatTime(preview.expires_at) }}</p>
        <div class="actions">
          <button v-if="preview && user" class="primary" :disabled="busy" @click="run(accept)">参加する</button>
          <button :disabled="busy" @click="run(async () => { clearInvite(); if (user) await home() })">招待を閉じる</button>
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
          <button class="primary" :disabled="busy" @click="recipientForm()">相手を登録</button>
          <p v-if="!recipients.length">贈り物を記録する相手を登録してください。</p>
          <article v-for="record in recipients" :key="record.id">
            <button :disabled="busy" @click="run(() => openRecipient(record.id))">{{ record.name }}</button>
            <small> {{ record.relationship }}{{ record.owner_id !== user.id ? ' · 共有' : '' }}</small>
          </article>
        </template>

        <template v-if="screen === 'recipient'">
          <button :disabled="busy" @click="run(home)">相手一覧へ</button>
          <h1>{{ recipient.name }}</h1>
          <p class="note">{{ recipient.relationship }}<template v-if="recipient.preferences"> · {{ recipient.preferences }}</template></p>
          <div class="actions">
            <button class="primary" :disabled="busy" @click="giftForm()">贈り物を記録</button>
            <button v-if="owner" :disabled="busy" @click="recipientForm(recipient)">相手を編集</button>
            <button v-if="owner" :disabled="busy" @click="run(sharing)">共有管理</button>
            <button v-else :disabled="busy" @click="screen = 'leave'">共有から退出</button>
          </div>
          <h2>贈り物の履歴</h2>
          <p v-if="!history.data.length">贈り物はまだ記録されていません。</p>
          <article v-for="record in history.data" :key="record.id">
            <time :datetime="record.gifted_on">{{ record.gifted_on }}</time>
            <div><button :disabled="busy" @click="run(() => openGift(record.id))">{{ record.name }}</button></div>
            <p class="note">{{ record.reaction || '反応は未記入' }}</p>
          </article>
          <div class="actions">
            <button v-if="history.current_page > 1" :disabled="busy" @click="run(() => openRecipient(recipient.id, history.current_page - 1))">前へ</button>
            <button v-if="history.next_page_url" :disabled="busy" @click="run(() => openRecipient(recipient.id, history.current_page + 1))">次へ</button>
          </div>
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

        <section v-if="screen === 'gift'">
          <button :disabled="busy" @click="run(() => openRecipient(recipient.id, history.current_page))">履歴へ戻る</button>
          <h1>{{ gift.name }}</h1>
          <dl>
            <dt>贈った日</dt><dd>{{ gift.gifted_on }}</dd>
            <dt>贈った人</dt><dd>{{ gift.giver }}</dd>
            <dt>登録者</dt><dd>{{ gift.recorder?.name }}</dd>
            <template v-if="gift.event"><dt>イベント</dt><dd>{{ gift.event }}</dd></template>
            <template v-if="gift.price != null"><dt>価格</dt><dd>{{ gift.price }}円</dd></template>
            <dt>反応</dt><dd class="note">{{ gift.reaction || '反応は未記入' }}</dd>
          </dl>
          <img v-if="gift.has_photo" :src="'/api' + giftPath + '/photo?updated=' + encodeURIComponent(gift.updated_at)" :alt="gift.name + 'の写真'">
          <div v-if="editor" class="actions">
            <button :disabled="busy" @click="giftForm(gift)">編集</button>
            <button :disabled="busy" @click="confirmAction('この贈り物の記録を削除しますか？', async () => { await api(giftPath, 'DELETE'); await openRecipient(recipient.id) })">削除</button>
          </div>
        </section>

        <section v-if="['gift-new', 'gift-edit'].includes(screen)">
          <h1>{{ screen === 'gift-new' ? '贈り物を記録' : '贈り物を編集' }}</h1>
          <form @submit.prevent="run(saveGift)">
            <label>品物名 <input v-model="draft.name" maxlength="255" required></label>
            <label>贈った日 <input v-model="draft.gifted_on" type="date" required></label>
            <label>贈った人 <input v-model="draft.giver" maxlength="255" required></label>
            <label>反応（任意） <textarea v-model="draft.reaction" maxlength="4000"></textarea></label>
            <label>価格（任意、円） <input v-model="draft.price" type="number" min="0" max="4294967295" step="1"></label>
            <label>イベント（任意） <input v-model="draft.event" maxlength="255"></label>
            <img v-if="screen === 'gift-edit' && gift.has_photo" :src="'/api' + giftPath + '/photo'" alt="編集中の贈り物の写真">
            <label>写真（任意、JPEG／PNG、2 MiB以下） <input type="file" accept="image/jpeg,image/png" @change="photo = $event.target.files[0] || null"></label>
            <div class="actions">
              <button class="primary" :disabled="busy">保存</button>
              <button type="button" :disabled="busy" @click="screen = screen === 'gift-edit' ? 'gift' : 'recipient'">戻る</button>
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

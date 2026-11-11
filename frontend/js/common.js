const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

const STATUS_SISWA = {
  menunggu_pembayaran: ['Menunggu pembayaran', 'warn'],
  menunggu_verifikasi: ['Menunggu verifikasi', 'info'],
  diterima: ['Diterima', 'ok'],
  ditolak: ['Ditolak', 'bad'],
};
const STATUS_BAYAR = { pending: ['Pending', 'warn'], paid: ['Lunas', 'ok'], failed: ['Gagal', 'bad'], expired: ['Kedaluwarsa', 'muted'] };
const AGAMA = ['Islam', 'Kristen', 'Katolik', 'Hindu', 'Buddha', 'Konghucu'];

function badge(map, key) {
  const [label, tone] = map[key] || [key || '-', 'muted'];
  return `<span class="badge badge-${tone}">${esc(label)}</span>`;
}
const rupiah = (n) => 'Rp ' + Number(n || 0).toLocaleString('id-ID');
function tgl(s, withTime) {
  if (!s) return '-';
  const d = new Date(String(s).replace(' ', 'T'));
  if (isNaN(d)) return esc(s);
  return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' }) +
    (withTime ? ', ' + d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) : '');
}
const safeUrl = (u) => (/^https?:\/\//i.test(u) ? u : null);

function toast(msg, type = 'ok') {
  let box = $('#toasts');
  if (!box) { box = document.createElement('div'); box.id = 'toasts'; document.body.appendChild(box); }
  const t = document.createElement('div');
  t.className = 'toast ' + type;
  t.textContent = msg;
  box.appendChild(t);
  setTimeout(() => t.remove(), 4200);
}

function setBusy(btn, busy, label) {
  if (!btn) return;
  if (busy) { btn.dataset.label = btn.textContent; btn.textContent = label || 'Memproses…'; btn.disabled = true; }
  else { btn.textContent = btn.dataset.label || btn.textContent; btn.disabled = false; }
}

function clearErrors(form) { $$('[data-err]', form).forEach((e) => (e.textContent = '')); const a = $('.alert', form); if (a) a.remove(); }
function showErrors(form, err) {
  clearErrors(form);
  Object.entries(err.errors || {}).forEach(([k, v]) => { const el = $(`[data-err="${k}"]`, form); if (el) el.textContent = v; });
  const box = document.createElement('div');
  box.className = 'alert alert-bad';
  box.setAttribute('role', 'alert');
  box.textContent = err.message || 'Terjadi kesalahan';
  form.prepend(box);
}

async function currentUser() {
  const r = await api.get('auth.php', { action: 'me' });
  return r.data.user;
}

async function guard(role) {
  let user;
  try { user = await currentUser(); } catch (e) { toast(e.message, 'bad'); throw e; }
  if (!user) { location.replace('login.html'); throw new Error('belum login'); }
  if (role && user.role !== role) { location.replace(user.role === 'admin' ? 'admin.html' : 'dashboard.html'); throw new Error('role salah'); }
  return user;
}

function renderNav(user) {
  const home = user ? (user.role === 'admin' ? 'admin.html' : 'dashboard.html') : 'login.html';
  $('#nav').innerHTML = `
    <div class="wrap">
      <a class="brand" href="index.html"><i>S</i> SMK Coding · PSB</a>
      <nav>
        <a class="link" href="index.html">Beranda</a>
        ${user
          ? `<a class="link" href="${home}">${user.role === 'admin' ? 'Panel Admin' : 'Dashboard'}</a>
             <span class="who">${esc(user.nama)}</span>
             <button class="link" id="btn-logout" type="button">Keluar</button>`
          : `<a class="link" href="login.html">Masuk</a><a class="btn btn-accent btn-sm" href="register.html">Daftar</a>`}
      </nav>
    </div>`;
  const out = $('#btn-logout');
  if (out) out.addEventListener('click', async () => {
    try { await api.post('auth.php', {}, { action: 'logout' }); } catch (e) { /* abaikan */ }
    location.href = 'login.html';
  });
}

/** Field formulir pendaftaran (dipakai pendaftar & admin). */
function siswaFields(v, jurusan) {
  v = v || {};
  const today = new Date().toISOString().slice(0, 10);
  const err = (n) => `<small class="err" data-err="${n}"></small>`;
  const text = (n, label, ph, type = 'text') =>
    `<div class="field"><label for="f-${n}">${label}</label><input id="f-${n}" type="${type}" name="${n}" value="${esc(v[n])}" placeholder="${esc(ph || '')}" required>${err(n)}</div>`;
  return `<div class="form-grid">
    ${text('nama', 'Nama lengkap', 'Sesuai ijazah / akta')}
    ${text('tempat_lahir', 'Tempat lahir', 'Kota/kabupaten')}
    <div class="field"><label for="f-tanggal_lahir">Tanggal lahir</label><input id="f-tanggal_lahir" type="date" name="tanggal_lahir" max="${today}" value="${esc(v.tanggal_lahir)}" required>${err('tanggal_lahir')}</div>
    <div class="field"><span class="lbl">Jenis kelamin</span>
      <div class="radios">
        <label><input type="radio" name="jenis_kelamin" value="laki-laki" ${v.jenis_kelamin === 'laki-laki' ? 'checked' : ''}> Laki-laki</label>
        <label><input type="radio" name="jenis_kelamin" value="perempuan" ${v.jenis_kelamin === 'perempuan' ? 'checked' : ''}> Perempuan</label>
      </div>${err('jenis_kelamin')}</div>
    <div class="field"><label for="f-agama">Agama</label><select id="f-agama" name="agama" required>
      <option value="">— pilih —</option>${AGAMA.map((a) => `<option ${v.agama === a ? 'selected' : ''}>${a}</option>`).join('')}</select>${err('agama')}</div>
    ${text('no_hp', 'Nomor HP / WhatsApp', '081234567890', 'tel')}
    <div class="field full"><label for="f-alamat">Alamat lengkap</label><textarea id="f-alamat" name="alamat" required>${esc(v.alamat)}</textarea>${err('alamat')}</div>
    ${text('sekolah_asal', 'Sekolah asal', 'Nama SMP/MTs')}
    ${text('nama_wali', 'Nama orang tua / wali', '')}
    <div class="field full"><label for="f-jurusan_id">Pilihan jurusan</label><select id="f-jurusan_id" name="jurusan_id" required>
      <option value="">— pilih jurusan —</option>
      ${jurusan.map((j) => `<option value="${j.id}" ${Number(v.jurusan_id) === j.id ? 'selected' : ''}>${esc(j.kode)} — ${esc(j.nama)}</option>`).join('')}
    </select>${err('jurusan_id')}</div>
  </div>`;
}

function formData(form) {
  const o = {};
  new FormData(form).forEach((val, k) => (o[k] = val));
  return o;
}

function renderPager(el, page, pages, go) {
  if (pages <= 1) { el.innerHTML = ''; return; }
  el.innerHTML = `<button class="btn btn-ghost btn-sm" ${page <= 1 ? 'disabled' : ''} data-p="${page - 1}">‹ Sebelumnya</button>
    <span class="muted small">Halaman ${page} dari ${pages}</span>
    <button class="btn btn-ghost btn-sm" ${page >= pages ? 'disabled' : ''} data-p="${page + 1}">Berikutnya ›</button>`;
  $$('button[data-p]', el).forEach((b) => b.addEventListener('click', () => go(Number(b.dataset.p))));
}

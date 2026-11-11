(async function () {
  let user;
  try { user = await guard('pendaftar'); } catch (e) { return; }
  renderNav(user);
  const app = $('#app');
  let jurusan = [], info = {}, state = { siswa: null, bayar: null }, editing = false;

  try {
    const [j, s] = await Promise.all([api.get('jurusan.php'), api.get('siswa.php')]);
    jurusan = j.data.jurusan; info = j.data.info;
    state = { siswa: s.data.siswa, bayar: s.data.pembayaran };
  } catch (e) {
    app.innerHTML = `<div class="alert alert-bad">${esc(e.message)}</div>`;
    return;
  }

  const LABELS = ['Isi formulir', 'Pembayaran', 'Verifikasi', 'Pengumuman'];
  const IDX = { menunggu_pembayaran: 1, menunggu_verifikasi: 2, diterima: 3, ditolak: 3 };
  const stepper = (i) => `<ol class="stepper">${LABELS.map((l, n) => `<li data-n="${n + 1}" class="${n < i ? 'done' : n === i ? 'cur' : ''}">${l}</li>`).join('')}</ol>`;

  function render() {
    const s = state.siswa;
    if (!s || editing) return formView();
    summaryView();
  }

  function formView() {
    const s = state.siswa;
    if (!s && !info.dibuka) {
      app.innerHTML = `${stepper(0)}<div class="alert alert-warn">Pendaftaran saat ini ditutup. Periode: ${tgl(info.buka)} – ${tgl(info.tutup)}.</div>`;
      return;
    }
    app.innerHTML = `${stepper(s ? 1 : 0)}
      <div class="card"><h2>${s ? 'Ubah data pendaftaran' : 'Formulir pendaftaran'}</h2>
        <p class="muted">Pastikan data sesuai dokumen resmi. Data dapat diubah selama pembayaran belum diselesaikan.</p>
        <form id="form" novalidate>${siswaFields(s || { nama: user.nama }, jurusan)}
          <div class="actions"><button class="btn" type="submit">${s ? 'Simpan perubahan' : 'Kirim pendaftaran'}</button>
          ${s ? '<button class="btn btn-ghost" type="button" id="cancel">Batal</button>' : ''}</div>
        </form></div>`;
    const form = $('#form');
    const cancel = $('#cancel');
    if (cancel) cancel.addEventListener('click', () => { editing = false; render(); });
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      clearErrors(form);
      const btn = $('button[type=submit]', form);
      setBusy(btn, true);
      try {
        const r = s ? await api.put('siswa.php', formData(form)) : await api.post('siswa.php', formData(form));
        state = { siswa: r.data.siswa, bayar: r.data.pembayaran };
        editing = false;
        toast(s ? 'Data berhasil diperbarui' : 'Pendaftaran terkirim. Lanjutkan ke pembayaran.');
        render();
        window.scrollTo({ top: 0, behavior: 'smooth' });
      } catch (e) {
        showErrors(form, e);
        setBusy(btn, false);
      }
    });
  }

  function statusPanel(s, b) {
    const pay = b ? `<p class="small muted">Transaksi terakhir: <code>${esc(b.order_id)}</code> ${badge(STATUS_BAYAR, b.status)}</p>` : '';
    if (s.status === 'menunggu_pembayaran') {
      const retry = b && (b.status === 'failed' || b.status === 'expired');
      return `<h3>Langkah berikutnya: bayar biaya pendaftaran</h3>
        ${retry ? '<div class="alert alert-warn">Pembayaran sebelumnya tidak berhasil. Silakan coba lagi.</div>' : ''}
        <p>Biaya pendaftaran <b>${rupiah(info.biaya)}</b>. Anda akan diarahkan ke halaman pembayaran ${info.mode_bayar === 'mock' ? '(mode simulasi)' : 'Midtrans'}.</p>
        ${pay}
        <div class="actions"><button class="btn btn-accent" id="btn-pay" type="button">${b && b.status === 'pending' ? 'Lanjutkan pembayaran' : 'Bayar sekarang'}</button>
        <button class="btn btn-ghost" id="btn-edit" type="button">Ubah data</button></div>`;
    }
    if (s.status === 'menunggu_verifikasi') {
      return `<h3>Pembayaran diterima</h3><div class="alert alert-info">Panitia sedang memverifikasi data Anda. Cek halaman ini secara berkala.</div>${pay}
        <div class="actions"><button class="btn btn-ghost" id="btn-print" type="button">Cetak bukti pendaftaran</button></div>`;
    }
    if (s.status === 'diterima') {
      return `<h3>Selamat, Anda diterima 🎉</h3><div class="alert alert-ok">Anda diterima di jurusan <b>${esc(s.jurusan_nama)}</b>.${s.catatan_admin ? '<br>Catatan: ' + esc(s.catatan_admin) : ''}</div>
        <div class="actions"><button class="btn btn-ghost" id="btn-print" type="button">Cetak bukti pendaftaran</button></div>`;
    }
    return `<h3>Mohon maaf, pendaftaran belum dapat diterima</h3>
      <div class="alert alert-bad">${s.catatan_admin ? 'Alasan: ' + esc(s.catatan_admin) : 'Silakan hubungi panitia untuk informasi lebih lanjut.'}</div>`;
  }

  function summaryView() {
    const s = state.siswa, b = state.bayar;
    app.innerHTML = `${stepper(IDX[s.status])}
      <div class="grid g2">
        <div class="card"><p class="muted small">Nomor pendaftaran</p><div class="nomor">${esc(s.no_pendaftaran)}</div>
          <p>${badge(STATUS_SISWA, s.status)}</p>
          <dl class="kv">
            <dt>Nama</dt><dd>${esc(s.nama)}</dd>
            <dt>Tempat, tgl lahir</dt><dd>${esc(s.tempat_lahir)}, ${tgl(s.tanggal_lahir)}</dd>
            <dt>Jenis kelamin</dt><dd class="cap">${esc(s.jenis_kelamin)}</dd>
            <dt>Agama</dt><dd>${esc(s.agama)}</dd>
            <dt>Alamat</dt><dd>${esc(s.alamat)}</dd>
            <dt>Sekolah asal</dt><dd>${esc(s.sekolah_asal)}</dd>
            <dt>Orang tua / wali</dt><dd>${esc(s.nama_wali)}</dd>
            <dt>No. HP</dt><dd>${esc(s.no_hp)}</dd>
            <dt>Jurusan</dt><dd>${esc(s.jurusan_kode)} — ${esc(s.jurusan_nama)}</dd>
          </dl></div>
        <div class="card">${statusPanel(s, b)}</div>
      </div>`;
    const pay = $('#btn-pay'), edit = $('#btn-edit'), print = $('#btn-print');
    if (edit) edit.addEventListener('click', () => { editing = true; render(); });
    if (print) print.addEventListener('click', () => window.print());
    if (pay) pay.addEventListener('click', async () => {
      setBusy(pay, true, 'Menyiapkan pembayaran…');
      try {
        const r = await api.post('pembayaran.php', {}, { action: 'create' });
        const url = safeUrl(r.data.redirect_url);
        if (!url) throw new Error('Tautan pembayaran tidak valid');
        location.href = url;
      } catch (e) {
        toast(e.message, 'bad');
        setBusy(pay, false);
      }
    });
  }

  render();
})();

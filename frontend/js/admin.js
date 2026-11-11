(async function () {
  let user;
  try { user = await guard('admin'); } catch (e) { return; }
  renderNav(user);
  const dlg = $('#dlg');
  let jurusan = [];
  let sPage = 1;

  const safe = (fn) => async (...a) => { try { await fn(...a); } catch (e) { toast(e.message, 'bad'); } };
  const closeDlg = () => dlg.close();
  const openDlg = (html) => {
    dlg.innerHTML = html;
    const x = $('#dlg-x', dlg);
    if (x) x.addEventListener('click', closeDlg);
    if (!dlg.open) dlg.showModal();
  };

  async function loadJurusanList() { jurusan = (await api.get('jurusan.php', { all: 1 })).data.jurusan; }

  // ---------- Ringkasan ----------
  async function loadRingkasan() {
    const d = (await api.get('dashboard.php')).data;
    const ps = d.per_status;
    const stat = (v, l) => `<div class="stat"><b>${v}</b><span>${l}</span></div>`;
    $('#tab-ringkasan').innerHTML = `
      <div class="grid g4">
        ${stat(d.total, 'Total pendaftar')}${stat(ps.menunggu_pembayaran, 'Menunggu pembayaran')}
        ${stat(ps.menunggu_verifikasi, 'Menunggu verifikasi')}${stat(ps.diterima, 'Diterima')}
        ${stat(ps.ditolak, 'Ditolak')}${stat(rupiah(d.pendapatan), 'Total pembayaran lunas')}
      </div>
      <h3 class="mt">Kuota per jurusan</h3>
      <div class="grid g2">${d.jurusan.map((j) => `<div class="card"><b>${esc(j.kode)}</b> — ${esc(j.nama)}
        <progress max="${j.kuota || 1}" value="${j.diterima}"></progress>
        <p class="small muted">${j.diterima} diterima dari ${j.kuota} kursi · ${j.pendaftar} pendaftar</p></div>`).join('')}</div>
      <h3 class="mt">Pendaftar terbaru</h3>
      <div class="tablewrap"><table><thead><tr><th>No. Daftar</th><th>Nama</th><th>Jurusan</th><th>Status</th><th>Tanggal</th></tr></thead><tbody>
        ${d.terbaru.map((s) => `<tr><td>${esc(s.no_pendaftaran)}</td><td>${esc(s.nama)}</td><td>${esc(s.jurusan_kode)}</td><td>${badge(STATUS_SISWA, s.status)}</td><td>${tgl(s.created_at)}</td></tr>`).join('') || '<tr><td colspan="5" class="muted">Belum ada pendaftar</td></tr>'}
      </tbody></table></div>`;
  }

  // ---------- Pendaftar ----------
  async function loadSiswa(page) {
    sPage = page || 1;
    const f = formData($('#filter'));
    $('#btn-export').href = api.url('export.php', { status: f.status });
    const r = (await api.get('siswa.php', { ...f, page: sPage })).data;
    $('#tb-siswa').innerHTML = r.items.map((s) => `<tr>
      <td>${esc(s.no_pendaftaran)}</td><td>${esc(s.nama)}</td><td>${esc(s.jurusan_kode)}</td><td>${esc(s.sekolah_asal)}</td>
      <td>${s.status_bayar ? badge(STATUS_BAYAR, s.status_bayar) : '<span class="muted">—</span>'}</td>
      <td>${badge(STATUS_SISWA, s.status)}</td>
      <td class="right"><button class="btn btn-ghost btn-sm" data-id="${s.id}" type="button">Detail</button></td></tr>`).join('')
      || '<tr><td colspan="7" class="muted">Tidak ada data</td></tr>';
    $$('#tb-siswa button[data-id]').forEach((b) => b.addEventListener('click', safe(() => openDetail(b.dataset.id))));
    renderPager($('#pg-siswa'), r.page, r.pages, safe(loadSiswa));
  }

  async function openDetail(id) {
    const r = (await api.get('siswa.php', { id })).data;
    const s = r.siswa, p = r.pembayaran;
    const opts = jurusan.filter((j) => j.aktif || j.id === Number(s.jurusan_id));
    const belumBayar = s.status === 'menunggu_pembayaran';
    openDlg(`
      <div class="dlg-head"><div><b>${esc(s.no_pendaftaran)}</b> ${badge(STATUS_SISWA, s.status)}</div><button class="x" id="dlg-x" type="button" aria-label="Tutup">×</button></div>
      <div class="dlg-body">
        <p class="small muted">Akun: ${esc(s.email)} · Pembayaran: ${p ? badge(STATUS_BAYAR, p.status) + ' ' + rupiah(p.jumlah) : 'belum ada transaksi'} · Mendaftar: ${tgl(s.created_at, true)}</p>
        <form id="frm-edit" novalidate>${siswaFields(s, opts)}<div class="actions"><button class="btn" type="submit">Simpan perubahan data</button></div></form>
        <hr>
        <form id="frm-status" novalidate>
          <h3>Keputusan seleksi</h3>
          ${belumBayar ? '<div class="alert alert-info">Pendaftar belum menyelesaikan pembayaran, sehingga keputusan seleksi belum dapat diberikan.</div>' : `
          <div class="form-grid">
            <div class="field"><label for="st">Status</label><select id="st" name="status">
              ${['menunggu_verifikasi', 'diterima', 'ditolak'].map((k) => `<option value="${k}" ${s.status === k ? 'selected' : ''}>${STATUS_SISWA[k][0]}</option>`).join('')}</select></div>
            <div class="field full"><label for="cat">Catatan / alasan (wajib jika ditolak)</label><textarea id="cat" name="catatan_admin">${esc(s.catatan_admin)}</textarea><small class="err" data-err="catatan_admin"></small></div>
          </div>`}
          <div class="actions">${belumBayar ? '' : '<button class="btn btn-accent" type="submit">Terapkan status</button>'}
            <button class="btn btn-danger" id="btn-del" type="button">Hapus pendaftar</button></div>
        </form>
      </div>`);
    const fe = $('#frm-edit', dlg), fs = $('#frm-status', dlg);
    fe.addEventListener('submit', async (ev) => {
      ev.preventDefault(); clearErrors(fe);
      try { await api.put('siswa.php', formData(fe), { id }); toast('Data diperbarui'); closeDlg(); loadSiswa(sPage); }
      catch (e) { showErrors(fe, e); }
    });
    fs.addEventListener('submit', async (ev) => {
      ev.preventDefault(); clearErrors(fs);
      try { await api.patch('siswa.php', formData(fs), { id }); toast('Status diperbarui'); closeDlg(); loadSiswa(sPage); loadRingkasan(); }
      catch (e) { showErrors(fs, e); }
    });
    $('#btn-del', dlg).addEventListener('click', safe(async () => {
      if (!confirm('Hapus pendaftar ini beserta data pembayarannya? Tindakan tidak dapat dibatalkan.')) return;
      await api.del('siswa.php', { id });
      toast('Pendaftar dihapus'); closeDlg(); loadSiswa(1);
    }));
  }

  // ---------- Jurusan ----------
  async function loadJurusan() {
    await loadJurusanList();
    $('#tb-jur').innerHTML = jurusan.map((j) => `<tr><td>${esc(j.kode)}</td><td>${esc(j.nama)}</td><td>${j.kuota}</td><td>${j.terisi}</td><td>${j.pendaftar}</td>
      <td>${j.aktif ? '<span class="badge badge-ok">Aktif</span>' : '<span class="badge badge-muted">Nonaktif</span>'}</td>
      <td class="right"><button class="btn btn-ghost btn-sm" data-edit="${j.id}" type="button">Ubah</button>
      <button class="btn btn-danger btn-sm" data-del="${j.id}" type="button">Hapus</button></td></tr>`).join('');
    $$('#tb-jur [data-edit]').forEach((b) => b.addEventListener('click', () => jurusanForm(jurusan.find((j) => j.id === Number(b.dataset.edit)))));
    $$('#tb-jur [data-del]').forEach((b) => b.addEventListener('click', safe(async () => {
      if (!confirm('Hapus jurusan ini?')) return;
      await api.del('jurusan.php', { id: b.dataset.del });
      toast('Jurusan dihapus'); loadJurusan();
    })));
  }

  function jurusanForm(j) {
    j = j || { kode: '', nama: '', kuota: 36, aktif: true };
    const f = (n, l, t, v) => `<div class="field"><label for="j-${n}">${l}</label><input id="j-${n}" name="${n}" type="${t}" value="${esc(v)}" required><small class="err" data-err="${n}"></small></div>`;
    openDlg(`<div class="dlg-head"><b>${j.id ? 'Ubah' : 'Tambah'} jurusan</b><button class="x" id="dlg-x" type="button" aria-label="Tutup">×</button></div>
      <div class="dlg-body"><form id="frm-jur" novalidate><div class="form-grid">
        ${f('kode', 'Kode', 'text', j.kode)}${f('kuota', 'Kuota', 'number', j.kuota)}${f('nama', 'Nama jurusan', 'text', j.nama)}
        <div class="field"><label><input type="checkbox" name="aktif" ${j.aktif ? 'checked' : ''}> Aktif (tampil untuk pendaftar)</label></div></div>
        <div class="actions"><button class="btn" type="submit">Simpan</button></div></form></div>`);
    const form = $('#frm-jur', dlg);
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault(); clearErrors(form);
      const d = formData(form);
      const body = { kode: d.kode, nama: d.nama, kuota: Number(d.kuota), aktif: !!d.aktif };
      try {
        if (j.id) await api.put('jurusan.php', body, { id: j.id }); else await api.post('jurusan.php', body);
        toast('Jurusan disimpan'); closeDlg(); loadJurusan();
      } catch (e) { showErrors(form, e); }
    });
  }

  // ---------- Pembayaran ----------
  async function loadBayar(page) {
    const r = (await api.get('pembayaran.php', { action: 'list', status: $('#ps').value, page: page || 1 })).data;
    $('#tb-bayar').innerHTML = r.items.map((p) => `<tr><td><code>${esc(p.order_id)}</code></td><td>${esc(p.nama)}<br><span class="small muted">${esc(p.no_pendaftaran)}</span></td>
      <td>${rupiah(p.jumlah)}</td><td>${esc(p.metode || '-')}</td><td>${esc(p.gateway)}</td><td>${badge(STATUS_BAYAR, p.status)}</td><td>${p.paid_at ? tgl(p.paid_at, true) : '-'}</td></tr>`).join('')
      || '<tr><td colspan="7" class="muted">Belum ada transaksi</td></tr>';
    renderPager($('#pg-bayar'), r.page, r.pages, safe(loadBayar));
  }

  // ---------- Tab & inisialisasi ----------
  const tabs = { ringkasan: loadRingkasan, pendaftar: () => loadSiswa(1), jurusan: loadJurusan, pembayaran: () => loadBayar(1) };
  function openTab(name) {
    $$('#tabs button').forEach((b) => b.classList.toggle('on', b.dataset.tab === name));
    Object.keys(tabs).forEach((k) => $('#tab-' + k).classList.toggle('hidden', k !== name));
    safe(tabs[name])();
  }
  $$('#tabs button').forEach((b) => b.addEventListener('click', () => openTab(b.dataset.tab)));
  $('#filter').addEventListener('submit', (ev) => { ev.preventDefault(); safe(loadSiswa)(1); });
  $('#btn-add-jur').addEventListener('click', () => jurusanForm());
  $('#ps').addEventListener('change', () => safe(loadBayar)(1));

  try {
    await loadJurusanList();
    $('#fs').insertAdjacentHTML('beforeend', Object.entries(STATUS_SISWA).map(([k, v]) => `<option value="${k}">${v[0]}</option>`).join(''));
    $('#fj').insertAdjacentHTML('beforeend', jurusan.map((j) => `<option value="${j.id}">${esc(j.kode)}</option>`).join(''));
  } catch (e) { toast(e.message, 'bad'); }
  openTab('ringkasan');
})();

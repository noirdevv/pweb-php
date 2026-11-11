(async function () {
  $('#yr').textContent = new Date().getFullYear();
  let user = null;
  try { user = await currentUser(); } catch (e) { /* server belum siap, tetap tampilkan halaman */ }
  renderNav(user);
  if (user) {
    const cta = $('#cta-main');
    cta.textContent = user.role === 'admin' ? 'Buka panel admin' : 'Buka dashboard';
    cta.href = user.role === 'admin' ? 'admin.html' : 'dashboard.html';
  }
  try {
    const { jurusan, info } = (await api.get('jurusan.php')).data;
    $('#h-tag').textContent = `Penerimaan Peserta Didik Baru · TA ${info.tahun_ajaran}`;
    $('#facts').innerHTML = [
      ['Biaya pendaftaran', rupiah(info.biaya)],
      ['Periode', `${tgl(info.buka)} – ${tgl(info.tutup)}`],
      ['Usia calon siswa', `${info.usia_min}–${info.usia_maks} tahun`],
      ['Status', info.dibuka ? 'Pendaftaran dibuka' : 'Pendaftaran ditutup'],
    ].map(([k, v]) => `<div class="fact"><b>${esc(v)}</b><span>${esc(k)}</span></div>`).join('');
    $('#jurusan-list').innerHTML = jurusan.map((j) => {
      const sisa = Math.max(0, j.kuota - j.terisi);
      return `<article class="card"><span class="badge badge-info">${esc(j.kode)}</span>
        <h3>${esc(j.nama)}</h3>
        <progress max="${j.kuota || 1}" value="${j.terisi}"></progress>
        <p class="small muted">${j.terisi} dari ${j.kuota} kursi terisi · ${sisa > 0 ? 'sisa ' + sisa : 'kuota penuh'}</p></article>`;
    }).join('') || '<p class="muted">Belum ada jurusan.</p>';
  } catch (e) {
    $('#jurusan-list').innerHTML = `<div class="alert alert-bad">${esc(e.message)}</div>`;
  }
})();

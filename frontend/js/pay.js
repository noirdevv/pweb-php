(async function () {
  let user;
  try { user = await guard('pendaftar'); } catch (e) { return; }
  renderNav(user);
  const app = $('#app');
  const page = document.body.dataset.page;
  const orderId = new URLSearchParams(location.search).get('order_id') || '';
  const toResult = () => { location.href = 'pay-result.html?order_id=' + encodeURIComponent(orderId); };

  if (!orderId) { app.innerHTML = '<div class="alert alert-bad">Order ID tidak ditemukan.</div>'; return; }
  let pay;
  try { pay = (await api.get('pembayaran.php', { action: 'status', order_id: orderId })).data; }
  catch (e) { app.innerHTML = `<div class="alert alert-bad">${esc(e.message)}</div>`; return; }

  if (page === 'simulasi') {
    if (pay.status !== 'pending') { toResult(); return; }
    app.innerHTML = `<div class="card auth">
      <span class="badge badge-warn">MODE SIMULASI</span>
      <h2 class="mt">Payment gateway (demo)</h2>
      <p class="muted">Halaman ini mensimulasikan layar pembayaran. Tidak ada uang sungguhan yang berpindah.</p>
      <dl class="kv"><dt>Order ID</dt><dd>${esc(pay.order_id)}</dd><dt>Total</dt><dd><b>${rupiah(pay.jumlah)}</b></dd></dl>
      <div class="actions"><button class="btn" id="ok" type="button">Bayar (berhasil)</button><button class="btn btn-danger" id="no" type="button">Gagalkan</button></div></div>`;
    const act = (result) => async (ev) => {
      setBusy(ev.currentTarget, true);
      try { await api.post('pembayaran.php', { order_id: orderId, result }, { action: 'simulate' }); toResult(); }
      catch (e) { toast(e.message, 'bad'); $$('button', app).forEach((b) => (b.disabled = false)); }
    };
    $('#ok').addEventListener('click', act('paid'));
    $('#no').addEventListener('click', act('failed'));
    return;
  }

  // halaman hasil
  const view = {
    paid: ['✅', 'Pembayaran berhasil', 'Terima kasih. Pendaftaran Anda kini menunggu verifikasi panitia.', 'ok'],
    pending: ['⏳', 'Menunggu pembayaran', 'Transaksi belum selesai. Selesaikan pembayaran lalu muat ulang halaman ini.', 'info'],
    failed: ['❌', 'Pembayaran gagal', 'Transaksi tidak berhasil. Anda dapat mencoba membayar lagi dari dashboard.', 'bad'],
    expired: ['⌛', 'Pembayaran kedaluwarsa', 'Batas waktu pembayaran habis. Silakan buat transaksi baru dari dashboard.', 'bad'],
  }[pay.status] || ['ℹ️', 'Status tidak diketahui', '', 'info'];
  app.innerHTML = `<div class="result card"><div class="ico">${view[0]}</div><h2>${view[1]}</h2><p class="muted">${view[2]}</p>
    <dl class="kv"><dt>Order ID</dt><dd>${esc(pay.order_id)}</dd><dt>Jumlah</dt><dd>${rupiah(pay.jumlah)}</dd><dt>Status</dt><dd>${badge(STATUS_BAYAR, pay.status)}</dd>${pay.paid_at ? `<dt>Dibayar</dt><dd>${tgl(pay.paid_at, true)}</dd>` : ''}</dl>
    <div class="actions"><a class="btn" href="dashboard.html">Kembali ke dashboard</a>${pay.status === 'pending' ? '<button class="btn btn-ghost" id="reload" type="button">Muat ulang</button>' : ''}</div></div>`;
  const rl = $('#reload');
  if (rl) rl.addEventListener('click', () => location.reload());
})();

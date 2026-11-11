(async function () {
  let user = null;
  try { user = await currentUser(); } catch (e) { /* abaikan */ }
  if (user) { location.replace(user.role === 'admin' ? 'admin.html' : 'dashboard.html'); return; }
  renderNav(null);

  const form = $('#form');
  const isRegister = !!$('#password2');
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    clearErrors(form);
    const d = formData(form);
    if (isRegister && d.password !== d.password2) {
      showErrors(form, { message: 'Periksa kembali isian Anda', errors: { password2: 'Kata sandi tidak sama' } });
      return;
    }
    const btn = $('button[type=submit]', form);
    setBusy(btn, true);
    try {
      const payload = isRegister ? { nama: d.nama, email: d.email, password: d.password } : { email: d.email, password: d.password };
      const r = await api.post('auth.php', payload, { action: isRegister ? 'register' : 'login' });
      location.href = r.data.user.role === 'admin' ? 'admin.html' : 'dashboard.html';
    } catch (e) {
      showErrors(form, e);
      setBusy(btn, false);
    }
  });
})();

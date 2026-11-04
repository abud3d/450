(() => {
  const $ = id => document.getElementById(id);
  const form = $('createForm') || $('joinForm');
  const mode = $('createForm') ? 'create' : 'join';
  const name = $('playerName');
  const saved = localStorage.getItem('baloot400PlayerName');
  if (saved) name.value = saved;

  const transport = new BalootHttpClient();
  let guest = localStorage.getItem('baloot400Guest');
  if (!guest) {
    guest = crypto.randomUUID();
    localStorage.setItem('baloot400Guest', guest);
  }

  function enterRoom(result, button) {
    button.disabled = false;
    if (!result?.ok) {
      $('formMessage').textContent = result?.error || 'تعذر تنفيذ الطلب.';
      return;
    }
    localStorage.setItem('baloot400Room', result.code);
    localStorage.setItem('baloot400RoomAt', String(Date.now()));
    location.href = '/table.html?room=' + encodeURIComponent(result.code);
  }

  form.addEventListener('submit', event => {
    event.preventDefault();
    const button = event.submitter;
    button.disabled = true;
    $('formMessage').textContent = 'جارٍ الاتصال بالغرفة...';
    localStorage.setItem('baloot400PlayerName', name.value.trim());

    const payload = { name: name.value.trim(), guestId: guest };
    if (mode === 'create') {
      payload.qaidEnabled = $('qaidEnabled').checked;
    } else {
      payload.code = $('roomCode').value.trim().toUpperCase();
    }

    transport.emit(mode === 'create' ? 'room:create' : 'room:join', payload, result => {
      enterRoom(result, button);
    });
  });

  const quickButton = $('quickJoin');
  quickButton?.addEventListener('click', () => {
    if (!name.reportValidity()) return;
    quickButton.disabled = true;
    $('formMessage').textContent = 'جارٍ البحث عن غرفة مناسبة...';
    localStorage.setItem('baloot400PlayerName', name.value.trim());

    transport.emit('room:quick-join', {
      name: name.value.trim(),
      guestId: guest,
      qaidEnabled: true
    }, result => {
      if (result?.ok) {
        $('formMessage').textContent = result.created
          ? 'لم نجد غرفة مناسبة؛ أنشأنا لك غرفة جديدة.'
          : 'وجدنا لك غرفة؛ جارٍ إدخالك الآن...';
      }
      enterRoom(result, quickButton);
    });
  });
})();
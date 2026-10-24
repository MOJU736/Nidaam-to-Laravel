(function () {
  var ballot = document.getElementById('ballot');
  if (ballot) {
    var modal = document.getElementById('confirm');
    document.getElementById('review').addEventListener('click', function () {
      var pick = ballot.querySelector('input[name=candidate]:checked');
      if (!pick) { alert('Please select a candidate first.'); return; }
      document.getElementById('cname').textContent = pick.dataset.name;
      modal.hidden = false;
    });
    document.getElementById('cancel').addEventListener('click', function () { modal.hidden = true; });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') modal.hidden = true; });
  }
  document.querySelectorAll('[data-confirm]').forEach(function (b) {
    b.addEventListener('click', function (e) { if (!confirm(b.dataset.confirm)) e.preventDefault(); });
  });
})();

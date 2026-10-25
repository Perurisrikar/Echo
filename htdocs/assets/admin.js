// Admin pages: copy-link button, and closing the "⋯" menu when you tap elsewhere.
document.addEventListener('DOMContentLoaded', () => {
  const copyBtn = document.getElementById('copyLinkBtn');
  if (copyBtn) {
    copyBtn.addEventListener('click', () => {
      const box = document.getElementById('flashLinkBox');
      if (!box) return;
      const text = box.textContent.trim();
      const done = () => { copyBtn.textContent = 'Copied ✓'; setTimeout(() => { copyBtn.textContent = 'Copy link'; }, 1800); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, () => { selectText(box); });
      } else {
        selectText(box);
      }
    });
  }
  function selectText(el) {
    const r = document.createRange(); r.selectNodeContents(el);
    const s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
  }

  document.addEventListener('click', (e) => {
    document.querySelectorAll('details.more[open]').forEach((d) => { if (!d.contains(e.target)) d.removeAttribute('open'); });
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') document.querySelectorAll('details.more[open]').forEach((d) => d.removeAttribute('open'));
  });
});

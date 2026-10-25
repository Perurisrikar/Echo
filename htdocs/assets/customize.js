document.addEventListener('DOMContentLoaded', () => {
  const swatches = document.getElementById('swatches');
  const accentInput = document.getElementById('accentInput');
  const glowCheck = document.getElementById('glowCheck');
  const preview = document.querySelector('.card.center .avatar');
  if (!swatches || !preview) return;

  function applyPreview(accentHex, glowOn) {
    preview.classList.toggle('glow', !!(glowOn && accentHex));
    if (accentHex) preview.style.setProperty('--glow', accentHex);
  }

  swatches.querySelectorAll('.swatch-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      swatches.querySelectorAll('.swatch-btn').forEach(b => b.classList.remove('selected'));
      btn.classList.add('selected');
      accentInput.value = btn.dataset.value;
      const hex = btn.dataset.value ? getComputedStyle(btn).backgroundColor : null;
      applyPreview(hex, glowCheck.checked);
    });
  });

  glowCheck.addEventListener('change', () => {
    const selected = swatches.querySelector('.swatch-btn.selected');
    const hex = selected && selected.dataset.value ? getComputedStyle(selected).backgroundColor : null;
    applyPreview(hex, glowCheck.checked);
  });
});

document.addEventListener('DOMContentLoaded', () => {
  const photoInput = document.getElementById('photoInput');
  const photoForm = document.getElementById('photoForm');
  const cropCard = document.getElementById('cropCard');
  const cropStage = document.getElementById('cropStage');
  const cropImg = document.getElementById('cropImg');
  const zoomRange = document.getElementById('zoomRange');
  const cropCancel = document.getElementById('cropCancel');
  const cropSave = document.getElementById('cropSave');
  const msg = document.getElementById('photoMsg');
  if (!photoInput || !photoForm || !cropCard) return;

  const OUTPUT_SIZE = 480;
  let naturalW = 0, naturalH = 0, coverScale = 1, scale = 1;
  let imgLeft = 0, imgTop = 0;
  let dragging = false, dragStartX = 0, dragStartY = 0, startLeft = 0, startTop = 0;

  const say = (text) => { if (msg) msg.textContent = text || ''; };
  const stageSize = () => cropStage.clientWidth || 280;

  function clamp() {
    const s = stageSize();
    const dW = naturalW * scale, dH = naturalH * scale;
    imgLeft = Math.max(Math.min(0, s - dW), Math.min(0, imgLeft));
    imgTop = Math.max(Math.min(0, s - dH), Math.min(0, imgTop));
  }

  function render() {
    cropImg.style.width = naturalW * scale + 'px';
    cropImg.style.height = naturalH * scale + 'px';
    cropImg.style.left = imgLeft + 'px';
    cropImg.style.top = imgTop + 'px';
  }

  function centerImage() {
    const s = stageSize();
    imgLeft = (s - naturalW * scale) / 2;
    imgTop = (s - naturalH * scale) / 2;
  }

  photoInput.addEventListener('change', () => {
    say('');
    const file = photoInput.files && photoInput.files[0];
    if (!file) return;
    if (!file.type.startsWith('image/')) { say('Please choose an image file.'); return; }

    const reader = new FileReader();
    reader.onerror = () => say("Couldn't read that file. Try another image.");
    reader.onload = (e) => {
      cropImg.onerror = () => {
        cropCard.classList.add('hidden');
        say("Your browser couldn't open that image. Try a JPG or PNG.");
      };
      cropImg.onload = () => {
        cropCard.classList.remove('hidden'); // must be visible before measuring: hidden elements measure 0 wide
        naturalW = cropImg.naturalWidth;
        naturalH = cropImg.naturalHeight;
        coverScale = stageSize() / Math.min(naturalW, naturalH);
        scale = coverScale;
        zoomRange.value = 100;
        centerImage();
        render();
        cropCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
      };
      cropImg.src = e.target.result;
    };
    reader.readAsDataURL(file);
  });

  zoomRange.addEventListener('input', () => {
    scale = coverScale * (parseFloat(zoomRange.value) / 100);
    centerImage();
    render();
  });

  cropStage.addEventListener('pointerdown', (e) => {
    dragging = true;
    dragStartX = e.clientX; dragStartY = e.clientY;
    startLeft = imgLeft; startTop = imgTop;
    cropStage.setPointerCapture(e.pointerId);
  });
  cropStage.addEventListener('pointermove', (e) => {
    if (!dragging) return;
    imgLeft = startLeft + (e.clientX - dragStartX);
    imgTop = startTop + (e.clientY - dragStartY);
    clamp();
    render();
  });
  const stopDrag = () => { dragging = false; };
  cropStage.addEventListener('pointerup', stopDrag);
  cropStage.addEventListener('pointercancel', stopDrag);

  cropCancel.addEventListener('click', () => {
    cropCard.classList.add('hidden');
    photoInput.value = '';
    say('');
  });

  cropSave.addEventListener('click', () => {
    if (!scale || !naturalW || !naturalH) return;
    const s = stageSize();

    const canvas = document.createElement('canvas');
    canvas.width = OUTPUT_SIZE;
    canvas.height = OUTPUT_SIZE;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, OUTPUT_SIZE, OUTPUT_SIZE);
    ctx.drawImage(cropImg, -imgLeft / scale, -imgTop / scale, s / scale, s / scale, 0, 0, OUTPUT_SIZE, OUTPUT_SIZE);

    cropSave.disabled = true;
    cropSave.textContent = 'Uploading…';

    canvas.toBlob((blob) => {
      if (!blob) {
        cropSave.disabled = false;
        cropSave.textContent = 'Use this photo';
        say("Couldn't prepare the image. Please try again.");
        return;
      }
      const file = new File([blob], 'photo.jpg', { type: 'image/jpeg' });
      const dt = new DataTransfer();
      dt.items.add(file);
      photoInput.files = dt.files;
      photoForm.submit();
    }, 'image/jpeg', 0.9);
  });
});

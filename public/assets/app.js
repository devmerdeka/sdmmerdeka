const canvas = document.querySelector('#snapshot');
const photoInput = document.querySelector('#photo');
const photoFile = document.querySelector('#photoFile');
const photoPreview = document.querySelector('#photoPreview');
const photoPrompt = document.querySelector('#photoPrompt');
const photoState = document.querySelector('#photoState');
const locationState = document.querySelector('#locationState');
const statusText = document.querySelector('#captureStatus');
const latitude = document.querySelector('#latitude');
const longitude = document.querySelector('#longitude');
const maxPhotoBytes = 500 * 1024;

function setStatus(message) {
  if (statusText) statusText.textContent = message;
}

function dataUrlBytes(dataUrl) {
  const base64 = dataUrl.split(',')[1] || '';
  return Math.ceil((base64.length * 3) / 4);
}

function canvasToCompressedPhoto(sourceCanvas) {
  let workingCanvas = sourceCanvas;
  let quality = 0.84;
  let dataUrl = workingCanvas.toDataURL('image/jpeg', quality);

  while (dataUrlBytes(dataUrl) > maxPhotoBytes && quality > 0.48) {
    quality -= 0.08;
    dataUrl = workingCanvas.toDataURL('image/jpeg', quality);
  }

  while (dataUrlBytes(dataUrl) > maxPhotoBytes && workingCanvas.width > 420) {
    const smaller = document.createElement('canvas');
    smaller.width = Math.round(workingCanvas.width * 0.82);
    smaller.height = Math.round(workingCanvas.height * 0.82);
    smaller.getContext('2d').drawImage(workingCanvas, 0, 0, smaller.width, smaller.height);
    workingCanvas = smaller;
    quality = 0.72;
    dataUrl = workingCanvas.toDataURL('image/jpeg', quality);
  }

  return dataUrl;
}

function usePhotoFile(file) {
  if (!file || !canvas || !photoInput) return;
  if (!file.type.startsWith('image/')) {
    setStatus('File harus berupa foto.');
    return;
  }
  const reader = new FileReader();
  reader.onload = () => {
    const image = new Image();
    image.onload = () => {
      canvas.width = 640;
      canvas.height = 640;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);

      const scale = Math.max(canvas.width / image.width, canvas.height / image.height);
      const width = image.width * scale;
      const height = image.height * scale;
      const x = (canvas.width - width) / 2;
      const y = (canvas.height - height) / 2;
      ctx.drawImage(image, x, y, width, height);

      photoInput.value = canvasToCompressedPhoto(canvas);
      const sizeKb = Math.ceil(dataUrlBytes(photoInput.value) / 1024);
      if (photoPreview) {
        photoPreview.src = photoInput.value;
        photoPreview.hidden = false;
      }
      if (photoPrompt) photoPrompt.textContent = 'Foto wajah siap';
      if (photoState) {
        photoState.textContent = 'Foto siap';
        photoState.classList.add('ready');
      }
      setStatus(`Foto wajah dikompres menjadi ${sizeKb} KB. Ambil lokasi lalu kirim absensi.`);
    };
    image.onerror = () => setStatus('Foto tidak bisa dibaca. Coba ambil ulang foto.');
    image.src = reader.result;
  };
  reader.onerror = () => setStatus('Foto tidak bisa dibaca. Coba ambil ulang foto.');
  reader.readAsDataURL(file);
}

function getLocation() {
  if (!latitude || !longitude) return;
  if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
    setStatus('Lokasi diblokir browser karena alamat masih HTTP. Buka lewat HTTPS/domain hosting agar GPS HP bisa dipakai.');
    return;
  }
  if (!navigator.geolocation) {
    setStatus('Browser ini tidak mendukung pengambilan lokasi GPS.');
    return;
  }
  setStatus('Mengambil koordinat lokasi...');
  navigator.geolocation.getCurrentPosition((position) => {
    latitude.value = position.coords.latitude;
    longitude.value = position.coords.longitude;
    if (locationState) {
      locationState.textContent = 'Lokasi siap';
      locationState.classList.add('ready');
    }
    setStatus(`Lokasi tersimpan: ${position.coords.latitude.toFixed(6)}, ${position.coords.longitude.toFixed(6)}`);
  }, (error) => {
    const messages = {
      1: 'Izin lokasi ditolak browser. Buka pengaturan situs lalu izinkan lokasi untuk alamat aplikasi ini.',
      2: 'GPS HP belum memberikan posisi. Nyalakan GPS mode akurasi tinggi lalu coba lagi di area terbuka.',
      3: 'Pengambilan lokasi terlalu lama. Pastikan sinyal GPS/internet stabil lalu tekan Ambil Lokasi lagi.'
    };
    setStatus(messages[error.code] || 'Lokasi tidak bisa diambil. Pastikan izin lokasi aktif.');
  }, {
    enableHighAccuracy: true,
    timeout: 20000,
    maximumAge: 0
  });
}

photoFile?.addEventListener('change', (event) => usePhotoFile(event.target.files?.[0]));
document.querySelector('#locateBtn')?.addEventListener('click', getLocation);
document.querySelector('.capture-form')?.addEventListener('submit', (event) => {
  if (!latitude?.value || !longitude?.value || (!photoInput?.value && !photoFile?.files?.length)) {
    event.preventDefault();
    setStatus('Ambil lokasi dan foto wajah terlebih dahulu.');
    return;
  }
  if (photoInput?.value && photoFile) photoFile.disabled = true;
});

(() => {
  const target = document.getElementById('qr-code');
  const uri = target?.dataset.otpUri;
  if (!target || !uri || typeof QRCode === 'undefined') return;
  new QRCode(target, { text: uri, width: 200, height: 200, colorDark: '#061a2f', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
})();

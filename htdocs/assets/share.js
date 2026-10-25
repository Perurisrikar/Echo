// Draws a clean, shareable status image on a canvas. Nothing is uploaded.
(function () {
  'use strict';
  var btn = document.getElementById('shareBtn');
  var el = document.getElementById('share-data');
  if (!btn || !el) return;
  var d = JSON.parse(el.textContent);

  function draw() {
    var W = 1080, H = 1080, c = document.createElement('canvas');
    c.width = W; c.height = H;
    var x = c.getContext('2d');
    var g = x.createLinearGradient(0, 0, W, H);
    g.addColorStop(0, '#E6F0D2'); g.addColorStop(.5, '#DCEBF6'); g.addColorStop(1, '#F8E4E1');
    x.fillStyle = g; x.fillRect(0, 0, W, H);
    // frosted panel
    x.fillStyle = 'rgba(255,255,255,.62)';
    var r = 56, px = 90, py = 150, pw = W - 180, ph = H - 300;
    x.beginPath();
    x.moveTo(px + r, py); x.arcTo(px + pw, py, px + pw, py + ph, r); x.arcTo(px + pw, py + ph, px, py + ph, r);
    x.arcTo(px, py + ph, px, py, r); x.arcTo(px, py, px + pw, py, r); x.closePath(); x.fill();

    var font = "'DM Sans', system-ui, -apple-system, 'Segoe UI', sans-serif";
    x.textAlign = 'center'; x.fillStyle = '#2D332F';
    x.font = '600 40px ' + font; x.fillText('Echo', W / 2, 110);
    x.font = '500 38px ' + font; x.fillStyle = '#8A948F';
    x.fillText(d.name, W / 2, 300);
    x.fillStyle = '#2D332F'; x.font = '700 250px ' + font;
    x.fillText(d.pct.toFixed(1) + '%', W / 2, 580);
    x.font = '500 40px ' + font; x.fillStyle = '#8A948F';
    x.fillText('overall attendance', W / 2, 650);
    // bar
    var bx = 200, bw = W - 400, by = 710;
    x.fillStyle = 'rgba(45,51,47,.10)'; x.fillRect(bx, by, bw, 18);
    x.fillStyle = '#8FAE5E'; x.fillRect(bx, by, bw * Math.min(Math.max(d.pct, 0), 100) / 100, 18);
    x.fillStyle = '#2D332F'; x.font = '600 44px ' + font;
    var line = d.subjects ? (d.safe === d.subjects ? 'Safe in all ' + d.subjects + ' subjects ✓' : d.safe + ' of ' + d.subjects + ' subjects above 75%') : '';
    x.fillText(line, W / 2, 830);
    x.font = '500 30px ' + font; x.fillStyle = '#8A948F';
    x.fillText('Track yours on Echo', W / 2, H - 100);
    return c;
  }

  btn.addEventListener('click', function () {
    var canvas = draw();
    canvas.toBlob(function (blob) {
      if (!blob) return;
      var file = new File([blob], 'echo-status.png', { type: 'image/png' });
      if (navigator.canShare && navigator.canShare({ files: [file] })) {
        navigator.share({ files: [file], title: 'My attendance on Echo' }).catch(function () {});
      } else {
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob); a.download = 'echo-status.png';
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 4000);
      }
    }, 'image/png');
  });
})();

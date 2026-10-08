<script>
(() => {
    const canvas = document.getElementById(@js($canvas));
    const input = document.getElementById(@js($input));
    const hint = document.getElementById(@js($hint));
    const ctx = canvas.getContext('2d');
    let drawing = false, drawn = false, last = null;

    const resize = () => {
        const ratio = window.devicePixelRatio || 1;
        const rect = canvas.getBoundingClientRect();
        canvas.width = rect.width * ratio;
        canvas.height = rect.height * ratio;
        ctx.scale(ratio, ratio);
        ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0b1f4d';
        drawn = false; input.value = ''; hint.style.display = '';
    };
    const point = (e) => { const r = canvas.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; };

    canvas.addEventListener('pointerdown', (e) => { drawing = true; last = point(e); canvas.setPointerCapture(e.pointerId); });
    canvas.addEventListener('pointermove', (e) => {
        if (!drawing) return;
        const p = point(e);
        ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
        last = p; drawn = true; hint.style.display = 'none';
    });
    const end = () => { if (drawing && drawn) { input.value = canvas.toDataURL('image/png'); } drawing = false; };
    canvas.addEventListener('pointerup', end);
    canvas.addEventListener('pointerleave', end);
    document.getElementById(@js($clear)).addEventListener('click', () => { ctx.clearRect(0, 0, canvas.width, canvas.height); drawn = false; input.value = ''; hint.style.display = ''; });
    document.getElementById(@js($form)).addEventListener('submit', (e) => { if (!input.value) { e.preventDefault(); hint.textContent = @js(__('Please sign in the field.')); hint.style.display = ''; } });
    resize();
})();
</script>

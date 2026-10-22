// A signature box for the customer portal: draw with a finger or a mouse,
// and the drawing goes with the form as a PNG.
export function startSignaturePads() {
    document.querySelectorAll('[data-signature-form]').forEach((form) => {
        const canvas = form.querySelector('[data-signature-pad]');
        const value = form.querySelector('[data-signature-value]');
        const clear = form.querySelector('[data-signature-clear]');
        if (!canvas || !value) return;

        const ctx = canvas.getContext('2d');
        let drawing = false;
        let drawn = false;

        const size = () => {
            const ratio = window.devicePixelRatio || 1;
            const rect = canvas.getBoundingClientRect();
            canvas.width = rect.width * ratio;
            canvas.height = rect.height * ratio;
            ctx.scale(ratio, ratio);
            ctx.lineWidth = 2.5;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#1c1917';
            drawn = false;
            value.value = '';
        };

        const point = (e) => {
            const rect = canvas.getBoundingClientRect();
            return { x: e.clientX - rect.left, y: e.clientY - rect.top };
        };

        canvas.addEventListener('pointerdown', (e) => {
            drawing = true;
            canvas.setPointerCapture(e.pointerId);
            const p = point(e);
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
        });
        canvas.addEventListener('pointermove', (e) => {
            if (!drawing) return;
            const p = point(e);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            drawn = true;
        });
        const stop = () => {
            if (drawing && drawn) value.value = canvas.toDataURL('image/png');
            drawing = false;
        };
        canvas.addEventListener('pointerup', stop);
        canvas.addEventListener('pointercancel', stop);
        clear?.addEventListener('click', () => {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            drawn = false;
            value.value = '';
        });

        form.addEventListener('submit', (e) => {
            if (!value.value) {
                e.preventDefault();
                canvas.classList.add('border-brand-red');
                canvas.scrollIntoView({ block: 'center' });
            }
        });

        // Phones fire "resize" whenever the address bar slides away on scroll; only a real
        // change of width (turning the phone) redraws the box, so a signature is not lost.
        let width = 0;
        const resize = () => {
            const w = Math.round(canvas.getBoundingClientRect().width);
            if (w !== width) {
                width = w;
                ctx.setTransform(1, 0, 0, 1, 0, 0);
                size();
            }
        };
        resize();
        window.addEventListener('resize', resize);
    });
}

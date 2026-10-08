{{-- Signature field: draw with finger, stylus or mouse; the state is a PNG data URL. --}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{
            state: $wire.$entangle(@js($getStatePath())),
            drawing: false, drawn: false, last: null, ctx: null,
            init() {
                const c = this.$refs.canvas, r = c.getBoundingClientRect(), ratio = window.devicePixelRatio || 1;
                c.width = r.width * ratio; c.height = r.height * ratio;
                this.ctx = c.getContext('2d'); this.ctx.scale(ratio, ratio);
                Object.assign(this.ctx, { lineWidth: 2.4, lineCap: 'round', lineJoin: 'round', strokeStyle: '#0b1f4d' });
                this.$watch('state', (v) => { if (! v) { this.ctx.clearRect(0, 0, c.width, c.height); this.drawn = false; } });
            },
            point(e) { const r = this.$refs.canvas.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; },
            down(e) { this.drawing = true; this.last = this.point(e); this.$refs.canvas.setPointerCapture(e.pointerId); },
            move(e) {
                if (! this.drawing) return;
                const p = this.point(e);
                this.ctx.beginPath(); this.ctx.moveTo(this.last.x, this.last.y); this.ctx.lineTo(p.x, p.y); this.ctx.stroke();
                this.last = p; this.drawn = true;
            },
            up() { if (this.drawing && this.drawn) { this.state = this.$refs.canvas.toDataURL('image/png'); } this.drawing = false; },
            clear() { this.state = null; },
        }"
        class="space-y-2"
        wire:ignore
    >
        <div style="position: relative; border: 1px dashed rgb(161 161 170); border-radius: 0.5rem; background: rgb(250 250 250); touch-action: none">
            <canvas x-ref="canvas" style="display: block; width: 100%; height: 220px; cursor: crosshair"
                x-on:pointerdown="down($event)" x-on:pointermove="move($event)" x-on:pointerup="up()" x-on:pointerleave="up()"></canvas>
            <span x-show="! drawn" style="position: absolute; left: 14px; bottom: 10px; color: rgb(161 161 170); font-size: 14px; pointer-events: none">{{ __('Sign here with finger, stylus or mouse') }}</span>
        </div>
        <button type="button" x-on:click="clear()" class="text-sm underline text-gray-600 dark:text-gray-300">{{ __('Clear') }}</button>
    </div>
</x-dynamic-component>

/**
 * Recuadro de firma propio (ADR 0003; ADR 0004, paso 4b-4): sustituye a signature_pad (librería de terceros) con la
 * misma API que usa el plugin (isEmpty, clear, toData, fromData, off, on, addEventListener('afterUpdateStroke'|'endStroke'))
 * y el mismo formato de datos: [{penColor, points: [{x, y, time, pressure}]}]. Sin dependencias. Lo usan el panel de
 * los firmantes del sistema, la firma en lote y el modal de Mi Cuenta (create-enrollment.js).
 */
(function (global) {
  "use strict";

  class EdusystemSignaturePad {
    constructor(canvas, options = {}) {
      this.canvas = canvas;
      this.ctx = canvas.getContext("2d");
      this.penColor = options.penColor || "black";
      this.lineWidth = options.lineWidth || 2.2;
      this.strokes = [];
      this.current = null;
      this.enabled = true;
      this.listeners = {};
      this._down = this._down.bind(this);
      this._move = this._move.bind(this);
      this._up = this._up.bind(this);
      // Cambiar el tamaño del lienzo (width/height) lo borra: se vuelve a dibujar lo que ya hay
      if (global.MutationObserver) {
        new MutationObserver(() => this._redraw()).observe(canvas, { attributes: true, attributeFilter: ["width", "height"] });
      }
      this.on();
    }

    // --- API compatible con el uso actual ---
    isEmpty() {
      return this.strokes.length === 0;
    }

    clear() {
      this.strokes = [];
      this.current = null;
      this._redraw();
    }

    toData() {
      return JSON.parse(JSON.stringify(this.strokes));
    }

    fromData(data) {
      this.strokes = Array.isArray(data)
        ? data.filter((stroke) => stroke && Array.isArray(stroke.points)).map((stroke) => ({
            penColor: stroke.penColor || this.penColor,
            points: stroke.points.map((p) => ({ x: +p.x, y: +p.y, time: +p.time || 0, pressure: +p.pressure || 0.5 })),
          }))
        : [];
      this._redraw();
    }

    on() {
      this.enabled = true;
      this.canvas.style.touchAction = "none";
      this.canvas.addEventListener("pointerdown", this._down);
    }

    off() {
      this.enabled = false;
      this.canvas.removeEventListener("pointerdown", this._down);
      window.removeEventListener("pointermove", this._move);
      window.removeEventListener("pointerup", this._up);
    }

    addEventListener(type, fn) {
      (this.listeners[type] = this.listeners[type] || []).push(fn);
    }

    // --- Dibujo ---
    _point(event) {
      const rect = this.canvas.getBoundingClientRect();
      return {
        x: Math.round((event.clientX - rect.left) * 100) / 100,
        y: Math.round((event.clientY - rect.top) * 100) / 100,
        time: Date.now(),
        pressure: event.pressure || 0.5,
      };
    }

    _down(event) {
      if (!this.enabled || (event.button !== undefined && event.button !== 0)) return;
      event.preventDefault();
      this.current = { penColor: this.penColor, points: [this._point(event)] };
      this.strokes.push(this.current);
      window.addEventListener("pointermove", this._move);
      window.addEventListener("pointerup", this._up);
      this._redraw();
    }

    _move(event) {
      if (!this.current) return;
      event.preventDefault();
      this.current.points.push(this._point(event));
      this._redraw();
      this._emit("afterUpdateStroke");
    }

    _up() {
      window.removeEventListener("pointermove", this._move);
      window.removeEventListener("pointerup", this._up);
      if (this.current) {
        this.current = null;
        this._emit("afterUpdateStroke");
        this._emit("endStroke");
      }
    }

    _emit(type) {
      (this.listeners[type] || []).forEach((fn) => fn());
    }

    _redraw() {
      const ctx = this.ctx;
      const ratio = this.canvas.width / (this.canvas.getBoundingClientRect().width || this.canvas.width) || 1;
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
      ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
      ctx.lineCap = "round";
      ctx.lineJoin = "round";
      ctx.lineWidth = this.lineWidth;
      this.strokes.forEach((stroke) => {
        const points = stroke.points;
        ctx.strokeStyle = stroke.penColor;
        ctx.fillStyle = stroke.penColor;
        if (points.length === 1) {
          ctx.beginPath();
          ctx.arc(points[0].x, points[0].y, this.lineWidth / 2, 0, Math.PI * 2);
          ctx.fill();
          return;
        }
        ctx.beginPath();
        ctx.moveTo(points[0].x, points[0].y);
        for (let i = 1; i < points.length - 1; i++) {
          const mx = (points[i].x + points[i + 1].x) / 2;
          const my = (points[i].y + points[i + 1].y) / 2;
          ctx.quadraticCurveTo(points[i].x, points[i].y, mx, my);
        }
        const last = points[points.length - 1];
        ctx.lineTo(last.x, last.y);
        ctx.stroke();
      });
    }
  }

  global.EdusystemSignaturePad = EdusystemSignaturePad;
})(window);

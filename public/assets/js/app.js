document.addEventListener('alpine:init', () => {
  Alpine.data('distanceModal', () => ({
    // Must match VoyagerDataService::DISTANCE_MODAL_CENTER.
    centerX: 450,
    centerY: 450,
    zoomBounds: { log: [0.5, 3], linear: [0.08, 20] },
    defaultZoom: { log: 1, linear: 0.5 },

    open: false,
    scaleMode: 'log',
    zoom: 1,
    panX: 0,
    panY: 0,
    dragging: false,
    lastX: 0,
    lastY: 0,

    show() {
      this.open = true;
      this.scaleMode = 'log';
      this.resetView();
    },
    hide() {
      this.open = false;
      this.dragging = false;
    },
    setScaleMode(mode) {
      this.scaleMode = mode;
      this.resetView();
    },
    resetView() {
      this.zoom = this.defaultZoom[this.scaleMode];
      this.panX = 0;
      this.panY = 0;
    },
    zoomIn() {
      this.setZoom(this.zoom * 1.4);
    },
    zoomOut() {
      this.setZoom(this.zoom / 1.4);
    },
    setZoom(next) {
      const [min, max] = this.zoomBounds[this.scaleMode];
      this.zoom = Math.min(max, Math.max(min, next));
    },
    onWheel(event) {
      event.deltaY < 0 ? this.zoomIn() : this.zoomOut();
    },
    pointerFrom(event) {
      const point = event.touches ? event.touches[0] : event;
      return { x: point.clientX, y: point.clientY };
    },
    startDrag(event) {
      this.dragging = true;
      const p = this.pointerFrom(event);
      this.lastX = p.x;
      this.lastY = p.y;
    },
    drag(event) {
      if (!this.dragging) return;
      const p = this.pointerFrom(event);
      this.panX += (p.x - this.lastX) / this.zoom;
      this.panY += (p.y - this.lastY) / this.zoom;
      this.lastX = p.x;
      this.lastY = p.y;
    },
    endDrag() {
      this.dragging = false;
    },
    get transform() {
      const tx = this.panX + this.centerX - this.centerX * this.zoom;
      const ty = this.panY + this.centerY - this.centerY * this.zoom;
      return `translate(${tx}, ${ty}) scale(${this.zoom})`;
    },
    get caption() {
      return this.scaleMode === 'log'
        ? 'Log scale: every body stays visible and correctly ordered by distance, but spacing is not linearly proportional.'
        : 'True linear scale: real proportional distances. Drag to pan, scroll or use +/- to zoom in from the outer solar system toward the Sun.';
    },
  }));
});

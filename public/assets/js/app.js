document.addEventListener('alpine:init', () => {
  Alpine.data('homeOrrery', () => ({
    zoom: 1,
    get isZoomOut() {
      return this.zoom === 0;
    },
    get caption() {
      return this.zoom === 0
        ? "Zoomed out: full solar system out to Pluto's orbit — both probes have crossed it."
        : "Default view: Sun, Earth, and each probe's current heading.";
    },
    zoomOut() {
      this.zoom = Math.max(0, this.zoom - 1);
    },
    zoomIn() {
      this.zoom = Math.min(1, this.zoom + 1);
    },
  }));

  Alpine.data('expandable', () => ({
    expanded: false,
    toggle() {
      this.expanded = !this.expanded;
    },
    get label() {
      return this.expanded ? 'Show less' : 'Show more';
    },
  }));
});

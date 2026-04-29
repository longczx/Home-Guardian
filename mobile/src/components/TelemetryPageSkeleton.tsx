export default function TelemetryPageSkeleton() {
  return (
    <div className="mobile-page mobile-page--tight">
      <div className="telemetry-skeleton">
        <div className="page-hero telemetry-skeleton__hero">
          <div className="skeleton-line skeleton-line--eyebrow" />
          <div className="skeleton-line skeleton-line--title" />
          <div className="skeleton-line skeleton-line--text" />
          <div className="skeleton-line skeleton-line--text skeleton-line--short" />
          <div className="telemetry-skeleton__chips">
            <span className="skeleton-chip" />
            <span className="skeleton-chip" />
          </div>
        </div>

        <div className="selector-group" style={{ marginTop: 16 }}>
          <div className="glass-card selector-field telemetry-skeleton__card">
            <div className="skeleton-line skeleton-line--label" />
            <div className="telemetry-skeleton__selector-row">
              <span className="skeleton-pill" />
              <span className="skeleton-pill" />
              <span className="skeleton-pill" />
            </div>
          </div>
          <div className="glass-card selector-field telemetry-skeleton__card">
            <div className="skeleton-line skeleton-line--label" />
            <div className="telemetry-skeleton__selector-row">
              <span className="skeleton-pill" />
              <span className="skeleton-pill" />
              <span className="skeleton-pill" />
              <span className="skeleton-pill" />
            </div>
          </div>
        </div>

        <div className="glass-card chart-panel telemetry-skeleton__chart" style={{ marginTop: 16 }}>
          <div className="chart-panel__header">
            <div style={{ width: '100%' }}>
              <div className="skeleton-line skeleton-line--title" style={{ width: '48%' }} />
              <div className="skeleton-line skeleton-line--label" style={{ marginTop: 8, width: '38%' }} />
            </div>
          </div>
          <div className="telemetry-skeleton__plot" />
        </div>
      </div>
    </div>
  );
}
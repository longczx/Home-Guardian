import type { AggregatedPoint } from '@/api/types';
export interface ChartPoint extends AggregatedPoint { time: number }
export function telemetryTime(value: string): number {
  let text = String(value || '').trim().replace(' ', 'T');
  if (/[+-]\d{2}$/.test(text)) text += ':00';
  if (/^\d{4}-\d\d-\d\dT\d\d:\d\d(?::\d\d(?:\.\d+)?)?$/.test(text)) text += 'Z';
  return Date.parse(text);
}
export function chartPoints(points: AggregatedPoint[]): ChartPoint[] {
  return points.filter(p => p.avg_value !== null && p.avg_value !== undefined && String(p.avg_value) !== '')
    .map(p => ({ ...p, time: telemetryTime(p.bucket), avg_value: Number(p.avg_value), min_value: Number(p.min_value), max_value: Number(p.max_value), sample_count: Number(p.sample_count) }))
    .filter(p => [p.time, p.avg_value, p.min_value, p.max_value, p.sample_count].every(Number.isFinite) && p.sample_count > 0)
    .sort((a, b) => a.time - b.time);
}
export function bucketDuration(start: number, end: number): number {
  return end - start <= 24 * 3600000 ? 300000 : end - start <= 7 * 86400000 ? 1800000 : 10800000;
}
export function chartSegments(points: ChartPoint[], gap: number): ChartPoint[][] {
  const segments: ChartPoint[][] = [];
  for (const point of points) {
    const previous = segments[segments.length - 1];
    if (!previous || point.time - previous[previous.length - 1].time > gap) segments.push([point]);
    else previous.push(point);
  }
  return segments;
}

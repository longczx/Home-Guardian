import ReactEChartsCore from 'echarts-for-react/lib/core';
import { CanvasRenderer } from 'echarts/renderers';
import { LineChart } from 'echarts/charts';
import {
  GridComponent,
  TooltipComponent,
} from 'echarts/components';
import * as echarts from 'echarts/core';
import type { EChartsCoreOption } from 'echarts/core';

echarts.use([
  CanvasRenderer,
  LineChart,
  GridComponent,
  TooltipComponent,
]);

interface TelemetryChartProps {
  option: EChartsCoreOption;
  height?: number;
}

export default function TelemetryChart({ option, height = 280 }: TelemetryChartProps) {
  return <ReactEChartsCore echarts={echarts} option={option} style={{ height }} />;
}
interface ConditionSummaryProps {
  telemetryKey: string;
  condition: string;
  thresholdValue: number[];
  duration?: number;
}

const conditionLabels: Record<string, string> = {
  gt: '大于',
  gte: '大于等于',
  lt: '小于',
  lte: '小于等于',
  eq: '等于',
  neq: '不等于',
  between: '介于',
  not_between: '不介于',
};

export default function ConditionSummary({ telemetryKey, condition, thresholdValue, duration }: ConditionSummaryProps) {
  const op = conditionLabels[condition] || condition;
  let valueText: string;
  if ((condition === 'between' || condition === 'not_between') && thresholdValue.length >= 2) {
    valueText = `${thresholdValue[0]} ~ ${thresholdValue[1]}`;
  } else {
    valueText = thresholdValue.join(', ');
  }

  return (
    <span style={{ fontSize: 13, color: 'var(--color-text-secondary)' }}>
      当 <b>{telemetryKey}</b> {op} <b>{valueText}</b>
      {duration ? ` 持续 ${duration}秒` : ''}
    </span>
  );
}

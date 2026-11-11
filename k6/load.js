// 負荷スクリプト
//
//   TARGET    叩くパス（既定: /random/）
//   RATE      1 秒あたりのリクエスト数（既定: 20）… 方式間で「同じ流量」をかけて DB 側の差を比べるため、
//             VU 数ではなくリクエスト数を固定する（constant-arrival-rate）
//   DURATION  継続時間（既定: 30s）
//
// リダイレクトは追わない（redirects: 0）。/random/ の「1 件選ぶ」コストだけを見るため。
// Host ヘッダーをサイト URL（localhost:8888）に合わせて、WordPress の正規化リダイレクトを避ける。

import http from 'k6/http';
import { check } from 'k6';

const BASE = __ENV.BASE_URL || 'http://wordpress';
const HOST = __ENV.WP_HOST || 'localhost:8888';
const TARGET = __ENV.TARGET || '/random/';
const RATE = parseInt(__ENV.RATE || '20', 10);
const DURATION = __ENV.DURATION || '30s';

export const options = {
  scenarios: {
    load: {
      executor: 'constant-arrival-rate',
      rate: RATE,
      timeUnit: '1s',
      duration: DURATION,
      preAllocatedVUs: Math.max(10, RATE),
      maxVUs: Math.max(50, RATE * 5),
    },
  },
  summaryTrendStats: ['avg', 'med', 'p(95)', 'max'],
  thresholds: {
    // 失敗しても止めない。結果の表示用
    http_req_failed: [{ threshold: 'rate<1', abortOnFail: false }],
  },
};

export default function () {
  const res = http.get(BASE + TARGET, {
    redirects: 0,
    headers: { Host: HOST },
    tags: { name: TARGET },
  });
  check(res, {
    '200 or 302': (r) => r.status === 200 || r.status === 302,
  });
}

export function handleSummary(data) {
  const d = data.metrics.http_req_duration.values;
  const reqs = data.metrics.http_reqs.values;
  const failed = data.metrics.http_req_failed.values.rate;
  const dropped = data.metrics.dropped_iterations ? data.metrics.dropped_iterations.values.count : 0;
  const line =
    `\n${TARGET}\n` +
    `  リクエスト: ${reqs.count} 件（${reqs.rate.toFixed(1)} req/s）  失敗率: ${(failed * 100).toFixed(1)}%  さばききれず: ${dropped} 件\n` +
    `  応答時間  平均 ${d.avg.toFixed(1)}ms / 中央 ${d.med.toFixed(1)}ms / p95 ${d['p(95)'].toFixed(1)}ms / 最大 ${d.max.toFixed(1)}ms\n`;
  return { stdout: line };
}

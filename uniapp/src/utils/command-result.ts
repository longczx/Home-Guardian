import { onWs } from '@/utils/ws';
import { useServerStore } from '@/stores/server';

/** Subscribe before submitting, since an ACK can arrive before the HTTP response. */
export async function withCommandResult(
  submit: () => Promise<{ request_id: string; status: string }>,
  timeoutMs = 65000,
  fetchStatus?: (requestId: string) => Promise<{ request_id: string; status: string } | undefined>,
): Promise<void> {
  const server = useServerStore();
  const revision = server.connectionRevision;
  const replies = new Map<string, Record<string, unknown>>();
  let requestId = '';
  let wake: (() => void) | undefined;
  const off = onWs('command_reply', (reply) => {
    if (server.connectionRevision !== revision) return;
    replies.set(String(reply.request_id), reply);
    if (replies.size > 100) replies.delete(replies.keys().next().value as string);
    wake?.();
  });
  let timer: ReturnType<typeof setTimeout> | undefined;
  let pollTimer: ReturnType<typeof setInterval> | undefined;
  let switchTimer: ReturnType<typeof setInterval> | undefined;
  try {
    const command = await submit();
    requestId = command.request_id;
    if (server.connectionRevision !== revision) throw new Error('服务器已切换，请核对设备状态');
    await new Promise<void>((resolve, reject) => {
      wake = () => {
        const reply = replies.get(requestId);
        if (!reply) return;
        if (reply.status === 'replied_ok') resolve();
        else reject(new Error(reply.status === 'timeout' ? '指令执行超时，请核对设备状态' : '设备执行失败，请核对设备状态'));
      };
      timer = setTimeout(() => reject(new Error('尚未收到设备确认，请核对设备状态')), timeoutMs);
      switchTimer = setInterval(() => {
        if (server.connectionRevision !== revision) reject(new Error('服务器已切换，请核对设备状态'));
      }, 250);
      wake();
      if (fetchStatus) {
        let polling = false;
        const poll = async () => {
          if (polling || server.connectionRevision !== revision) return;
          polling = true;
          try {
            const result = await fetchStatus(requestId);
            if (server.connectionRevision === revision && result && ['replied_ok', 'replied_error', 'timeout'].includes(result.status)) {
              replies.set(requestId, result);
              wake?.();
            }
          } catch { /* WS 或下一次轮询补足暂时的网络错误 */ }
          finally { polling = false; }
        };
        pollTimer = setInterval(poll, 2000);
        void poll();
      }
    });
  } finally {
    off();
    if (timer) clearTimeout(timer);
    if (pollTimer) clearInterval(pollTimer);
    if (switchTimer) clearInterval(switchTimer);
  }
}

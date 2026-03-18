import { useEffect } from 'react';
import { useWSStore, type WSMessage } from '@/stores/wsStore';

export function useWSSubscription(type: string, handler: (msg: WSMessage) => void) {
  const subscribe = useWSStore((s) => s.subscribe);

  useEffect(() => {
    return subscribe(type, handler);
  }, [type, handler, subscribe]);
}

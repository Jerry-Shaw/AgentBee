import { afterEach, describe, expect, it, vi } from 'vitest';
import type { ChatMessage, ChatSession } from '../protocol/types';
import {
  createWebSocketConnection,
  useWebSocketAgent,
  validateWebSocketUrl,
} from './useWebSocketAgent';

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

/**
 * 搭一个可手工投递服务端事件的 agent。
 * window.setTimeout 被打桩成永不触发，所以流式缓冲只在 end/tool_result 等事件里被同步 flush。
 */
function setupAgent() {
  const sockets: FakeWebSocket[] = [];
  class FakeWebSocket {
    static readonly CLOSED = 3;
    static readonly CONNECTING = 0;
    static readonly OPEN = 1;

    readonly [Symbol.toStringTag] = 'WebSocket';

    onclose: ((event: CloseEvent) => void) | null = null;
    onerror: (() => void) | null = null;
    onmessage: ((event: MessageEvent) => void) | null = null;
    onopen: (() => void) | null = null;
    readyState = FakeWebSocket.CONNECTING;
    sent: string[] = [];

    constructor() {
      sockets.push(this);
    }

    close() {
      this.readyState = FakeWebSocket.CLOSED;
    }

    open() {
      this.readyState = FakeWebSocket.OPEN;
      this.onopen?.();
    }

    send(payload: string) {
      this.sent.push(payload);
    }
  }

  let nextId = 0;
  const makeSession = (id: string): ChatSession => ({
    id,
    title: 'Test',
    createdAt: '',
    updatedAt: '',
    messages: [],
  });
  const sessionList: ChatSession[] = [makeSession('session-1')];
  const session = sessionList[0];
  let activeId = session.id;

  function currentSession(): ChatSession {
    return sessionList.find((item) => item.id === activeId) || sessionList[0];
  }

  function createSession(id: string): ChatSession {
    const created = makeSession(id);
    sessionList.push(created);
    return created;
  }

  function switchTo(id: string) {
    activeId = id;
  }

  const addMessage = vi.fn((
    role: ChatMessage['role'],
    content: string,
    extra: Partial<ChatMessage> = {},
  ) => {
    const message: ChatMessage = {
      id: `local-${nextId += 1}`,
      role,
      content,
      time: '',
      ...extra,
    };
    currentSession().messages.push(message);
    return message;
  });
  const storage = new Map<string, string>();
  vi.stubGlobal('WebSocket', FakeWebSocket);
  vi.stubGlobal('navigator', { onLine: true });
  vi.stubGlobal('document', {
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    visibilityState: 'visible',
  });
  vi.stubGlobal('localStorage', {
    getItem: (key: string) => storage.get(key) ?? null,
    removeItem: (key: string) => storage.delete(key),
    setItem: (key: string, value: string) => storage.set(key, value),
  });
  vi.stubGlobal('window', {
    addEventListener: vi.fn(),
    clearTimeout: vi.fn(),
    crypto: { randomUUID: () => `uuid-${nextId += 1}` },
    location: { protocol: 'http:' },
    removeEventListener: vi.fn(),
    setTimeout: vi.fn(() => 1),
  });
  vi.spyOn(console, 'warn').mockImplementation(() => undefined);

  const agent = useWebSocketAgent({
    activeSession: () => currentSession(),
    addMessage,
    getSessionById: (id: string) => sessionList.find((item) => item.id === id) || null,
    saveSessions: vi.fn(),
    scheduleSaveSessions: vi.fn(),
    touchSession: vi.fn(),
  });

  function emit(payload: unknown) {
    sockets[0].onmessage?.({ data: JSON.stringify(payload) } as MessageEvent);
  }

  function sendUserText(text: string): string {
    agent.sendText(text);
    sockets[0].open();
    const last = JSON.parse(sockets[0].sent[sockets[0].sent.length - 1]);
    return last.messageId as string;
  }

  function assistantOf(messageId: string, sessionId: string = activeId) {
    const target = sessionList.find((item) => item.id === sessionId);
    return target?.messages.find((message) => (
      message.role === 'assistant' && message.messageId === messageId
    ));
  }

  return {
    agent,
    assistantOf,
    createSession,
    emit,
    sendUserText,
    session,
    sessionList,
    sockets,
    switchTo,
  };
}

describe('close event handling', () => {
  it('keeps an already finished reply when a stale close arrives', () => {
    const { assistantOf, emit, sendUserText, session } = setupAgent();
    const messageId = sendUserText('first question');

    emit({ type: 'content', messageId, data: 'first answer' });
    emit({ type: 'end', messageId });
    expect(assistantOf(messageId)?.content).toBe('first answer');
    expect(assistantOf(messageId)?.status).toBe('done');

    // 后端在下一条用户消息前，会对残留的 curr_message_id 补发一次 close。
    const before = session.messages.length;
    emit({ type: 'close', messageId });

    expect(assistantOf(messageId)?.content).toBe('first answer');
    expect(session.messages).toHaveLength(before);
  });

  it('stops an in-flight reply but keeps what already streamed', () => {
    const { assistantOf, emit, sendUserText } = setupAgent();
    const messageId = sendUserText('second question');

    emit({ type: 'content', messageId, data: 'partial answer' });
    // tool_result 会先把缓冲的 content 同步 flush 出来。
    emit({ type: 'tool_result', messageId, data: { name: 'X', content: 'ok' } });
    expect(assistantOf(messageId)?.content).toBe('partial answer');

    emit({ type: 'close', messageId });

    expect(assistantOf(messageId)?.content).toBe('partial answer');
    expect(assistantOf(messageId)?.status).toBe('stopped');
  });

  it('still removes an empty reply that never produced anything', () => {
    const { assistantOf, emit, sendUserText } = setupAgent();
    const messageId = sendUserText('third question');

    expect(assistantOf(messageId)).toBeTruthy();
    emit({ type: 'close', messageId });

    expect(assistantOf(messageId)).toBeUndefined();
  });
});

describe('abort event handling', () => {
  // 服务端停止生成后的回执（原样取自后端）：
  // {"create_id":…,"type":"abort","data":{"message":"已停止生成"},"sessionId":…,"messageId":…}
  function abortEvent(messageId: string, sessionId: string) {
    return {
      create_id: 1790668633450829,
      type: 'abort',
      data: { message: '已停止生成' },
      sender: 'AgentBee',
      isSubTalk: 0,
      workerName: 'AgentBee',
      workerRole: 'Assistant',
      sessionId,
      messageId,
      WindowName: '蜂小秘 - AgentBee',
      socket_id: 'sock_74',
    };
  }

  it('finishes the aborted turn as stopped instead of dumping the raw payload', () => {
    const { assistantOf, emit, sendUserText, session } = setupAgent();
    const messageId = sendUserText('要停的问题');

    emit({ type: 'content', messageId, data: '流到一半的回答' });
    // tool_result 会先把缓冲的 content 同步 flush 出来。
    emit({ type: 'tool_result', messageId, data: { name: 'X', content: 'ok' } });

    const before = session.messages.length;
    emit(abortEvent(messageId, session.id));

    expect(assistantOf(messageId)?.content).toBe('流到一半的回答');
    expect(assistantOf(messageId)?.status).toBe('stopped');
    // 兜底分支会把整包 JSON 当正文再推一条 assistant 消息。
    expect(session.messages).toHaveLength(before);
    expect(assistantOf(messageId)?.content).not.toContain('已停止生成');
  });

  it('drops the empty shell when the turn never produced anything', () => {
    const { assistantOf, emit, sendUserText, session } = setupAgent();
    const messageId = sendUserText('立刻停掉');

    expect(assistantOf(messageId)).toBeTruthy();
    emit(abortEvent(messageId, session.id));

    expect(assistantOf(messageId)).toBeUndefined();
  });

  it('routes the abort receipt to the session that owns the turn', () => {
    const { assistantOf, createSession, emit, sendUserText, session, switchTo } = setupAgent();
    const messageId = sendUserText('第一个会话的问题');
    emit({ type: 'content', messageId, data: 'half' });

    switchTo(createSession('session-2').id);
    emit(abortEvent(messageId, session.id));

    expect(assistantOf(messageId, session.id)?.status).toBe('stopped');
    expect(assistantOf(messageId, 'session-2')).toBeUndefined();
  });
});

describe('stream content without a message id', () => {
  it('does not create a ghost assistant message when no turn is pending', () => {
    const { agent, emit, session, sendUserText } = setupAgent();
    const messageId = sendUserText('question');

    emit({ type: 'content', messageId, data: 'answer' });
    emit({ type: 'end', messageId });

    const settledCount = session.messages.filter((message) => message.role === 'assistant').length;
    expect(agent.hasPendingTurns.value).toBe(false);

    // 没有 messageId 的零散内容，且此刻没有在等待的轮次。
    emit({ type: 'content', data: 'stray chunk' });
    emit({ type: 'content', messageId: null, data: 'another stray chunk' });

    expect(session.messages.filter((message) => message.role === 'assistant')).toHaveLength(settledCount);
    expect(agent.hasPendingTurns.value).toBe(false);
  });
});

describe('switching sessions while a turn is streaming', () => {
  it('keeps streaming into the session that started the turn', () => {
    const { agent, assistantOf, createSession, emit, sendUserText, switchTo } = setupAgent();
    const messageId = sendUserText('第一个会话的问题');

    emit({ type: 'content', messageId, data: 'part 1' });
    // tool_result 会把缓冲的 content 同步 flush 出来。
    emit({ type: 'tool_result', messageId, data: { name: 'X', content: 'ok' } });
    expect(assistantOf(messageId, 'session-1')?.content).toBe('part 1');
    expect(agent.streamingSessionIds.value).toEqual(['session-1']);

    // 用户在这一轮还没结束时切到另一个会话。
    const second = createSession('session-2');
    switchTo(second.id);

    emit({ type: 'content', messageId, data: ' part 2' });
    emit({ type: 'end', messageId });

    // 内容必须落回发起这一轮的会话，而不是切过去之后当前的那个。
    expect(assistantOf(messageId, 'session-1')?.content).toBe('part 1 part 2');
    expect(assistantOf(messageId, 'session-1')?.status).toBe('done');
    expect(assistantOf(messageId, 'session-2')).toBeUndefined();
    expect(second.messages.filter((message) => message.role === 'assistant')).toHaveLength(0);
    expect(agent.streamingSessionIds.value).toEqual([]);
    expect(agent.hasPendingTurns.value).toBe(false);
  });

  it('closes the turn in its own session after switching away', () => {
    const { assistantOf, createSession, emit, sendUserText, switchTo } = setupAgent();
    const messageId = sendUserText('第二个会话的问题');

    emit({ type: 'content', messageId, data: 'half' });
    emit({ type: 'tool_result', messageId, data: { name: 'X', content: 'ok' } });

    switchTo(createSession('session-2').id);
    emit({ type: 'close', messageId });

    expect(assistantOf(messageId, 'session-1')?.content).toBe('half');
    expect(assistantOf(messageId, 'session-1')?.status).toBe('stopped');
  });

  it('stops the turn of the session it belongs to, not the one on screen', () => {
    const { agent, assistantOf, createSession, emit, sendUserText, sockets, switchTo } = setupAgent();
    const messageId = sendUserText('要停的问题');
    emit({ type: 'content', messageId, data: 'x' });
    emit({ type: 'tool_result', messageId, data: { name: 'X', content: 'ok' } });

    switchTo(createSession('session-2').id);
    agent.stopCurrent();

    const abortPayload = JSON.parse(sockets[0].sent[sockets[0].sent.length - 1]);
    expect(abortPayload).toMatchObject({ type: 'abort', sessionId: 'session-1', messageId });
    expect(assistantOf(messageId, 'session-1')?.status).toBe('stopped');
  });

  it('sends the chat payload with the session active at send time', () => {
    const { agent, createSession, sendUserText, sessionList, sockets, switchTo } = setupAgent();
    const firstId = sessionList[0].id;

    sendUserText('第一个会话的问题');
    expect(JSON.parse(sockets[0].sent[sockets[0].sent.length - 1]))
      .toMatchObject({ type: 'chat', sessionId: firstId });

    const second = createSession('session-2');
    switchTo(second.id);
    agent.sendText('第二个会话的问题');

    expect(JSON.parse(sockets[0].sent[sockets[0].sent.length - 1]))
      .toMatchObject({ type: 'chat', sessionId: 'session-2' });
    expect(second.messages.filter((item) => item.role === 'user'))
      .toHaveLength(1);
  });

  it('drops events for a turn whose session is gone instead of resurrecting it', () => {
    const { agent, emit, sendUserText, sessionList } = setupAgent();
    const messageId = sendUserText('会被删掉的会话');
    emit({ type: 'content', messageId, data: 'x' });
    emit({ type: 'tool_result', messageId, data: { name: 'X', content: 'ok' } });

    sessionList.splice(0, sessionList.length);

    expect(() => emit({ type: 'content', messageId, data: 'late chunk' })).not.toThrow();
    expect(() => emit({ type: 'end', messageId })).not.toThrow();
    expect(agent.hasPendingTurns.value).toBe(false);
  });
});

describe('validateWebSocketUrl', () => {
  it('accepts ws and wss URLs on an HTTP page', () => {
    expect(validateWebSocketUrl('ws://127.0.0.1:8686', 'http:')).toBeNull();
    expect(validateWebSocketUrl('wss://agent.example.test/socket', 'http:')).toBeNull();
  });

  it('accepts wss on an HTTPS page', () => {
    expect(validateWebSocketUrl('wss://agent.example.test/socket', 'https:')).toBeNull();
  });

  it('reports malformed and unsupported URLs', () => {
    expect(validateWebSocketUrl('127.0.0.1:8686', 'http:')?.kind).toBe('invalid-url');
    expect(validateWebSocketUrl('https://agent.example.test', 'http:')?.kind).toBe('invalid-url');
  });

  it('reports insecure ws from an HTTPS page as mixed content', () => {
    expect(validateWebSocketUrl('ws://agent.example.test/socket', 'https:')?.kind).toBe('mixed-content');
  });
});

describe('createWebSocketConnection', () => {
  it('omits the subprotocol argument when the token is empty', () => {
    const calls: Array<[string, string | string[] | undefined]> = [];
    class FakeWebSocket {
      constructor(url: string, protocols?: string | string[]) {
        calls.push([url, protocols]);
      }
    }

    createWebSocketConnection(
      'ws://127.0.0.1:8686',
      '   ',
      FakeWebSocket as unknown as typeof WebSocket,
    );

    expect(calls).toEqual([['ws://127.0.0.1:8686', undefined]]);
  });

  it('uses a non-empty token as the WebSocket subprotocol', () => {
    const calls: Array<[string, string | string[] | undefined]> = [];
    class FakeWebSocket {
      constructor(url: string, protocols?: string | string[]) {
        calls.push([url, protocols]);
      }
    }

    createWebSocketConnection(
      'wss://agent.example.test/socket',
      ' token-value ',
      FakeWebSocket as unknown as typeof WebSocket,
    );

    expect(calls).toEqual([['wss://agent.example.test/socket', ['token-value']]]);
  });
});

describe('sendText reconnect dispatch', () => {
  it('opens one connection and sends the queued message only after it is open', () => {
    const sockets: FakeWebSocket[] = [];
    class FakeWebSocket {
      static readonly CLOSED = 3;
      static readonly CONNECTING = 0;
      static readonly OPEN = 1;

      readonly [Symbol.toStringTag] = 'WebSocket';

      onclose: ((event: CloseEvent) => void) | null = null;
      onerror: (() => void) | null = null;
      onmessage: ((event: MessageEvent) => void) | null = null;
      onopen: (() => void) | null = null;
      readyState = FakeWebSocket.CONNECTING;
      sent: string[] = [];

      constructor() {
        sockets.push(this);
      }

      close() {
        this.readyState = FakeWebSocket.CLOSED;
      }

      open() {
        this.readyState = FakeWebSocket.OPEN;
        this.onopen?.();
      }

      send(payload: string) {
        this.sent.push(payload);
      }
    }

    let nextId = 0;
    const session: ChatSession = {
      id: 'session-1',
      title: 'Test',
      createdAt: '',
      updatedAt: '',
      messages: [],
    };
    const addMessage = vi.fn((
      role: ChatMessage['role'],
      content: string,
      extra: Partial<ChatMessage> = {},
    ) => {
      const message: ChatMessage = {
        id: `local-${nextId += 1}`,
        role,
        content,
        time: '',
        ...extra,
      };
      session.messages.push(message);
      return message;
    });
    const storage = new Map<string, string>();
    vi.stubGlobal('WebSocket', FakeWebSocket);
    vi.stubGlobal('navigator', { onLine: true });
    vi.stubGlobal('document', {
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      visibilityState: 'visible',
    });
    vi.stubGlobal('localStorage', {
      getItem: (key: string) => storage.get(key) ?? null,
      removeItem: (key: string) => storage.delete(key),
      setItem: (key: string, value: string) => storage.set(key, value),
    });
    vi.stubGlobal('window', {
      addEventListener: vi.fn(),
      clearTimeout: vi.fn(),
      crypto: { randomUUID: () => `uuid-${nextId += 1}` },
      location: { protocol: 'http:' },
      removeEventListener: vi.fn(),
      setTimeout: vi.fn(() => 1),
    });
    vi.spyOn(console, 'warn').mockImplementation(() => undefined);

    const agent = useWebSocketAgent({
      activeSession: () => session,
      addMessage,
      saveSessions: vi.fn(),
      scheduleSaveSessions: vi.fn(),
      touchSession: vi.fn(),
    });
    const dispatched = vi.fn();

    expect(agent.sendText('send after reconnect', [], dispatched)).toBe(true);
    expect(sockets).toHaveLength(1);
    expect(session.messages.some((message) => message.role === 'user')).toBe(false);
    expect(dispatched).not.toHaveBeenCalled();

    sockets[0].open();

    expect(dispatched).toHaveBeenCalledOnce();
    expect(dispatched).toHaveBeenCalledWith(true);
    expect(session.messages.some((message) => (
      message.role === 'user' && message.content === 'send after reconnect'
    ))).toBe(true);
    expect(sockets[0].sent).toHaveLength(1);
    expect(JSON.parse(sockets[0].sent[0])).toMatchObject({
      content: [{ type: 'text', text: 'send after reconnect' }],
      sessionId: 'session-1',
      type: 'chat',
    });
  });
});

describe('memory history requests', () => {
  it('carries the active session id so the backend can filter by session', () => {
    const { agent, createSession, sendUserText, sockets, switchTo } = setupAgent();
    // 先发一条消息把连接建起来（测试里 window.setTimeout 被打桩，自动重连不会真的跑）。
    sendUserText('warmup');

    expect(agent.readMemory(20)).toBe(true);
    expect(JSON.parse(sockets[0].sent[sockets[0].sent.length - 1])).toMatchObject({
      type: 'memory',
      sessionId: 'session-1',
      content: { act: 'read', create_id: 0, length: 20 },
    });

    // 切到别的会话后必须换成新的 sessionId：后端 `go.php:863` 用它设置
    // `utils->session_id`，`process_memory` 的 read 再据此给 agent_memory 加会话过滤。
    // 漏掉就会返回**全局**历史，新建的会话就会看到旧会话的记录。
    createSession('session-2');
    switchTo('session-2');
    expect(agent.readMemory(20)).toBe(true);
    expect(JSON.parse(sockets[0].sent[sockets[0].sent.length - 1])).toMatchObject({
      sessionId: 'session-2',
    });
  });
});

describe('session rename requests', () => {
  it('puts sessionId at the top level and keeps only session_name in content', () => {
    const { agent, sendUserText, sockets } = setupAgent();
    sendUserText('warmup');

    expect(agent.renameSession('session-1', '我起的名字')).toBe(true);
    const payload = JSON.parse(sockets[0].sent[sockets[0].sent.length - 1]);
    // 目标会话 id 在**顶层**：后端 `process_memory($socket_id, $data_content, $session_id)`
    // 的第三个参数就是它。content 里只放 act 自己的参数。
    expect(payload).toMatchObject({
      type: 'memory',
      sessionId: 'session-1',
      content: { act: 'renameSession', session_name: '我起的名字' },
    });
    expect(payload.content).not.toHaveProperty('sessionId');
  });

  it('refuses to send an empty session id or an empty name', () => {
    const { agent, sendUserText, sockets } = setupAgent();
    sendUserText('warmup');
    const before = sockets[0].sent.length;

    expect(agent.renameSession('', '名字')).toBe(false);
    expect(agent.renameSession('   ', '名字')).toBe(false);
    expect(agent.renameSession('session-1', '   ')).toBe(false);
    expect(sockets[0].sent).toHaveLength(before);
  });
});

describe('session delete requests', () => {
  it('puts the target sessionId at the top level, not in content', () => {
    const { agent, sendUserText, sockets } = setupAgent();
    sendUserText('warmup');

    expect(agent.deleteSession('session-9')).toBe(true);
    const payload = JSON.parse(sockets[0].sent[sockets[0].sent.length - 1]);
    expect(payload).toMatchObject({
      type: 'memory',
      sessionId: 'session-9',
      content: { act: 'deleteSession' },
    });
    expect(payload.content).not.toHaveProperty('sessionId');
  });

  it('carries the active session id on readSession too', () => {
    const { agent, sendUserText, sockets } = setupAgent();
    sendUserText('warmup');

    expect(agent.readSessions()).toBe(true);
    // readSession 本身不按会话过滤，但顶层不带 sessionId 会把后端的
    // `utils->session_id` 清空，所以照样带上当前会话。
    expect(JSON.parse(sockets[0].sent[sockets[0].sent.length - 1])).toMatchObject({
      type: 'memory',
      sessionId: 'session-1',
      content: { act: 'readSession' },
    });
  });
});
describe('session isolation for broadcast events', () => {
  it.each(['sessionId', 'session_id'])('routes foreign turns using %s without changing the active conversation', (field) => {
    const { assistantOf, createSession, emit, sendUserText, session } = setupAgent();
    sendUserText('local question');
    const second = createSession('session-2');
    const before = session.messages.length;
    const route = { [field]: second.id, messageId: 'broadcast-turn' };
    emit({ ...route, type: 'content', data: 'remote answer' });
    emit({ ...route, type: 'think', data: 'remote thought' });
    emit({ ...route, type: 'tool_result', data: { content: 'ok' } });
    emit({ ...route, type: 'end' });
    expect(assistantOf('broadcast-turn', second.id)).toMatchObject({
      content: 'remote answer', think: 'remote thought', status: 'done',
    });
    expect(assistantOf('broadcast-turn', second.id)?.toolEvents).toHaveLength(1);
    expect(session.messages).toHaveLength(before);
    emit({ ...route, type: 'error', error: 'remote failure' });
    expect(second.messages.some((item) => item.role === 'error')).toBe(true);
    expect(session.messages).toHaveLength(before);
  });

  it('drops unknown sessions and unowned message IDs', () => {
    const { assistantOf, emit, sendUserText, session } = setupAgent();
    const localId = sendUserText('local');
    const before = session.messages.length;
    for (const route of [
      { sessionId: 'missing', messageId: 'foreign' },
      { messageId: 'unknown' },
      { sessionId: 'missing', messageId: localId },
    ]) {
      emit({ ...route, type: 'content', data: 'wrong' });
      emit({ ...route, type: 'error', error: 'wrong' });
      emit({ ...route, type: 'end' });
    }
    expect(session.messages).toHaveLength(before);
    expect(assistantOf(localId)?.content).toBe('');
    expect(assistantOf(localId)?.status).toBe('loading');
  });

  it('resolves missing message IDs only within the declared session', () => {
    const { agent, assistantOf, createSession, emit, sendUserText, switchTo } = setupAgent();
    const first = sendUserText('first');
    switchTo(createSession('session-2').id);
    const second = sendUserText('second');
    emit({ type: 'content', data: 'ambiguous' });
    emit({ type: 'end' });
    expect(agent.hasPendingTurns.value).toBe(true);
    emit({ type: 'content', sessionId: 'session-1', data: 'first answer' });
    emit({ type: 'end', sessionId: 'session-1' });
    expect(assistantOf(first, 'session-1')?.content).toBe('first answer');
    expect(assistantOf(second)?.content).toBe('');
    expect(assistantOf(second)?.status).toBe('loading');
  });

  it('rejects conflicting session IDs and keeps late events in their original session', () => {
    const { assistantOf, createSession, emit, sendUserText, switchTo } = setupAgent();
    const messageId = sendUserText('first');
    createSession('session-2');
    emit({ type: 'content', sessionId: 'session-2', messageId, data: 'wrong' });
    emit({ type: 'content', sessionId: 'session-1', messageId, data: 'answer' });
    emit({ type: 'end', sessionId: 'session-1', messageId });
    switchTo('session-2');
    emit({ type: 'content', messageId, data: ' late' });
    emit({ type: 'end', messageId });
    expect(assistantOf(messageId, 'session-1')?.content).toBe('answer late');
    expect(assistantOf(messageId, 'session-2')).toBeUndefined();
  });
});
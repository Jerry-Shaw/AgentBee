# 专家功能说明

## 功能概述

BeeWeb 现在支持专家对话的独立显示。当后端返回的消息包含 `isSubTalk: 1` 字段时，这些消息会被识别为专家的对话，不会显示在主聊天区域，而是显示在右侧的独立面板中。

## 后端协议扩展

### 新增字段

在原有的 WebSocket 协议基础上，服务端消息新增以下可选字段：

```typescript
{
  "type": "content",           // 或其他消息类型
  "messageId": "msg-xxx",
  "sessionId": "session-xxx",
  "data": "消息内容",
  "workerName": "greeter_worker",  // 必需，用于识别和分组专家
  "workerRole": "问候者",          // 可选，显示用
  
  // 新增字段
  "isSubTalk": 1               // 标记为专家消息（1表示是，0或不传表示否）
}
```

### 字段说明

- **isSubTalk**: `number` - 值为1时表示这是专家的消息，将不在主聊天区显示
- **workerName**: `string` - Worker的标识名称，用于区分不同的专家并分组显示
- **workerRole**: `string` - Worker的角色/显示名称（可选）

### 实际示例

#### 专家消息
```json
{
  "type": "end",
  "data": "",
  "sender": "Expert",
  "isSubTalk": 1,
  "workerName": "greeter_worker",
  "workerRole": "问候者",
  "sessionId": "61bfca547e4f81343b83ee256052c39d",
  "messageId": "greeter_worker-23322eb4be25523d7ad541d70e450ec0",
  "talk_count": 2,
  "socket_id": "sock_82"
}
```

#### 主Agent消息
```json
{
  "type": "content",
  "data": "\n",
  "sender": "WorkerMain",
  "isSubTalk": 0,
  "workerName": "AgentBee (蜂小秘)",
  "workerRole": "Assistant",
  "sessionId": "61bfca547e4f81343b83ee256052c39d",
  "messageId": "AgentBee (蜂小秘)-32783e454b29bd3ec31b9cad85328a81"
}
```

## 前端界面

### 主聊天区域

- 只显示 `isSubTalk` 不为1的消息
- 保持原有的消息渲染逻辑

### 右侧专家面板

#### 竖向Tab栏
- 位于右侧边缘
- 每个不同 `workerName` 的专家显示一个图标按钮
- 图标右上角显示该专家的消息数量徽章
- 支持鼠标悬浮和点击操作

#### 展开面板
- 点击Tab图标后，从右侧滑出展示面板
- 显示标题为该专家的 `workerName`
- 显示该专家的所有对话记录
- 支持消息的所有交互操作（复制、重发等）
- 点击关闭按钮可收起面板

### UI特性

1. **多专家支持**: 可以同时有多个专家活跃，每个都有独立的Tab
2. **消息统计**: 每个Tab显示该专家的消息总数
3. **独立交互**: 专家面板内的消息支持完整的交互功能
4. **响应式设计**: 面板宽度固定为420px，适配不同屏幕尺寸

## 使用场景

### 场景1: 问候Worker

主Agent处理用户查询时，可以调用问候Worker：

```
主聊天区:
用户: 你好
主Agent: 你好！欢迎使用AgentBee

右侧面板 [greeter_worker]:
greeter_worker: 准备问候语...
greeter_worker: 问候完成
```

### 场景2: 搜索Worker

```
主聊天区:
用户: 帮我查一下今天的天气
主Agent: 好的，让我为您查询...

右侧面板 [search_worker]:
search_worker: 正在连接天气API...
search_worker: 获取到当前位置: 北京
search_worker: 查询成功：晴天，25°C
```

### 场景3: 多步骤任务

```
主聊天区:
用户: 帮我准备一份报告
主Agent: 开始准备报告...

右侧面板 [data_collector]:
data_collector: 从数据库读取数据...
data_collector: 共获取500条记录

右侧面板 [report_generator]:
report_generator: 生成图表...
report_generator: 生成摘要...
report_generator: 报告已完成
```

## 实现细节

### 前端组件结构

```
App.vue
├── ChatMessage.vue (主聊天消息)
└── SubAgentPanel.vue (专家面板)
    ├── 竖向Tab栏
    └── 展开式消息面板
        └── ChatMessage.vue (专家消息)
```

### 数据流

1. WebSocket接收到带有 `isSubTalk: 1` 的消息
2. `useWebSocketAgent` 将 `isSubTalk` 字段附加到消息对象
3. 消息保存到会话的messages数组
4. `App.vue` 的 `visibleChatItems` 计算属性过滤掉专家消息
5. `subAgentMessages` 和 `subAgents` 计算属性提取专家相关数据
6. `SubAgentPanel` 组件根据 `workerName` 分组渲染专家界面

### 消息分组逻辑

前端根据 `workerName` 字段对专家消息进行分组：

```javascript
const subAgents = computed(() => {
  const agents = new Map<string, { name: string; count: number }>();
  subAgentMessages.value.forEach((msg) => {
    const name = msg.senderName; // 即 workerName
    if (name) {
      const existing = agents.get(name);
      if (existing) {
        existing.count += 1;
      } else {
        agents.set(name, { name, count: 1 });
      }
    }
  });
  return Array.from(agents.values());
});
```

### 存储

- 专家消息与普通消息一起保存在 `localStorage`
- 通过 `isSubTalk` 字段区分消息类型
- 刷新页面后专家状态会恢复

## 兼容性

- 向后兼容：不发送 `isSubTalk` 字段的消息会正常显示在主聊天区
- 渐进增强：后端可以逐步采用此功能，不影响现有功能
- 旧数据兼容：历史会话中没有 `isSubTalk` 字段的消息会正常显示

## 后端实现建议

### Python示例
```python
# 专家消息
message = {
    "type": "content",
    "sessionId": session_id,
    "messageId": message_id,
    "data": "专家消息内容",
    "workerName": "my_worker",
    "workerRole": "我的助手",
    "isSubTalk": 1  # 标记为专家
}
ws.send(json.dumps(message))

# 主Agent消息
message = {
    "type": "content",
    "sessionId": session_id,
    "messageId": message_id,
    "data": "主Agent消息内容",
    "workerName": "AgentBee",
    "workerRole": "Assistant",
    "isSubTalk": 0  # 或不传此字段
}
ws.send(json.dumps(message))
```

### Node.js示例
```javascript
// 专家消息
ws.send(JSON.stringify({
  type: 'content',
  sessionId: sessionId,
  messageId: messageId,
  data: '专家消息内容',
  workerName: 'my_worker',
  workerRole: '我的助手',
  isSubTalk: 1
}));

// 主Agent消息
ws.send(JSON.stringify({
  type: 'content',
  sessionId: sessionId,
  messageId: messageId,
  data: '主Agent消息内容',
  workerName: 'AgentBee',
  workerRole: 'Assistant',
  isSubTalk: 0  // 或不传此字段
}));
```

## 样式定制

如需调整专家面板的样式，可以修改 `SubAgentPanel.vue` 中的样式变量：

```css
.subagent-panel {
  width: 420px;  /* 面板宽度 */
}

.subagent-tab {
  width: 44px;   /* Tab按钮大小 */
  height: 44px;
}
```

## 注意事项

1. **workerName的重要性**: 前端使用 `workerName` 字段来分组和识别不同的专家，确保相同专家的消息使用相同的 `workerName`
2. **消息类型支持**: 所有消息类型（content、status、think、tool_calls等）都支持添加 `isSubTalk` 字段
3. **性能考虑**: 大量专家消息可能影响渲染性能，建议合理控制消息数量

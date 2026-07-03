# Hyperf Scheduled Task

這是一個為 Hyperf 框架設計的**定時任務基礎表結構 Package**，提供兩個核心資料表：

- `scheduled_tasks`：排程任務設定表
- `task_execution_logs`：任務執行記錄表（支援毫秒精度）

---

## 安裝方式

```bash
composer require chep6915/hyperf-scheduled-task
# Hyperf Scheduled Task

<p align="center">
    <img src="https://img.shields.io/badge/PHP-8.1+-blue.svg" alt="PHP Version">
    <img src="https://img.shields.io/badge/Hyperf-3.0+-brightgreen.svg" alt="Hyperf Version">
    <img src="https://img.shields.io/badge/License-MIT-green.svg" alt="License">
</p>

一個為 Hyperf 框架設計的**高性能定時任務排程系統**，採用 Producer-Consumer 架構，支援秒級精度的任務調度。

## 📋 目錄

- [功能特色](#功能特色)
- [系統需求](#系統需求)
- [安裝方式](#安裝方式)
- [快速開始](#快速開始)
- [使用指南](#使用指南)
- [進階配置](#進階配置)
- [架構說明](#架構說明)
- [資料表結構](#資料表結構)
- [常見問題](#常見問題)
- [注意事項](#注意事項)

## ✨ 功能特色

- ✅ **Producer-Consumer 架構**：自動預產生未來 4 小時的待執行任務，提升執行效率
- ✅ **秒級精度排程**：支援 6 欄位 Cron 表達式（含秒），最小粒度 1 秒
- ✅ **完整狀態管理**：Pending → Running → Success/Failed 完整生命週期追蹤
- ✅ **樂觀鎖機制**：防止多進程環境下任務重複執行
- ✅ **執行時間記錄**：自動記錄任務開始、結束時間及執行耗時
- ✅ **結果日誌保存**：完整保存任務執行結果或錯誤訊息
- ✅ **手動產生命令**：支援指定時間手動產生任務紀錄，方便測試和補單
- ✅ **依賴注入支援**：任務類別支援建構函數注入，整合 Hyperf 容器
- ✅ **防重複設計**：唯一索引確保同一任務在同一秒不會重複產生
- ✅ **異常處理機制**：完善的錯誤捕獲和狀態更新

## 📦 系統需求

- PHP >= 8.1
- Hyperf >= 3.0
- MySQL >= 5.7 或 MariaDB >= 10.2
- Swoole >= 5.0

## 核心組件

| 組件 | 類型 | 說明 |
|------|------|------|
| `scheduled_tasks` | 資料表 | 排程任務設定表，儲存任務類別和 Cron 表達式 |
| `task_execution_logs` | 資料表 | 任務執行記錄表，追蹤每次執行狀態 |
| `ScheduledTaskProducerProcess` | 常駐進程 | 每 30 秒預產生未來 4 小時的 Pending 任務 |
| `ScheduledTaskConsumerProcess` | 常駐進程 | 每秒掃描並執行到期的 Pending 任務 |
| `GeneratePendingRecordsCommand` | CLI 命令 | 手動產生任務紀錄，支援指定時間 |
| `TaskExecutionStatus` | Enum | 任務狀態枚舉類別 |

---

## 安裝方式

### 1. 安裝套件

```bash
composer require chep6915/hyperf-scheduled-task
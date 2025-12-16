# WDS 数据优化方案 - 快速实施指南

**版本**: 1.0 | **创建日期**: 2025-12-16

---

## 📋 实施前检查清单

- [ ] 已备份数据库
- [ ] 已备份现有JSON文件
- [ ] 确认PHP版本 >= 8.2
- [ ] 确认MariaDB/MySQL版本 >= 10.5
- [ ] 确认有tar命令（`which tar`）
- [ ] 确认web服务器用户有写权限

---

## 🚀 5分钟快速部署

### 步骤1: 执行数据库脚本（2分钟）

```bash
cd /home/user/wdsxxx

# 执行优化表创建
mysql -u mhdlmskp2kpxguj -p < docs/wds_optimization_tables.sql

# 验证表创建
mysql -u mhdlmskp2kpxguj -p -e "SHOW TABLES LIKE 'wds_%archive%'" mhdlmskp2kpxguj
```

**预期输出**:
```
+--------------------------------------+
| Tables_in_mhdlmskp2kpxguj (wds_%archive%) |
+--------------------------------------+
| wds_archive_history                  |
| wds_db_archive_log                   |
| wds_monthly_archives                 |
| wds_weather_hourly_forecast_archive  |
+--------------------------------------+
```

### 步骤2: 验证代码部署（1分钟）

```bash
# 检查新增文件
ls -l app/wds/src/maintenance/
# 应该看到: monthly_archiver.php, db_archiver.php

# 检查目录创建
ls -ld app/wds/storage/raw/archives
# 应该看到: drwxr-xr-x
```

### 步骤3: 测试功能（2分钟）

访问测试页面查看当前状态：
```
http://yourdomain.com/wds/console/test_optimization.php?action=status
```

**预期看到**:
- 数据库总大小
- 热表行数
- JSON文件数量统计

---

## ✅ 验证部署成功

### 测试1: API响应正常

```bash
curl -H "Authorization: Bearer 3UsMvup5VdFWmFw7UcyfXs5FRJNumtzdqabS5Eepdzb77pWtUBbjGgc" \
     "http://yourdomain.com/wds/api/auto_collect.php"
```

**预期**: 返回JSON响应，包含 `"ok": true`

### 测试2: 智能回填工作

等待01:15时间槽触发，或手动测试：

访问: `http://yourdomain.com/wds/console/test_optimization.php?action=status`

查看数据库：
```sql
SELECT * FROM wds_archive_history ORDER BY created_at DESC LIMIT 1;
```

### 测试3: 归档功能正常

手动触发测试：
```
http://yourdomain.com/wds/console/test_optimization.php?action=test_monthly&month=2024-11
```

**预期**: 看到压缩文件生成和统计信息

---

## 📊 优化效果预测

基于您当前的5个活跃地点，预计效果：

| 指标 | 优化前 | 优化后 | 改善 |
|------|--------|--------|------|
| **月新增JSON文件** | 900个 | 100个 | ↓ 89% |
| **年JSON文件总数** | 10,800个 | 1,200个 | ↓ 89% |
| **数据库年增长** | 360 MB | 120 MB | ↓ 67% |
| **JSON年增长** | 300 MB | 60 MB | ↓ 80% |

---

## 🔄 自动化运行机制

优化方案完全自动化，无需人工干预：

### 每天01:15执行（自动）

```
✓ 拉取16天预报数据
✓ 智能回填最近2天历史数据（跳过已存在）
✓ 检查数据库行数，超过10万行自动归档到冷表
```

### 每月1日01:15额外执行（自动）

```
✓ 压缩上月预报JSON → forecast_YYYY-MM.tar.gz
✓ 压缩上月历史JSON → archive_YYYY-MM.tar.gz
✓ 生成索引文件
✓ 删除2个月前原始JSON文件
```

---

## 🛠️ 日常运维

### 查看系统状态
```
http://yourdomain.com/wds/console/test_optimization.php?action=status
```

### 查看详细统计
```
http://yourdomain.com/wds/console/test_optimization.php?action=stats
```

### 手动触发归档（如需要）
```
http://yourdomain.com/wds/console/test_optimization.php?action=test_monthly&month=YYYY-MM
```

---

## 📂 重要文件位置

| 文件 | 路径 |
|------|------|
| **完整系统文档** | `/docs/WDS_OPTIMIZATION_SYSTEM_DOCUMENTATION.md` |
| **数据库表SQL** | `/docs/wds_optimization_tables.sql` |
| **测试页面** | `/dc_html/wds/console/test_optimization.php` |
| **配置文件** | `/app/wds/config_wds/env_wds.php` |
| **归档文件** | `/app/wds/storage/raw/archives/` |

---

## ⚠️ 注意事项

### ✅ 正确的做法

- ✅ 让系统自动运行，定期检查状态即可
- ✅ 每周查看一次测试页面，确认归档正常
- ✅ 每月1日查看归档日志，验证压缩成功
- ✅ 需要查询历史数据时使用视图 `vw_weather_forecast_all`

### ❌ 错误的做法

- ❌ 手动删除数据库表数据
- ❌ 直接删除JSON文件（应等待自动清理）
- ❌ 修改归档配置后不测试
- ❌ 归档失败后不查看日志

---

## 🆘 遇到问题？

### 问题1: 归档没有自动执行

**检查**:
```bash
# 查看API调用日志
tail -f /var/log/wds/auto_collect.log

# 检查01:15是否被调用
grep "01:15" /var/log/wds/auto_collect.log
```

### 问题2: 压缩文件生成失败

**检查**:
```bash
# 查看权限
ls -ld /home/user/wdsxxx/app/wds/storage/raw/archives

# 查看磁盘空间
df -h

# 手动测试tar命令
tar --version
```

### 问题3: 数据库归档慢

**优化**:
```sql
-- 检查热表大小
SELECT COUNT(*) FROM wds_weather_hourly_forecast;

-- 如果超过50万行，考虑分批归档
```

---

## 📞 获取帮助

1. **查看完整文档**: `/docs/WDS_OPTIMIZATION_SYSTEM_DOCUMENTATION.md`
2. **故障排查章节**: 文档第11章
3. **性能优化**: 文档第12章

---

## 🎉 部署完成！

如果所有测试通过，恭喜您成功部署了WDS数据优化方案！

系统现在将：
- ✅ 自动智能回填，避免重复JSON
- ✅ 每月自动归档压缩，节省空间
- ✅ 数据库冷热分离，保持查询性能
- ✅ 零数据删除，长期保留所有数据

**享受优化后的系统吧！** 🚀

---

**版本**: 1.0 | **最后更新**: 2025-12-16

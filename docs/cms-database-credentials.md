# CMS 翻译密钥入库（简化版）

## 行为

- 仅新增 `cms.translation_settings.api_key_ciphertext text NULL`。
- 密文非 NULL 时使用数据库密钥；解密失败直接报错，不回退到其他计费密钥。
- 密文为 NULL 时沿用 `GOOGLE_TRANSLATE_API_KEY`。
- 输入框留空保留原密钥；非空时测试成功后加密保存。测试连接不保存。
- 接口仅返回是否配置、来源和脱敏末四位，不回传明文或密文。
- 平台后台维持平台统一配置；白标后台按所属内容空间配置。白标不能访问其他空间的密钥。
- platform worker 按任务所属配置选择密钥，统一执行平台和白标任务；agent 不新增重复调度。

## 部署顺序（尚未执行）

1. 备份 CMS 数据库；在 platform 的共享 CMS 数据库执行一次迁移：

   ```sh
   php artisan migrate --path=database/migrations/2026_11_03_235000_add_cms_translation_api_key_ciphertext.php
   ```

   核心变更仅为：

   ```sql
   ALTER TABLE cms.translation_settings
       ADD COLUMN IF NOT EXISTS api_key_ciphertext text NULL;
   ```

2. 通过安全的部署密钥管理渠道生成并配置同一份 `CMS_TRANSLATION_ENCRYPTION_KEY`，格式为 `base64:` 加 32 个随机字节的 Base64 编码。agent、platform Web 服务及 platform 翻译 worker 必须一致。不要修改 APP_KEY，不要提交或打印密钥。持久保管主密钥；直接替换会使已有密文无法解密。
3. 发布本次 6mm-php、6mm-ui，并更新两个宿主依赖。当前工作区为本地联调，Composer 使用 path 仓库，npm 使用 file 依赖；独立部署前须发布共享包并换成发布版本，或确保部署目录完整包含共享库及已构建 UI。不能仅部署宿主而遗漏共享包。
4. 更新两套后台的配置缓存，并重启 platform 翻译 worker，使专用密钥和新代码生效。这些运行操作本次未执行。
5. 在翻译配置中填写 Google API Key，测试连接并保存。此步骤会访问 Google，可能产生计费；本次测试全部使用模拟 HTTP，未调用 Google。

现有数据库配置无需回填；NULL 保留旧环境行为。若旧 Google 环境密钥在两套服务中不同，platform 统一 worker 会使用 platform 环境密钥；应为白标保存数据库密钥，确保测试与实际任务一致。

## 回滚与验证

迁移禁止自动 down 删除密文。回滚前先停翻译 worker、保留密文字段和主密钥，避免旧代码改用环境密钥计费。

已验证加密保存、脱敏、空值保留、候选密钥测试不落库、失败不覆盖、版本冲突、错误主密钥不回退、平台及两个白标任务密钥隔离、请求日志脱敏，以及共享配置弹窗输入/保存/关闭清理。数据库测试使用临时 PostgreSQL，不涉及业务库。

共享 UI 构建与类型检查、platform 前端类型检查通过。agent 全量类型检查未通过，报错位于 editor、export-data、agent_margin、decoration、prediction/grid 等非本次修改文件；未声称 agent 全量检查通过。

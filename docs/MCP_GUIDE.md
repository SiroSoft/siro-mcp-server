# MCP Server — Hướng dẫn sử dụng

> **Siro MCP Server** cho phép AI agents (Claude, GPT, Cursor, VS Code, OpenCode)
> tương tác trực tiếp với project SiroPHP — đọc code, phân tích kiến trúc, scaffold CRUD, chạy CLI.

---

## 1. Cài đặt

```bash
composer require sirosoft/mcp-server
```

> Yêu cầu PHP 8.2+ và `sirosoft/core ^0.35.0`.

---

## 2. VS Code

### Cách 1: Dùng extension mặc định (VS Code 1.90+)

Tạo file `.vscode/mcp.json` trong thư mục project:

```json
{
  "servers": {
    "siro": {
      "type": "stdio",
      "command": "php",
      "args": ["siro", "mcp:serve"],
      "cwd": "${workspaceFolder}"
    }
  }
}
```

Sau đó mở VS Code Palette (`Ctrl+Shift+P`) → **MCP: List Servers** để kiểm tra kết nối.

### Cách 2: Dùng Claude extension

Cài extension **Claude for VS Code** → Settings → MCP Servers → Add:

```json
{
  "command": "php",
  "args": ["siro", "mcp:serve"]
}
```

---

## 3. OpenCode

Tạo file `.opencode.json` trong thư mục project (hoặc `~/.config/opencode/config.json` cho global):

```json
{
  "mcpServers": {
    "siro": {
      "command": "php",
      "args": ["siro", "mcp:serve"],
      "env": {}
    }
  }
}
```

Sau đó khởi động lại OpenCode, AI agent sẽ có quyền truy cập toàn bộ project SiroPHP.

---

## 4. Claude Desktop

Sửa file `claude_desktop_config.json` (Windows: `%APPDATA%\Claude\claude_desktop_config.json`, macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`):

```json
{
  "mcpServers": {
    "siro": {
      "command": "php",
      "args": ["siro", "mcp:serve"],
      "cwd": "/path/to/your-project"
    }
  }
}
```

---

## 5. Cursor

**Settings → Cursor Settings → MCP Servers → Add New:**

| Field | Value |
|---|---|
| Name | `siro` |
| Type | `command` |
| Command | `php` |
| Arguments | `siro mcp:serve` |

Hoặc tạo `.cursor/mcp.json`:

```json
{
  "mcpServers": {
    "siro": {
      "command": "php",
      "args": ["siro", "mcp:serve"]
    }
  }
}
```

---

## 6. Tools (AI có thể làm gì?)

Sau khi kết nối, AI agent có thể:

### Phân tích project
```
"Phân tích kiến trúc project này"
→ AI đọc routes, models, controllers qua siro://app/* resources
```

### Scaffold CRUD
```
"Tạo CRUD cho bảng categories"
→ AI gọi scaffold_resource với các tham số
```

### Debug
```
"Kiểm tra trace lỗi mới nhất"
→ AI đọc siro://debug/errors/latest
```

### Chạy CLI
```
"Chạy migration"
→ AI gọi execute_cli với lệnh php siro migrate --force
```

### Danh sách tools đầy đủ

| Tool | Mô tả | Ví dụ AI prompt |
|---|---|---|
| `analyze_project` | Phân tích toàn bộ project | "Phân tích project này có những gì" |
| `read_documentation` | Đọc docs SiroPHP | "Cách dùng query builder" |
| `execute_cli` | Chạy CLI command (whitelist) | "Chạy php siro migrate" |
| `write_file` | Ghi file mới | "Tạo file config/mail.php" |
| `patch_file` | Sửa file có sẵn | "Thêm validate cho field email" |
| `scaffold_model` | Tạo Model | "Tạo Model Product với fillable name,price" |
| `scaffold_controller` | Tạo Controller | "Tạo ProductController với CRUD" |
| `scaffold_migration` | Tạo Migration | "Tạo migration create_products_table" |
| `scaffold_resource` | Full CRUD (model + migration + controller + routes) | "Tạo CRUD cho categories" |

---

## 7. Resources (AI có thể đọc gì?)

| URI | Nội dung |
|---|---|
| `siro://docs/*` | Tài liệu framework (15+ chủ đề) |
| `siro://app/routes` | Danh sách API routes |
| `siro://app/models` | Models với fillable, casts, relations |
| `siro://app/controllers` | Controllers với methods |
| `siro://app/services` | Service layer |
| `siro://app/middleware` | Middleware |
| `siro://app/structure` | Cấu trúc thư mục |
| `siro://app/config` | Config |
| `siro://app/openapi` | OpenAPI spec |
| `siro://debug/traces/latest` | Trace request gần nhất |
| `siro://debug/errors/latest` | Error gần nhất |

---

## 8. Security

- **Path traversal**: Mọi file operation đều được check không thoát khỏi project root
- **CLI whitelist**: Chỉ cho phép ~40 command an toàn
- **Blocked**: `tinker`, `shell`, `exec`, `eval`, `system`, `passthru`
- **Destructive**: `migrate`, `db:seed` yêu cầu `--force`

---

## 9. Ví dụ workflow hoàn chỉnh

### AI scaffold cả tính năng mới

```
User: "Thêm tính năng quản lý danh mục (categories)"

AI:
1. read_documentation("CRUD") → đọc cách tạo CRUD
2. scaffold_resource("category", "name:string,slug:string,description:text") 
   → tạo Model + Migration + Controller + Routes
3. execute_cli("php siro migrate --force")
   → chạy migration
4. Đọc siro://app/routes → kiểm tra routes đã được thêm
```

### AI debug lỗi

```
User: "API trả về 500, check giúp tôi"

AI:
1. Đọc siro://debug/errors/latest → xem error message
2. Đọc file báo lỗi → phân tích nguyên nhân
3. patch_file → sửa lỗi
4. execute_cli("php siro api:test POST /api/products")
   → kiểm tra lại
```

---

## 10. Troubleshooting

| Vấn đề | Giải pháp |
|---|---|
| `Connection refused` | Chạy `php siro mcp:serve` thủ công để kiểm tra |
| `Tool not found` | Update `composer require sirosoft/mcp-server` lên mới nhất |
| `Permission denied` | Kiểm tra PHP có quyền đọc/ghi project |
| AI không thấy tools | Khởi động lại AI client sau khi kết nối MCP |

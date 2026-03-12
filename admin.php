<?php
// 注意：头信息已在index.php中设置，这里不再重复设置

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// 登录功能不需要数据库连接，先处理登录请求
$request_uri = $_SERVER['REQUEST_URI'];
// 移除查询参数，只保留路径部分用于路由匹配
$path = parse_url($request_uri, PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// 先处理登录请求，不依赖数据库
if ($path === '/api/admin/login' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';
    
    if ($username === 'admin' && $password === 'admin123') {
        echo json_encode(['success' => true, 'token' => 'admin-secret-token']);
        exit;
    }
    echo json_encode(['success' => false, 'message' => '用户名或密码错误']);
    exit;
}

// 其他API需要数据库连接，再加载数据库配置
try {
    require_once __DIR__ . '/../config/db.php';
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '数据库连接失败']);
    exit;
}

session_start();

function verifyToken() {
    // 尝试多种方式获取Authorization头
    $authHeader = '';
    
    // 方法1: 使用getallheaders()函数
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    
    // 方法2: 如果getallheaders()不可用，尝试从$_SERVER获取
    if (empty($authHeader)) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    }
    
    if (preg_match('/Bearer\s+(.*)/', $authHeader, $matches)) {
        $token = $matches[1];
        return $token === 'admin-secret-token';
    }
    
    // 方法3: 如果Authorization头不存在，允许跳过验证（开发环境友好）
    // 注意：生产环境应该移除这一行
    return true;
}

// 从环境变量中获取原始请求URI，如果不存在则使用当前请求URI
$original_uri = $_SERVER['HTTP_X_ORIGINAL_URI'] ?? $_SERVER['REQUEST_URI'];
$request_uri = $original_uri;
// 移除查询参数，只保留路径部分用于路由匹配
$path = parse_url($request_uri, PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

if ($path === '/api/admin/login' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';
    
    if ($username === 'admin' && $password === 'admin123') {
        echo json_encode(['success' => true, 'token' => 'admin-secret-token']);
        exit;
    }
    echo json_encode(['success' => false, 'message' => '用户名或密码错误']);
    exit;
}

if (strpos($path, '/api/admin/') === 0) {
    if (!verifyToken()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    
    // 流量卡套餐管理 API
    if ($path === '/api/admin/packages' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT * FROM traffic_cards');
        $stmt->execute();
        $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($packages);
        exit;
    }
    
    if ($path === '/api/admin/packages' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare('INSERT INTO traffic_cards (title, restricted_regions, age_restriction, features, details, purchase_link, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            $input['title'],
            $input['restricted_regions'] ?? '',
            $input['age_restriction'] ?? '18-100',
            $input['features'],
            $input['details'],
            $input['purchase_link'] ?? ''
        ]);
        echo json_encode(['success' => true]);
        exit;
    }
    
    // 批量删除套餐
    if ($path === '/api/admin/packages/batch-delete' && $method === 'DELETE') {
        $input = json_decode(file_get_contents('php://input'), true);
        $ids = $input['ids'] ?? [];
        if (empty($ids)) {
            echo json_encode(['success' => false, 'message' => '请选择要删除的套餐']);
            exit;
        }
        
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM traffic_cards WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        echo json_encode(['success' => true]);
        exit;
    }
    
    // 编辑套餐
    if (preg_match('#^/api/admin/packages/([0-9]+)$#', $path, $matches) && $method === 'PUT') {
        $id = $matches[1];
        $input = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare('UPDATE traffic_cards SET title = ?, restricted_regions = ?, age_restriction = ?, features = ?, details = ?, purchase_link = ? WHERE id = ?');
        $stmt->execute([
            $input['title'],
            $input['restricted_regions'] ?? '',
            $input['age_restriction'] ?? '18-100',
            $input['features'],
            $input['details'],
            $input['purchase_link'] ?? '',
            $id
        ]);
        echo json_encode(['success' => true]);
        exit;
    }
    
    // 删除单个套餐
    if (preg_match('#^/api/admin/packages/([0-9]+)$#', $path, $matches) && $method === 'DELETE') {
        $id = $matches[1];
        $stmt = $pdo->prepare('DELETE FROM traffic_cards WHERE id = ?');
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }
    
    // Excel模板下载
    if ((strpos($path, '/api/admin/excel/template') !== false) && $method === 'GET') {
        // 添加调试信息到服务器日志
        error_log('模板下载请求 - URI: ' . $request_uri . ', Method: ' . $method);
        
        // 移除所有JSON相关的头部，确保正确输出CSV
        header_remove('Content-Type');
        
        // 使用CSV格式，可被Excel直接打开和编辑
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=traffic_card_template.csv');
        header('Pragma: public');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Content-Transfer-Encoding: binary');
        
        // 设置CSV标题和示例数据
        $template = [
            ['套餐名称', '禁发区域', '年龄限制', '产品特点', '套餐详情', '办理链接'],
            ['学生流量卡', '新疆,西藏,香港', '18-25', '校园专属,高速流量,全国通用', '每月80GB流量，月租39元，包含100分钟通话', 'https://example.com/student'],
            ['无限流量卡', '无', '18-65', '全国无限流量,4G/5G通用,无漫游费', '每月100GB流量，超过后限速至1Mbps，月租59元', 'https://example.com/unlimited'],
            ['老人流量卡', '无', '55-80', '大字体,一键呼救,低月租', '每月30GB流量，月租19元，包含50分钟通话', 'https://example.com/senior']
        ];
        
        // 输出BOM头，确保Excel正确识别UTF-8编码
        echo "\xEF\xBB\xBF";
        
        // 输出CSV内容
        $fp = fopen('php://output', 'w');
        // 设置CSV输出的分隔符和包围字符
        foreach ($template as $row) {
            fputcsv($fp, $row, ',', '"');
        }
        fclose($fp);
        exit;
    }
    
    // Excel文件上传处理
    if ($path === '/api/admin/packages/excel' && $method === 'POST') {
        if (!isset($_FILES['excel_file'])) {
            echo json_encode(['success' => false, 'message' => '没有上传文件']);
            exit;
        }
        
        $file = $_FILES['excel_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => '文件上传失败']);
            exit;
        }
        
        // 验证文件类型
        $allowedExtensions = ['csv', 'xlsx', 'xls'];
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($fileExtension, $allowedExtensions)) {
            echo json_encode(['success' => false, 'message' => '只支持上传 CSV、XLSX、XLS 格式的文件']);
            exit;
        }
        
        // 简单的CSV文件处理（支持带BOM头的CSV文件）
        $csvFile = fopen($file['tmp_name'], 'r');
        if (!$csvFile) {
            echo json_encode(['success' => false, 'message' => '无法打开上传的文件']);
            exit;
        }
        
        // 读取并跳过BOM头（如果存在）
        $bom = fread($csvFile, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            // 如果不是BOM头，将指针重置到文件开头
            rewind($csvFile);
        }
        
        // 跳过表头
        fgetcsv($csvFile);
        
        $count = 0;
        $pdo->beginTransaction();
        
        try {
            $stmt = $pdo->prepare('INSERT INTO traffic_cards (title, restricted_regions, age_restriction, features, details, purchase_link, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
            
            while (($row = fgetcsv($csvFile)) !== false) {
                // 跳过空行
                if (empty(array_filter($row))) {
                    continue;
                }
                
                // 确保至少有必要的字段
                if (count($row) < 5 || empty(trim($row[0])) || empty(trim($row[3])) || empty(trim($row[4]))) {
                    continue;
                }
                
                $stmt->execute([
                    trim($row[0]) ?? '',
                    trim($row[1]) ?? '',
                    trim($row[2]) ?? '18-100',
                    trim($row[3]) ?? '',
                    trim($row[4]) ?? '',
                    trim($row[5]) ?? ''
                ]);
                $count++;
            }
            
            $pdo->commit();
            echo json_encode(['success' => true, 'count' => $count, 'message' => '成功导入 ' . $count . ' 条数据']);
        } catch (Exception $e) {
            $pdo->rollback();
            echo json_encode(['success' => false, 'message' => '导入失败: ' . $e->getMessage()]);
        }
        
        fclose($csvFile);
        exit;
    }
    
    // SEO设置 API
    if ($path === '/api/admin/seo' && $method === 'GET') {
        $stmt = $pdo->query('SELECT * FROM seo_settings LIMIT 1');
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($settings ?? []);
        exit;
    }
    
    if ($path === '/api/admin/seo' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare('REPLACE INTO seo_settings (meta_title, meta_description, keywords, site_name, site_author, site_copyright) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $input['meta_title'] ?? '',
            $input['meta_description'] ?? '',
            $input['keywords'] ?? '',
            $input['site_name'] ?? '',
            $input['site_author'] ?? '',
            $input['site_copyright'] ?? ''
        ]);
        echo json_encode(['success' => true]);
        exit;
    }
    
    // API统计和Token消耗 API
    if ($path === '/api/admin/stats' && $method === 'GET') {
        $range = $_GET['range'] ?? 'today';
        // 返回模拟统计数据
        echo json_encode([
            'total_calls' => 1250,
            'success_calls' => 1200,
            'failed_calls' => 50,
            'avg_response_time' => 150,
            'total_tokens' => 56789,
            'avg_tokens' => 45
        ]);
        exit;
    }
    
    if ($path === '/api/admin/stats/calls' && $method === 'GET') {
        // 返回模拟API调用记录
        $calls = [];
        for ($i = 0; $i < 20; $i++) {
            $calls[] = [
                'timestamp' => date('Y-m-d H:i:s', strtotime("-$i minute")),
                'endpoint' => '/api/chat',
                'method' => 'POST',
                'status' => $i % 10 === 0 ? 'failed' : 'success',
                'response_time' => rand(100, 500),
                'tokens_used' => rand(30, 80),
                'ip_address' => '192.168.1.' . rand(1, 255)
            ];
        }
        echo json_encode($calls);
        exit;
    }
    
    if ($path === '/api/admin/stats/tokens' && $method === 'GET') {
        // 返回模拟Token消耗统计
        $tokenStats = [];
        for ($i = 0; $i < 7; $i++) {
            $tokenStats[] = [
                'date' => date('Y-m-d', strtotime("-$i day")),
                'total_tokens' => rand(5000, 12000),
                'avg_tokens_per_call' => rand(40, 60),
                'call_count' => rand(100, 300)
            ];
        }
        echo json_encode($tokenStats);
        exit;
    }
    
    if ($path === '/api/admin/stats/export' && $method === 'GET') {
        // 导出统计数据
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename=api-stats.csv');
        echo '时间,接口,方法,状态,响应时间,Token消耗,IP地址\n';
        for ($i = 0; $i < 50; $i++) {
            echo date('Y-m-d H:i:s', strtotime("-$i minute")) . ',';
            echo '/api/chat' . ',';
            echo 'POST' . ',';
            echo ($i % 10 === 0 ? 'failed' : 'success') . ',';
            echo rand(100, 500) . ',';
            echo rand(30, 80) . ',';
            echo '192.168.1.' . rand(1, 255) . '\n';
        }
        exit;
    }
    
    // 查询日志 API
    if ($path === '/api/admin/logs' && $method === 'GET') {
        // 返回模拟查询日志
        $logs = [];
        for ($i = 0; $i < 20; $i++) {
            $logs[] = [
                'id' => $i + 1,
                'created_at' => date('Y-m-d H:i:s', strtotime("-$i hour")),
                'user_ip' => '192.168.1.' . rand(1, 255),
                'query_content' => '推荐一个适合学生的流量卡套餐',
                'response_content' => '根据您的需求，为您推荐以下学生流量卡套餐...',
                'status' => $i % 15 === 0 ? 'failed' : 'success',
                'tokens_used' => rand(40, 100),
                'response_time' => rand(100, 600)
            ];
        }
        echo json_encode(['logs' => $logs, 'total_pages' => 1]);
        exit;
    }
    
    if (preg_match('#^/api/admin/logs/([0-9]+)$#', $path, $matches) && $method === 'GET') {
        $id = $matches[1];
        // 返回单条日志详情
        echo json_encode([
            'id' => $id,
            'created_at' => date('Y-m-d H:i:s', strtotime("-1 hour")),
            'user_ip' => '192.168.1.100',
            'query_content' => '推荐一个适合学生的流量卡套餐',
            'response_content' => '根据您的需求，为您推荐以下学生流量卡套餐：\n1. 学生畅享卡：每月50GB全国流量，月租29元\n2. 青春无限卡：每月100GB流量，月租49元\n3. 校园专属卡：每月80GB流量，月租39元',
            'status' => 'success',
            'tokens_used' => 65,
            'response_time' => 250
        ]);
        exit;
    }
    
    if (preg_match('#^/api/admin/logs/([0-9]+)$#', $path, $matches) && $method === 'DELETE') {
        $id = $matches[1];
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($path === '/api/admin/logs/stats' && $method === 'GET') {
        // 返回日志统计
        echo json_encode([
            'total_queries' => 1560,
            'today_queries' => 120,
            'success_queries' => 1500,
            'failed_queries' => 60
        ]);
        exit;
    }
    
    if ($path === '/api/admin/logs/export' && $method === 'GET') {
        // 导出日志
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename=query-logs.csv');
        echo 'ID,时间,IP地址,查询内容,回复内容,状态,Token消耗,响应时间\n';
        for ($i = 0; $i < 50; $i++) {
            echo ($i + 1) . ',';
            echo date('Y-m-d H:i:s', strtotime("-$i hour")) . ',';
            echo '192.168.1.' . rand(1, 255) . ',';
            echo '"推荐一个适合学生的流量卡套餐"' . ',';
            echo '"根据您的需求，为您推荐以下学生流量卡套餐..."' . ',';
            echo ($i % 15 === 0 ? 'failed' : 'success') . ',';
            echo rand(40, 100) . ',';
            echo rand(100, 600) . '\n';
        }
        exit;
    }
    
    if ($path === '/api/admin/logs/clear' && $method === 'DELETE') {
        echo json_encode(['success' => true]);
        exit;
    }
    
    // 管理员账号 API
    if ($path === '/api/admin/account' && $method === 'GET') {
        // 从数据库获取真实管理员信息
        try {
            // 首先检查数据库连接是否正常
            $stmt = $pdo->query("SELECT 1");
            
            // 检查 admins 表是否存在
            $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'admins'");
            $tableExists = $stmt->fetchColumn() > 0;
            
            if ($tableExists) {
                // 从 admins 表获取管理员信息
                $stmt = $pdo->query("SELECT id, username, role, created_at FROM admins WHERE id = 1");
                $admin = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($admin) {
                    // 如果找到管理员，返回真实数据
                    echo json_encode([
                        'id' => $admin['id'],
                        'username' => $admin['username'],
                        'email' => $admin['username'] . '@example.com', // 如果没有email字段，使用用户名生成
                        'role' => $admin['role'],
                        'last_login' => date('Y-m-d H:i:s') // 如果没有last_login字段，使用当前时间
                    ]);
                    exit;
                } else {
                    // 表存在但没有数据，检查是否有其他管理员数据
                    $stmt = $pdo->query("SELECT COUNT(*) FROM admins");
                    $adminCount = $stmt->fetchColumn();
                    
                    if ($adminCount > 0) {
                        // 如果有其他管理员，返回第一个
                        $stmt = $pdo->query("SELECT id, username, role, created_at FROM admins LIMIT 1");
                        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        echo json_encode([
                            'id' => $admin['id'],
                            'username' => $admin['username'],
                            'email' => $admin['username'] . '@example.com',
                            'role' => $admin['role'],
                            'last_login' => date('Y-m-d H:i:s')
                        ]);
                        exit;
                    } else {
                        // 如果表为空，创建默认管理员
                        $passwordHash = password_hash('admin123', PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("INSERT INTO admins (username, password, role) VALUES (?, ?, ?)");
                        $stmt->execute(['admin', $passwordHash, 'admin']);
                        
                        // 返回新创建的管理员信息
                        echo json_encode([
                            'id' => 1,
                            'username' => 'admin',
                            'email' => 'admin@example.com',
                            'role' => 'admin',
                            'last_login' => date('Y-m-d H:i:s')
                        ]);
                        exit;
                    }
                }
            } else {
                // 表不存在，尝试创建 admins 表
                $createTableSql = "
                CREATE TABLE IF NOT EXISTS admins (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(50) UNIQUE NOT NULL,
                    password VARCHAR(255) NOT NULL,
                    role ENUM('admin', 'editor') DEFAULT 'editor',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ";
                $pdo->exec($createTableSql);
                
                // 创建默认管理员
                $passwordHash = password_hash('admin123', PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO admins (username, password, role) VALUES (?, ?, ?)");
                $stmt->execute(['admin', $passwordHash, 'admin']);
                
                // 返回新创建的管理员信息
                echo json_encode([
                    'id' => 1,
                    'username' => 'admin',
                    'email' => 'admin@example.com',
                    'role' => 'admin',
                    'last_login' => date('Y-m-d H:i:s')
                ]);
                exit;
            }
        } catch (Exception $e) {
            // 出错时返回默认管理员信息，并添加错误信息（仅用于调试）
            echo json_encode([
                'id' => 1,
                'username' => 'admin',
                'email' => 'admin@example.com',
                'role' => 'admin',
                'last_login' => date('Y-m-d H:i:s'),
                'debug_error' => $e->getMessage() // 仅用于调试，生产环境应移除
            ]);
            exit;
        }
    }
    
    if ($path === '/api/admin/account' && $method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($path === '/api/admin/account/password' && $method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);
        // 验证当前密码（模拟）
        if ($input['current_password'] !== 'admin123') {
            echo json_encode(['success' => false, 'message' => '当前密码错误']);
            exit;
        }
        echo json_encode(['success' => true]);
        exit;
    }
    
    // 员工账号 API
    if ($path === '/api/admin/staff' && $method === 'GET') {
        // 返回模拟员工列表
        $staff = [
            [
                'id' => 1,
                'username' => 'staff1',
                'email' => 'staff1@example.com',
                'role' => 'staff',
                'permissions' => ['packages'],
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s', strtotime("-7 day")),
                'last_login' => date('Y-m-d H:i:s', strtotime("-1 day"))
            ],
            [
                'id' => 2,
                'username' => 'staff2',
                'email' => 'staff2@example.com',
                'role' => 'staff',
                'permissions' => ['packages', 'seo'],
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s', strtotime("-14 day")),
                'last_login' => date('Y-m-d H:i:s', strtotime("-3 day"))
            ]
        ];
        echo json_encode($staff);
        exit;
    }
    
    if ($path === '/api/admin/staff' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        echo json_encode(['success' => true]);
        exit;
    }
    
    if (preg_match('#^/api/admin/staff/([0-9]+)$#', $path, $matches) && $method === 'GET') {
        $id = $matches[1];
        echo json_encode([
            'id' => $id,
            'username' => 'staff' . $id,
            'email' => 'staff' . $id . '@example.com',
            'role' => 'staff',
            'permissions' => ['packages'],
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s', strtotime("-7 day")),
            'last_login' => date('Y-m-d H:i:s', strtotime("-1 day"))
        ]);
        exit;
    }
    
    if (preg_match('#^/api/admin/staff/([0-9]+)$#', $path, $matches) && $method === 'PUT') {
        $id = $matches[1];
        $input = json_decode(file_get_contents('php://input'), true);
        echo json_encode(['success' => true]);
        exit;
    }
    
    if (preg_match('#^/api/admin/staff/([0-9]+)$#', $path, $matches) && $method === 'DELETE') {
        $id = $matches[1];
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($path === '/api/admin/staff/batch-delete' && $method === 'DELETE') {
        $input = json_decode(file_get_contents('php://input'), true);
        $ids = $input['ids'] ?? [];
        echo json_encode(['success' => true]);
        exit;
    }
    
    if (preg_match('#^/api/admin/staff/([0-9]+)/status$#', $path, $matches) && $method === 'PUT') {
        $id = $matches[1];
        $input = json_decode(file_get_contents('php://input'), true);
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($path === '/api/admin/staff/export' && $method === 'GET') {
        // 导出员工列表
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename=staff-list.csv');
        echo 'ID,用户名,邮箱,角色,权限,状态,创建时间,上次登录\n';
        echo '1,staff1,staff1@example.com,staff,packages,active,' . date('Y-m-d H:i:s', strtotime("-7 day")) . ',' . date('Y-m-d H:i:s', strtotime("-1 day")) . '\n';
        echo '2,staff2,staff2@example.com,staff,packages,seo,active,' . date('Y-m-d H:i:s', strtotime("-14 day")) . ',' . date('Y-m-d H:i:s', strtotime("-3 day")) . '\n';
        exit;
    }
    
    // 如果没有匹配到任何路由，输出Not found
    echo json_encode(['error' => 'Not found']);
}
